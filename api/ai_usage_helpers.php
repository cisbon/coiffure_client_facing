<?php
/**
 * AI stylist quotas, usage accounting and overage pricing.
 * -------------------------------------------------------------------
 * One place decides whether a salon may generate another AI image and what
 * that image costs. Three callers share it:
 *
 *   api/ai-consultation.php  asks before spending money at OpenRouter, and
 *                            books the image afterwards
 *   api/ai-usage.php         serves the snapshot to the tablet and the
 *                            dashboard, and saves the owner's overage choice
 *   api/dashboard-stats.php  embeds the snapshot in the Übersicht payload
 *
 * Two regimes, chosen by coiffure_salons.status (see migration 027):
 *
 *   trial         one lifetime allowance (ai_trial_image_limit). Exhausted =
 *                 the feature is off. A trial can never incur a charge.
 *   subscription  ai_monthly_image_limit images per calendar month. Past that
 *                 the owner's ai_overage_allowed decides between "stop" and
 *                 "keep going at ai_overage_price per image".
 *
 * A limit of 0 means unlimited, matching coiffure_subscription_plans.
 *
 * Everything is expressed as a snapshot array so the API layer never
 * re-derives the rules:
 *
 *   mode              'trial' | 'subscription'
 *   allowed           bool — may one more image be generated right now?
 *   block_reason      null when allowed, otherwise a stable code the clients
 *                     translate: feature_disabled | salon_suspended |
 *                     trial_limit_reached | monthly_limit_reached
 *   used / limit      images counted in the current window, 0 limit = ∞
 *   remaining         null when unlimited
 *   overage_count     images already past the limit this month
 *   overage_cost      what they add up to, in `currency`
 *   next_image_billed true when the NEXT image would be an overage image
 */

require_once __DIR__ . '/config.php';

/** Quota columns arrive with migration 027; absent before it has run. */
function aiUsageReady(mysqli $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    $columns = $conn->query("SHOW COLUMNS FROM coiffure_salons LIKE 'ai_monthly_image_limit'");
    $table = $conn->query("SHOW TABLES LIKE 'coiffure_ai_image_usage'");

    $ready = $columns && $columns->num_rows > 0 && $table && $table->num_rows > 0;
    return $ready;
}

/**
 * The salon's commercial terms for the AI stylists.
 * Returns null when the salon does not exist.
 */
function aiUsageConfig(mysqli $conn, int $salonId): ?array
{
    $stmt = $conn->prepare(
        'SELECT salon_id, salon_name, status, is_active, currency,
                ai_feature_enabled, ai_trial_image_limit, ai_monthly_image_limit,
                ai_overage_allowed, ai_overage_price
         FROM coiffure_salons WHERE salon_id = ?'
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $salonId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return null;
    }

    return [
        'salon_id' => (int)$row['salon_id'],
        'salon_name' => (string)$row['salon_name'],
        'status' => (string)($row['status'] ?? 'active'),
        'is_active' => (int)($row['is_active'] ?? 1) === 1,
        'currency' => (string)($row['currency'] ?? 'EUR'),
        'feature_enabled' => (int)($row['ai_feature_enabled'] ?? 1) === 1,
        'trial_limit' => (int)($row['ai_trial_image_limit'] ?? 0),
        'monthly_limit' => (int)($row['ai_monthly_image_limit'] ?? 0),
        'overage_allowed' => (int)($row['ai_overage_allowed'] ?? 0) === 1,
        'overage_price' => (float)($row['ai_overage_price'] ?? 0),
    ];
}

/** Images booked for a salon in one calendar month. */
function aiUsageCountForPeriod(mysqli $conn, int $salonId, int $year, int $month): int
{
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS c FROM coiffure_ai_image_usage
         WHERE salon_id = ? AND period_year = ? AND period_month = ?'
    );
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param('iii', $salonId, $year, $month);
    $stmt->execute();
    $count = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
    return $count;
}

/** Every image a salon ever generated — the trial allowance is a lifetime one. */
function aiUsageCountLifetime(mysqli $conn, int $salonId): int
{
    $stmt = $conn->prepare('SELECT COUNT(*) AS c FROM coiffure_ai_image_usage WHERE salon_id = ?');
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param('i', $salonId);
    $stmt->execute();
    $count = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
    return $count;
}

