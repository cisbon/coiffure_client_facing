<?php
/**
 * AI stylist consumption and quota settings
 * -------------------------------------------------------------------
 *   GET  ai-usage.php?salon_id=N&public=1
 *        → unauthenticated read for the tablet. Answers one question -- may
 *          this salon generate another image, and if not, why -- with no
 *          pricing information (same posture as loyalty-config.php).
 *
 *   GET  ai-usage.php[?salon_id=N][&months=6]
 *        → authenticated read for the dashboard: the full snapshot plus the
 *          per-stylist split and the last months of history.
 *        Access: view_insights.
 *
 *   POST ai-usage.php?section=overage   {ai_overage_allowed}
 *        → the salon owner's own decision: stop at the monthly limit, or keep
 *          generating and pay per extra image.
 *        Access: change_settings.
 *
 *   POST ai-usage.php?section=limits    {ai_feature_enabled, ai_trial_image_limit,
 *                                        ai_monthly_image_limit, ai_overage_price}
 *        → the commercial terms themselves. These are platform decisions, not
 *          salon ones, so they need a platform role (Administrator / Delegate).
 *
 * The rules behind all of this live in ai_usage_helpers.php; this file only
 * handles access, validation and persistence.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/ai_usage_helpers.php';

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$conn = getDbConnection();
if (!$conn) {
    sendErrorResponse('Database connection failed', 500);
}

// ------------------------------------------------------------------
// Public read for the tablet
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET['public'])) {
    $salonId = (int)($_GET['salon_id'] ?? DEFAULT_SALON_ID);
    if ($salonId < 1) {
        $salonId = (int)DEFAULT_SALON_ID;
    }

    $snapshot = aiUsageSnapshot($conn, $salonId);
    $conn->close();

    sendJsonResponse([
        'success' => true,
        'usage' => aiUsagePublicState($snapshot),
    ], 200);
}

$user = requireAuth($conn);
requireDashboardAccess($user);

// ------------------------------------------------------------------
// GET — consumption for the dashboard
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $salonId = resolveSalonScope($conn, $user, $_GET['salon_id'] ?? null);
    requirePermission($conn, $user, 'view_insights', $salonId);

    $months = isset($_GET['months']) ? max(1, min(24, (int)$_GET['months'])) : 6;
    $snapshot = aiUsageSnapshot($conn, $salonId);

    $payload = [
        'success' => true,
        'salon_id' => $salonId,
        'usage' => $snapshot,
        'by_type' => aiUsageByTypeForPeriod(
            $conn,
            $salonId,
            (int)$snapshot['period_year'],
            (int)$snapshot['period_month']
        ),
        'history' => aiUsageHistory($conn, $salonId, $months),
        'can_change_overage' => hasPermission($conn, $user, 'change_settings', $salonId),
        'can_change_limits' => in_array($user['role'] ?? '', PLATFORM_ROLES, true),
    ];

    // The snapshot only carries the limit for the salon's current mode. A
    // platform admin edits both, so send both.
    if ($payload['can_change_limits']) {
        $config = aiUsageConfig($conn, $salonId);
        $payload['limits'] = [
            'ai_feature_enabled' => $config ? $config['feature_enabled'] : true,
            'ai_trial_image_limit' => $config ? $config['trial_limit'] : 0,
            'ai_monthly_image_limit' => $config ? $config['monthly_limit'] : 0,
            'ai_overage_price' => $config ? $config['overage_price'] : 0,
        ];
    }

    $conn->close();
    sendJsonResponse($payload, 200);
}

// ------------------------------------------------------------------
// POST — save quota settings
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendErrorResponse('Method not allowed.', 405);
}

if (!aiUsageReady($conn)) {
    sendErrorResponse('AI usage columns missing. Please run migration 027.', 500, [
        'hint' => 'php api/apply_migration_027.php',
    ]);
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$salonId = resolveSalonScope($conn, $user, $input['salon_id'] ?? ($_GET['salon_id'] ?? null));
$section = $_GET['section'] ?? '';

switch ($section) {
    case 'overage':
        requirePermission($conn, $user, 'change_settings', $salonId);
        saveOverageChoice($conn, $salonId, $user, $input);
        break;

    case 'limits':
        requirePlatformRole($user);
        saveLimits($conn, $salonId, $user, $input);
        break;

    default:
        sendErrorResponse('Unknown section. Use ?section=overage or ?section=limits.', 400);
}

/**
 * The salon owner's overage decision, and the ceiling on it.
 *
 * Deliberately the only AI settings a salon can change itself: they are the
 * ones that cost them money, so they must be their explicit choice, while the
 * limits and the price per image stay with the platform.
 */
