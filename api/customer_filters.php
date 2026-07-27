<?php
/**
 * Customer segmentation filter builder
 * -------------------------------------------------------------------
 * One place that turns a filter object into SQL. It is shared by:
 *   insights.php        the Kunden list, the CSV export and the counts
 *   segments.php        validating and previewing a saved segment
 *   campaigns.php       resolving a campaign's recipients (later stage)
 *
 * Keeping it here matters: a segment saved from the Kunden screen must select
 * exactly the same people when a campaign is sent from it, otherwise a salon
 * mails the wrong list. Every caller goes through buildCustomerQuery().
 *
 * Filter shape (all keys optional):
 *   search          string   name / email / phone / town
 *   members_only    bool     is_member = 1
 *   gender          string   female | male | diverse
 *   age_min/age_max int      derived from birth_year; rows without a year are
 *                            excluded when either bound is set
 *   zip             string   postcode prefix, e.g. "10" matches 10115
 *   visited_within_weeks    int  had a visit in the last N weeks
 *   not_visited_within_weeks int no visit in the last N weeks (inactive preset)
 *   registered_from / registered_to  Y-m-d
 *   min_visits      int      total visits at least N
 *   consent_email   bool     only customers who may receive marketing e-mail
 */

require_once __DIR__ . '/config.php';

/** Filter keys the system understands. Anything else is ignored, not trusted. */
const CUSTOMER_FILTER_KEYS = [
    'search', 'members_only', 'gender', 'age_min', 'age_max', 'zip',
    'visited_within_weeks', 'not_visited_within_weeks',
    'registered_from', 'registered_to', 'min_visits', 'consent_email',
];

/**
 * Normalise a raw filter array: drop unknown keys, coerce types, discard empties.
 * Used before persisting a segment so stored JSON stays clean and comparable.
 */
function normaliseCustomerFilter($raw): array
{
    if (is_string($raw)) {
        $raw = json_decode($raw, true);
    }
    if (!is_array($raw)) {
        return [];
    }

    $out = [];
    foreach (CUSTOMER_FILTER_KEYS as $key) {
        if (!array_key_exists($key, $raw)) {
            continue;
        }
        $value = $raw[$key];

        if ($value === null || $value === '' || $value === false) {
            continue;
        }

        switch ($key) {
            case 'members_only':
            case 'consent_email':
                $out[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                if (!$out[$key]) {
                    unset($out[$key]);
                }
                break;

            case 'age_min':
            case 'age_max':
            case 'visited_within_weeks':
            case 'not_visited_within_weeks':
            case 'min_visits':
                $number = (int)$value;
                if ($number > 0) {
                    $out[$key] = $number;
                }
                break;

            case 'gender':
                if (in_array($value, ['female', 'male', 'diverse'], true)) {
                    $out[$key] = $value;
                }
                break;

            case 'registered_from':
            case 'registered_to':
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$value)) {
                    $out[$key] = (string)$value;
                }
                break;

            default:
                $out[$key] = trim((string)$value);
                if ($out[$key] === '') {
                    unset($out[$key]);
                }
        }
    }

    return $out;
}

/**
 * Build the WHERE clause and bind arguments for a customer query.
 *
 * @param int[] $salonIds already resolved by resolveSalonScopeList()
 * @return array{where:string, types:string, args:array}
 */
