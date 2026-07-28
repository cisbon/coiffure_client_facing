<?php
/**
 * Consent trail writer
 * -------------------------------------------------------------------
 * Shared helper, not an endpoint. api/consent-history.php reads what this
 * writes.
 *
 * Migration 024 created coiffure_consent_history to prove what a customer
 * agreed to and when. This is what fills it: call it wherever a consent value
 * can change, with the values as they were and as they now are.
 *
 * Only fields that actually changed are recorded, so re-saving a registration
 * without touching a checkbox does not add noise to the trail. A first
 * registration has no "before", which is recorded as NULL → true.
 *
 * Best-effort by design: failing to write the trail must never fail the
 * registration that produced it.
 */

require_once __DIR__ . '/config.php';

/**
 * The consent columns on coiffure_customers that are worth a trail. These are
 * exactly the columns that exist -- a field listed here that the caller does
 * not pass is skipped, so this list is the whole vocabulary of the trail.
 */
const CONSENT_FIELDS = [
    'consent_data_processing',
    'consent_email_marketing',
    'consent_sms_whatsapp',
    'consent_postal',
    'consent_marketing',
    'consent_cancellation_policy',
];

function consentHistoryTableExists(mysqli $conn): bool
{
    static $exists = null;
    if ($exists === null) {
        $res = $conn->query("SHOW TABLES LIKE 'coiffure_consent_history'");
        $exists = $res && $res->num_rows > 0;
    }
    return $exists;
}

/**
 * Record every consent value that changed.
 *
 * @param array $before Previous row (or [] for a new customer).
 * @param array $after  The values just written, keyed by column name.
 * @param string $source tablet | dashboard | import
 * @return int How many rows were written.
 */
function recordConsentChanges(
    mysqli $conn,
    int $customerId,
    ?int $salonId,
    array $before,
    array $after,
    string $source = 'tablet',
    ?string $changedBy = null,
    ?string $policyVersion = null
): int {
    if (!consentHistoryTableExists($conn) || $customerId <= 0) {
        return 0;
    }

    $stmt = $conn->prepare(
        'INSERT INTO coiffure_consent_history
            (customer_id, salon_id, consent_field, old_value, new_value,
             policy_version, source, changed_by, ip_address, user_agent)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        error_log('recordConsentChanges: prepare failed: ' . $conn->error);
        return 0;
    }

    $ip = getClientIp();
    $userAgent = getUserAgent();
    $written = 0;

    foreach (CONSENT_FIELDS as $field) {
        if (!array_key_exists($field, $after)) {
            continue;
        }

        $newValue = normaliseConsent($after[$field]);
        $oldValue = array_key_exists($field, $before) ? normaliseConsent($before[$field]) : null;

        // Unchanged means nothing to prove; skip rather than pad the trail.
        if ($oldValue === $newValue) {
            continue;
        }

        $old = $oldValue === null ? null : ($oldValue ? '1' : '0');
        $new = $newValue ? '1' : '0';

        $stmt->bind_param(
            'iissssssss',
            $customerId, $salonId, $field, $old, $new,
            $policyVersion, $source, $changedBy, $ip, $userAgent
        );
        if ($stmt->execute()) {
            $written++;
        }
    }

    $stmt->close();
    return $written;
}

/** Consent arrives as bool, int, '1'/'0' or 'true' depending on the caller. */
function normaliseConsent($value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value)) {
        return $value !== 0;
    }
    return in_array(strtolower((string)$value), ['1', 'true', 'yes', 'ja', 'on'], true);
}
