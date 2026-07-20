<?php
/**
 * Products API Endpoint (In-salon shop catalogue)
 * -------------------------------------------------------------------
 *   GET products.php[?category=Pflege]  → list of products
 *
 * MVP: reads from data/products.json. This is an in-salon catalogue only —
 * there is no cart or online checkout. The structure is DB-ready so it can
 * later be backed by a `coiffure_products` table without changing the shape.
 */

require_once __DIR__ . '/config.php';

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendErrorResponse('Method not allowed. Use GET.', 405);
}

$dataFile = __DIR__ . '/../data/products.json';
if (!is_file($dataFile)) {
    sendJsonResponse(['success' => true, 'products' => []], 200);
}

$products = json_decode(file_get_contents($dataFile), true);
if (!is_array($products)) {
    error_log('products.php: invalid JSON in data/products.json');
    sendJsonResponse(['success' => true, 'products' => []], 200);
}

$category = trim($_GET['category'] ?? '');
if ($category !== '') {
    $products = array_values(array_filter($products, function ($p) use ($category) {
        return strcasecmp($p['category'] ?? '', $category) === 0;
    }));
}

sendJsonResponse(['success' => true, 'products' => $products], 200);
