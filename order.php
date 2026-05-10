<?php
if (!defined('APP_PAGE_DEBUG')) {
    define('APP_PAGE_DEBUG', true);
}

if (APP_PAGE_DEBUG) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}

if (!function_exists('app_page_debug_log')) {
    function app_page_debug_log($message)
    {
        @file_put_contents(
            __DIR__ . '/php_page_errors.log',
            '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL,
            FILE_APPEND
        );
    }
}

if (!function_exists('app_page_debug_render')) {
    function app_page_debug_render($title, $message, $file = '', $line = '')
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
        }

        echo '<!doctype html><meta charset="utf-8">';
        echo '<div style="max-width:980px;margin:40px auto;padding:22px;border:2px solid #dc2626;border-radius:14px;background:#fff5f5;color:#111;font-family:Arial,sans-serif;line-height:1.45">';
        echo '<h1 style="margin:0 0 12px;color:#b91c1c">Fehler auf dieser Seite</h1>';
        echo '<p><strong>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . ':</strong> ' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';

        if ($file !== '') {
            echo '<p><strong>Datei:</strong> ' . htmlspecialchars($file, ENT_QUOTES, 'UTF-8') . ($line !== '' ? ' Zeile ' . htmlspecialchars((string)$line, ENT_QUOTES, 'UTF-8') : '') . '</p>';
        }

        echo '<p style="font-size:13px;color:#555">Zusätzlich wurde der Fehler in <code>php_page_errors.log</code> gespeichert.</p>';
        echo '</div>';
    }
}

set_exception_handler(function ($e) {
    app_page_debug_log(get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    app_page_debug_render(get_class($e), $e->getMessage(), $e->getFile(), $e->getLine());
    exit;
});

register_shutdown_function(function () {
    $e = error_get_last();

    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        app_page_debug_log('Fatal error: ' . $e['message'] . ' in ' . $e['file'] . ':' . $e['line']);
        app_page_debug_render('Fatal error', $e['message'], $e['file'], $e['line']);
    }
});

require_once __DIR__ . '/config/config.php';

if (function_exists('requireLogin')) {
    $currentUser = requireLogin($pdo);
} else {
    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, family_name, `rank` FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([(int)$_SESSION['user_id']]);
    $currentUser = $stmt->fetch();

    if (!$currentUser) {
        header('Location: login.php');
        exit;
    }
}

$pageTitle = 'Meine Bestellungen';
$userId = (int)$currentUser['id'];

function orderPageTimezone(): DateTimeZone
{
    return new DateTimeZone('Indian/Antananarivo');
}

function orderPageNow(): DateTime
{
    return new DateTime('now', orderPageTimezone());
}

function orderPageDateTime($date): ?DateTime
{
    if (!$date) {
        return null;
    }

    return new DateTime((string)$date, orderPageTimezone());
}

function orderPageFormatDateTime($date, $withTimezone = true): string
{
    $dateTime = orderPageDateTime($date);

    if (!$dateTime) {
        return '-';
    }

    return $dateTime->format('d.m.Y H:i') . ($withTimezone ? ' EAT' : '');
}

function orderPageIsDeadlineOpen($deadlineAt): bool
{
    $deadline = orderPageDateTime($deadlineAt);

    if (!$deadline) {
        return false;
    }

    return $deadline >= orderPageNow();
}

function orderPageJsonResponse($success, $message, $data = []): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/json; charset=utf-8');

    echo json_encode([
        'success' => (bool)$success,
        'message' => (string)$message,
        'data' => $data
    ]);

    exit;
}

function orderPageWriteActivityLog(PDO $pdo, string $action, $userId = null, $userName = null): void
{
    global $currentUser;

    try {
        $logUserId = $userId !== null ? (int)$userId : (int)($currentUser['id'] ?? ($_SESSION['user_id'] ?? 0));
        $logUserName = $userName !== null ? $userName : ($currentUser['family_name'] ?? 'System');

        $stmt = $pdo->prepare("
            INSERT INTO activity_logs (user_id, user_name, action)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$logUserId, $logUserName, $action]);
    } catch (Throwable $e) {
        // Logging darf die eigentliche Aktion niemals blockieren.
    }
}

function orderPageShortenLogText($text, $maxLength = 245): string
{
    $text = trim(preg_replace('/\s+/', ' ', (string)$text));

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($text, 'UTF-8') > $maxLength
            ? mb_substr($text, 0, $maxLength - 3, 'UTF-8') . '...'
            : $text;
    }

    return strlen($text) > $maxLength ? substr($text, 0, $maxLength - 3) . '...' : $text;
}

function orderPageFormatAriary($value): string
{
    return number_format((int)$value, 0, ',', '.') . ' Ariary';
}

function orderPageUnitShort($unit): string
{
    return match ((string)$unit) {
        'Kilogramm' => 'kg',
        'Gramm' => 'g',
        'Liter' => 'l',
        'Milliliter', 'Mililiter', 'ml' => 'ml',
        'Cups', 'cups' => 'Cups',
        'Staude' => 'Staude',
        default => 'Stück',
    };
}

function orderPageFormatQuantityForLog($quantity, $unit): string
{
    return (int)$quantity . ' ' . orderPageUnitShort($unit);
}

function orderPageGetUserFeeSettings(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare("
        SELECT discount_percent, service_fee_waived, shopping_fee_waived
        FROM users
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId]);

    return $stmt->fetch() ?: [
        'discount_percent' => 0,
        'service_fee_waived' => 0,
        'shopping_fee_waived' => 0,
    ];
}

function orderPageCalculateOrderTotals(PDO $pdo, int $userId, int $subtotal): array
{
    $settings = orderPageGetUserFeeSettings($pdo, $userId);

    $discountPercent = (int)($settings['discount_percent'] ?? 0);
    $serviceFeeWaived = (int)($settings['service_fee_waived'] ?? 0) === 1;
    $shoppingFeeWaived = (int)($settings['shopping_fee_waived'] ?? 0) === 1;

    $discountAmount = $subtotal * ($discountPercent / 100);
    $subtotalAfterDiscount = $subtotal - $discountAmount;

    $serviceFee = ($subtotal > 0 && !$serviceFeeWaived) ? 15000 : 0;
    $shoppingFee = ($subtotal > 0 && !$shoppingFeeWaived) ? ($subtotalAfterDiscount * 0.10) : 0;
    $total = $subtotalAfterDiscount + $serviceFee + $shoppingFee;

    return [
        'subtotal' => (int)$subtotal,
        'service_fee' => (int)$serviceFee,
        'shopping_fee' => (int)$shoppingFee,
        'total' => (int)$total
    ];
}