/** Overage images booked this month and what they cost. */
function aiUsageOverageForPeriod(mysqli $conn, int $salonId, int $year, int $month): array
{
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS c, COALESCE(SUM(overage_price), 0) AS total
         FROM coiffure_ai_image_usage
         WHERE salon_id = ? AND period_year = ? AND period_month = ?
           AND billing_state = 'overage'"
    );
    if (!$stmt) {
        return ['count' => 0, 'cost' => 0.0];
    }
    $stmt->bind_param('iii', $salonId, $year, $month);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return [
        'count' => (int)($row['c'] ?? 0),
        'cost' => round((float)($row['total'] ?? 0), 2),
    ];
}

/**
 * The full quota state for a salon, right now.
 *
 * @param array|null $config pass an already-loaded aiUsageConfig() to avoid a
 *                           second query; omitted, it is loaded here.
 */
function aiUsageSnapshot(mysqli $conn, int $salonId, ?array $config = null): array
{
    $now = time();
    $year = (int)date('Y', $now);
    $month = (int)date('n', $now);

    // Before migration 027 there is nothing to meter: keep the feature working
    // exactly as it did rather than locking every salon out.
    if (!aiUsageReady($conn)) {
        return aiUsageUnmetered($year, $month);
    }

    $config = $config ?? aiUsageConfig($conn, $salonId);
    if (!$config) {
        return array_merge(aiUsageUnmetered($year, $month), [
            'allowed' => false,
            'block_reason' => 'feature_disabled',
        ]);
    }

    $mode = $config['status'] === 'trial' ? 'trial' : 'subscription';
    $used = $mode === 'trial'
        ? aiUsageCountLifetime($conn, $salonId)
        : aiUsageCountForPeriod($conn, $salonId, $year, $month);

    return aiUsageEvaluate(
        $config,
        $used,
        aiUsageOverageForPeriod($conn, $salonId, $year, $month),
        $year,
        $month
    );
}

/**
 * The quota rules themselves, with no database in sight.
 *
 * Kept pure so the decisions can be reasoned about (and tested) on their own:
 * everything above only supplies the counts.
 *
 * @param array $config  from aiUsageConfig()
 * @param int   $used    images in the relevant window (lifetime for a trial,
 *                       this calendar month for a subscription)
 * @param array $overage ['count' => int, 'cost' => float] for this month
 */
function aiUsageEvaluate(array $config, int $used, array $overage, int $year, int $month): array
{
    $mode = $config['status'] === 'trial' ? 'trial' : 'subscription';
    $limit = $mode === 'trial' ? $config['trial_limit'] : $config['monthly_limit'];

    $unlimited = $limit === 0;
    $overLimit = !$unlimited && $used >= $limit;

    // Trials are never billed, so overage only ever applies to a subscription.
    $overageActive = $mode === 'subscription' && $config['overage_allowed'];
    $nextImageBilled = $overLimit && $overageActive;

    $blockReason = null;
    if (!$config['feature_enabled']) {
        $blockReason = 'feature_disabled';
    } elseif (!$config['is_active'] || $config['status'] === 'suspended') {
        $blockReason = 'salon_suspended';
    } elseif ($overLimit && !$overageActive) {
        $blockReason = $mode === 'trial' ? 'trial_limit_reached' : 'monthly_limit_reached';
    }

    return [
        'metered' => true,
        'mode' => $mode,
        'allowed' => $blockReason === null,
        'block_reason' => $blockReason,
        'used' => $used,
        'limit' => $limit,
        'unlimited' => $unlimited,
        'remaining' => $unlimited ? null : max(0, $limit - $used),
        'over_limit' => $overLimit,
        'overage_allowed' => $config['overage_allowed'],
        'overage_price' => round($config['overage_price'], 4),
        'overage_count' => $overage['count'],
        'overage_cost' => $overage['cost'],
        'next_image_billed' => $nextImageBilled,
        'currency' => $config['currency'],
        'feature_enabled' => $config['feature_enabled'],
        'salon_status' => $config['status'],
        'period_year' => $year,
        'period_month' => $month,
        'period_label' => sprintf('%04d-%02d', $year, $month),
    ];
}

/** The "no metering configured" snapshot: everything allowed, nothing counted. */
function aiUsageUnmetered(int $year, int $month): array
{
    return [
        'metered' => false,
        'mode' => 'subscription',
        'allowed' => true,
        'block_reason' => null,
        'used' => 0,
        'limit' => 0,
        'unlimited' => true,
        'remaining' => null,
        'over_limit' => false,
        'overage_allowed' => false,
        'overage_price' => 0.0,
        'overage_count' => 0,
        'overage_cost' => 0.0,
        'next_image_billed' => false,
        'currency' => 'EUR',
        'feature_enabled' => true,
        'salon_status' => 'active',
        'period_year' => $year,
        'period_month' => $month,
        'period_label' => sprintf('%04d-%02d', $year, $month),
    ];
}

