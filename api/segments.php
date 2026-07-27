<?php
/**
 * Saved customer segments
 * -------------------------------------------------------------------
 *   GET    segments.php                → segments for the salon, with live counts
 *   POST   segments.php                → create   {name, description?, filter}
 *   PUT    segments.php?segment_id=N   → update   {name?, description?, filter?}
 *   DELETE segments.php?segment_id=N   → delete (presets cannot be deleted)
 *
 * A segment is a named filter, not a frozen list of people. The count is
 * recomputed on every read through the same builder the Kunden screen and the
 * campaign recipient resolver use (api/customer_filters.php), so a segment
 * called "Inaktiv seit 10 Wochen" keeps meaning that as time passes.
 *
 * Access: view_insights, scoped to a single salon -- segments belong to one
 * salon, so the aggregated "Alle Salons" view cannot create them.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/customer_filters.php';

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$conn = getDbConnection();
if (!$conn) {
    sendErrorResponse('Database connection failed', 500);
}

$user = requireAuth($conn);
requireDashboardAccess($user);

$salonId = resolveSalonScope($conn, $user, $_GET['salon_id'] ?? null);
requirePermission($conn, $user, 'view_insights', $salonId);

if (!segmentsTableExists($conn)) {
    sendErrorResponse('Segments are unavailable until migration 019 has been applied.', 503);
}

/**
 * Built-in presets the spec asks for (best customers, inactive 6/10 weeks).
 * They are seeded per salon on first read so every salon starts with something
 * useful, and are marked is_preset so the UI can stop them being deleted.
 */
const PRESETS = [
    ['key' => 'inactive_6',  'name' => 'Inaktiv seit 6 Wochen',  'filter' => ['not_visited_within_weeks' => 6]],
    ['key' => 'inactive_10', 'name' => 'Inaktiv seit 10 Wochen', 'filter' => ['not_visited_within_weeks' => 10]],
    ['key' => 'members',     'name' => 'Nur Mitglieder',         'filter' => ['members_only' => true]],
    ['key' => 'best',        'name' => 'Beste Kunden',           'filter' => ['min_visits' => 5]],
];

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        seedPresets($conn, $salonId, $user);
        handleList($conn, $salonId);
        break;
    case 'POST':
        handleCreate($conn, $salonId, $user);
        break;
    case 'PUT':
        handleUpdate($conn, $salonId, $user);
        break;
    case 'DELETE':
        handleDelete($conn, $salonId, $user);
        break;
    default:
        sendErrorResponse('Method not allowed.', 405);
}

function segmentsTableExists(mysqli $conn): bool
{
    $res = $conn->query("SHOW TABLES LIKE 'coiffure_segments'");
    return $res && $res->num_rows > 0;
}

/** Create the built-in presets once per salon. INSERT IGNORE keeps it idempotent. */
function seedPresets(mysqli $conn, int $salonId, array $user): void
{
    $stmt = $conn->prepare(
        'INSERT IGNORE INTO coiffure_segments (salon_id, name, description, filter_json, is_preset, created_by)
         VALUES (?, ?, ?, ?, 1, ?)'
    );
    if (!$stmt) {
        return;
    }

    foreach (PRESETS as $preset) {
        $name = $preset['name'];
        $description = 'Voreinstellung';
        $json = json_encode(normaliseCustomerFilter($preset['filter']));
        $createdBy = (int)$user['user_id'];
        $stmt->bind_param('isssi', $salonId, $name, $description, $json, $createdBy);
        $stmt->execute();
    }
    $stmt->close();
}

function handleList(mysqli $conn, int $salonId): void
{
    $stmt = $conn->prepare(
        'SELECT segment_id, name, description, filter_json, is_preset, created_at, updated_at
         FROM coiffure_segments WHERE salon_id = ?
         ORDER BY is_preset DESC, name'
    );
    if (!$stmt) {
        sendErrorResponse('Failed to load segments.', 500);
    }

    $stmt->bind_param('i', $salonId);
    $stmt->execute();
    $result = $stmt->get_result();

    $segments = [];
    while ($row = $result->fetch_assoc()) {
        $filter = normaliseCustomerFilter($row['filter_json']);
        $segments[] = [
            'segment_id'  => (int)$row['segment_id'],
            'name'        => $row['name'],
            'description' => $row['description'],
            'filter'      => $filter,
            'is_preset'   => (int)$row['is_preset'] === 1,
            // Recomputed each time: a segment is a rule, not a frozen list.
            'count'       => countCustomers($conn, [$salonId], $filter),
            'created_at'  => $row['created_at'],
            'updated_at'  => $row['updated_at'],
        ];
    }
    $stmt->close();

    sendJsonResponse(['success' => true, 'segments' => $segments], 200);
}

