<?php
/**
 * Guard for the AI image quota rules.
 * ------------------------------------------------------------
 * There is no test runner in this project, so nothing would otherwise catch a
 * change to aiUsageEvaluate() that quietly starts billing a trial salon or
 * lets a salon past its limit. This script is the check: it walks every
 * scenario the feature promises, against the real rules.
 *
 * The rules are deliberately a pure function, so no database is needed.
 *
 * Usage: php scripts/check-ai-quota.php
 * Exits non-zero when a rule changed, so it can gate a commit.
 */

// Only aiUsageEvaluate() is under test; extracting it avoids pulling in
// config.php and its database constants.
$source = file_get_contents(__DIR__ . '/../api/ai_usage_helpers.php');
$start = strpos($source, 'function aiUsageEvaluate');
$end = strpos($source, "\n}\n", $start) + 3;
eval(substr($source, $start, $end - $start));

function cfg(array $over = []): array
{
    return array_merge([
        'salon_id' => 1,
        'salon_name' => 'Test',
        'status' => 'active',
        'is_active' => true,
        'currency' => 'EUR',
        'feature_enabled' => true,
        'trial_limit' => 100,
        'monthly_limit' => 500,
        'overage_allowed' => false,
        'overage_price' => 0.01,
        'overage_cap' => 0.0,
    ], $over);
}

$failures = 0;
function check(string $name, $actual, $expected)
{
    global $failures;
    $ok = $actual === $expected;
    if (!$ok) $failures++;
    printf(
        "%s %-58s got %s, expected %s\n",
        $ok ? '  ok  ' : ' FAIL ',
        $name,
        var_export($actual, true),
        var_export($expected, true)
    );
}

function scenario(string $title)
{
    echo "\n$title\n" . str_repeat('-', strlen($title)) . "\n";
}

$noOverage = ['count' => 0, 'cost' => 0.0];

// ------------------------------------------------------------------
scenario('Trial: 40 of 100 used');
$s = aiUsageEvaluate(cfg(['status' => 'trial']), 40, $noOverage, 2026, 8);
check('mode', $s['mode'], 'trial');
check('limit is the trial allowance', $s['limit'], 100);
check('allowed', $s['allowed'], true);
check('remaining', $s['remaining'], 60);
check('next image is free', $s['next_image_billed'], false);

// ------------------------------------------------------------------
scenario('Trial: 100 of 100 used -> feature off, no paid overage');
$s = aiUsageEvaluate(cfg(['status' => 'trial']), 100, $noOverage, 2026, 8);
check('allowed', $s['allowed'], false);
check('block reason', $s['block_reason'], 'trial_limit_reached');
check('remaining', $s['remaining'], 0);

scenario('Trial: limit reached AND owner allowed overage -> still blocked');
$s = aiUsageEvaluate(cfg(['status' => 'trial', 'overage_allowed' => true]), 120, $noOverage, 2026, 8);
check('allowed', $s['allowed'], false);
check('block reason', $s['block_reason'], 'trial_limit_reached');
check('never billed during a trial', $s['next_image_billed'], false);

// ------------------------------------------------------------------
scenario('Subscription: 200 of 500, no additional cost');
$s = aiUsageEvaluate(cfg(), 200, $noOverage, 2026, 8);
check('mode', $s['mode'], 'subscription');
check('allowed', $s['allowed'], true);
check('used/limit', "{$s['used']}/{$s['limit']}", '200/500');
check('overage cost', $s['overage_cost'], 0.0);
check('next image is free', $s['next_image_billed'], false);

// ------------------------------------------------------------------
scenario('Subscription: 500 of 500, overage OFF -> limit reached, feature disabled');
$s = aiUsageEvaluate(cfg(), 500, $noOverage, 2026, 8);
check('allowed', $s['allowed'], false);
check('block reason', $s['block_reason'], 'monthly_limit_reached');
check('over limit', $s['over_limit'], true);
check('overage cost', $s['overage_cost'], 0.0);

// ------------------------------------------------------------------
scenario('Subscription: 600 of 500, overage ON -> 2.15 EUR additional cost');
$s = aiUsageEvaluate(
    cfg(['overage_allowed' => true, 'overage_price' => 0.0215]),
    600,
    ['count' => 100, 'cost' => 2.15],
    2026,
    8
);
check('allowed', $s['allowed'], true);
check('block reason', $s['block_reason'], null);
check('used/limit', "{$s['used']}/{$s['limit']}", '600/500');
check('overage images', $s['overage_count'], 100);
check('overage cost', $s['overage_cost'], 2.15);
check('next image is billed', $s['next_image_billed'], true);
check('price frozen on the snapshot', $s['overage_price'], 0.0215);

// ------------------------------------------------------------------
scenario('Subscription: exactly at the limit with overage ON -> keeps going, billed');
$s = aiUsageEvaluate(cfg(['overage_allowed' => true]), 500, $noOverage, 2026, 8);
check('allowed', $s['allowed'], true);
check('next image is billed', $s['next_image_billed'], true);