function buildCustomerWhere(array $salonIds, array $filter): array
{
    $salonIds = array_values(array_map('intval', $salonIds)) ?: [0];
    $placeholders = implode(', ', array_fill(0, count($salonIds), '?'));

    $where = ["c.salon_id IN ($placeholders)", 'c.is_deleted = 0'];
    $types = str_repeat('i', count($salonIds));
    $args = $salonIds;

    if (!empty($filter['search'])) {
        $where[] = '(c.full_name LIKE ? OR c.email LIKE ? OR c.phone LIKE ? OR c.mobile LIKE ? OR c.city LIKE ? OR c.zip LIKE ?)';
        $like = '%' . $filter['search'] . '%';
        $types .= 'ssssss';
        array_push($args, $like, $like, $like, $like, $like, $like);
    }

    if (!empty($filter['members_only'])) {
        $where[] = 'c.is_member = 1';
    }

    if (!empty($filter['gender'])) {
        $where[] = 'c.gender = ?';
        $types .= 's';
        $args[] = $filter['gender'];
    }

    // Age is derived from birth_year, which is optional. When an age bound is
    // set, customers without a birth year cannot be judged, so they drop out.
    if (!empty($filter['age_min'])) {
        $where[] = 'c.birth_year IS NOT NULL AND (YEAR(CURDATE()) - c.birth_year) >= ?';
        $types .= 'i';
        $args[] = (int)$filter['age_min'];
    }
    if (!empty($filter['age_max'])) {
        $where[] = 'c.birth_year IS NOT NULL AND (YEAR(CURDATE()) - c.birth_year) <= ?';
        $types .= 'i';
        $args[] = (int)$filter['age_max'];
    }

    if (!empty($filter['zip'])) {
        $where[] = 'c.zip LIKE ?';
        $types .= 's';
        $args[] = $filter['zip'] . '%';
    }

    if (!empty($filter['registered_from'])) {
        $where[] = 'c.created_at >= ?';
        $types .= 's';
        $args[] = $filter['registered_from'] . ' 00:00:00';
    }
    if (!empty($filter['registered_to'])) {
        $where[] = 'c.created_at <= ?';
        $types .= 's';
        $args[] = $filter['registered_to'] . ' 23:59:59';
    }

    // Marketing consent: the newer per-channel flag, falling back to the legacy
    // general one so customers registered before migration 008 still qualify.
    if (!empty($filter['consent_email'])) {
        $where[] = '(c.consent_email_marketing = 1 OR c.consent_marketing = 1)';
    }

    if (!empty($filter['visited_within_weeks'])) {
        $where[] = 'EXISTS (SELECT 1 FROM coiffure_visits v
                            WHERE v.customer_id = c.customer_id
                              AND v.checked_in_at >= DATE_SUB(CURDATE(), INTERVAL ? WEEK))';
        $types .= 'i';
        $args[] = (int)$filter['visited_within_weeks'];
    }

    // "Not visited in N weeks" must also catch customers who have never visited
    // at all -- NOT EXISTS covers both cases in one clause.
    if (!empty($filter['not_visited_within_weeks'])) {
        $where[] = 'NOT EXISTS (SELECT 1 FROM coiffure_visits v
                                WHERE v.customer_id = c.customer_id
                                  AND v.checked_in_at >= DATE_SUB(CURDATE(), INTERVAL ? WEEK))';
        $types .= 'i';
        $args[] = (int)$filter['not_visited_within_weeks'];
    }

    if (!empty($filter['min_visits'])) {
        $where[] = '(SELECT COUNT(*) FROM coiffure_visits v WHERE v.customer_id = c.customer_id) >= ?';
        $types .= 'i';
        $args[] = (int)$filter['min_visits'];
    }

    return [
        'where' => implode(' AND ', $where),
        'types' => $types,
        'args'  => $args,
    ];
}

/** Columns every customer list returns, including the derived visit stats. */
const CUSTOMER_SELECT = "
    c.customer_id, c.salon_id, c.full_name, c.first_name, c.last_name,
    c.gender, c.title, c.email, c.phone, c.mobile,
    c.birth_day, c.birth_month, c.birth_year,
    c.zip, c.city, c.is_member, c.member_id, c.member_since,
    c.referral_source, c.created_at,
    c.consent_email_marketing, c.consent_marketing, c.consent_sms_whatsapp,
    c.consent_postal, c.consent_data_processing,
    (SELECT COUNT(*) FROM coiffure_visits v WHERE v.customer_id = c.customer_id) AS visit_count,
    (SELECT MAX(v.checked_in_at) FROM coiffure_visits v WHERE v.customer_id = c.customer_id) AS last_visit