function saveOverageChoice(mysqli $conn, int $salonId, array $user, array $input): void
{
    if (!array_key_exists('ai_overage_allowed', $input)) {
        sendErrorResponse('ai_overage_allowed is required.', 400);
    }

    $allowed = !empty($input['ai_overage_allowed']) ? 1 : 0;
    $capSupported = aiUsageCapReady($conn);

    // 0 = no cap. The upper bound is a sanity rail against a stray keypress
    // turning into a budget nobody meant to approve.
    $cap = null;
    if ($capSupported && array_key_exists('ai_overage_monthly_cap', $input)) {
        $cap = round(max(0, min(100000, (float)$input['ai_overage_monthly_cap'])), 2);
    }

    if ($cap !== null) {
        $stmt = prepareOrFail(
            $conn,
            'UPDATE coiffure_salons SET ai_overage_allowed = ?, ai_overage_monthly_cap = ? WHERE salon_id = ?',
            'ai overage update'
        );
        $stmt->bind_param('idi', $allowed, $cap, $salonId);
    } else {
        $stmt = prepareOrFail(
            $conn,
            'UPDATE coiffure_salons SET ai_overage_allowed = ? WHERE salon_id = ?',
            'ai overage update'
        );
        $stmt->bind_param('ii', $allowed, $salonId);
    }

    if (!$stmt->execute()) {
        $stmt->close();
        sendErrorResponse('Failed to save the setting.', 500);
    }
    $stmt->close();

    logAudit(
        $conn,
        'salon',
        $salonId,
        'update',
        'AI overage ' . ($allowed ? 'enabled' : 'disabled')
            . ($cap !== null ? sprintf(', monthly cap %.2f', $cap) : ''),
        'user:' . ($user['user_id'] ?? '?')
    );

    respondWithSnapshot($conn, $salonId);
}

/** Platform-side commercial terms. */
function saveLimits(mysqli $conn, int $salonId, array $user, array $input): void
{
    $current = aiUsageConfig($conn, $salonId);
    if (!$current) {
        sendErrorResponse('Salon not found.', 404);
    }

    $enabled = array_key_exists('ai_feature_enabled', $input)
        ? (!empty($input['ai_feature_enabled']) ? 1 : 0)
        : ($current['feature_enabled'] ? 1 : 0);

    // 0 means unlimited; the upper bounds are sanity rails against a typo
    // turning into a five-figure OpenRouter bill.
    $trialLimit = array_key_exists('ai_trial_image_limit', $input)
        ? max(0, min(1000000, (int)$input['ai_trial_image_limit']))
        : $current['trial_limit'];

    $monthlyLimit = array_key_exists('ai_monthly_image_limit', $input)
        ? max(0, min(1000000, (int)$input['ai_monthly_image_limit']))
        : $current['monthly_limit'];

    $price = array_key_exists('ai_overage_price', $input)
        ? max(0, min(100, (float)$input['ai_overage_price']))
        : $current['overage_price'];

    $stmt = prepareOrFail(
        $conn,
        'UPDATE coiffure_salons
         SET ai_feature_enabled = ?, ai_trial_image_limit = ?, ai_monthly_image_limit = ?, ai_overage_price = ?
         WHERE salon_id = ?',
        'ai limits update'
    );
    $stmt->bind_param('iiidi', $enabled, $trialLimit, $monthlyLimit, $price, $salonId);
    if (!$stmt->execute()) {
        $stmt->close();
        sendErrorResponse('Failed to save the limits.', 500);
    }
    $stmt->close();

    logAudit(
        $conn,
        'salon',
        $salonId,
        'update',
        sprintf(
            'AI limits: feature=%d trial=%d monthly=%d price=%.4f',
            $enabled,
            $trialLimit,
            $monthlyLimit,
            $price
        ),
        'user:' . ($user['user_id'] ?? '?')
    );

    respondWithSnapshot($conn, $salonId);
}

/** Every save answers with the fresh snapshot, so the UI never guesses. */
function respondWithSnapshot(mysqli $conn, int $salonId): void
{
    $snapshot = aiUsageSnapshot($conn, $salonId);
    $conn->close();
    sendJsonResponse([
        'success' => true,
        'salon_id' => $salonId,
        'usage' => $snapshot,
    ], 200);
}