scenario('Subscription: one below the limit -> still included');
$s = aiUsageEvaluate(cfg(['overage_allowed' => true]), 499, $noOverage, 2026, 8);
check('next image is free', $s['next_image_billed'], false);

// ------------------------------------------------------------------
// Monthly spend cap on extras (migration 028)
// ------------------------------------------------------------------
scenario('Cap: 12.00 of a 20.00 budget spent -> keeps generating');
$s = aiUsageEvaluate(
    cfg(['overage_allowed' => true, 'overage_cap' => 20.0]),
    700,
    ['count' => 1200, 'cost' => 12.0],
    2026,
    8
);
check('allowed', $s['allowed'], true);
check('next image is billed', $s['next_image_billed'], true);
check('capped', $s['overage_capped'], false);
check('budget left', $s['overage_budget_left'], 8.0);

scenario('Cap: budget exactly spent -> feature off for the month');
$s = aiUsageEvaluate(
    cfg(['overage_allowed' => true, 'overage_cap' => 20.0]),
    2500,
    ['count' => 2000, 'cost' => 20.0],
    2026,
    8
);
check('allowed', $s['allowed'], false);
check('block reason', $s['block_reason'], 'overage_cap_reached');
check('capped', $s['overage_capped'], true);
check('budget left', $s['overage_budget_left'], 0.0);
check('next image not billed', $s['next_image_billed'], false);

scenario('Cap is a hard ceiling: the last affordable image is allowed...');
$s = aiUsageEvaluate(
    cfg(['overage_allowed' => true, 'overage_cap' => 20.0]),
    2499,
    ['count' => 1999, 'cost' => 19.99],
    2026,
    8
);
check('allowed (19.99 + 0.01 = 20.00, still within)', $s['allowed'], true);
check('next image is billed', $s['next_image_billed'], true);

scenario('...but the one that would exceed it is not');
$s = aiUsageEvaluate(
    cfg(['overage_allowed' => true, 'overage_cap' => 20.0, 'overage_price' => 0.02]),
    2500,
    ['count' => 1000, 'cost' => 19.99],
    2026,
    8
);
check('allowed (19.99 + 0.02 = 20.01, over)', $s['allowed'], false);
check('block reason', $s['block_reason'], 'overage_cap_reached');

scenario('Cap 0 means no cap');
$s = aiUsageEvaluate(
    cfg(['overage_allowed' => true, 'overage_cap' => 0.0]),
    9999,
    ['count' => 9000, 'cost' => 90.0],
    2026,
    8
);
check('allowed', $s['allowed'], true);
check('capped', $s['overage_capped'], false);
check('budget left is null', $s['overage_budget_left'], null);

scenario('Cap reached but still inside the included allowance -> unaffected');
$s = aiUsageEvaluate(
    cfg(['overage_allowed' => true, 'overage_cap' => 1.0]),
    200,
    ['count' => 100, 'cost' => 1.0],
    2026,
    8
);
check('allowed', $s['allowed'], true);
check('block reason', $s['block_reason'], null);

scenario('Extras declined -> the plain limit message, not the cap one');
$s = aiUsageEvaluate(
    cfg(['overage_allowed' => false, 'overage_cap' => 20.0]),
    500,
    $noOverage,
    2026,
    8
);
check('block reason', $s['block_reason'], 'monthly_limit_reached');

scenario('A cap never applies to a trial');
$s = aiUsageEvaluate(
    cfg(['status' => 'trial', 'overage_allowed' => true, 'overage_cap' => 20.0]),
    100,
    $noOverage,
    2026,
    8
);
check('block reason', $s['block_reason'], 'trial_limit_reached');

// ------------------------------------------------------------------
scenario('Master switch off beats everything');
$s = aiUsageEvaluate(cfg(['feature_enabled' => false, 'overage_allowed' => true]), 0, $noOverage, 2026, 8);
check('allowed', $s['allowed'], false);
check('block reason', $s['block_reason'], 'feature_disabled');

scenario('Suspended salon');
$s = aiUsageEvaluate(cfg(['status' => 'suspended']), 0, $noOverage, 2026, 8);
check('block reason', $s['block_reason'], 'salon_suspended');

scenario('Deactivated salon (is_active = 0)');
$s = aiUsageEvaluate(cfg(['is_active' => false]), 0, $noOverage, 2026, 8);
check('block reason', $s['block_reason'], 'salon_suspended');

// ------------------------------------------------------------------
scenario('Limit 0 means unlimited');
$s = aiUsageEvaluate(cfg(['monthly_limit' => 0]), 99999, $noOverage, 2026, 8);
check('allowed', $s['allowed'], true);
check('unlimited', $s['unlimited'], true);
check('remaining is null', $s['remaining'], null);
check('never billed', $s['next_image_billed'], false);

scenario('Period label');
$s = aiUsageEvaluate(cfg(), 1, $noOverage, 2026, 3);
check('label', $s['period_label'], '2026-03');

echo "\n" . ($failures === 0 ? "All checks passed.\n" : "$failures CHECK(S) FAILED\n");
exit($failures === 0 ? 0 : 1);
