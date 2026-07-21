<?php
/**
 * Loyalty helpers
 * -------------------------------------------------------------------
 * Shared between api/loyalty-config.php (admin read/write) and
 * api/checkin.php (welcome-screen progress bar). Keeps the loyalty maths and
 * the discount-label formatting in ONE place so the kiosk never hardcodes a
 * threshold or a discount amount.
 */

/** True once migration 012 has added the loyalty columns. */
function loyaltyColumnsExist(mysqli $conn): bool
{
    $res = $conn->query("SHOW COLUMNS FROM coiffure_salons LIKE 'loyalty_visit_threshold'");
    return $res && $res->num_rows > 0;
}

/**
 * Human-readable discount label. A salon may override it with a free-text
 * label; otherwise we compute "10 €" / "15 %" from type + value.
 */
function computeDiscountLabel(string $type, float $value, ?string $custom): string
{
    $custom = $custom !== null ? trim($custom) : '';
    if ($custom !== '') {
        return $custom;
    }
    // Drop a trailing .00 so "10.00" reads as "10".
    $num = (fmod($value, 1.0) === 0.0)
        ? number_format($value, 0, ',', '.')
        : number_format($value, 2, ',', '.');
    return $type === 'percentage' ? "$num %" : "$num €";
}

/**
 * Effective loyalty config for a salon, with sane defaults when the columns
 * are missing (pre-migration) or the salon row is absent.
 */
function getLoyaltyConfig(mysqli $conn, int $salonId): array
{
    $defaults = [
        'loyalty_active'   => true,
        'visit_threshold'  => 5,
        'discount_type'    => 'fixed_eur',
        'discount_value'   => 10.0,
        'discount_label'   => '10 €',
    ];

    if (!loyaltyColumnsExist($conn)) {
        return $defaults;
    }

    $stmt = $conn->prepare(
        "SELECT loyalty_active, loyalty_visit_threshold, loyalty_discount_type,
                loyalty_discount_value, loyalty_discount_label
         FROM coiffure_salons WHERE salon_id = ? LIMIT 1"
    );
    if (!$stmt) {
        return $defaults;
    }
    $stmt->bind_param("i", $salonId);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 0) {
        $stmt->close();
        return $defaults;
    }
    $row = $res->fetch_assoc();
    $stmt->close();

    $type  = $row['loyalty_discount_type'] ?: 'fixed_eur';
    $value = (float)$row['loyalty_discount_value'];

    return [
        'loyalty_active'  => (int)$row['loyalty_active'] === 1,
        'visit_threshold' => max(1, (int)$row['loyalty_visit_threshold']),
        'discount_type'   => $type,
        'discount_value'  => $value,
        'discount_label'  => computeDiscountLabel($type, $value, $row['loyalty_discount_label']),
    ];
}

/**
 * Progress calculation for the welcome-screen bar.
 * The modulo naturally handles mid-cycle threshold changes: customers simply
 * continue counting against the new threshold, no visit_count reset needed.
 */
function getLoyaltyProgress(array $config, int $visitCount): array
{
    $threshold = max(1, (int)$config['visit_threshold']);
    // A reward lands exactly on every Nth visit.
    $isReward = $visitCount > 0 && ($visitCount % $threshold === 0);
    // Visits into the current cycle: N (full) on a reward visit, else remainder.
    $inCycle = $isReward ? $threshold : ($visitCount % $threshold);
    $remaining = $isReward ? 0 : ($threshold - $inCycle);
    $percent = (int)round(($inCycle / $threshold) * 100);

    return [
        'visit_count'      => $visitCount,
        'visit_threshold'  => $threshold,
        'visits_in_cycle'  => $inCycle,
        'visits_remaining' => $remaining,
        'percent'          => $percent,
        'is_reward_visit'  => $isReward,
        'discount_label'   => $config['discount_label'],
    ];
}