/**
 * The part of a snapshot a public caller may see.
 *
 * The tablet only needs to know whether it may generate and why not; prices,
 * accrued cost and the salon's overage setting are commercial data that stays
 * inside the authenticated dashboard.
 */
function aiUsagePublicState(array $snapshot): array
{
    return [
        'metered' => (bool)$snapshot['metered'],
        'mode' => $snapshot['mode'],
        'allowed' => (bool)$snapshot['allowed'],
        'block_reason' => $snapshot['block_reason'],
        'used' => (int)$snapshot['used'],
        'limit' => (int)$snapshot['limit'],
        'unlimited' => (bool)$snapshot['unlimited'],
        'remaining' => $snapshot['remaining'],
        'period_label' => $snapshot['period_label'],
    ];
}

/**
 * Book one generated image against the salon's allowance.
 *
 * Called only after an image actually came back, so a failed generation never
 * costs the salon anything. `$snapshot` is the state captured *before* the
 * generation: it decides whether this image is included or billed, and freezes
 * the price that applied at that moment.
 *
 * Never throws — a bookkeeping failure must not turn a delivered image into an
 * error for the customer standing at the tablet; it is logged instead.
 */
function aiUsageRecord(
    mysqli $conn,
    int $salonId,
    ?int $consultationId,
    string $consultationType,
    array $snapshot
): void {
    if (!aiUsageReady($conn) || empty($snapshot['metered'])) {
        return;
    }

    $billingState = !empty($snapshot['next_image_billed']) ? 'overage' : 'included';
    $price = $billingState === 'overage' ? (float)$snapshot['overage_price'] : 0.0;

    $stmt = $conn->prepare(
        'INSERT INTO coiffure_ai_image_usage
            (salon_id, consultation_id, consultation_type, period_year, period_month,
             quota_mode, billing_state, overage_price, currency)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        error_log('aiUsageRecord: prepare failed: ' . $conn->error);
        return;
    }

    $year = (int)$snapshot['period_year'];
    $month = (int)$snapshot['period_month'];
    $mode = (string)$snapshot['mode'];
    $currency = (string)$snapshot['currency'];

    $stmt->bind_param(
        'iisiissds',
        $salonId,
        $consultationId,
        $consultationType,
        $year,
        $month,
        $mode,
        $billingState,
        $price,
        $currency
    );

    if (!$stmt->execute()) {
        error_log('aiUsageRecord: insert failed: ' . $stmt->error);
    }
    $stmt->close();
}

/**
 * The last N months of usage, newest first — the history strip in the
 * dashboard and the input for a monthly invoice line.
 */
function aiUsageHistory(mysqli $conn, int $salonId, int $months = 6): array
{
    if (!aiUsageReady($conn)) {
        return [];
    }

    $stmt = $conn->prepare(
        "SELECT period_year, period_month,
                COUNT(*) AS images,
                SUM(billing_state = 'overage') AS overage_images,
                COALESCE(SUM(overage_price), 0) AS overage_cost
         FROM coiffure_ai_image_usage
         WHERE salon_id = ?
         GROUP BY period_year, period_month
         ORDER BY period_year DESC, period_month DESC
         LIMIT ?"
    );
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('ii', $salonId, $months);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            'period_year' => (int)$row['period_year'],
            'period_month' => (int)$row['period_month'],
            'period_label' => sprintf('%04d-%02d', (int)$row['period_year'], (int)$row['period_month']),
            'images' => (int)$row['images'],
            'overage_images' => (int)$row['overage_images'],
            'overage_cost' => round((float)$row['overage_cost'], 2),
        ];
    }
    $stmt->close();

    return $rows;
}

/** Images per stylist for the current month — "which feature is being used". */
function aiUsageByTypeForPeriod(mysqli $conn, int $salonId, int $year, int $month): array
{
    if (!aiUsageReady($conn)) {
        return [];
    }

    $stmt = $conn->prepare(
        'SELECT consultation_type, COUNT(*) AS images
         FROM coiffure_ai_image_usage
         WHERE salon_id = ? AND period_year = ? AND period_month = ?
         GROUP BY consultation_type
         ORDER BY images DESC'
    );
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('iii', $salonId, $year, $month);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            'consultation_type' => (string)$row['consultation_type'],
            'images' => (int)$row['images'],
        ];
    }
    $stmt->close();

    return $rows;
}