function handleCreate(mysqli $conn, int $salonId, array $user): void
{
    $input = json_decode(file_get_contents('php://input'), true) ?: [];

    $name = trim((string)($input['name'] ?? ''));
    if ($name === '') {
        sendErrorResponse('A segment name is required.', 400, ['field' => 'name']);
    }
    if (mb_strlen($name) > 120) {
        sendErrorResponse('The segment name is too long (max 120).', 400, ['field' => 'name']);
    }

    $filter = normaliseCustomerFilter($input['filter'] ?? []);
    if (empty($filter)) {
        sendErrorResponse('A segment needs at least one filter.', 400, ['field' => 'filter']);
    }

    $description = trim((string)($input['description'] ?? ''));
    $json = json_encode($filter);
    $createdBy = (int)$user['user_id'];

    $stmt = $conn->prepare(
        'INSERT INTO coiffure_segments (salon_id, name, description, filter_json, is_preset, created_by)
         VALUES (?, ?, ?, ?, 0, ?)'
    );
    if (!$stmt) {
        sendErrorResponse('Failed to create the segment.', 500);
    }

    $stmt->bind_param('isssi', $salonId, $name, $description, $json, $createdBy);

    if (!$stmt->execute()) {
        $duplicate = $conn->errno === 1062;
        $stmt->close();
        sendErrorResponse(
            $duplicate ? 'A segment with this name already exists.' : 'Failed to create the segment.',
            $duplicate ? 409 : 500,
            ['field' => 'name']
        );
    }

    $segmentId = $stmt->insert_id;
    $stmt->close();

    logAdminAudit($conn, $user, 'segment', $segmentId, 'create', "Segment created: $name", $salonId);

    sendJsonResponse([
        'success' => true,
        'segment' => [
            'segment_id' => $segmentId,
            'name'       => $name,
            'description' => $description,
            'filter'     => $filter,
            'is_preset'  => false,
            'count'      => countCustomers($conn, [$salonId], $filter),
        ],
    ], 201);
}

function handleUpdate(mysqli $conn, int $salonId, array $user): void
{
    $segmentId = (int)($_GET['segment_id'] ?? 0);
    if ($segmentId <= 0) {
        sendErrorResponse('segment_id is required.', 400);
    }

    $existing = loadSegment($conn, $segmentId, $salonId);
    $input = json_decode(file_get_contents('php://input'), true) ?: [];

    $name = array_key_exists('name', $input) ? trim((string)$input['name']) : $existing['name'];
    if ($name === '') {
        sendErrorResponse('A segment name is required.', 400, ['field' => 'name']);
    }

    $description = array_key_exists('description', $input)
        ? trim((string)$input['description'])
        : (string)$existing['description'];

    $filter = array_key_exists('filter', $input)
        ? normaliseCustomerFilter($input['filter'])
        : normaliseCustomerFilter($existing['filter_json']);

    if (empty($filter)) {
        sendErrorResponse('A segment needs at least one filter.', 400, ['field' => 'filter']);
    }

    $json = json_encode($filter);
    $stmt = $conn->prepare(
        'UPDATE coiffure_segments SET name = ?, description = ?, filter_json = ?
         WHERE segment_id = ? AND salon_id = ?'
    );
    if (!$stmt) {
        sendErrorResponse('Failed to update the segment.', 500);
    }

    $stmt->bind_param('sssii', $name, $description, $json, $segmentId, $salonId);
    if (!$stmt->execute()) {
        $duplicate = $conn->errno === 1062;
        $stmt->close();
        sendErrorResponse(
            $duplicate ? 'A segment with this name already exists.' : 'Failed to update the segment.',
            $duplicate ? 409 : 500,
            ['field' => 'name']
        );
    }
    $stmt->close();

    logAdminAudit($conn, $user, 'segment', $segmentId, 'update', "Segment updated: $name", $salonId);

    sendJsonResponse([
        'success' => true,
        'segment' => [
            'segment_id'  => $segmentId,
            'name'        => $name,
            'description' => $description,
            'filter'      => $filter,
            'is_preset'   => (int)$existing['is_preset'] === 1,
            'count'       => countCustomers($conn, [$salonId], $filter),
        ],
    ], 200);
}

function handleDelete(mysqli $conn, int $salonId, array $user): void
{
    $segmentId = (int)($_GET['segment_id'] ?? 0);
    if ($segmentId <= 0) {
        sendErrorResponse('segment_id is required.', 400);
    }

    $existing = loadSegment($conn, $segmentId, $salonId);

    if ((int)$existing['is_preset'] === 1) {
        sendErrorResponse('Built-in segments cannot be deleted.', 400);
    }

    $stmt = $conn->prepare('DELETE FROM coiffure_segments WHERE segment_id = ? AND salon_id = ?');
    if (!$stmt) {
        sendErrorResponse('Failed to delete the segment.', 500);
    }
    $stmt->bind_param('ii', $segmentId, $salonId);
    $stmt->execute();
    $stmt->close();

    logAdminAudit(
        $conn,
        $user,
        'segment',
        $segmentId,
        'delete',
        "Segment deleted: {$existing['name']}",
        $salonId
    );

    sendJsonResponse(['success' => true], 200);
}

/** Load a segment, refusing one that belongs to another salon. */
function loadSegment(mysqli $conn, int $segmentId, int $salonId): array
{
    $stmt = $conn->prepare(
        'SELECT segment_id, name, description, filter_json, is_preset
         FROM coiffure_segments WHERE segment_id = ? AND salon_id = ?'
    );
    if (!$stmt) {
        sendErrorResponse('Failed to load the segment.', 500);
    }

    $stmt->bind_param('ii', $segmentId, $salonId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        sendErrorResponse('Segment not found.', 404);
    }

    return $row;
}
