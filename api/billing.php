<?php
/**
 * Billing and subscriptions (Administrator only)
 * -------------------------------------------------------------------
 *   GET  billing.php?action=overview           → salons with plan + status
 *   GET  billing.php?action=plans              → subscription plans
 *   POST billing.php?action=plan               → create/update a plan
 *   POST billing.php?action=assign             → {salon_id, plan_id, …}
 *   GET  billing.php?action=invoices           → invoice list (filterable)
 *   GET  billing.php?action=invoice&id=N       → one invoice with its items
 *   POST billing.php?action=create_invoice     → {salon_id, year, month, …}
 *   POST billing.php?action=mark_paid          → {invoice_id}
 *
 * Guarded by platform_billing, which only a full administrator holds -- an
 * Admin Delegate can run the platform but not its money.
 *
 * There is no payment provider here on purpose. This records what a salon owes
 * and what has been paid so an operator can invoice outside the system; adding
 * a real gateway later means one new action, not a rewrite.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/notify.php';

/**
 * Declared before the dispatch below: a top-level `const` is evaluated in
 * source order, so one placed after the switch would not exist yet when a
 * handler runs.
 */
const PAYMENT_STATUSES = ['active', 'trial', 'overdue', 'cancelled'];
const INVOICE_STATUSES = ['open', 'paid', 'cancelled'];

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
requirePermission($conn, $user, 'platform_billing');

if (!billingReady($conn)) {
    sendErrorResponse('Billing needs migration 022 to be applied first.', 503);
}

$action = $_GET['action'] ?? 'overview';
$input = json_decode(file_get_contents('php://input'), true) ?: [];

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        if ($action === 'plans')    handlePlans($conn);
        if ($action === 'invoices') handleInvoices($conn);
        if ($action === 'invoice')  handleInvoice($conn);
        handleOverview($conn);
        break;
    case 'POST':
        if ($action === 'plan')           handleSavePlan($conn, $user, $input);
        if ($action === 'assign')         handleAssign($conn, $user, $input);
        if ($action === 'create_invoice') handleCreateInvoice($conn, $user, $input);
        if ($action === 'mark_paid')      handleMarkPaid($conn, $user, $input);
        sendErrorResponse('Unknown action.', 400);
        break;
    default:
        sendErrorResponse('Method not allowed.', 405);
}

function billingReady(mysqli $conn): bool
{
    $res = $conn->query("SHOW TABLES LIKE 'coiffure_salon_subscriptions'");
    return $res && $res->num_rows > 0;
}

/* ============================================================
   Overview
   ============================================================ */

