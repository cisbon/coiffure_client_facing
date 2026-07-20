<?php
/**
 * Content API Endpoint (Trends & Tipps)
 * -------------------------------------------------------------------
 * Serves the digital waiting-room magazine content for the tablet.
 *
 *   GET content.php?type=trend   → trend articles + videos
 *   GET content.php?type=tip     → styling / care tips
 *   GET content.php               → all items
 *
 * MVP: reads from data/trends.json. The structure is DB-ready, so this can
 * later be swapped to read from a `coiffure_content` table without changing
 * the response shape. The salon owner will manage entries via the web access.
 */

require_once __DIR__ . '/config.php';

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendErrorResponse('Method not allowed. Use GET.', 405);
}

$dataFile = __DIR__ . '/../data/trends.json';
if (!is_file($dataFile)) {
    sendJsonResponse(['success' => true, 'items' => []], 200);
}

$items = json_decode(file_get_contents($dataFile), true);
if (!is_array($items)) {
    error_log('content.php: invalid JSON in data/trends.json');
    sendJsonResponse(['success' => true, 'items' => []], 200);
}

// Optional type filter (accept "trend"/"trends", "tip"/"tips").
$type = strtolower(trim($_GET['type'] ?? ''));
$type = rtrim($type, 's'); // normalise plural → singular

if ($type === 'trend' || $type === 'tip') {
    $items = array_values(array_filter($items, function ($it) use ($type) {
        $t = strtolower($it['type'] ?? '');
        if ($type === 'trend') {
            // Videos are shown under Trends too.
            return $t === 'trend' || $t === 'video';
        }
        return $t === 'tip';
    }));
}

sendJsonResponse(['success' => true, 'type' => $type ?: 'all', 'items' => $items], 200);