function orderPageGetOrderSnapshot(PDO $pdo, int $orderId, int $userId): ?array
{
    $stmt = $pdo->prepare("
        SELECT
            og.id,
            og.subtotal,
            og.service_fee,
            og.shopping_fee,
            og.total,
            od.title AS order_date_title
        FROM order_groups og
        LEFT JOIN order_dates od ON od.id = og.order_date_id
        WHERE og.id = ?
        AND og.user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$orderId, $userId]);
    $group = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$group) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT id, product_id, product_name, unit, price, quantity, row_total
        FROM order_items
        WHERE order_group_id = ?
        ORDER BY product_name ASC, id ASC
    ");
    $stmt->execute([$orderId]);

    $items = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $key = (int)$item['product_id'];

        $items[$key] = [
            'id' => (int)$item['id'],
            'product_id' => $key,
            'product_name' => (string)$item['product_name'],
            'unit' => (string)$item['unit'],
            'price' => (int)$item['price'],
            'quantity' => (int)$item['quantity'],
            'row_total' => (int)$item['row_total'],
        ];
    }

    return [
        'group' => [
            'id' => (int)$group['id'],
            'order_date_title' => (string)($group['order_date_title'] ?? ''),
            'subtotal' => (int)$group['subtotal'],
            'service_fee' => (int)$group['service_fee'],
            'shopping_fee' => (int)$group['shopping_fee'],
            'total' => (int)$group['total'],
        ],
        'items' => $items,
    ];
}

function orderPageBuildOrderChangeLines($before, $after): array
{
    if (!$before || !$after) {
        return [];
    }

    $changes = [];
    $beforeItems = $before['items'] ?? [];
    $afterItems = $after['items'] ?? [];

    $allProductIds = array_unique(array_merge(array_keys($beforeItems), array_keys($afterItems)));
    sort($allProductIds);

    foreach ($allProductIds as $productId) {
        $oldItem = $beforeItems[$productId] ?? null;
        $newItem = $afterItems[$productId] ?? null;

        if ($oldItem && !$newItem) {
            $changes[] = $oldItem['product_name'] . ' entfernt: vorher ' . orderPageFormatQuantityForLog($oldItem['quantity'], $oldItem['unit']) . ' / ' . orderPageFormatAriary($oldItem['row_total']) . ', nachher 0';
            continue;
        }

        if (!$oldItem && $newItem) {
            $changes[] = $newItem['product_name'] . ' hinzugefügt: vorher 0, nachher ' . orderPageFormatQuantityForLog($newItem['quantity'], $newItem['unit']) . ' / ' . orderPageFormatAriary($newItem['row_total']);
            continue;
        }

        if ($oldItem && $newItem) {
            $quantityChanged = (int)$oldItem['quantity'] !== (int)$newItem['quantity'];
            $priceChanged = (int)$oldItem['price'] !== (int)$newItem['price'];
            $rowTotalChanged = (int)$oldItem['row_total'] !== (int)$newItem['row_total'];

            if ($quantityChanged || $priceChanged || $rowTotalChanged) {
                $line = $newItem['product_name'] . ': ' .
                    orderPageFormatQuantityForLog($oldItem['quantity'], $oldItem['unit']) .
                    ' / ' . orderPageFormatAriary($oldItem['row_total']) .
                    ' → ' .
                    orderPageFormatQuantityForLog($newItem['quantity'], $newItem['unit']) .
                    ' / ' . orderPageFormatAriary($newItem['row_total']);

                if ($priceChanged) {
                    $line .= ' (Preis: ' . orderPageFormatAriary($oldItem['price']) . ' → ' . orderPageFormatAriary($newItem['price']) . ')';
                }

                $changes[] = $line;
            }
        }
    }

    $totalLabels = [
        'subtotal' => 'Zwischensumme',
        'service_fee' => 'Servicegebühr',
        'shopping_fee' => 'Einkaufsgebühr',
        'total' => 'Gesamt',
    ];

    foreach ($totalLabels as $field => $label) {
        $oldValue = (int)($before['group'][$field] ?? 0);
        $newValue = (int)($after['group'][$field] ?? 0);

        if ($oldValue !== $newValue) {
            $changes[] = $label . ': ' . orderPageFormatAriary($oldValue) . ' → ' . orderPageFormatAriary($newValue);
        }
    }

    return $changes;
}

function orderPageWriteDetailedOrderChangeLog($orderId, $userId, $userName, array $changes): void
{
    if (empty($changes)) {
        return;
    }

    $lines = [];
    $lines[] = '[' . orderPageNow()->format('Y-m-d H:i:s') . ' EAT] ' . $userName . ' (User-ID ' . (int)$userId . ')';
    $lines[] = 'Bestellung #' . (int)$orderId . ' geändert:';

    foreach ($changes as $change) {
        $lines[] = '- ' . $change;
    }

    $lines[] = '';

    @file_put_contents(__DIR__ . '/order_changes.log', implode(PHP_EOL, $lines), FILE_APPEND);
}

function orderPageLogOrderChanges(PDO $pdo, int $orderId, int $userId, $beforeSnapshot, $afterSnapshot): void
{
    global $currentUser;

    $changes = orderPageBuildOrderChangeLines($beforeSnapshot, $afterSnapshot);

    if (empty($changes)) {
        orderPageWriteActivityLog($pdo, 'Bestellung #' . $orderId . ' gespeichert: keine Änderung erkannt', $userId);
        return;
    }

    $userName = $currentUser['family_name'] ?? 'Unbekannt';
    $action = 'Bestellung #' . $orderId . ' geändert: ' . implode('; ', $changes);
    $action = orderPageShortenLogText($action, 245);

    orderPageWriteActivityLog($pdo, $action, $userId, $userName);
    orderPageWriteDetailedOrderChangeLog($orderId, $userId, $userName, $changes);
}

function orderPageGetCurrentOrderDate(PDO $pdo)
{
    $stmt = $pdo->prepare("
        SELECT id, title, deadline_at
        FROM order_dates
        WHERE archived = 0
        ORDER BY deadline_at ASC
    ");
    $stmt->execute();

    foreach ($stmt->fetchAll() as $date) {
        if (orderPageIsDeadlineOpen($date['deadline_at'])) {
            return $date;
        }
    }

    return null;
}

function orderPageGetCurrentUserOrder(PDO $pdo, int $userId, $orderDateId)
{
    if (!$orderDateId) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT id
        FROM order_groups
        WHERE user_id = ?
        AND order_date_id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId, $orderDateId]);

    return $stmt->fetch();
}