";

/** Sort keys the client may request, mapped to safe SQL. */
const CUSTOMER_SORTS = [
    'name'        => 'c.full_name',
    'created_at'  => 'c.created_at',
    'visits'      => 'visit_count',
    'last_visit'  => 'last_visit',
    'zip'         => 'c.zip',
];

/**
 * Run a filtered customer query.
 *
 * @return array{rows:array, total:int}
 */
function buildCustomerQuery(
    mysqli $conn,
    array $salonIds,
    array $filter,
    string $sort = 'created_at',
    string $direction = 'desc',
    int $limit = 0,
    int $offset = 0
): array {
    $clause = buildCustomerWhere($salonIds, $filter);

    $orderColumn = CUSTOMER_SORTS[$sort] ?? CUSTOMER_SORTS['created_at'];
    $orderDir = strtolower($direction) === 'asc' ? 'ASC' : 'DESC';

    $sql = 'SELECT ' . CUSTOMER_SELECT . "
            FROM coiffure_customers c
            WHERE {$clause['where']}
            ORDER BY $orderColumn $orderDir, c.customer_id DESC";

    $types = $clause['types'];
    $args = $clause['args'];

    if ($limit > 0) {
        $sql .= ' LIMIT ? OFFSET ?';
        $types .= 'ii';
        $args[] = $limit;
        $args[] = max(0, $offset);
    }

    $rows = [];
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param($types, ...$args);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
    } else {
        error_log('buildCustomerQuery: prepare failed: ' . $conn->error);
    }

    return ['rows' => $rows, 'total' => countCustomers($conn, $salonIds, $filter)];
}

/** How many customers match, without fetching them. */
function countCustomers(mysqli $conn, array $salonIds, array $filter): int
{
    $clause = buildCustomerWhere($salonIds, $filter);

    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total FROM coiffure_customers c WHERE {$clause['where']}"
    );
    if (!$stmt) {
        error_log('countCustomers: prepare failed: ' . $conn->error);
        return 0;
    }

    $stmt->bind_param($clause['types'], ...$clause['args']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row['total'] ?? 0);
}

/** Shape a raw customer row for JSON, casting the numeric columns. */
function presentCustomer(array $row): array
{
    return [
        'customer_id'  => (int)$row['customer_id'],
        'salon_id'     => (int)$row['salon_id'],
        'full_name'    => $row['full_name'],
        'first_name'   => $row['first_name'],
        'last_name'    => $row['last_name'],
        'gender'       => $row['gender'],
        'title'        => $row['title'],
        'email'        => $row['email'],
        'phone'        => $row['phone'],
        'mobile'       => $row['mobile'],
        'birth_day'    => $row['birth_day'] !== null ? (int)$row['birth_day'] : null,
        'birth_month'  => $row['birth_month'] !== null ? (int)$row['birth_month'] : null,
        'birth_year'   => $row['birth_year'] !== null ? (int)$row['birth_year'] : null,
        'age'          => $row['birth_year'] ? ((int)date('Y') - (int)$row['birth_year']) : null,
        'zip'          => $row['zip'],
        'city'         => $row['city'],
        'is_member'    => (int)$row['is_member'] === 1,
        'member_id'    => $row['member_id'],
        'member_since' => $row['member_since'],
        'referral_source' => $row['referral_source'],
        'created_at'   => $row['created_at'],
        'visit_count'  => (int)$row['visit_count'],
        'last_visit'   => $row['last_visit'],
        'consent' => [
            'email_marketing'  => (int)($row['consent_email_marketing'] ?? 0) === 1,
            'marketing'        => (int)($row['consent_marketing'] ?? 0) === 1,
            'sms_whatsapp'     => (int)($row['consent_sms_whatsapp'] ?? 0) === 1,
            'postal'           => (int)($row['consent_postal'] ?? 0) === 1,
            'data_processing'  => (int)($row['consent_data_processing'] ?? 0) === 1,
        ],
    ];
}
