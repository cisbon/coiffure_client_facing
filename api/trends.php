<?php
/**
 * Trends slider API
 * -------------------------------------------------------------------
 *   GET  trends.php[?gender=female]
 *        → active slides from coiffure_trends, ordered by `sort` then id.
 *          Public (used by the tablet home screen). `gender` is optional; when
 *          given it returns slides for that gender plus the universal ones
 *          (gender IS NULL / empty). Without it, all active slides are returned.
 *
 * Bare image_url filenames are returned as-is; the frontend resolves them under
 * the salon images base URL.
 */

require_once __DIR__ . '/config.php';

setCorsHeaders();

$conn = getDbConnection();
if (!$conn) {
    sendErrorResponse('Database connection failed', 500);
}

// Table may not exist yet (migration 016 not run) — return an empty list, not an error.
$exists = $conn->query("SHOW TABLES LIKE 'coiffure_trends'");
if (!$exists || $exists->num_rows === 0) {
    $conn->close();
    sendJsonResponse(['success' => true, 'trends' => []], 200);
}

$gender = isset($_GET['gender']) ? trim((string)$_GET['gender']) : '';

if ($gender !== '') {
    $stmt = $conn->prepare(
        "SELECT id, title, `text`, sort, image_url, link, gender
         FROM coiffure_trends
         WHERE active = 1 AND (gender IS NULL OR gender = '' OR gender = ?)
         ORDER BY sort ASC, id ASC"
    );
    $stmt->bind_param("s", $gender);
} else {
    $stmt = $conn->prepare(
        "SELECT id, title, `text`, sort, image_url, link, gender
         FROM coiffure_trends
         WHERE active = 1
         ORDER BY sort ASC, id ASC"
    );
}

if (!$stmt) {
    sendErrorResponse('Database query preparation failed', 500);
}
$stmt->execute();
$res = $stmt->get_result();

$trends = [];
while ($row = $res->fetch_assoc()) {
    $trends[] = [
        'id'        => (int)$row['id'],
        'title'     => $row['title'] ?? '',
        'text'      => $row['text'] ?? '',
        'sort'      => (int)$row['sort'],
        'image_url' => $row['image_url'] ?? '',
        'link'      => ($row['link'] !== null && trim((string)$row['link']) !== '') ? $row['link'] : null,
        'gender'    => $row['gender'] ?? null,
    ];
}
$stmt->close();
$conn->close();

sendJsonResponse(['success' => true, 'trends' => $trends], 200);
