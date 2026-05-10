<?php
require_once __DIR__ . '/config/config.php';

define('APP_EAT_TIMEZONE', 'Indian/Antananarivo');
date_default_timezone_set(APP_EAT_TIMEZONE);

$currentUser = requireRank($pdo, 5);
$currentRank = (int)($currentUser['rank'] ?? 1);
$userId = (int)($currentUser['id'] ?? 0);
$pageTitle = 'Bestell Übersicht';

function appTimezone(): DateTimeZone
{
    return new DateTimeZone(APP_EAT_TIMEZONE);
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function formatAriary($value): string
{
    return number_format((float)$value, 0, ',', '.') . ' Ariary';
}

function unitLabelShort(string $unit): string
{
    return match ($unit) {
        'Kilogramm' => 'kg',
        'Gramm' => 'g',
        'Liter' => 'l',
        'Millilliter', 'Milliliter' => 'ml',
        'Cups' => 'Cups',
        'Staude' => 'Staude',
        default => 'Stück',
    };
}

function formatQuantity($quantity, $unit): string
{
    $quantity = (float)$quantity;

    $formatted = fmod($quantity, 1.0) === 0.0
        ? number_format($quantity, 0, ',', '.')
        : number_format($quantity, 2, ',', '.');

    return $formatted . ' ' . unitLabelShort((string)$unit);
}

function normalizeDeadlineInput($value): string
{
    $value = trim((string)$value);

    if ($value === '') {
        return '';
    }

    $timezone = appTimezone();

    foreach (['Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i'] as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $value, $timezone);

        if ($date instanceof DateTimeImmutable) {
            return $date->format('Y-m-d H:i:s');
        }
    }

    return '';
}

function formatDateTimeLocal($value, bool $withTimezoneLabel = true): string
{
    $value = trim((string)$value);

    if ($value === '') {
        return '-';
    }

    $timezone = appTimezone();

    $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, $timezone)
        ?: DateTimeImmutable::createFromFormat('Y-m-d H:i', $value, $timezone);

    if (!$date) {
        try {
            $date = new DateTimeImmutable($value, $timezone);
        } catch (Throwable $e) {
            return '-';
        }
    }

    return $date->format('d.m.Y H:i') . ($withTimezoneLabel ? ' EAT' : '');
}

function dateTimeLocalValue($value): string
{
    $value = trim((string)$value);

    if ($value === '') {
        return '';
    }

    $timezone = appTimezone();

    $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, $timezone)
        ?: DateTimeImmutable::createFromFormat('Y-m-d H:i', $value, $timezone);

    if (!$date) {
        try {
            $date = new DateTimeImmutable($value, $timezone);
        } catch (Throwable $e) {
            return '';
        }
    }

    return $date->format('Y-m-d\TH:i');
}

function jsonResponse($success, $message, $data = []): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }

    echo json_encode([
        'success' => (bool)$success,
        'message' => (string)$message,
        'data' => $data
    ]);

    exit;
}

function adminOrdersLogActivity(PDO $pdo, string $action, $userId = null, $userName = null): void
{
    global $currentUser;

    try {
        $logUserId = $userId !== null ? (int)$userId : (isset($currentUser['id']) ? (int)$currentUser['id'] : null);
        $logUserName = $userName !== null ? $userName : ($currentUser['family_name'] ?? 'System');

        $stmt = $pdo->prepare("
            INSERT INTO activity_logs (user_id, user_name, action)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$logUserId, $logUserName, $action]);
    } catch (Throwable $e) {
        // Logging darf Admin-Aktionen nicht blockieren.
    }
}

function calculateInvoiceTotals($subtotal, $discountPercent, $serviceFeeWaived, $shoppingFeeWaived): array
{
    $subtotal = (int)$subtotal;
    $discountPercent = (int)$discountPercent;
    $serviceFeeWaived = (int)$serviceFeeWaived === 1;
    $shoppingFeeWaived = (int)$shoppingFeeWaived === 1;

    $discountAmount = $subtotal * ($discountPercent / 100);
    $subtotalAfterDiscount = $subtotal - $discountAmount;

    $serviceFee = ($subtotal > 0 && !$serviceFeeWaived) ? 15000 : 0;
    $shoppingFee = ($subtotal > 0 && !$shoppingFeeWaived) ? ($subtotalAfterDiscount * 0.10) : 0;

    return [
        'subtotal' => (int)$subtotal,
        'service_fee' => (int)$serviceFee,
        'shopping_fee' => (int)$shoppingFee,
        'total' => (int)($subtotalAfterDiscount + $serviceFee + $shoppingFee)
    ];
}

function recalculateOrderGroup(PDO $pdo, int $orderGroupId): void
{
    $stmt = $pdo->prepare("
        SELECT 
            og.id,
            u.discount_percent,
            u.service_fee_waived,
            u.shopping_fee_waived
        FROM order_groups og
        INNER JOIN users u ON u.id = og.user_id
        WHERE og.id = ?
        LIMIT 1
    ");
    $stmt->execute([$orderGroupId]);
    $orderGroup = $stmt->fetch();

    if (!$orderGroup) {
        jsonResponse(false, 'Bestellung wurde nicht gefunden.');
    }

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(row_total), 0)
        FROM order_items
        WHERE order_group_id = ?
    ");
    $stmt->execute([$orderGroupId]);
    $subtotal = (int)$stmt->fetchColumn();

    $totals = calculateInvoiceTotals(
        $subtotal,
        (int)$orderGroup['discount_percent'],
        (int)$orderGroup['service_fee_waived'],
        (int)$orderGroup['shopping_fee_waived']
    );

    $stmt = $pdo->prepare("
        UPDATE order_groups
        SET subtotal = ?, service_fee = ?, shopping_fee = ?, total = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $totals['subtotal'],
        $totals['service_fee'],
        $totals['shopping_fee'],
        $totals['total'],
        $orderGroupId
    ]);
}