function handleOverview(mysqli $conn): void
{
    $result = $conn->query(
        "SELECT s.salon_id, s.salon_name, s.is_active,
                sub.subscription_id, sub.plan_id, sub.payment_status,
                sub.trial_ends_at, sub.started_at, sub.cancelled_at, sub.notes,
                p.name AS plan_name, p.monthly_price, p.currency,
                (SELECT COUNT(*) FROM coiffure_customers c
                  WHERE c.salon_id = s.salon_id AND c.is_deleted = 0) AS customer_count,
                (SELECT COUNT(*) FROM coiffure_invoices i
                  WHERE i.salon_id = s.salon_id AND i.status = 'open') AS open_invoices
         FROM coiffure_salons s
         LEFT JOIN coiffure_salon_subscriptions sub ON sub.salon_id = s.salon_id
         LEFT JOIN coiffure_subscription_plans p ON p.plan_id = sub.plan_id
         ORDER BY s.salon_name"
    );
    if (!$result) {
        sendErrorResponse('Failed to load the billing overview.', 500);
    }

    $rows = [];
    $monthlyTotal = 0.0;
    $counts = ['active' => 0, 'trial' => 0, 'overdue' => 0, 'cancelled' => 0, 'unassigned' => 0];

    while ($row = $result->fetch_assoc()) {
        $status = $row['subscription_id'] ? (string)$row['payment_status'] : 'unassigned';

        // A trial is a state of the subscription, not a separate column: it is
        // a live subscription whose trial_ends_at is still in the future.
        if ($status === 'active' && $row['trial_ends_at'] && strtotime($row['trial_ends_at']) >= strtotime('today')) {
            $status = 'trial';
        }

        if (isset($counts[$status])) {
            $counts[$status]++;
        }
        if (in_array($status, ['active', 'overdue'], true)) {
            $monthlyTotal += (float)$row['monthly_price'];
        }

        $rows[] = [
            'salon_id'   => (int)$row['salon_id'],
            'salon_name' => $row['salon_name'],
            'is_active'  => (bool)$row['is_active'],
            'plan_id'    => $row['plan_id'] !== null ? (int)$row['plan_id'] : null,
            'plan_name'  => $row['plan_name'],
            'monthly_price' => $row['monthly_price'] !== null ? (float)$row['monthly_price'] : null,
            'currency'   => $row['currency'] ?: 'EUR',
            'status'     => $status,
            'trial_ends_at' => $row['trial_ends_at'],
            'started_at' => $row['started_at'],
            'cancelled_at' => $row['cancelled_at'],
            'notes'      => $row['notes'],
            'customer_count' => (int)$row['customer_count'],
            'open_invoices'  => (int)$row['open_invoices'],
        ];
    }

    sendJsonResponse([
        'success' => true,
        'salons' => $rows,
        'summary' => [
            'monthly_recurring' => round($monthlyTotal, 2),
            'counts' => $counts,
            'total_salons' => count($rows),
        ],
    ], 200);
}

/* ============================================================
   Plans
   ============================================================ */

function handlePlans(mysqli $conn): void
{
    $result = $conn->query(
        "SELECT p.*,
                (SELECT COUNT(*) FROM coiffure_salon_subscriptions sub
                  WHERE sub.plan_id = p.plan_id) AS salon_count
         FROM coiffure_subscription_plans p
         ORDER BY p.sort_order, p.monthly_price"
    );
    if (!$result) {
        sendErrorResponse('Failed to load the plans.', 500);
    }

    $plans = [];
    while ($row = $result->fetch_assoc()) {
        $plans[] = presentPlan($row);
    }

    sendJsonResponse(['success' => true, 'plans' => $plans], 200);
}

function presentPlan(array $row): array
{
    return [
        'plan_id'  => (int)$row['plan_id'],
        'name'     => $row['name'],
        'description' => $row['description'],
        'monthly_price' => (float)$row['monthly_price'],
        'currency' => $row['currency'],
        'max_customers' => (int)$row['max_customers'],
        'max_campaigns_per_month' => (int)$row['max_campaigns_per_month'],
        'features' => json_decode((string)$row['features'], true) ?: [],
        'is_active' => (bool)$row['is_active'],
        'sort_order' => (int)$row['sort_order'],
        'salon_count' => isset($row['salon_count']) ? (int)$row['salon_count'] : 0,
    ];
}