function orderPageGetEditableOrder(PDO $pdo, int $orderId, int $userId)
{
    $stmt = $pdo->prepare("
        SELECT 
            og.id,
            og.order_date_id,
            od.deadline_at,
            od.archived
        FROM order_groups og
        INNER JOIN order_dates od ON od.id = og.order_date_id
        WHERE og.id = ?
        AND og.user_id = ?
        LIMIT 1
    ");
    $stmt->execute([$orderId, $userId]);

    return $stmt->fetch();
}

function orderPageValidateOpenOrder($order, $forDelete = false): void
{
    if (!$order) {
        orderPageJsonResponse(false, 'Keine Berechtigung für diese Bestellung.');
    }

    if ((int)$order['archived'] === 1) {
        orderPageJsonResponse(
            false,
            $forDelete
                ? 'Archivierte Bestellungen können nicht gelöscht werden.'
                : 'Diese Bestellung ist archiviert und kann nicht mehr bearbeitet werden.'
        );
    }

    if (!orderPageIsDeadlineOpen($order['deadline_at'])) {
        orderPageJsonResponse(
            false,
            $forDelete
                ? 'Der Bestellschluss ist abgelaufen. Diese Bestellung kann nicht mehr gelöscht werden.'
                : 'Der Bestellschluss ist abgelaufen. Diese Bestellung kann nicht mehr bearbeitet werden.'
        );
    }
}

function orderPageGetProducts(PDO $pdo): array
{
    return $pdo->query("
        SELECT
            p.*,
            COALESCE(pc.name, 'Ohne Kategorie') AS category_name
        FROM products p
        LEFT JOIN product_categories pc ON pc.id = p.category_id
        WHERE p.active = 1
        ORDER BY COALESCE(pc.name, 'Ohne Kategorie') ASC, p.name ASC
    ")->fetchAll();
}

function orderPageProductsByCategory(array $products): array
{
    $grouped = [];

    foreach ($products as $product) {
        $categoryName = trim((string)($product['category_name'] ?? ''));

        if ($categoryName === '') {
            $categoryName = 'Ohne Kategorie';
        }

        $grouped[$categoryName][] = $product;
    }

    return $grouped;
}

function orderPageGetUserOrderGroups(PDO $pdo, int $userId, bool $archived): array
{
    $stmt = $pdo->prepare("
        SELECT 
            og.*,
            od.title AS order_date_title,
            od.deadline_at,
            od.archived,
            (
                SELECT COUNT(*)
                FROM order_items oi
                WHERE oi.order_group_id = og.id
            ) AS product_count
        FROM order_groups og
        INNER JOIN order_dates od ON od.id = og.order_date_id
        WHERE og.user_id = ?
        AND od.archived = ?
        ORDER BY od.deadline_at DESC, og.created_at DESC
    ");
    $stmt->execute([$userId, $archived ? 1 : 0]);

    return $stmt->fetchAll();
}

function orderPageGetUserOrderItemsGrouped(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare("
        SELECT
            oi.*,
            COALESCE(pc.name, 'Ohne Kategorie') AS category_name
        FROM order_items oi
        INNER JOIN order_groups og ON og.id = oi.order_group_id
        LEFT JOIN products p ON p.id = oi.product_id
        LEFT JOIN product_categories pc ON pc.id = p.category_id
        WHERE og.user_id = ?
        ORDER BY COALESCE(pc.name, 'Ohne Kategorie') ASC, oi.product_name ASC
    ");
    $stmt->execute([$userId]);

    $items = [];

    foreach ($stmt->fetchAll() as $item) {
        $items[(int)$item['order_group_id']][(int)$item['product_id']] = $item;
    }

    return $items;
}

function orderPageItemsByCategory(array $items): array
{
    $grouped = [];

    foreach ($items as $item) {
        $categoryName = trim((string)($item['category_name'] ?? ''));

        if ($categoryName === '') {
            $categoryName = 'Ohne Kategorie';
        }

        $grouped[$categoryName][] = $item;
    }

    return $grouped;
}

function orderPageCreateOrderWithItems(PDO $pdo, int $userId, $currentOrderDateId): void
{
    if (!$currentOrderDateId) {
        orderPageJsonResponse(false, 'Aktuell ist kein freigegebener Bestelltermin vorhanden.');
    }

    if (orderPageGetCurrentUserOrder($pdo, $userId, $currentOrderDateId)) {
        orderPageJsonResponse(false, 'Du hast für diesen Termin bereits eine Bestellung.');
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO order_groups 
            (user_id, order_date_id, subtotal, service_fee, shopping_fee, total)
            VALUES (?, ?, 0, 0, 0, 0)
        ");
        $stmt->execute([$userId, $currentOrderDateId]);

        $orderId = (int)$pdo->lastInsertId();
        $subtotal = 0;
        $addedItems = 0;

        foreach ($_POST['add_quantity'] ?? [] as $productId => $quantity) {
            $productId = (int)$productId;
            $quantity = (int)$quantity;

            if ($quantity <= 0) {
                continue;
            }

            $stmt = $pdo->prepare("
                SELECT id, name, unit, price
                FROM products
                WHERE id = ?
                AND active = 1
                LIMIT 1
            ");
            $stmt->execute([$productId]);
            $product = $stmt->fetch();

            if (!$product) {
                continue;
            }

            $rowTotal = $quantity * (int)$product['price'];
            $subtotal += $rowTotal;

            $stmt = $pdo->prepare("
                INSERT INTO order_items
                (order_group_id, product_id, product_name, unit, price, quantity, row_total)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $orderId,
                (int)$product['id'],
                $product['name'],
                $product['unit'],
                (int)$product['price'],
                $quantity,
                $rowTotal
            ]);

            $addedItems++;
        }

        $totals = orderPageCalculateOrderTotals($pdo, $userId, $subtotal);

        $stmt = $pdo->prepare("
            UPDATE order_groups
            SET subtotal = ?, service_fee = ?, shopping_fee = ?, total = ?
            WHERE id = ?
            AND user_id = ?
        ");
        $stmt->execute([
            $totals['subtotal'],
            $totals['service_fee'],
            $totals['shopping_fee'],
            $totals['total'],
            $orderId,
            $userId
        ]);

        $pdo->commit();

        orderPageWriteActivityLog(
            $pdo,
            $addedItems > 0
                ? 'Bestellung angelegt mit ' . $addedItems . ' Produkt(en)'
                : 'Leere Bestellung angelegt'
        );

        orderPageJsonResponse(true, 'Bestellung wurde angelegt.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        orderPageJsonResponse(false, 'Fehler beim Anlegen der Bestellung.');
    }
}

function orderPageDeleteOrder(PDO $pdo, int $userId, int $orderId): void
{
    $order = orderPageGetEditableOrder($pdo, $orderId, $userId);
    orderPageValidateOpenOrder($order, true);

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("DELETE FROM order_items WHERE order_group_id = ?");
        $stmt->execute([$orderId]);

        $stmt = $pdo->prepare("
            DELETE FROM order_groups
            WHERE id = ?
            AND user_id = ?
        ");
        $stmt->execute([$orderId, $userId]);

        $pdo->commit();

        orderPageWriteActivityLog($pdo, 'Bestellung gelöscht');

        orderPageJsonResponse(true, 'Bestellung wurde gelöscht.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        orderPageJsonResponse(false, 'Fehler beim Löschen.');
    }
}

function orderPageUpdateOrder(PDO $pdo, int $userId, int $orderId): void
{
    $order = orderPageGetEditableOrder($pdo, $orderId, $userId);
    orderPageValidateOpenOrder($order, false);

    $beforeSnapshot = orderPageGetOrderSnapshot($pdo, $orderId, $userId);

    try {
        $pdo->beginTransaction();

        foreach ($_POST['items'] ?? [] as $itemId => $quantity) {
            $itemId = (int)$itemId;
            $quantity = (int)$quantity;

            $stmt = $pdo->prepare("
                SELECT oi.id, oi.price
                FROM order_items oi
                INNER JOIN order_groups og ON og.id = oi.order_group_id
                WHERE oi.id = ?
                AND oi.order_group_id = ?
                AND og.user_id = ?
                LIMIT 1
            ");
            $stmt->execute([$itemId, $orderId, $userId]);
            $item = $stmt->fetch();

            if (!$item) {
                continue;
            }

            if ($quantity <= 0) {
                $stmt = $pdo->prepare("
                    DELETE FROM order_items
                    WHERE id = ?
                    AND order_group_id = ?
                ");
                $stmt->execute([$itemId, $orderId]);
                continue;
            }

            $rowTotal = $quantity * (int)$item['price'];

            $stmt = $pdo->prepare("
                UPDATE order_items
                SET quantity = ?, row_total = ?
                WHERE id = ?
                AND order_group_id = ?
            ");
            $stmt->execute([$quantity, $rowTotal, $itemId, $orderId]);
        }

        foreach ($_POST['add_quantity'] ?? [] as $productId => $quantity) {
            $productId = (int)$productId;
            $quantity = (int)$quantity;

            if ($quantity <= 0) {
                continue;
            }

            $stmt = $pdo->prepare("
                SELECT id, name, unit, price
                FROM products
                WHERE id = ?
                AND active = 1
                LIMIT 1
            ");
            $stmt->execute([$productId]);
            $product = $stmt->fetch();

            if (!$product) {
                continue;
            }

            $stmt = $pdo->prepare("
                SELECT id, quantity, price
                FROM order_items
                WHERE order_group_id = ?
                AND product_id = ?
                LIMIT 1
            ");
            $stmt->execute([$orderId, $productId]);
            $existingItem = $stmt->fetch();

            if ($existingItem) {
                $newQuantity = (int)$existingItem['quantity'] + $quantity;
                $rowTotal = $newQuantity * (int)$existingItem['price'];

                $stmt = $pdo->prepare("
                    UPDATE order_items
                    SET quantity = ?, row_total = ?
                    WHERE id = ?
                    AND order_group_id = ?
                ");
                $stmt->execute([
                    $newQuantity,
                    $rowTotal,
                    (int)$existingItem['id'],
                    $orderId
                ]);

                continue;
            }

            $rowTotal = $quantity * (int)$product['price'];

            $stmt = $pdo->prepare("
                INSERT INTO order_items
                (order_group_id, product_id, product_name, unit, price, quantity, row_total)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $orderId,
                (int)$product['id'],
                $product['name'],
                $product['unit'],
                (int)$product['price'],
                $quantity,
                $rowTotal
            ]);
        }

        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(row_total), 0) AS subtotal
            FROM order_items
            WHERE order_group_id = ?
        ");
        $stmt->execute([$orderId]);
        $subtotal = (int)$stmt->fetch()['subtotal'];

        $totals = orderPageCalculateOrderTotals($pdo, $userId, $subtotal);

        $stmt = $pdo->prepare("
            UPDATE order_groups
            SET subtotal = ?, service_fee = ?, shopping_fee = ?, total = ?
            WHERE id = ?
            AND user_id = ?
        ");
        $stmt->execute([
            $totals['subtotal'],
            $totals['service_fee'],
            $totals['shopping_fee'],
            $totals['total'],
            $orderId,
            $userId
        ]);

        $afterSnapshot = orderPageGetOrderSnapshot($pdo, $orderId, $userId);

        $pdo->commit();

        orderPageLogOrderChanges($pdo, $orderId, $userId, $beforeSnapshot, $afterSnapshot);

        orderPageJsonResponse(true, 'Bestellung wurde aktualisiert.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        orderPageJsonResponse(false, 'Fehler beim Aktualisieren.');
    }
}

$currentOrderDate = orderPageGetCurrentOrderDate($pdo);
$currentOrderDateId = $currentOrderDate ? (int)$currentOrderDate['id'] : null;
$currentUserOrder = orderPageGetCurrentUserOrder($pdo, $userId, $currentOrderDateId);
$hasCurrentOrder = $currentUserOrder ? true : false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    $action = $_POST['ajax_action'];

    if ($action === 'create_order_with_items') {
        orderPageCreateOrderWithItems($pdo, $userId, $currentOrderDateId);
    }

    if ($action === 'delete_order') {
        orderPageDeleteOrder($pdo, $userId, (int)($_POST['order_id'] ?? 0));
    }

    if ($action === 'update_order') {
        orderPageUpdateOrder($pdo, $userId, (int)($_POST['order_id'] ?? 0));
    }

    orderPageJsonResponse(false, 'Unbekannte Aktion.');
}

$products = orderPageGetProducts($pdo);
$productsByCategory = orderPageProductsByCategory($products);
$userFeeSettings = orderPageGetUserFeeSettings($pdo, $userId);
$orderGroups = orderPageGetUserOrderGroups($pdo, $userId, false);
$archivedOrderGroups = orderPageGetUserOrderGroups($pdo, $userId, true);
$orderItems = orderPageGetUserOrderItemsGrouped($pdo, $userId);

$currentActiveOrder = null;

if ($currentUserOrder) {
    foreach ($orderGroups as $group) {
        if ((int)$group['id'] === (int)$currentUserOrder['id']) {
            $currentActiveOrder = $group;
            break;
        }
    }
}

$activeOrderCount = count($orderGroups);
$archivedOrderCount = count($archivedOrderGroups);

ob_start();
?>

<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 9999;">
    <div id="appToast" class="toast align-items-center text-bg-success border-0" role="alert">
        <div class="d-flex">
            <div class="toast-body" id="toastMessage">Aktion erfolgreich.</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto"></button>
        </div>
    </div>
</div>

<div class="order-page">
    <div class="app-page-header">
        <div>
            <h1>Meine Bestellungen</h1>
            <p>Bestellungen werden über ein Popup angelegt oder bearbeitet. Produkte sind dort nach Kategorien sortiert.</p>
        </div>

        <?php if ($currentOrderDate && !$hasCurrentOrder): ?>
            <div class="app-page-actions">
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createOrderModal">
                    <i class="bi bi-plus-circle me-1"></i>
                    Bestellung anlegen
                </button>
            </div>
        <?php endif; ?>
    </div>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body d-flex justify-content-between align-items-center gap-3 flex-wrap">
            <div>
                <h5 class="mb-1">Aktueller Bestelltermin</h5>

                <?php if ($currentOrderDate): ?>
                    <strong><?= htmlspecialchars($currentOrderDate['title'], ENT_QUOTES, 'UTF-8') ?></strong><br>
                    <small class="text-muted">
                        Bestellschluss: <?= orderPageFormatDateTime($currentOrderDate['deadline_at']) ?>
                    </small>
                <?php else: ?>
                    <span class="text-muted">Aktuell ist kein freigegebener Bestelltermin vorhanden.</span>
                <?php endif; ?>
            </div>

            <div>
                <?php if ($currentOrderDate && $hasCurrentOrder): ?>
                    <span class="badge bg-success fs-6">Bestellung angelegt</span>
                <?php elseif ($currentOrderDate): ?>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createOrderModal">
                        <i class="bi bi-plus-circle me-1"></i>
                        Bestellung anlegen
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="order-summary-box">
                <span>Aktuelle Bestellung</span>
                <strong><?= $currentActiveOrder ? orderPageFormatAriary($currentActiveOrder['total']) : '0 Ariary' ?></strong>
            </div>
        </div>

        <div class="col-md-4">
            <div class="order-summary-box">
                <span>Aktive Bestellungen</span>
                <strong><?= (int)$activeOrderCount ?></strong>
            </div>
        </div>

        <div class="col-md-4">
            <div class="order-summary-box">
                <span>Archivierte Bestellungen</span>
                <strong><?= (int)$archivedOrderCount ?></strong>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header">
            <h5 class="mb-0">
                <i class="bi bi-clock-history me-2"></i>Aktive Bestellungen
            </h5>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead>
                    <tr>
                        <th>Datum</th>
                        <th>Termin</th>
                        <th>Produkte</th>
                        <th>Gesamt</th>
                        <th style="width: 170px;">Aktion</th>
                    </tr>
                    </thead>

                    <tbody>
                    <?php if ($orderGroups): ?>
                        <?php foreach ($orderGroups as $order): ?>
                            <?php
                            $canEdit = (int)$order['archived'] === 0 && orderPageIsDeadlineOpen($order['deadline_at']);
                            ?>
                            <tr>
                                <td><?= orderPageFormatDateTime($order['created_at']) ?></td>

                                <td>
                                    <strong><?= htmlspecialchars($order['order_date_title'], ENT_QUOTES, 'UTF-8') ?></strong><br>
                                    <small class="text-muted">
                                        bis <?= orderPageFormatDateTime($order['deadline_at']) ?>
                                    </small>
                                </td>

                                <td><?= (int)$order['product_count'] ?> Produkte</td>

                                <td>
                                    <strong><?= orderPageFormatAriary($order['total']) ?></strong>
                                </td>

                                <td>
                                    <button type="button"
                                            class="btn btn-outline-secondary btn-sm"
                                            data-bs-toggle="modal"
                                            data-bs-target="#viewOrderModal<?= (int)$order['id'] ?>"
                                            title="Anzeigen">
                                        <i class="bi bi-eye"></i>
                                    </button>

                                    <?php if ($canEdit): ?>
                                        <button type="button"
                                                class="btn btn-primary btn-sm"
                                                data-bs-toggle="modal"
                                                data-bs-target="#editOrderModal<?= (int)$order['id'] ?>"
                                                title="Bearbeiten">
                                            <i class="bi bi-pencil"></i>
                                        </button>

                                        <button type="button"
                                                class="btn btn-outline-danger btn-sm delete-order-btn"
                                                data-order-id="<?= (int)$order['id'] ?>"
                                                title="Löschen">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">
                                Noch keine aktiven Bestellungen vorhanden.
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-header d-flex justify-content-between align-items-center gap-3 flex-wrap">
            <h5 class="mb-0">
                <i class="bi bi-archive me-2"></i>Archivierte Bestellungen
            </h5>

            <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#archivedOrdersBox">
                Anzeigen / Ausblenden
            </button>
        </div>

        <div class="collapse" id="archivedOrdersBox">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead>
                        <tr>
                            <th>Datum</th>
                            <th>Termin</th>
                            <th>Produkte</th>
                            <th>Gesamt</th>
                            <th style="width: 120px;">Aktion</th>
                        </tr>
                        </thead>

                        <tbody>
                        <?php if ($archivedOrderGroups): ?>
                            <?php foreach ($archivedOrderGroups as $order): ?>
                                <tr>
                                    <td><?= orderPageFormatDateTime($order['created_at']) ?></td>

                                    <td>
                                        <strong><?= htmlspecialchars($order['order_date_title'], ENT_QUOTES, 'UTF-8') ?></strong><br>
                                        <small class="text-muted">
                                            bis <?= orderPageFormatDateTime($order['deadline_at']) ?>
                                        </small>
                                    </td>

                                    <td><?= (int)$order['product_count'] ?> Produkte</td>

                                    <td>
                                        <strong><?= orderPageFormatAriary($order['total']) ?></strong>
                                    </td>

                                    <td>
                                        <button type="button"
                                                class="btn btn-outline-secondary btn-sm"
                                                data-bs-toggle="modal"
                                                data-bs-target="#archivedOrderModal<?= (int)$order['id'] ?>"
                                                title="Anzeigen">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">
                                    Noch keine archivierten Bestellungen vorhanden.
                                </td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php foreach ($orderGroups as $order): ?>
    <?php
    $canEdit = (int)$order['archived'] === 0 && orderPageIsDeadlineOpen($order['deadline_at']);
    $itemsForOrder = $orderItems[(int)$order['id']] ?? [];
    $itemsByCategory = orderPageItemsByCategory($itemsForOrder);
    ?>

    <div class="modal fade" id="viewOrderModal<?= (int)$order['id'] ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title">Bestellung ansehen</h5>
                        <small class="text-muted"><?= htmlspecialchars($order['order_date_title'], ENT_QUOTES, 'UTF-8') ?></small>
                    </div>

                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>

                <div class="modal-body">
                    <?= renderOrderViewHtml($itemsByCategory, (int)$order['id']) ?>
                    <?= renderOrderTotalsHtml($order) ?>
                </div>
            </div>
        </div>
    </div>

    <?php if ($canEdit): ?>
        <div class="modal fade" id="editOrderModal<?= (int)$order['id'] ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-xl modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title">Bestellung bearbeiten</h5>
                            <small class="text-muted">Produkte nach Kategorie auswählen. Gespeichert wird erst unten über den Button.</small>
                        </div>

                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                    </div>

                    <div class="modal-body">
                        <?= renderOrderEditHtml($productsByCategory, $orderItems[(int)$order['id']] ?? [], $userFeeSettings, (int)$order['id'], $order) ?>
                    </div>

                    <div class="modal-footer d-flex justify-content-between">
                        <div class="text-muted small">
                            <span class="order-selected-count">0</span> Produkte ausgewählt
                        </div>

                        <div>
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Abbrechen</button>

                            <button type="button"
                                    class="btn btn-success save-order-btn"
                                    data-order-id="<?= (int)$order['id'] ?>">
                                <i class="bi bi-save me-1"></i>
                                Änderungen speichern
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
<?php endforeach; ?>

<?php foreach ($archivedOrderGroups as $order): ?>
    <?php
    $itemsForOrder = $orderItems[(int)$order['id']] ?? [];
    $itemsByCategory = orderPageItemsByCategory($itemsForOrder);
    ?>

    <div class="modal fade" id="archivedOrderModal<?= (int)$order['id'] ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title">Archivierte Bestellung</h5>
                        <small class="text-muted"><?= htmlspecialchars($order['order_date_title'], ENT_QUOTES, 'UTF-8') ?></small>
                    </div>

                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>

                <div class="modal-body">
                    <?= renderOrderViewHtml($itemsByCategory, (int)$order['id'], 'archived') ?>
                    <?= renderOrderTotalsHtml($order) ?>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<?php if ($currentOrderDate && !$hasCurrentOrder): ?>
    <div class="modal fade" id="createOrderModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title">Neue Bestellung anlegen</h5>
                        <small class="text-muted">Produkte nach Kategorie auswählen. Die Bestellung wird erst beim Speichern angelegt.</small>
                    </div>

                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>

                <div class="modal-body">
                    <?= renderOrderCreateHtml($productsByCategory, $userFeeSettings) ?>
                </div>

                <div class="modal-footer d-flex justify-content-between">
                    <div class="text-muted small">
                        <span class="order-selected-count">0</span> Produkte ausgewählt
                    </div>

                    <div>
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Abbrechen</button>

                        <button type="button" class="btn btn-success" id="createOrderSubmitBtn">
                            <i class="bi bi-save me-1"></i>
                            Bestellung speichern
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php
function renderOrderTotalsHtml(array $order): string
{
    ob_start();
    ?>
    <div class="order-total-box mt-3">
        <div>
            <span>Zwischensumme</span>
            <strong><?= orderPageFormatAriary($order['subtotal']) ?></strong>
        </div>

        <div>
            <span>Servicegebühr</span>
            <strong><?= (int)$order['service_fee'] === 0 ? 'Erlassen' : orderPageFormatAriary($order['service_fee']) ?></strong>
        </div>

        <div>
            <span>Einkaufsgebühr</span>
            <strong><?= (int)$order['shopping_fee'] === 0 ? 'Erlassen' : orderPageFormatAriary($order['shopping_fee']) ?></strong>
        </div>

        <div class="total">
            <span>Gesamt</span>
            <strong><?= orderPageFormatAriary($order['total']) ?></strong>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

function renderOrderViewHtml(array $itemsByCategory, int $orderId, string $prefix = 'view'): string
{
    ob_start();

    if (!$itemsByCategory): ?>
        <div class="text-muted">Diese Bestellung enthält keine Produkte.</div>
    <?php else: ?>
        <div class="accordion order-category-accordion" id="<?= htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8') ?>Accordion<?= $orderId ?>">
            <?php $categoryIndex = 0; ?>
            <?php foreach ($itemsByCategory as $categoryName => $categoryItems): ?>
                <?php
                $categoryIndex++;
                $collapseId = $prefix . 'Order' . $orderId . 'Category' . $categoryIndex;
                ?>
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button <?= $categoryIndex === 1 ? '' : 'collapsed' ?>"
                                type="button"
                                data-bs-toggle="collapse"
                                data-bs-target="#<?= htmlspecialchars($collapseId, ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($categoryName, ENT_QUOTES, 'UTF-8') ?>
                            <span class="category-count ms-2"><?= count($categoryItems) ?></span>
                        </button>
                    </h2>

                    <div id="<?= htmlspecialchars($collapseId, ENT_QUOTES, 'UTF-8') ?>"
                         class="accordion-collapse collapse <?= $categoryIndex === 1 ? 'show' : '' ?>"
                         data-bs-parent="#<?= htmlspecialchars($prefix, ENT_QUOTES, 'UTF-8') ?>Accordion<?= $orderId ?>">
                        <div class="accordion-body p-0">
                            <table class="table table-sm mb-0 align-middle">
                                <thead>
                                <tr>
                                    <th>Produkt</th>
                                    <th>Preis</th>
                                    <th>Menge</th>
                                    <th class="text-end">Summe</th>
                                </tr>
                                </thead>

                                <tbody>
                                <?php foreach ($categoryItems as $item): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($item['product_name'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                                        <td><?= orderPageFormatAriary($item['price']) ?></td>
                                        <td>
                                            <?= (int)$item['quantity'] ?>
                                            <?= htmlspecialchars(orderPageUnitShort($item['unit']), ENT_QUOTES, 'UTF-8') ?>
                                        </td>
                                        <td class="text-end">
                                            <strong><?= orderPageFormatAriary($item['row_total']) ?></strong>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif;

    return ob_get_clean();
}

function renderOrderCreateHtml(array $productsByCategory, array $userFeeSettings): string
{
    ob_start();
    ?>
    <div class="order-edit-panel"
         id="createOrderPanel"
         data-discount-percent="<?= (int)($userFeeSettings['discount_percent'] ?? 0) ?>"
         data-service-waived="<?= (int)($userFeeSettings['service_fee_waived'] ?? 0) ?>"
         data-shopping-waived="<?= (int)($userFeeSettings['shopping_fee_waived'] ?? 0) ?>">

        <?= renderProductAccordionHtml($productsByCategory, [], 'createOrderAccordion', 'create') ?>

        <div class="order-total-box mt-3">
            <div>
                <span>Zwischensumme</span>
                <strong class="live-subtotal">0 Ariary</strong>
            </div>

            <div>
                <span>Servicegebühr</span>
                <strong class="live-service-fee">Erlassen</strong>
            </div>

            <div>
                <span>Einkaufsgebühr</span>
                <strong class="live-shopping-fee">Erlassen</strong>
            </div>

            <div class="total">
                <span>Gesamt</span>
                <strong class="live-total">0 Ariary</strong>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

function renderOrderEditHtml(array $productsByCategory, array $existingItems, array $userFeeSettings, int $orderId, array $order): string
{
    ob_start();
    ?>
    <div class="order-edit-panel"
         data-discount-percent="<?= (int)($userFeeSettings['discount_percent'] ?? 0) ?>"
         data-service-waived="<?= (int)($userFeeSettings['service_fee_waived'] ?? 0) ?>"
         data-shopping-waived="<?= (int)($userFeeSettings['shopping_fee_waived'] ?? 0) ?>">

        <?= renderProductAccordionHtml($productsByCategory, $existingItems, 'editOrderAccordion' . $orderId, 'edit' . $orderId) ?>

        <div class="order-total-box mt-3">
            <div>
                <span>Zwischensumme</span>
                <strong class="live-subtotal"><?= orderPageFormatAriary($order['subtotal']) ?></strong>
            </div>

            <div>
                <span>Servicegebühr</span>
                <strong class="live-service-fee"><?= (int)$order['service_fee'] === 0 ? 'Erlassen' : orderPageFormatAriary($order['service_fee']) ?></strong>
            </div>

            <div>
                <span>Einkaufsgebühr</span>
                <strong class="live-shopping-fee"><?= (int)$order['shopping_fee'] === 0 ? 'Erlassen' : orderPageFormatAriary($order['shopping_fee']) ?></strong>
            </div>

            <div class="total">
                <span>Gesamt</span>
                <strong class="live-total"><?= orderPageFormatAriary($order['total']) ?></strong>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

function renderProductAccordionHtml(array $productsByCategory, array $existingItems, string $accordionId, string $prefix): string
{
    ob_start();

    if (!$productsByCategory): ?>
        <div class="text-muted">Aktuell sind keine Produkte verfügbar.</div>
    <?php else: ?>
        <div class="accordion order-category-accordion" id="<?= htmlspecialchars($accordionId, ENT_QUOTES, 'UTF-8') ?>">
            <?php $categoryIndex = 0; ?>
            <?php foreach ($productsByCategory as $categoryName => $categoryProducts): ?>
                <?php
                $categoryIndex++;
                $collapseId = $prefix . 'Category' . $categoryIndex;
                ?>
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button <?= $categoryIndex === 1 ? '' : 'collapsed' ?>"
                                type="button"
                                data-bs-toggle="collapse"
                                data-bs-target="#<?= htmlspecialchars($collapseId, ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($categoryName, ENT_QUOTES, 'UTF-8') ?>
                            <span class="category-count ms-2"><?= count($categoryProducts) ?></span>
                        </button>
                    </h2>

                    <div id="<?= htmlspecialchars($collapseId, ENT_QUOTES, 'UTF-8') ?>"
                         class="accordion-collapse collapse <?= $categoryIndex === 1 ? 'show' : '' ?>"
                         data-bs-parent="#<?= htmlspecialchars($accordionId, ENT_QUOTES, 'UTF-8') ?>">
                        <div class="accordion-body p-0">
                            <table class="table table-sm mb-0 align-middle">
                                <thead>
                                <tr>
                                    <th>Produkt</th>
                                    <th>Preis</th>
                                    <th style="width: 220px;">Menge</th>
                                    <th class="text-end">Summe</th>
                                </tr>
                                </thead>

                                <tbody>
                                <?php foreach ($categoryProducts as $product): ?>
                                    <?php
                                    $existingItem = $existingItems[(int)$product['id']] ?? null;
                                    $quantity = $existingItem ? (int)$existingItem['quantity'] : 0;
                                    $rowTotal = $existingItem ? (int)$existingItem['row_total'] : 0;
                                    $unitShort = orderPageUnitShort($product['unit']);
                                    ?>
                                    <tr class="order-product-row <?= $quantity > 0 ? 'has-selected-quantity' : '' ?>">
                                        <td>
                                            <strong><?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        </td>

                                        <td><?= orderPageFormatAriary($product['price']) ?></td>

                                        <td>
                                            <div class="input-group input-group-sm order-qty-control">
                                                <button type="button" class="btn btn-outline-secondary order-qty-button" data-step="-1">
                                                    <i class="bi bi-dash"></i>
                                                </button>

                                                <?php if ($existingItem): ?>
                                                    <input type="number"
                                                           min="0"
                                                           step="1"
                                                           class="form-control order-edit-input order-live-quantity text-center"
                                                           data-item-id="<?= (int)$existingItem['id'] ?>"
                                                           data-price="<?= (int)$product['price'] ?>"
                                                           value="<?= $quantity ?>">
                                                <?php else: ?>
                                                    <input type="number"
                                                           min="0"
                                                           step="1"
                                                           class="form-control order-add-input order-live-quantity text-center"
                                                           data-product-id="<?= (int)$product['id'] ?>"
                                                           data-price="<?= (int)$product['price'] ?>"
                                                           value="0">
                                                <?php endif; ?>

                                                <button type="button" class="btn btn-outline-secondary order-qty-button" data-step="1">
                                                    <i class="bi bi-plus"></i>
                                                </button>

                                                <span class="input-group-text"><?= htmlspecialchars($unitShort, ENT_QUOTES, 'UTF-8') ?></span>
                                            </div>
                                        </td>

                                        <td class="text-end live-row-total" data-price="<?= (int)$product['price'] ?>">
                                            <?= $rowTotal > 0 ? orderPageFormatAriary($rowTotal) : '-' ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif;

    return ob_get_clean();
}
?>

<style>
    .order-page .order-summary-box {
        border: 1px solid var(--app-border, #e2e8f0);
        background: #fff;
        border-radius: 14px;
        padding: 1rem;
        box-shadow: 0 8px 22px rgba(15, 23, 42, .055);
    }

    .order-page .order-summary-box span {
        display: block;
        color: #64748b;
        font-size: .82rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .04em;
        margin-bottom: .25rem;
    }

    .order-page .order-summary-box strong {
        display: block;
        color: #0f172a;
        font-size: 1.1rem;
    }

    .order-category-accordion .accordion-item {
        border-color: #e2e8f0;
    }

    .order-category-accordion .accordion-button {
        font-weight: 800;
        color: #0f172a;
        background: #f8fafc;
    }

    .order-category-accordion .accordion-button:not(.collapsed) {
        background: #eef6ff;
        color: #1d4ed8;
        box-shadow: none;
    }

    .category-count {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 24px;
        height: 24px;
        padding: 0 .45rem;
        border-radius: 999px;
        background: #fff;
        color: #64748b;
        font-size: .75rem;
        font-weight: 800;
    }

    .order-qty-control {
        max-width: 220px;
    }

    .order-qty-control .form-control {
        max-width: 70px;
    }

    .order-qty-button {
        min-width: 34px;
    }

    .order-product-row.has-selected-quantity td {
        background: rgba(22, 163, 74, 0.06);
    }

    .order-total-box {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: .75rem;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        padding: .9rem;
        background: #f8fafc;
    }

    .order-total-box span {
        display: block;
        color: #64748b;
        font-size: .8rem;
        margin-bottom: .15rem;
    }

    .order-total-box strong {
        display: block;
        color: #0f172a;
    }

    .order-total-box .total strong {
        color: #2563eb;
        font-size: 1.1rem;
    }

    body.dark-mode .order-page .order-summary-box,
    body.dark-mode .order-total-box {
        background: #1c232b;
        border-color: #303a45;
    }

    body.dark-mode .order-page .order-summary-box strong,
    body.dark-mode .order-total-box strong,
    body.dark-mode .order-category-accordion .accordion-button {
        color: #f8fafc;
    }

    body.dark-mode .order-category-accordion .accordion-button {
        background: #252d36;
    }

    body.dark-mode .order-category-accordion .accordion-button:not(.collapsed) {
        background: #1e3a5f;
        color: #dbeafe;
    }

    body.dark-mode .category-count {
        background: #1c232b;
        color: #dbe3ec;
    }

    @media (max-width: 992px) {
        .order-total-box {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 576px) {
        .order-total-box {
            grid-template-columns: 1fr;
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
            delay: 3000
        }).show();
    }

    function postOrderAction(formData) {
        return fetch('order.php', {
            method: 'POST',
            body: formData
        }).then(response => response.json());
    }

    function reloadAfterSuccess(result, delay = 900) {
        showToast(result.message, result.success);

        if (result.success) {
            setTimeout(() => location.reload(), delay);
        }
    }

    function orderPageFormatAriary(value) {
        return Number(Math.round(value || 0)).toLocaleString('de-DE') + ' Ariary';
    }

    function recalculateOrderPanel(panel) {
        if (!panel) {
            return;
        }

        let subtotal = 0;

        panel.querySelectorAll('tr.order-product-row').forEach(row => {
            const totalCell = row.querySelector('.live-row-total');
            const input = row.querySelector('.order-live-quantity');

            if (!totalCell || !input) {
                return;
            }

            const price = Number(totalCell.dataset.price || input.dataset.price || 0);
            const quantity = Math.max(0, Number(input.value || 0));
            const rowTotal = price * quantity;

            subtotal += rowTotal;

            totalCell.textContent = rowTotal > 0 ? orderPageFormatAriary(rowTotal) : '-';
            row.classList.toggle('has-selected-quantity', quantity > 0);
        });

        const discountPercent = Number(panel.dataset.discountPercent || 0);
        const serviceWaived = panel.dataset.serviceWaived === '1';
        const shoppingWaived = panel.dataset.shoppingWaived === '1';

        const discountAmount = subtotal * (discountPercent / 100);
        const subtotalAfterDiscount = subtotal - discountAmount;
        const serviceFee = subtotal > 0 && !serviceWaived ? 15000 : 0;
        const shoppingFee = subtotal > 0 && !shoppingWaived ? Math.round(subtotalAfterDiscount * 0.10) : 0;
        const total = subtotalAfterDiscount + serviceFee + shoppingFee;

        panel.querySelector('.live-subtotal') && (panel.querySelector('.live-subtotal').textContent = orderPageFormatAriary(subtotal));
        panel.querySelector('.live-service-fee') && (panel.querySelector('.live-service-fee').textContent = serviceFee === 0 ? 'Erlassen' : orderPageFormatAriary(serviceFee));
        panel.querySelector('.live-shopping-fee') && (panel.querySelector('.live-shopping-fee').textContent = shoppingFee === 0 ? 'Erlassen' : orderPageFormatAriary(shoppingFee));
        panel.querySelector('.live-total') && (panel.querySelector('.live-total').textContent = orderPageFormatAriary(total));

        updateSelectedCount(panel);
    }

    function updateSelectedCount(panel) {
        if (!panel) {
            return;
        }

        let selectedCount = 0;

        panel.querySelectorAll('.order-live-quantity').forEach(input => {
            if (Number(input.value || 0) > 0) {
                selectedCount++;
            }
        });

        const modal = panel.closest('.modal-content');
        const selectedCountElement = modal?.querySelector('.order-selected-count');

        if (selectedCountElement) {
            selectedCountElement.textContent = selectedCount;
        }
    }

    document.addEventListener('shown.bs.modal', function (e) {
        const panel = e.target.querySelector('.order-edit-panel');

        if (panel) {
            recalculateOrderPanel(panel);
        }
    });

    document.addEventListener('click', function (e) {
        const qtyButton = e.target.closest('.order-qty-button');
        const deleteButton = e.target.closest('.delete-order-btn');
        const saveButton = e.target.closest('.save-order-btn');
        const createButton = e.target.closest('#createOrderSubmitBtn');

        if (qtyButton) {
            const group = qtyButton.closest('.order-qty-control');
            const input = group?.querySelector('.order-live-quantity');

            if (!input) {
                return;
            }

            const step = Number(qtyButton.dataset.step || 0);
            const currentValue = Number(input.value || 0);
            const nextValue = Math.max(0, currentValue + step);

            input.value = nextValue;
            input.dispatchEvent(new Event('input', { bubbles: true }));
            return;
        }

        if (deleteButton) {
            const orderId = deleteButton.dataset.orderId;

            if (!confirm('Diese Bestellung wirklich löschen?')) {
                return;
            }

            const formData = new FormData();
            formData.append('ajax_action', 'delete_order');
            formData.append('order_id', orderId);

            postOrderAction(formData)
                .then(result => reloadAfterSuccess(result))
                .catch(() => showToast('Fehler beim Löschen.', false));
        }

        if (saveButton) {
            const orderId = saveButton.dataset.orderId;
            const modal = saveButton.closest('.modal-content');
            const panel = modal?.querySelector('.order-edit-panel');

            if (!panel) {
                showToast('Bestellung konnte nicht gefunden werden.', false);
                return;
            }

            const formData = new FormData();
            formData.append('ajax_action', 'update_order');
            formData.append('order_id', orderId);

            panel.querySelectorAll('.order-edit-input').forEach(input => {
                formData.append('items[' + input.dataset.itemId + ']', input.value);
            });

            panel.querySelectorAll('.order-add-input').forEach(input => {
                formData.append('add_quantity[' + input.dataset.productId + ']', input.value);
            });

            postOrderAction(formData)
                .then(result => reloadAfterSuccess(result, 1200))
                .catch(() => showToast('Fehler beim Aktualisieren.', false));
        }

        if (createButton) {
            const panel = document.getElementById('createOrderPanel');

            if (!panel) {
                showToast('Keine Produkte verfügbar.', false);
                return;
            }

            const formData = new FormData();
            formData.append('ajax_action', 'create_order_with_items');

            panel.querySelectorAll('.order-add-input').forEach(input => {
                formData.append('add_quantity[' + input.dataset.productId + ']', input.value);
            });

            createButton.disabled = true;

            postOrderAction(formData)
                .then(result => reloadAfterSuccess(result, 1200))
                .catch(() => {
                    createButton.disabled = false;
                    showToast('Fehler beim Anlegen.', false);
                });
        }
    });

    document.addEventListener('input', function (e) {
        const input = e.target.closest('.order-live-quantity');

        if (!input) {
            return;
        }

        input.value = Math.max(0, Number(input.value || 0));

        const panel = input.closest('.order-edit-panel');
        recalculateOrderPanel(panel);
    });
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/style/layout.php';