function deleteUserOrder(PDO $pdo, int $orderGroupId): void
{
    $stmt = $pdo->prepare("
        SELECT 
            og.id,
            u.family_name
        FROM order_groups og
        INNER JOIN users u ON u.id = og.user_id
        WHERE og.id = ?
        LIMIT 1
    ");
    $stmt->execute([$orderGroupId]);
    $orderGroup = $stmt->fetch();

    if (!$orderGroup) {
        jsonResponse(false, 'Bestellung wurde nicht gefunden.');
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            DELETE FROM invoice_items
            WHERE invoice_id IN (
                SELECT id FROM invoices WHERE order_group_id = ?
            )
        ");
        $stmt->execute([$orderGroupId]);

        $stmt = $pdo->prepare("DELETE FROM invoices WHERE order_group_id = ?");
        $stmt->execute([$orderGroupId]);

        $stmt = $pdo->prepare("DELETE FROM order_items WHERE order_group_id = ?");
        $stmt->execute([$orderGroupId]);

        $stmt = $pdo->prepare("DELETE FROM order_groups WHERE id = ?");
        $stmt->execute([$orderGroupId]);

        $pdo->commit();

        adminOrdersLogActivity($pdo, 'Bestellung gelöscht von: ' . $orderGroup['family_name']);
        jsonResponse(true, 'Bestellung wurde gelöscht.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        jsonResponse(false, 'Fehler beim Löschen der Bestellung.');
    }
}

/* =========================
   AJAX
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    if (ob_get_level() === 0) {
        ob_start();
    }

    $action = $_POST['ajax_action'];

    if ($action === 'add_order_date') {
        $title = trim($_POST['title'] ?? '');
        $deadlineAt = normalizeDeadlineInput($_POST['deadline_at'] ?? '');

        if ($title === '' || $deadlineAt === '') {
            jsonResponse(false, 'Bitte Titel und Bestellschluss ausfüllen.');
        }

        $stmt = $pdo->prepare("
            INSERT INTO order_dates (title, deadline_at, archived)
            VALUES (?, ?, 0)
        ");
        $stmt->execute([$title, $deadlineAt]);

        $newId = (int)$pdo->lastInsertId();

        adminOrdersLogActivity($pdo, 'Bestelltermin erstellt: ' . $title . ' (' . formatDateTimeLocal($deadlineAt) . ')');

        jsonResponse(true, 'Bestelltermin wurde angelegt.', [
            'id' => $newId
        ]);
    }

    if ($action === 'update_order_date') {
        $id = (int)($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $deadlineAt = normalizeDeadlineInput($_POST['deadline_at'] ?? '');

        if ($id <= 0 || $title === '' || $deadlineAt === '') {
            jsonResponse(false, 'Bitte alle Felder ausfüllen.');
        }

        $stmt = $pdo->prepare("
            UPDATE order_dates
            SET title = ?, deadline_at = ?
            WHERE id = ?
        ");
        $stmt->execute([$title, $deadlineAt, $id]);

        adminOrdersLogActivity($pdo, 'Bestelltermin geändert: ' . $title . ' (' . formatDateTimeLocal($deadlineAt) . ')');

        jsonResponse(true, 'Bestelltermin wurde aktualisiert.');
    }

    if ($action === 'archive_order_date' || $action === 'restore_order_date') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            jsonResponse(false, 'Ungültiger Termin.');
        }

        $archived = $action === 'archive_order_date' ? 1 : 0;

        $stmt = $pdo->prepare("
            UPDATE order_dates
            SET archived = ?
            WHERE id = ?
        ");
        $stmt->execute([$archived, $id]);

        jsonResponse(
            true,
            $archived ? 'Bestelltermin wurde archiviert.' : 'Bestelltermin wurde wiederhergestellt.'
        );
    }

    if ($action === 'delete_order_date') {
        $id = (int)($_POST['id'] ?? 0);

        if ($id <= 0) {
            jsonResponse(false, 'Ungültiger Termin.');
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                UPDATE order_groups
                SET order_date_id = NULL
                WHERE order_date_id = ?
            ");
            $stmt->execute([$id]);

            $stmt = $pdo->prepare("
                DELETE FROM order_dates
                WHERE id = ?
            ");
            $stmt->execute([$id]);

            $pdo->commit();

            jsonResponse(true, 'Bestelltermin wurde gelöscht.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            jsonResponse(false, 'Fehler beim Löschen des Termins.');
        }
    }

    if ($action === 'delete_user_order') {
        $orderGroupId = (int)($_POST['order_group_id'] ?? 0);

        if ($orderGroupId <= 0) {
            jsonResponse(false, 'Ungültige Bestellung.');
        }

        deleteUserOrder($pdo, $orderGroupId);
    }

    if ($action === 'update_order_item') {
        $orderItemId = (int)($_POST['order_item_id'] ?? 0);
        $quantity = max(0, (float)($_POST['quantity'] ?? 0));

        if ($orderItemId <= 0) {
            jsonResponse(false, 'Ungültiges Produkt.');
        }

        $stmt = $pdo->prepare("
            SELECT order_group_id, price
            FROM order_items
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$orderItemId]);
        $item = $stmt->fetch();

        if (!$item) {
            jsonResponse(false, 'Produkt wurde nicht gefunden.');
        }

        $rowTotal = (int)$item['price'] * $quantity;

        $stmt = $pdo->prepare("
            UPDATE order_items
            SET quantity = ?, row_total = ?
            WHERE id = ?
        ");
        $stmt->execute([$quantity, (int)$rowTotal, $orderItemId]);

        recalculateOrderGroup($pdo, (int)$item['order_group_id']);

        jsonResponse(true, 'Produkt wurde aktualisiert.');
    }

    if ($action === 'delete_order_item') {
        $orderItemId = (int)($_POST['order_item_id'] ?? 0);

        if ($orderItemId <= 0) {
            jsonResponse(false, 'Ungültiges Produkt.');
        }

        $stmt = $pdo->prepare("
            SELECT order_group_id
            FROM order_items
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$orderItemId]);
        $item = $stmt->fetch();

        if (!$item) {
            jsonResponse(false, 'Produkt wurde nicht gefunden.');
        }

        $stmt = $pdo->prepare("
            DELETE FROM order_items
            WHERE id = ?
        ");
        $stmt->execute([$orderItemId]);

        recalculateOrderGroup($pdo, (int)$item['order_group_id']);

        jsonResponse(true, 'Produkt wurde gelöscht.');
    }

    if ($action === 'create_invoices_for_order_date') {
        $orderDateId = (int)($_POST['id'] ?? 0);

        if ($orderDateId <= 0) {
            jsonResponse(false, 'Ungültiger Termin.');
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                SELECT 
                    og.*,
                    u.family_name,
                    u.discount_percent,
                    u.service_fee_waived,
                    u.shopping_fee_waived
                FROM order_groups og
                INNER JOIN users u ON u.id = og.user_id
                WHERE og.order_date_id = ?
                AND EXISTS (
                    SELECT 1 FROM order_items oi WHERE oi.order_group_id = og.id
                )
                AND NOT EXISTS (
                    SELECT 1 FROM invoices i WHERE i.order_group_id = og.id
                )
            ");
            $stmt->execute([$orderDateId]);
            $groups = $stmt->fetchAll();

            if (!$groups) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                jsonResponse(false, 'Keine neuen Rechnungen vorhanden. Entweder gibt es keine Bestellungen oder Rechnungen wurden bereits erstellt.');
            }

            $created = 0;
            $invoiceRecipients = [];

            foreach ($groups as $group) {
                $sumStmt = $pdo->prepare("
                    SELECT COALESCE(SUM(row_total), 0)
                    FROM order_items
                    WHERE order_group_id = ?
                ");
                $sumStmt->execute([(int)$group['id']]);
                $subtotal = (int)$sumStmt->fetchColumn();

                $totals = calculateInvoiceTotals(
                    $subtotal,
                    (int)($group['discount_percent'] ?? 0),
                    (int)($group['service_fee_waived'] ?? 0),
                    (int)($group['shopping_fee_waived'] ?? 0)
                );

                $updateStmt = $pdo->prepare("
                    UPDATE order_groups
                    SET subtotal = ?, service_fee = ?, shopping_fee = ?, total = ?
                    WHERE id = ?
                ");
                $updateStmt->execute([
                    $totals['subtotal'],
                    $totals['service_fee'],
                    $totals['shopping_fee'],
                    $totals['total'],
                    (int)$group['id']
                ]);

                $publicToken = bin2hex(random_bytes(32));
                $invoiceNumber = 'R-' . date('Y') . '-' . (int)$group['id'];

                $invoiceStmt = $pdo->prepare("
                    INSERT INTO invoices
                        (invoice_number, public_token, order_group_id, order_date_id, user_id, subtotal, service_fee, shopping_fee, total, status, created_by)
                    VALUES
                        (?, ?, ?, ?, ?, ?, ?, ?, ?, 'offen', ?)
                ");
                $invoiceStmt->execute([
                    $invoiceNumber,
                    $publicToken,
                    (int)$group['id'],
                    $orderDateId,
                    (int)$group['user_id'],
                    $totals['subtotal'],
                    $totals['service_fee'],
                    $totals['shopping_fee'],
                    $totals['total'],
                    $userId
                ]);

                $invoiceId = (int)$pdo->lastInsertId();

                $notifyStmt = $pdo->prepare("
                    INSERT INTO notifications (user_id, type, title, message, link, created_at)
                    VALUES (?, 'new_invoice', ?, ?, ?, NOW())
                ");
                $notifyStmt->execute([
                    (int)$group['user_id'],
                    'Neue Rechnung',
                    'Deine neue Rechnung ' . $invoiceNumber . ' wurde erstellt.',
                    'invoice.php?token=' . $publicToken
                ]);

                $itemStmt = $pdo->prepare("
                    SELECT *
                    FROM order_items
                    WHERE order_group_id = ?
                    ORDER BY product_name ASC
                ");
                $itemStmt->execute([(int)$group['id']]);

                $insertItemStmt = $pdo->prepare("
                    INSERT INTO invoice_items
                        (invoice_id, product_id, product_name, unit, original_price, original_quantity, price, quantity, row_total)
                    VALUES
                        (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                foreach ($itemStmt->fetchAll() as $item) {
                    $insertItemStmt->execute([
                        $invoiceId,
                        (int)$item['product_id'],
                        $item['product_name'],
                        $item['unit'],
                        (int)$item['price'],
                        (float)$item['quantity'],
                        (int)$item['price'],
                        (float)$item['quantity'],
                        (int)$item['row_total']
                    ]);
                }

                $recipientName = trim((string)($group['family_name'] ?? '')) ?: 'User-ID ' . (int)$group['user_id'];
                $invoiceRecipients[] = $recipientName . ' (' . $invoiceNumber . ')';
                $created++;
            }

            $pdo->commit();

            adminOrdersLogActivity(
                $pdo,
                'Rechnungen erstellt: ' . $created . ' Rechnung(en) an: ' . implode(', ', array_unique($invoiceRecipients))
            );

            jsonResponse(true, $created . ' Rechnung(en) wurden erstellt.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            jsonResponse(false, 'Fehler beim Erstellen der Rechnungen.');
        }
    }

    jsonResponse(false, 'Unbekannte Aktion.');
}

/* =========================
   DATEN LADEN
========================= */
$viewMode = $_GET['view'] ?? 'products';

if (!in_array($viewMode, ['products', 'users'], true)) {
    $viewMode = 'products';
}

$orderDates = $pdo->query("
    SELECT 
        od.*,
        (
            SELECT COUNT(*)
            FROM order_groups og
            WHERE og.order_date_id = od.id
        ) AS order_count,
        (
            SELECT COUNT(DISTINCT og.user_id)
            FROM order_groups og
            WHERE og.order_date_id = od.id
        ) AS user_count,
        (
            SELECT COUNT(DISTINCT oi.product_id)
            FROM order_groups og
            INNER JOIN order_items oi ON oi.order_group_id = og.id
            WHERE og.order_date_id = od.id
        ) AS product_count,
        (
            SELECT COALESCE(SUM(og.total), 0)
            FROM order_groups og
            WHERE og.order_date_id = od.id
        ) AS total_sum
    FROM order_dates od
    WHERE od.archived = 0
    ORDER BY od.deadline_at DESC
")->fetchAll();

$archivedOrderDates = $pdo->query("
    SELECT 
        od.*,
        (
            SELECT COUNT(*)
            FROM order_groups og
            WHERE og.order_date_id = od.id
        ) AS order_count,
        (
            SELECT COUNT(DISTINCT og.user_id)
            FROM order_groups og
            WHERE og.order_date_id = od.id
        ) AS user_count,
        (
            SELECT COUNT(DISTINCT oi.product_id)
            FROM order_groups og
            INNER JOIN order_items oi ON oi.order_group_id = og.id
            WHERE og.order_date_id = od.id
        ) AS product_count,
        (
            SELECT COALESCE(SUM(og.total), 0)
            FROM order_groups og
            WHERE og.order_date_id = od.id
        ) AS total_sum
    FROM order_dates od
    WHERE od.archived = 1
    ORDER BY od.deadline_at DESC
")->fetchAll();

$allOrderDates = array_merge($orderDates, $archivedOrderDates);

$selectedOrderDateId = isset($_GET['order_date_id']) ? (int)$_GET['order_date_id'] : 0;

if ($selectedOrderDateId <= 0 && $orderDates) {
    $selectedOrderDateId = (int)$orderDates[0]['id'];
}

if ($selectedOrderDateId <= 0 && $archivedOrderDates) {
    $selectedOrderDateId = (int)$archivedOrderDates[0]['id'];
}

$selectedOrderDate = null;

foreach ($allOrderDates as $date) {
    if ((int)$date['id'] === $selectedOrderDateId) {
        $selectedOrderDate = $date;
        break;
    }
}

$productSummary = [];
$productDetails = [];
$userOrders = [];
$userItems = [];

$overall = [
    'product_count' => 0,
    'user_count' => 0,
    'item_rows' => 0,
    'subtotal' => 0,
    'service_fee' => 0,
    'shopping_fee' => 0,
    'total' => 0
];

if ($selectedOrderDateId > 0 && $selectedOrderDate) {
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(pc.name, 'Ohne Kategorie') AS category_name,
            oi.product_id,
            oi.product_name,
            oi.unit,
            oi.price,
            SUM(oi.quantity) AS total_quantity,
            SUM(oi.row_total) AS total_amount,
            COUNT(DISTINCT og.user_id) AS user_count
        FROM order_items oi
        INNER JOIN order_groups og ON og.id = oi.order_group_id
        LEFT JOIN products p ON p.id = oi.product_id
        LEFT JOIN product_categories pc ON pc.id = p.category_id
        WHERE og.order_date_id = ?
        GROUP BY 
            COALESCE(pc.name, 'Ohne Kategorie'),
            oi.product_id,
            oi.product_name,
            oi.unit,
            oi.price
        ORDER BY 
            COALESCE(pc.name, 'Ohne Kategorie') ASC,
            oi.product_name ASC
    ");
    $stmt->execute([$selectedOrderDateId]);
    $productSummary = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(pc.name, 'Ohne Kategorie') AS category_name,
            oi.product_id,
            oi.product_name,
            oi.unit,
            oi.price,
            oi.quantity,
            oi.row_total,
            og.id AS order_group_id,
            u.id AS user_id,
            u.family_name
        FROM order_items oi
        INNER JOIN order_groups og ON og.id = oi.order_group_id
        INNER JOIN users u ON u.id = og.user_id
        LEFT JOIN products p ON p.id = oi.product_id
        LEFT JOIN product_categories pc ON pc.id = p.category_id
        WHERE og.order_date_id = ?
        ORDER BY 
            COALESCE(pc.name, 'Ohne Kategorie') ASC,
            oi.product_name ASC,
            u.family_name ASC
    ");
    $stmt->execute([$selectedOrderDateId]);

    foreach ($stmt->fetchAll() as $detail) {
        $key = ($detail['category_name'] ?? 'Ohne Kategorie') . '|' . (int)$detail['product_id'] . '|' . $detail['product_name'];
        $productDetails[$key][] = $detail;
    }

    $stmt = $pdo->prepare("
        SELECT 
            og.id,
            og.user_id,
            og.subtotal,
            og.service_fee,
            og.shopping_fee,
            og.total,
            og.created_at,
            u.family_name,
            COUNT(oi.id) AS item_count,
            (
                SELECT COUNT(*)
                FROM invoices i
                WHERE i.order_group_id = og.id
            ) AS invoice_count
        FROM order_groups og
        INNER JOIN users u ON u.id = og.user_id
        LEFT JOIN order_items oi ON oi.order_group_id = og.id
        WHERE og.order_date_id = ?
        GROUP BY 
            og.id,
            og.user_id,
            og.subtotal,
            og.service_fee,
            og.shopping_fee,
            og.total,
            og.created_at,
            u.family_name
        HAVING item_count > 0
        ORDER BY u.family_name ASC
    ");
    $stmt->execute([$selectedOrderDateId]);
    $userOrders = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT 
            og.user_id,
            oi.id,
            COALESCE(pc.name, 'Ohne Kategorie') AS category_name,
            oi.product_name,
            oi.unit,
            oi.price,
            oi.quantity,
            oi.row_total
        FROM order_items oi
        INNER JOIN order_groups og ON og.id = oi.order_group_id
        LEFT JOIN products p ON p.id = oi.product_id
        LEFT JOIN product_categories pc ON pc.id = p.category_id
        WHERE og.order_date_id = ?
        ORDER BY 
            og.user_id ASC,
            COALESCE(pc.name, 'Ohne Kategorie') ASC,
            oi.product_name ASC
    ");
    $stmt->execute([$selectedOrderDateId]);

    foreach ($stmt->fetchAll() as $item) {
        $userItems[(int)$item['user_id']][] = $item;
    }

    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT og.user_id) AS user_count,
            COUNT(DISTINCT oi.product_id) AS product_count,
            COUNT(oi.id) AS item_rows,
            COALESCE(SUM(oi.row_total), 0) AS subtotal
        FROM order_groups og
        INNER JOIN order_items oi ON oi.order_group_id = og.id
        WHERE og.order_date_id = ?
    ");
    $stmt->execute([$selectedOrderDateId]);
    $overall = array_merge($overall, $stmt->fetch() ?: []);

    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(service_fee), 0) AS service_fee,
            COALESCE(SUM(shopping_fee), 0) AS shopping_fee,
            COALESCE(SUM(total), 0) AS total
        FROM order_groups
        WHERE order_date_id = ?
        AND EXISTS (
            SELECT 1 FROM order_items oi WHERE oi.order_group_id = order_groups.id
        )
    ");
    $stmt->execute([$selectedOrderDateId]);
    $overall = array_merge($overall, $stmt->fetch() ?: []);
}

$productsByCategory = [];

foreach ($productSummary as $product) {
    $categoryName = trim((string)($product['category_name'] ?? ''));

    if ($categoryName === '') {
        $categoryName = 'Ohne Kategorie';
    }

    $productsByCategory[$categoryName][] = $product;
}

ob_start();
?>

<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index:9999;">
    <div id="appToast" class="toast align-items-center text-bg-success border-0" role="alert">
        <div class="d-flex">
            <div class="toast-body" id="toastMessage">Aktion erfolgreich.</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<div class="orders-workspace">

    <div class="page-head">
        <div>
            <h1>Bestell Übersicht</h1>
            <p>Bestelltermine verwalten, Produkte prüfen und Rechnungen erstellen.</p>
        </div>

        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addOrderDateModal">
            <i class="bi bi-plus-lg me-1"></i>
            Neuer Bestelltermin
        </button>
    </div>

    <div class="control-panel">
        <form method="get" class="order-date-form">
            <input type="hidden" name="view" value="<?= h($viewMode) ?>">

            <div class="form-main">
                <label class="form-label">Bestelltermin</label>
                <select name="order_date_id" class="form-select" onchange="this.form.submit()">
                    <?php if (!$allOrderDates): ?>
                        <option value="">Kein Bestelltermin vorhanden</option>
                    <?php endif; ?>

                    <?php if ($orderDates): ?>
                        <optgroup label="Aktive Bestelltermine">
                            <?php foreach ($orderDates as $date): ?>
                                <option value="<?= (int)$date['id'] ?>" <?= (int)$date['id'] === $selectedOrderDateId ? 'selected' : '' ?>>
                                    <?= h($date['title']) ?> — <?= h(formatDateTimeLocal($date['deadline_at'], false)) ?>
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endif; ?>

                    <?php if ($archivedOrderDates): ?>
                        <optgroup label="Archiv">
                            <?php foreach ($archivedOrderDates as $date): ?>
                                <option value="<?= (int)$date['id'] ?>" <?= (int)$date['id'] === $selectedOrderDateId ? 'selected' : '' ?>>
                                    <?= h($date['title']) ?> — <?= h(formatDateTimeLocal($date['deadline_at'], false)) ?> — archiviert
                                </option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endif; ?>
                </select>
            </div>
        </form>

        <?php if ($selectedOrderDate): ?>
            <div class="order-date-actions admin-date-card" data-id="<?= (int)$selectedOrderDate['id'] ?>">
                <span class="date-title d-none"><?= h($selectedOrderDate['title']) ?></span>
                <span class="date-deadline d-none" data-value="<?= h(dateTimeLocalValue($selectedOrderDate['deadline_at'])) ?>"></span>

                <button type="button" class="btn btn-outline-secondary btn-sm btn-edit-date">
                    <i class="bi bi-pencil me-1"></i>
                    Bearbeiten
                </button>

                <?php if ((int)$selectedOrderDate['archived'] === 1): ?>
                    <button type="button" class="btn btn-outline-success btn-sm btn-restore-date">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>
                        Wiederherstellen
                    </button>
                <?php else: ?>
                    <button type="button" class="btn btn-outline-secondary btn-sm btn-archive-date">
                        <i class="bi bi-archive me-1"></i>
                        Archivieren
                    </button>
                <?php endif; ?>

                <button type="button" class="btn btn-outline-danger btn-sm btn-delete-date">
                    <i class="bi bi-trash me-1"></i>
                    Löschen
                </button>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!$selectedOrderDate): ?>

        <div class="empty-box">
            <i class="bi bi-calendar-plus"></i>
            <h3>Kein Bestelltermin vorhanden</h3>
            <p>Lege zuerst einen Bestelltermin an, damit Bestellungen gesammelt werden können.</p>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addOrderDateModal">
                Bestelltermin anlegen
            </button>
        </div>

    <?php else: ?>

        <div class="status-line">
            <div>
                <span class="status-label">Bestellschluss</span>
                <strong><?= h(formatDateTimeLocal($selectedOrderDate['deadline_at'])) ?></strong>
            </div>

            <div>
                <span class="status-label">Familien</span>
                <strong><?= number_format((int)$overall['user_count'], 0, ',', '.') ?></strong>
            </div>

            <div>
                <span class="status-label">Produkte</span>
                <strong><?= number_format((int)$overall['product_count'], 0, ',', '.') ?></strong>
            </div>

            <div>
                <span class="status-label">Gesamtwert</span>
                <strong><?= formatAriary($overall['total']) ?></strong>
            </div>

            <?php if ((int)$selectedOrderDate['archived'] === 1): ?>
                <div>
                    <span class="status-badge archived">Archiviert</span>
                </div>
            <?php endif; ?>
        </div>

        <div class="primary-actions">
            <a href="dealer_export.php?order_date_id=<?= (int)$selectedOrderDateId ?>" class="btn btn-outline-secondary">
                <i class="bi bi-shop-window me-1"></i>
                Händler Export
            </a>

            <button type="button"
                    class="btn btn-primary btn-create-invoices"
                    data-id="<?= (int)$selectedOrderDate['id'] ?>">
                <i class="bi bi-receipt-cutoff me-1"></i>
                Rechnungen erstellen
            </button>
        </div>

        <div class="view-tabs">
            <a href="admin_orders.php?order_date_id=<?= (int)$selectedOrderDateId ?>&view=products"
               class="<?= $viewMode === 'products' ? 'active' : '' ?>">
                Produkte zusammengefasst
            </a>

            <a href="admin_orders.php?order_date_id=<?= (int)$selectedOrderDateId ?>&view=users"
               class="<?= $viewMode === 'users' ? 'active' : '' ?>">
                Familien anzeigen
            </a>
        </div>

        <?php if ($viewMode === 'products'): ?>
            <section class="work-card">
                <div class="work-card-head">
                    <div>
                        <h2>Produkte zusammengefasst</h2>
                        <p>Kompakte Übersicht aller Produkte für den ausgewählten Bestelltermin.</p>
                    </div>
                </div>

                <?php if (!$productsByCategory): ?>
                    <div class="empty-box small">
                        <i class="bi bi-basket"></i>
                        <h3>Keine Produkte vorhanden</h3>
                        <p>Für diesen Bestelltermin gibt es noch keine Produkte.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive compact-table-wrap">
                        <table class="table compact-order-table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Produkt</th>
                                    <th>Einheit</th>
                                    <th class="text-end">Gesamtmenge</th>
                                    <th class="text-end">Preis</th>
                                    <th class="text-end">Warenwert</th>
                                    <th>Besteller</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach ($productsByCategory as $categoryName => $products): ?>
                                    <tr class="category-row">
                                        <td colspan="6">
                                            <?= h($categoryName) ?>
                                        </td>
                                    </tr>

                                    <?php foreach ($products as $product): ?>
                                        <?php
                                        $detailKey = ($product['category_name'] ?? 'Ohne Kategorie') . '|' . (int)$product['product_id'] . '|' . $product['product_name'];
                                        $details = $productDetails[$detailKey] ?? [];
                                        ?>
                                        <tr>
                                            <td>
                                                <strong><?= h($product['product_name']) ?></strong>
                                            </td>

                                            <td>
                                                <?= h(unitLabelShort((string)$product['unit'])) ?>
                                            </td>

                                            <td class="text-end fw-semibold">
                                                <?= h(formatQuantity($product['total_quantity'], $product['unit'])) ?>
                                            </td>

                                            <td class="text-end">
                                                <?= formatAriary($product['price']) ?>
                                            </td>

                                            <td class="text-end fw-semibold">
                                                <?= formatAriary($product['total_amount']) ?>
                                            </td>

                                            <td>
                                                <details class="buyer-details">
                                                    <summary><?= count($details) ?> Besteller</summary>

                                                    <div>
                                                        <?php foreach ($details as $detail): ?>
                                                            <span>
                                                                <?= h($detail['family_name']) ?>:
                                                                <?= h(formatQuantity($detail['quantity'], $detail['unit'])) ?>
                                                            </span>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </details>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if ($viewMode === 'users'): ?>
            <section class="work-card">
                <div class="work-card-head">
                    <div>
                        <h2>Familienbestellungen</h2>
                        <p>Einzelne Bestellungen prüfen, Mengen anpassen oder löschen.</p>
                    </div>
                </div>

                <?php if (!$userOrders): ?>
                    <div class="empty-box small">
                        <i class="bi bi-people"></i>
                        <h3>Keine Familienbestellungen vorhanden</h3>
                        <p>Für diesen Bestelltermin wurden noch keine Bestellungen abgegeben.</p>
                    </div>
                <?php else: ?>
                    <div class="family-list" id="familyOrderAccordion">
                        <?php foreach ($userOrders as $index => $order): ?>
                            <?php
                            $itemsForUser = $userItems[(int)$order['user_id']] ?? [];
                            $collapseId = 'familyOrderCollapse' . (int)$order['id'];
                            $isOpen = $index === 0;

                            $itemsByCategory = [];
                            foreach ($itemsForUser as $item) {
                                $itemsByCategory[$item['category_name'] ?: 'Ohne Kategorie'][] = $item;
                            }
                            ?>

                            <div class="family-card">
                                <button class="family-toggle <?= $isOpen ? '' : 'collapsed' ?>"
                                        type="button"
                                        data-bs-toggle="collapse"
                                        data-bs-target="#<?= h($collapseId) ?>"
                                        aria-expanded="<?= $isOpen ? 'true' : 'false' ?>">
                                    <span>
                                        <strong><?= h($order['family_name']) ?></strong>
                                        <small>
                                            <?= (int)$order['item_count'] ?> Produkte ·
                                            <?= h(formatDateTimeLocal($order['created_at'], false)) ?>
                                            <?= (int)$order['invoice_count'] > 0 ? ' · Rechnung vorhanden' : '' ?>
                                        </small>
                                    </span>

                                    <span class="family-total">
                                        <?= formatAriary($order['total']) ?>
                                    </span>
                                </button>

                                <div id="<?= h($collapseId) ?>"
                                     class="collapse <?= $isOpen ? 'show' : '' ?>"
                                     data-bs-parent="#familyOrderAccordion">
                                    <div class="family-body">
                                        <?php foreach ($itemsByCategory as $categoryName => $items): ?>
                                            <div class="category-title small-title">
                                                <?= h($categoryName) ?>
                                            </div>

                                            <div class="table-responsive">
                                                <table class="table family-table align-middle mb-3">
                                                    <thead>
                                                        <tr>
                                                            <th>Produkt</th>
                                                            <th>Einheit</th>
                                                            <th class="text-end">Preis</th>
                                                            <th style="width: 130px;">Menge</th>
                                                            <th class="text-end">Summe</th>
                                                            <th class="text-end">Aktion</th>
                                                        </tr>
                                                    </thead>

                                                    <tbody>
                                                        <?php foreach ($items as $item): ?>
                                                            <tr data-order-item-id="<?= (int)$item['id'] ?>">
                                                                <td>
                                                                    <strong><?= h($item['product_name']) ?></strong>
                                                                </td>

                                                                <td>
                                                                    <?= h($item['unit']) ?>
                                                                </td>

                                                                <td class="text-end">
                                                                    <?= formatAriary($item['price']) ?>
                                                                </td>

                                                                <td>
                                                                    <input
                                                                        type="number"
                                                                        class="form-control form-control-sm order-item-quantity"
                                                                        value="<?= h($item['quantity']) ?>"
                                                                        min="0"
                                                                        step="0.01">
                                                                </td>

                                                                <td class="text-end fw-semibold">
                                                                    <?= formatAriary($item['row_total']) ?>
                                                                </td>

                                                                <td class="text-end">
                                                                    <button type="button" class="btn btn-success btn-sm btn-save-order-item">
                                                                        <i class="bi bi-check-lg"></i>
                                                                    </button>

                                                                    <button type="button" class="btn btn-outline-danger btn-sm btn-delete-order-item">
                                                                        <i class="bi bi-trash"></i>
                                                                    </button>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php endforeach; ?>

                                        <div class="family-footer">
                                            <span>Zwischensumme: <strong><?= formatAriary($order['subtotal']) ?></strong></span>
                                            <span>Service: <strong><?= formatAriary($order['service_fee']) ?></strong></span>
                                            <span>Einkauf: <strong><?= formatAriary($order['shopping_fee']) ?></strong></span>
                                            <span>Gesamt: <strong><?= formatAriary($order['total']) ?></strong></span>

                                            <button type="button"
                                                    class="btn btn-outline-danger btn-sm btn-delete-user-order"
                                                    data-order-group-id="<?= (int)$order['id'] ?>">
                                                Bestellung löschen
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

    <?php endif; ?>
</div>

<div class="modal fade" id="addOrderDateModal" tabindex="-1" aria-labelledby="addOrderDateModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content clean-modal">
            <div class="modal-header">
                <h5 class="modal-title" id="addOrderDateModalLabel">
                    Neuer Bestelltermin
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
            </div>

            <form id="addOrderDateForm">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Titel</label>
                        <input type="text" name="title" class="form-control" placeholder="z.B. Freitagseinkauf" required>
                    </div>

                    <div>
                        <label class="form-label">Bestellschluss</label>
                        <input type="datetime-local" name="deadline_at" class="form-control" required>
                        <div class="form-text">Zeitzone: Madagaskar / EAT</div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button class="btn btn-primary" type="submit">
                        Anlegen
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
    .orders-workspace {
        --ow-border: #e5e7eb;
        --ow-muted: #6b7280;
        --ow-text: #111827;
        --ow-bg: #ffffff;
        --ow-soft: #f9fafb;
        --ow-blue: #2563eb;
        max-width: 1380px;
    }

    .page-head {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 1rem;
        margin-bottom: 1.15rem;
    }

    .page-head h1 {
        margin: 0;
        font-size: 1.55rem;
        font-weight: 750;
        color: var(--ow-text);
    }

    .page-head p {
        margin: .25rem 0 0;
        color: var(--ow-muted);
        font-size: .95rem;
    }

    .control-panel {
        display: flex;
        align-items: end;
        justify-content: space-between;
        gap: 1rem;
        padding: .95rem;
        background: var(--ow-bg);
        border: 1px solid var(--ow-border);
        border-radius: 12px;
        margin-bottom: .85rem;
    }

    .order-date-form {
        flex: 1;
        min-width: 260px;
    }

    .form-main {
        max-width: 660px;
    }

    .order-date-actions {
        display: flex;
        gap: .45rem;
        flex-wrap: wrap;
        justify-content: flex-end;
    }

    .edit-date-row {
        display: flex;
        gap: .5rem;
        flex-wrap: wrap;
        width: 100%;
    }

    .edit-date-row input {
        min-width: 210px;
        flex: 1;
    }

    .status-line {
        display: grid;
        grid-template-columns: 1.4fr repeat(3, 1fr) auto;
        gap: .75rem;
        padding: .8rem .95rem;
        background: var(--ow-soft);
        border: 1px solid var(--ow-border);
        border-radius: 12px;
        margin-bottom: .85rem;
    }

    .status-line > div {
        min-width: 0;
    }

    .status-label {
        display: block;
        color: var(--ow-muted);
        font-size: .76rem;
        margin-bottom: .15rem;
    }

    .status-line strong {
        color: var(--ow-text);
        font-weight: 700;
        font-size: .94rem;
    }

    .status-badge {
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        padding: .32rem .68rem;
        font-size: .78rem;
        font-weight: 700;
    }

    .status-badge.archived {
        background: #f3f4f6;
        color: #374151;
        border: 1px solid #d1d5db;
    }

    .primary-actions {
        display: flex;
        justify-content: flex-end;
        gap: .5rem;
        margin-bottom: .85rem;
        flex-wrap: wrap;
    }

    .view-tabs {
        display: flex;
        gap: .5rem;
        margin-bottom: .85rem;
        border-bottom: 1px solid var(--ow-border);
    }

    .view-tabs a {
        text-decoration: none;
        color: var(--ow-muted);
        padding: .7rem .9rem;
        border-bottom: 2px solid transparent;
        font-weight: 650;
        font-size: .92rem;
    }

    .view-tabs a.active {
        color: var(--ow-blue);
        border-color: var(--ow-blue);
    }

    .work-card {
        background: var(--ow-bg);
        border: 1px solid var(--ow-border);
        border-radius: 12px;
        overflow: hidden;
    }

    .work-card-head {
        padding: .9rem 1rem;
        border-bottom: 1px solid var(--ow-border);
        background: #fff;
    }

    .work-card-head h2 {
        margin: 0;
        font-size: 1.08rem;
        font-weight: 750;
        color: var(--ow-text);
    }

    .work-card-head p {
        margin: .2rem 0 0;
        color: var(--ow-muted);
        font-size: .88rem;
    }

    .compact-table-wrap {
        background: #fff;
    }

    .compact-order-table {
        font-size: .92rem;
    }

    .compact-order-table thead th {
        background: #f8fafc;
        color: #4b5563;
        border-bottom: 1px solid var(--ow-border);
        font-size: .76rem;
        text-transform: uppercase;
        letter-spacing: .04em;
        font-weight: 750;
        padding: .72rem .85rem;
        white-space: nowrap;
    }

    .compact-order-table tbody td {
        padding: .7rem .85rem;
        border-bottom: 1px solid #eef2f7;
        vertical-align: middle;
    }

    .compact-order-table tbody tr:hover td {
        background: #fafafa;
    }

    .category-row td {
        background: #f3f4f6 !important;
        color: #374151;
        font-size: .76rem;
        text-transform: uppercase;
        letter-spacing: .06em;
        font-weight: 800;
        padding: .58rem .85rem !important;
        border-top: 1px solid var(--ow-border);
        border-bottom: 1px solid var(--ow-border);
    }

    .buyer-details summary {
        cursor: pointer;
        color: var(--ow-blue);
        font-weight: 650;
        font-size: .86rem;
        list-style: none;
    }

    .buyer-details summary::-webkit-details-marker {
        display: none;
    }

    .buyer-details div {
        display: flex;
        flex-wrap: wrap;
        gap: .35rem;
        margin-top: .45rem;
        max-width: 420px;
    }

    .buyer-details span {
        display: inline-flex;
        padding: .2rem .48rem;
        border: 1px solid var(--ow-border);
        border-radius: 999px;
        font-size: .76rem;
        color: #4b5563;
        background: #fff;
    }

    .family-list {
        padding: .9rem;
        display: grid;
        gap: .7rem;
    }

    .family-card {
        border: 1px solid var(--ow-border);
        border-radius: 10px;
        overflow: hidden;
        background: #fff;
    }

    .family-toggle {
        width: 100%;
        border: 0;
        background: #fff;
        padding: .82rem .95rem;
        display: flex;
        justify-content: space-between;
        gap: 1rem;
        text-align: left;
    }

    .family-toggle:not(.collapsed) {
        border-bottom: 1px solid var(--ow-border);
        background: #fafafa;
    }

    .family-toggle strong {
        display: block;
        color: var(--ow-text);
    }

    .family-toggle small {
        display: block;
        color: var(--ow-muted);
        margin-top: .15rem;
    }

    .family-total {
        white-space: nowrap;
        font-weight: 750;
        color: var(--ow-text);
    }

    .family-body {
        padding: .75rem .95rem .95rem;
    }

    .category-title {
        margin: .85rem 0 .45rem;
        padding-bottom: .35rem;
        border-bottom: 1px solid var(--ow-border);
        font-size: .76rem;
        color: #374151;
        text-transform: uppercase;
        letter-spacing: .06em;
        font-weight: 800;
    }

    .category-title:first-child {
        margin-top: 0;
    }

    .family-table {
        font-size: .9rem;
        border: 1px solid var(--ow-border);
    }

    .family-table thead th {
        background: #f8fafc;
        color: #4b5563;
        font-size: .74rem;
        text-transform: uppercase;
        letter-spacing: .04em;
        padding: .62rem .7rem;
        border-bottom: 1px solid var(--ow-border);
    }

    .family-table tbody td {
        padding: .58rem .7rem;
        border-bottom: 1px solid #eef2f7;
    }

    .family-footer {
        display: flex;
        flex-wrap: wrap;
        justify-content: flex-end;
        align-items: center;
        gap: .75rem;
        margin-top: .9rem;
        padding-top: .9rem;
        border-top: 1px solid var(--ow-border);
        color: var(--ow-muted);
        font-size: .88rem;
    }

    .family-footer strong {
        color: var(--ow-text);
    }

    .empty-box {
        text-align: center;
        padding: 3rem 1rem;
        background: #fff;
        border: 1px solid var(--ow-border);
        border-radius: 12px;
        color: var(--ow-muted);
    }

    .empty-box.small {
        border: 0;
        border-radius: 0;
    }

    .empty-box i {
        font-size: 2rem;
        display: block;
        margin-bottom: .6rem;
        color: #9ca3af;
    }

    .empty-box h3 {
        color: var(--ow-text);
        font-size: 1.1rem;
        margin-bottom: .35rem;
    }

    .clean-modal {
        border: 0;
        border-radius: 16px;
    }

    body.dark-mode .orders-workspace {
        --ow-border: #343a40;
        --ow-muted: #adb5bd;
        --ow-text: #f8f9fa;
        --ow-bg: #212529;
        --ow-soft: #2b3035;
    }

    body.dark-mode .control-panel,
    body.dark-mode .work-card,
    body.dark-mode .work-card-head,
    body.dark-mode .family-card,
    body.dark-mode .family-toggle,
    body.dark-mode .empty-box,
    body.dark-mode .compact-table-wrap {
        background: var(--ow-bg);
        border-color: var(--ow-border);
        color: var(--ow-text);
    }

    body.dark-mode .compact-order-table thead th,
    body.dark-mode .family-table thead th,
    body.dark-mode .category-row td {
        background: #2b3035 !important;
        color: #dee2e6;
        border-color: #495057;
    }

    body.dark-mode .compact-order-table tbody td,
    body.dark-mode .family-table tbody td {
        background: var(--ow-bg);
        color: var(--ow-text);
        border-color: var(--ow-border);
    }

    body.dark-mode .compact-order-table tbody tr:hover td {
        background: #2b3035;
    }

    body.dark-mode .family-toggle:not(.collapsed) {
        background: #2b3035;
    }

    body.dark-mode .buyer-details span {
        background: #2b3035;
        border-color: #495057;
        color: #dee2e6;
    }

    @media (max-width: 1100px) {
        .status-line {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    @media (max-width: 720px) {
        .page-head,
        .control-panel,
        .family-toggle {
            flex-direction: column;
            align-items: stretch;
        }

        .order-date-actions,
        .primary-actions {
            justify-content: flex-start;
        }

        .status-line {
            grid-template-columns: 1fr;
        }

        .view-tabs {
            overflow-x: auto;
        }

        .view-tabs a {
            white-space: nowrap;
        }
    }
</style>

<script>
    function showToast(message, success = true) {
        const toastElement = document.getElementById('appToast');
        const toastMessage = document.getElementById('toastMessage');

        if (!toastElement || !toastMessage) {
            alert(message);
            return;
        }

        toastElement.classList.remove('text-bg-success', 'text-bg-danger');
        toastElement.classList.add(success ? 'text-bg-success' : 'text-bg-danger');
        toastMessage.textContent = message;

        new bootstrap.Toast(toastElement, {
            delay: 4500
        }).show();
    }

    function readJsonResponse(response) {
        return response.text().then(text => {
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error('Keine gültige JSON-Antwort:', text);
                return {
                    success: false,
                    message: 'Aktion wurde ausgeführt, aber die Server-Antwort war ungültig. Bitte Seite neu laden.',
                    data: {}
                };
            }
        });
    }

    function postAdminOrderAction(action, data = {}, reloadDelay = 700) {
        const formData = new FormData();
        formData.append('ajax_action', action);

        Object.keys(data).forEach(key => {
            formData.append(key, data[key]);
        });

        fetch('admin_orders.php', {
            method: 'POST',
            body: formData
        })
            .then(readJsonResponse)
            .then(result => {
                showToast(result.message, result.success);

                if (result.success) {
                    setTimeout(() => location.reload(), reloadDelay);
                }
            })
            .catch(() => showToast('Fehler bei der Aktion.', false));
    }

    document.getElementById('addOrderDateForm')?.addEventListener('submit', function (e) {
        e.preventDefault();

        const submitButton = this.querySelector('button[type="submit"]');

        if (submitButton) {
            submitButton.disabled = true;
        }

        const formData = new FormData(this);
        formData.append('ajax_action', 'add_order_date');

        fetch('admin_orders.php', {
            method: 'POST',
            body: formData
        })
            .then(readJsonResponse)
            .then(result => {
                showToast(result.message, result.success);

                if (result.success) {
                    const modalEl = document.getElementById('addOrderDateModal');
                    const modal = modalEl ? bootstrap.Modal.getInstance(modalEl) : null;

                    if (modal) {
                        modal.hide();
                    }

                    this.reset();

                    setTimeout(() => {
                        location.href = 'admin_orders.php?order_date_id=' + result.data.id;
                    }, 700);
                } else if (submitButton) {
                    submitButton.disabled = false;
                }
            })
            .catch(() => {
                showToast('Fehler beim Erstellen.', false);

                if (submitButton) {
                    submitButton.disabled = false;
                }
            });
    });

    document.addEventListener('click', function (e) {
        const card = e.target.closest('.admin-date-card');
        const editDateButton = e.target.closest('.btn-edit-date');
        const saveDateButton = e.target.closest('.btn-save-date');
        const cancelDateButton = e.target.closest('.btn-cancel-date');
        const deleteDateButton = e.target.closest('.btn-delete-date');
        const archiveDateButton = e.target.closest('.btn-archive-date');
        const restoreDateButton = e.target.closest('.btn-restore-date');
        const createInvoicesButton = e.target.closest('.btn-create-invoices');
        const deleteUserOrderButton = e.target.closest('.btn-delete-user-order');
        const saveOrderItemButton = e.target.closest('.btn-save-order-item');
        const deleteOrderItemButton = e.target.closest('.btn-delete-order-item');

        if (editDateButton && card) {
            const title = card.querySelector('.date-title')?.textContent.trim() || '';
            const deadline = card.querySelector('.date-deadline')?.dataset.value || '';

            card.dataset.original = card.innerHTML;

            card.innerHTML = `
                <div class="edit-date-row">
                    <input type="text" class="form-control form-control-sm edit-date-title" value="${escapeHtml(title)}">
                    <input type="datetime-local" class="form-control form-control-sm edit-date-deadline" value="${escapeHtml(deadline)}">
                    <button type="button" class="btn btn-success btn-sm btn-save-date">Speichern</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm btn-cancel-date">Abbrechen</button>
                </div>
            `;
        }

        if (cancelDateButton && card) {
            card.innerHTML = card.dataset.original || card.innerHTML;
        }

        if (saveDateButton && card) {
            const id = card.dataset.id;
            const title = card.querySelector('.edit-date-title')?.value.trim() || '';
            const deadline = card.querySelector('.edit-date-deadline')?.value || '';

            postAdminOrderAction('update_order_date', {
                id,
                title,
                deadline_at: deadline
            });
        }

        if (deleteDateButton && card) {
            const id = card.dataset.id;
            const title = card.querySelector('.date-title')?.textContent.trim() || 'diesen Termin';

            if (!confirm('Bestelltermin "' + title + '" wirklich löschen? Bestehende Bestellungen werden vom Termin gelöst.')) {
                return;
            }

            postAdminOrderAction('delete_order_date', {
                id
            }, 900);
        }

        if (archiveDateButton && card) {
            const id = card.dataset.id;

            if (!confirm('Bestelltermin wirklich archivieren?')) {
                return;
            }

            postAdminOrderAction('archive_order_date', {
                id
            });
        }

        if (restoreDateButton && card) {
            const id = card.dataset.id;

            postAdminOrderAction('restore_order_date', {
                id
            });
        }

        if (createInvoicesButton) {
            const id = createInvoicesButton.dataset.id;

            if (!confirm('Für alle offenen Bestellungen dieses Termins Rechnungen erstellen?')) {
                return;
            }

            createInvoicesButton.disabled = true;

            postAdminOrderAction('create_invoices_for_order_date', {
                id
            }, 1000);
        }

        if (deleteUserOrderButton) {
            const orderGroupId = deleteUserOrderButton.dataset.orderGroupId;

            if (!confirm('Diese komplette Bestellung wirklich löschen? Dazu gehörende Rechnungen werden ebenfalls entfernt.')) {
                return;
            }

            postAdminOrderAction('delete_user_order', {
                order_group_id: orderGroupId
            });
        }

        if (saveOrderItemButton) {
            const row = saveOrderItemButton.closest('tr');
            const orderItemId = row?.dataset.orderItemId;
            const quantity = row?.querySelector('.order-item-quantity')?.value || 0;

            postAdminOrderAction('update_order_item', {
                order_item_id: orderItemId,
                quantity
            });
        }

        if (deleteOrderItemButton) {
            const row = deleteOrderItemButton.closest('tr');
            const orderItemId = row?.dataset.orderItemId;

            if (!confirm('Dieses Produkt aus der Bestellung entfernen?')) {
                return;
            }

            postAdminOrderAction('delete_order_item', {
                order_item_id: orderItemId
            });
        }
    });

    function escapeHtml(value) {
        return String(value)
            .replaceAll('&', '&amp;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;');
    }
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/style/layout.php';