function handleSavePlan(mysqli $conn, array $user, array $input): void
{
    $planId = (int)($input['plan_id'] ?? 0);
    $errors = [];

    $name = trim((string)($input['name'] ?? ''));
    if ($name === '') $errors['name'] = 'Ein Name ist erforderlich.';

    $price = (float)($input['monthly_price'] ?? 0);
    if ($price < 0) $errors['monthly_price'] = 'Der Preis darf nicht negativ sein.';

    if ($errors) {
        sendErrorResponse('Bitte prüfen Sie Ihre Eingaben.', 422, ['fields' => $errors]);
    }

    $args = [
        $name,
        trim((string)($input['description'] ?? '')) ?: null,
        $price,
        strtoupper(substr((string)($input['currency'] ?? 'EUR'), 0, 3)),
        max(0, (int)($input['max_customers'] ?? 0)),
        max(0, (int)($input['max_campaigns_per_month'] ?? 0)),
        json_encode((object)($input['features'] ?? [])),
        !empty($input['is_active']) ? 1 : 0,
        (int)($input['sort_order'] ?? 0),
    ];

    if ($planId > 0) {
        $stmt = $conn->prepare(
            'UPDATE coiffure_subscription_plans
             SET name = ?, description = ?, monthly_price = ?, currency = ?,
                 max_customers = ?, max_campaigns_per_month = ?, features = ?,
                 is_active = ?, sort_order = ?
             WHERE plan_id = ?'
        );
        $args[] = $planId;
    } else {
        $stmt = $conn->prepare(
            'INSERT INTO coiffure_subscription_plans
                (name, description, monthly_price, currency, max_customers,
                 max_campaigns_per_month, features, is_active, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
    }

    if (!$stmt) {
        sendErrorResponse('Der Tarif konnte nicht gespeichert werden.', 500);
    }
    bindTyped($stmt, $args);
    if (!$stmt->execute()) {
        $stmt->close();
        sendErrorResponse('Der Tarif konnte nicht gespeichert werden.', 500);
    }
    $savedId = $planId > 0 ? $planId : $stmt->insert_id;
    $stmt->close();

    logAdminAudit($conn, $user, 'billing', $savedId, $planId > 0 ? 'update' : 'create',
        "Plan saved: $name");

    sendJsonResponse(['success' => true, 'plan_id' => $savedId], $planId > 0 ? 200 : 201);
}

/* ============================================================
   Assigning a plan to a salon
   ============================================================ */

function handleAssign(mysqli $conn, array $user, array $input): void
{
    $salonId = (int)($input['salon_id'] ?? 0);
    if ($salonId <= 0) {
        sendErrorResponse('salon_id is required.', 400);
    }

    $planId = isset($input['plan_id']) && $input['plan_id'] !== '' ? (int)$input['plan_id'] : null;
    $status = (string)($input['payment_status'] ?? 'active');
    if (!in_array($status, PAYMENT_STATUSES, true)) {
        sendErrorResponse('Bitte prüfen Sie Ihre Eingaben.', 422, [
            'fields' => ['payment_status' => 'Unbekannter Status.'],
        ]);
    }

    // 'trial' is not stored as a status; it is an active subscription with a
    // trial end date, which keeps "is this salon paying?" a single question.
    $trialEndsAt = trim((string)($input['trial_ends_at'] ?? '')) ?: null;
    if ($status === 'trial') {
        $status = 'active';
        if (!$trialEndsAt) {
            $trialEndsAt = date('Y-m-d', strtotime('+30 days'));
        }
    }

    $startedAt = trim((string)($input['started_at'] ?? '')) ?: date('Y-m-d');
    $cancelledAt = $status === 'cancelled' ? date('Y-m-d') : null;
    $notes = trim((string)($input['notes'] ?? '')) ?: null;
    $updatedBy = (int)$user['user_id'];

    $stmt = $conn->prepare(
        'INSERT INTO coiffure_salon_subscriptions
            (salon_id, plan_id, payment_status, trial_ends_at, started_at, cancelled_at, notes, updated_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            plan_id = VALUES(plan_id), payment_status = VALUES(payment_status),
            trial_ends_at = VALUES(trial_ends_at), started_at = VALUES(started_at),
            cancelled_at = VALUES(cancelled_at), notes = VALUES(notes),
            updated_by = VALUES(updated_by)'
    );
    if (!$stmt) {
        sendErrorResponse('Das Abonnement konnte nicht gespeichert werden.', 500);
    }
    bindTyped($stmt, [$salonId, $planId, $status, $trialEndsAt, $startedAt, $cancelledAt, $notes, $updatedBy]);
    if (!$stmt->execute()) {
        $stmt->close();
        sendErrorResponse('Das Abonnement konnte nicht gespeichert werden.', 500);
    }
    $stmt->close();

    $planName = planName($conn, $planId);
    logAdminAudit($conn, $user, 'billing', $salonId, 'subscription_changed',
        "Subscription set to " . ($planName ?? 'no plan') . " ($status)", $salonId);

    // The salon should learn that its plan changed without being told by hand.
    notifySalonAdmins(
        $conn, $salonId, 'subscription', 'admin.notify.subscription_changed',
        ['plan' => $planName ?? '—'], '#/uebersicht'
    );

    sendJsonResponse(['success' => true], 200);
}

function planName(mysqli $conn, ?int $planId): ?string
{
    if (!$planId) {
        return null;
    }
    $stmt = $conn->prepare('SELECT name FROM coiffure_subscription_plans WHERE plan_id = ?');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $planId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row['name'] ?? null;
}

/* ============================================================
   Invoices
   ============================================================ */

function handleInvoices(mysqli $conn): void
{
    $where = ['1 = 1'];
    $values = [];

    $salonId = (int)($_GET['salon_id'] ?? 0);
    if ($salonId > 0) {
        $where[] = 'i.salon_id = ?';
        $values[] = $salonId;
    }

    $status = trim((string)($_GET['status'] ?? ''));
    if ($status !== '' && in_array($status, INVOICE_STATUSES, true)) {
        $where[] = 'i.status = ?';
        $values[] = $status;
    }

    $year = (int)($_GET['year'] ?? 0);
    if ($year > 0) {
        $where[] = 'i.period_year = ?';
        $values[] = $year;
    }

    $whereSql = implode(' AND ', $where);

    $stmt = $conn->prepare(
        "SELECT i.*, s.salon_name
         FROM coiffure_invoices i
         LEFT JOIN coiffure_salons s ON s.salon_id = i.salon_id
         WHERE $whereSql
         ORDER BY i.period_year DESC, i.period_month DESC, i.invoice_id DESC
         LIMIT 500"
    );
    if (!$stmt) {
        sendErrorResponse('Failed to load the invoices.', 500);
    }
    bindTyped($stmt, $values);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    sendJsonResponse([
        'success' => true,
        'invoices' => array_map('presentInvoice', $rows),
    ], 200);
}

function presentInvoice(array $row): array
{
    return [
        'invoice_id'     => (int)$row['invoice_id'],
        'invoice_number' => $row['invoice_number'],
        'salon_id'       => (int)$row['salon_id'],
        'salon_name'     => $row['salon_name'] ?? null,
        'period_year'    => (int)$row['period_year'],
        'period_month'   => (int)$row['period_month'],
        'subtotal'       => (float)$row['subtotal'],
        'tax_rate'       => (float)$row['tax_rate'],
        'tax_amount'     => (float)$row['tax_amount'],
        'total'          => (float)$row['total'],
        'currency'       => $row['currency'],
        'status'         => $row['status'],
        'issued_at'      => $row['issued_at'],
        'paid_at'        => $row['paid_at'],
    ];
}

function handleInvoice(mysqli $conn): void
{
    $invoiceId = (int)($_GET['id'] ?? 0);
    if ($invoiceId <= 0) {
        sendErrorResponse('id is required.', 400);
    }

    $stmt = $conn->prepare(
        'SELECT i.*, s.salon_name, s.address AS salon_address, s.email AS salon_email
         FROM coiffure_invoices i
         LEFT JOIN coiffure_salons s ON s.salon_id = i.salon_id
         WHERE i.invoice_id = ?'
    );
    if (!$stmt) {
        sendErrorResponse('Failed to load the invoice.', 500);
    }
    $stmt->bind_param('i', $invoiceId);
    $stmt->execute();
    $invoice = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$invoice) {
        sendErrorResponse('Invoice not found.', 404);
    }

    $itemStmt = $conn->prepare(
        'SELECT description, quantity, unit_price, amount
         FROM coiffure_invoice_items WHERE invoice_id = ? ORDER BY sort_order, item_id'
    );
    $items = [];
    if ($itemStmt) {
        $itemStmt->bind_param('i', $invoiceId);
        $itemStmt->execute();
        $result = $itemStmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $items[] = [
                'description' => $row['description'],
                'quantity'    => (float)$row['quantity'],
                'unit_price'  => (float)$row['unit_price'],
                'amount'      => (float)$row['amount'],
            ];
        }
        $itemStmt->close();
    }

    sendJsonResponse([
        'success' => true,
        'invoice' => presentInvoice($invoice) + [
            'salon_address' => $invoice['salon_address'],
            'salon_email'   => $invoice['salon_email'],
        ],
        'items' => $items,
    ], 200);
}

function handleCreateInvoice(mysqli $conn, array $user, array $input): void
{
    $salonId = (int)($input['salon_id'] ?? 0);
    if ($salonId <= 0) {
        sendErrorResponse('salon_id is required.', 400);
    }

    $year = (int)($input['period_year'] ?? date('Y'));
    $month = (int)($input['period_month'] ?? date('n'));
    if ($month < 1 || $month > 12) {
        sendErrorResponse('Bitte prüfen Sie Ihre Eingaben.', 422, [
            'fields' => ['period_month' => 'Monat muss zwischen 1 und 12 liegen.'],
        ]);
    }

    // One invoice per salon per period, so a double click cannot bill twice.
    $dupe = $conn->prepare(
        "SELECT invoice_id FROM coiffure_invoices
         WHERE salon_id = ? AND period_year = ? AND period_month = ? AND status <> 'cancelled'"
    );
    if ($dupe) {
        $dupe->bind_param('iii', $salonId, $year, $month);
        $dupe->execute();
        $existing = $dupe->get_result()->fetch_assoc();
        $dupe->close();
        if ($existing) {
            sendErrorResponse('Für diesen Zeitraum existiert bereits eine Rechnung.', 409, [
                'invoice_id' => (int)$existing['invoice_id'],
            ]);
        }
    }

    // Items come from the request when given, otherwise from the salon's plan.
    $items = [];
    foreach ((array)($input['items'] ?? []) as $item) {
        $description = trim((string)($item['description'] ?? ''));
        if ($description === '') {
            continue;
        }
        $quantity = (float)($item['quantity'] ?? 1);
        $unitPrice = (float)($item['unit_price'] ?? 0);
        $items[] = [
            'description' => $description,
            'quantity'    => $quantity,
            'unit_price'  => $unitPrice,
            'amount'      => round($quantity * $unitPrice, 2),
        ];
    }

    $currency = 'EUR';
    if (empty($items)) {
        $planStmt = $conn->prepare(
            'SELECT p.name, p.monthly_price, p.currency
             FROM coiffure_salon_subscriptions sub
             JOIN coiffure_subscription_plans p ON p.plan_id = sub.plan_id
             WHERE sub.salon_id = ?'
        );
        if ($planStmt) {
            $planStmt->bind_param('i', $salonId);
            $planStmt->execute();
            $plan = $planStmt->get_result()->fetch_assoc();
            $planStmt->close();

            if ($plan) {
                $currency = $plan['currency'];
                $items[] = [
                    'description' => sprintf('%s – %02d/%d', $plan['name'], $month, $year),
                    'quantity'    => 1.0,
                    'unit_price'  => (float)$plan['monthly_price'],
                    'amount'      => (float)$plan['monthly_price'],
                ];
            }
        }
    }

    if (empty($items)) {
        sendErrorResponse('Für diesen Salon ist kein Tarif hinterlegt und es wurden keine Positionen angegeben.', 422);
    }

    $subtotal = round(array_sum(array_column($items, 'amount')), 2);
    $taxRate = (float)($input['tax_rate'] ?? 0);
    $taxAmount = round($subtotal * $taxRate / 100, 2);
    $total = round($subtotal + $taxAmount, 2);
    $currency = strtoupper(substr((string)($input['currency'] ?? $currency), 0, 3));
    $issuedAt = trim((string)($input['issued_at'] ?? '')) ?: date('Y-m-d');
    $createdBy = (int)$user['user_id'];

    $conn->begin_transaction();
    try {
        $number = nextInvoiceNumber($conn, $year);

        $stmt = $conn->prepare(
            "INSERT INTO coiffure_invoices
                (invoice_number, salon_id, plan_id, period_year, period_month,
                 subtotal, tax_rate, tax_amount, total, currency, status, issued_at, created_by)
             VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, 'open', ?, ?)"
        );
        if (!$stmt) {
            throw new RuntimeException('invoice prepare failed: ' . $conn->error);
        }
        bindTyped($stmt, [
            $number, $salonId, $year, $month,
            $subtotal, $taxRate, $taxAmount, $total, $currency, $issuedAt, $createdBy,
        ]);
        if (!$stmt->execute()) {
            throw new RuntimeException('invoice insert failed: ' . $stmt->error);
        }
        $invoiceId = $stmt->insert_id;
        $stmt->close();

        $itemStmt = $conn->prepare(
            'INSERT INTO coiffure_invoice_items
                (invoice_id, description, quantity, unit_price, amount, sort_order)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        if (!$itemStmt) {
            throw new RuntimeException('item prepare failed: ' . $conn->error);
        }
        foreach ($items as $index => $item) {
            bindTyped($itemStmt, [
                $invoiceId, $item['description'], $item['quantity'],
                $item['unit_price'], $item['amount'], $index,
            ]);
            $itemStmt->execute();
        }
        $itemStmt->close();

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('billing create_invoice: ' . $e->getMessage());
        sendErrorResponse('Die Rechnung konnte nicht erstellt werden.', 500);
    }

    logAdminAudit($conn, $user, 'billing', $invoiceId, 'invoice_created',
        "Invoice $number created for salon $salonId ($total $currency)", $salonId);

    sendJsonResponse([
        'success' => true,
        'invoice_id' => $invoiceId,
        'invoice_number' => $number,
        'total' => $total,
    ], 201);
}

/**
 * Sequential invoice number, per year: 2026-0001.
 *
 * Derived from the highest number already issued for that year rather than from
 * a count, so cancelling an invoice never causes a number to be reused. Called
 * inside the invoice transaction.
 */
function nextInvoiceNumber(mysqli $conn, int $year): string
{
    $stmt = $conn->prepare(
        'SELECT invoice_number FROM coiffure_invoices
         WHERE period_year = ? AND invoice_number LIKE ?
         ORDER BY invoice_number DESC LIMIT 1
         FOR UPDATE'
    );
    if (!$stmt) {
        return $year . '-0001';
    }
    $like = $year . '-%';
    $stmt->bind_param('is', $year, $like);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $sequence = 1;
    if ($row && preg_match('/-(\d+)$/', (string)$row['invoice_number'], $match)) {
        $sequence = (int)$match[1] + 1;
    }

    return sprintf('%d-%04d', $year, $sequence);
}

function handleMarkPaid(mysqli $conn, array $user, array $input): void
{
    $invoiceId = (int)($input['invoice_id'] ?? 0);
    if ($invoiceId <= 0) {
        sendErrorResponse('invoice_id is required.', 400);
    }

    $status = (string)($input['status'] ?? 'paid');
    if (!in_array($status, INVOICE_STATUSES, true)) {
        sendErrorResponse('Unbekannter Status.', 422);
    }

    $paidAt = $status === 'paid' ? date('Y-m-d') : null;

    $stmt = $conn->prepare(
        'UPDATE coiffure_invoices SET status = ?, paid_at = ? WHERE invoice_id = ?'
    );
    if (!$stmt) {
        sendErrorResponse('Die Rechnung konnte nicht aktualisiert werden.', 500);
    }
    bindTyped($stmt, [$status, $paidAt, $invoiceId]);
    $stmt->execute();
    $changed = $stmt->affected_rows;
    $stmt->close();

    if ($changed === 0) {
        sendErrorResponse('Invoice not found.', 404);
    }

    logAdminAudit($conn, $user, 'billing', $invoiceId, 'update', "Invoice marked $status");

    sendJsonResponse(['success' => true, 'status' => $status, 'paid_at' => $paidAt], 200);
}
