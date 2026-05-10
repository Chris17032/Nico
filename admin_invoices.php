<?php
require_once __DIR__ . '/config/config.php';

$currentRank = (int)$currentUser['rank'];

function jsonResponse($success, $message, $data = [])
{
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit;
}

function writeActivityLog(PDO $pdo, string $action, ?int $targetUserId = null, string $targetUserName = 'System', array $options = []): void
{
    global $currentUser;

    try {
        if (function_exists('auditLog')) {
            auditLog($pdo, $currentUser ?? null, $action, array_merge([
                'category' => 'invoice',
                'severity' => 'info',
                'target_type' => 'invoice',
                'target_id' => $options['target_id'] ?? null,
                'meta' => [
                    'target_user_id' => $targetUserId,
                    'target_user_name' => $targetUserName
                ]
            ], $options));

            return;
        }

        $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, user_name, action) VALUES (?, ?, ?)");
        $stmt->execute([$targetUserId, $targetUserName, $action]);
    } catch (Throwable $e) {
        // Logging darf die eigentliche Aktion nicht blockieren.
    }
}

function normalizeInvoiceStatus(string $status): string
{
    $status = mb_strtolower(trim($status), 'UTF-8');

    $allowed = [
        'offen',
        'teilbezahlt',
        'bezahlt',
        'überfällig',
        'storniert'
    ];

    return in_array($status, $allowed, true) ? $status : 'offen';
}

function invoiceStatusLabel(string $status): string
{
    return match (normalizeInvoiceStatus($status)) {
        'offen' => 'Offen',
        'teilbezahlt' => 'Teilbezahlt',
        'bezahlt' => 'Bezahlt',
        'überfällig' => 'Überfällig',
        'storniert' => 'Storniert',
        default => 'Offen',
    };
}

function invoiceStatusBadgeClass(string $status): string
{
    return match (normalizeInvoiceStatus($status)) {
        'offen' => 'bg-warning text-dark',
        'teilbezahlt' => 'bg-info text-dark',
        'bezahlt' => 'bg-success',
        'überfällig' => 'bg-danger',
        'storniert' => 'bg-secondary',
        default => 'bg-secondary',
    };
}

function calculateInvoiceTotals($subtotal, $serviceFeeWaived, $shoppingFeeWaived)
{
    $serviceFee = ($subtotal > 0 && !$serviceFeeWaived) ? 15000 : 0;
    $shoppingFee = ($subtotal > 0 && !$shoppingFeeWaived) ? (int)round($subtotal * 0.10) : 0;
    $total = $subtotal + $serviceFee + $shoppingFee;

    return [
        'subtotal' => (int)$subtotal,
        'service_fee' => (int)$serviceFee,
        'shopping_fee' => (int)$shoppingFee,
        'total' => (int)$total
    ];
}

function autoMarkOverdueInvoices(PDO $pdo): void
{
    $stmt = $pdo->prepare("
        SELECT id, invoice_number, status, user_id, created_at
        FROM invoices
        WHERE status IN ('offen', 'teilbezahlt')
        AND created_at <= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ");
    $stmt->execute();
    $invoices = $stmt->fetchAll();

    foreach ($invoices as $invoice) {
        $oldStatus = normalizeInvoiceStatus((string)$invoice['status']);

        $update = $pdo->prepare("
            UPDATE invoices
            SET status = 'überfällig'
            WHERE id = ?
        ");
        $update->execute([(int)$invoice['id']]);

        writeActivityLog(
            $pdo,
            'Rechnungsstatus automatisch geändert: ' .
            $invoice['invoice_number'] .
            ' von "' . invoiceStatusLabel($oldStatus) . '" zu "Überfällig"',
            (int)$invoice['user_id'],
            'System',
            [
                'severity' => 'warning',
                'target_type' => 'invoice',
                'target_id' => (int)$invoice['id'],
                'meta' => [
                    'invoice_id' => (int)$invoice['id'],
                    'invoice_number' => $invoice['invoice_number'],
                    'old_status' => $oldStatus,
                    'new_status' => 'überfällig',
                    'created_at' => $invoice['created_at']
                ]
            ]
        );
    }
}

$statusOptions = [
    'offen',
    'teilbezahlt',
    'bezahlt',
    'überfällig',
    'storniert'
];

autoMarkOverdueInvoices($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {

    if ($_POST['ajax_action'] === 'delete_invoice') {
        $invoiceId = (int)($_POST['invoice_id'] ?? 0);

        if ($invoiceId <= 0) {
            jsonResponse(false, 'Ungültige Rechnung.');
        }

        $stmt = $pdo->prepare("SELECT id, invoice_number, user_id, order_group_id, order_date_id, subtotal, service_fee, shopping_fee, total, status FROM invoices WHERE id = ? LIMIT 1");
        $stmt->execute([$invoiceId]);
        $invoiceBeforeDelete = $stmt->fetch();

        if (!$invoiceBeforeDelete) {
            jsonResponse(false, 'Rechnung wurde nicht gefunden.');
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("DELETE FROM invoice_items WHERE invoice_id = ?");
            $stmt->execute([$invoiceId]);

            $stmt = $pdo->prepare("DELETE FROM invoices WHERE id = ?");
            $stmt->execute([$invoiceId]);

            $pdo->commit();

            writeActivityLog(
                $pdo,
                'Rechnung gelöscht: ' . $invoiceBeforeDelete['invoice_number'],
                (int)$invoiceBeforeDelete['user_id'],
                'System',
                [
                    'severity' => 'danger',
                    'target_type' => 'invoice',
                    'target_id' => $invoiceId,
                    'meta' => $invoiceBeforeDelete
                ]
            );

            jsonResponse(true, 'Rechnung wurde gelöscht.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            jsonResponse(false, 'Fehler beim Löschen der Rechnung.');
        }
    }

    if ($_POST['ajax_action'] === 'update_invoice') {
        $invoiceId = (int)($_POST['invoice_id'] ?? 0);
        $invoiceNumber = trim($_POST['invoice_number'] ?? '');
        $status = normalizeInvoiceStatus($_POST['status'] ?? 'offen');
        $note = trim($_POST['note'] ?? '');
        $serviceFeeWaived = (int)($_POST['service_fee_waived'] ?? 0) === 1;
        $shoppingFeeWaived = (int)($_POST['shopping_fee_waived'] ?? 0) === 1;

        if ($invoiceId <= 0 || $invoiceNumber === '') {
            jsonResponse(false, 'Ungültige Rechnung.');
        }

        $stmt = $pdo->prepare("
            SELECT 
                id,
                user_id,
                invoice_number,
                public_token,
                status,
                note,
                service_fee_waived,
                shopping_fee_waived,
                subtotal,
                service_fee,
                shopping_fee,
                total
            FROM invoices
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$invoiceId]);
        $oldInvoice = $stmt->fetch();

        if (!$oldInvoice) {
            jsonResponse(false, 'Rechnung wurde nicht gefunden.');
        }

        $oldStatus = normalizeInvoiceStatus((string)$oldInvoice['status']);

        try {
            $pdo->beginTransaction();

            foreach ($_POST['items'] ?? [] as $itemId => $itemData) {
                $itemId = (int)$itemId;
                $quantity = max(0, (int)($itemData['quantity'] ?? 0));
                $price = max(0, (int)($itemData['price'] ?? 0));
                $rowTotal = $quantity * $price;

                $stmt = $pdo->prepare("
                    UPDATE invoice_items
                    SET quantity = ?, price = ?, row_total = ?
                    WHERE id = ? AND invoice_id = ?
                ");
                $stmt->execute([$quantity, $price, $rowTotal, $itemId, $invoiceId]);
            }

            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(row_total), 0) AS subtotal
                FROM invoice_items
                WHERE invoice_id = ?
            ");
            $stmt->execute([$invoiceId]);
            $subtotal = (int)$stmt->fetch()['subtotal'];

            $totals = calculateInvoiceTotals($subtotal, $serviceFeeWaived, $shoppingFeeWaived);

            $stmt = $pdo->prepare("
                UPDATE invoices
                SET 
                    invoice_number = ?,
                    status = ?,
                    note = ?,
                    service_fee_waived = ?,
                    shopping_fee_waived = ?,
                    subtotal = ?,
                    service_fee = ?,
                    shopping_fee = ?,
                    total = ?
                WHERE id = ?
            ");

            $stmt->execute([
                $invoiceNumber,
                $status,
                $note,
                $serviceFeeWaived ? 1 : 0,
                $shoppingFeeWaived ? 1 : 0,
                $totals['subtotal'],
                $totals['service_fee'],
                $totals['shopping_fee'],
                $totals['total'],
                $invoiceId
            ]);

            if ($oldStatus !== $status) {
                writeActivityLog(
                    $pdo,
                    'Rechnungsstatus geändert: ' .
                    $oldInvoice['invoice_number'] .
                    ' von "' . invoiceStatusLabel($oldStatus) . '" zu "' . invoiceStatusLabel($status) . '"',
                    (int)$oldInvoice['user_id'],
                    'System',
                    [
                        'severity' => 'warning',
                        'target_type' => 'invoice',
                        'target_id' => $invoiceId,
                        'meta' => [
                            'invoice_id' => $invoiceId,
                            'invoice_number' => $oldInvoice['invoice_number'],
                            'old_status' => $oldStatus,
                            'new_status' => $status
                        ]
                    ]
                );
            }

            $notificationMessages = [];

            if ($oldInvoice['invoice_number'] !== $invoiceNumber) {
                $notificationMessages[] = 'Die Rechnungsnummer wurde geändert.';
            }

            if ($oldStatus !== $status) {
                $notificationMessages[] = 'Der Status deiner Rechnung wurde auf "' . invoiceStatusLabel($status) . '" geändert.';
            }

            if ((int)$oldInvoice['total'] !== (int)$totals['total']) {
                $notificationMessages[] = 'Der Rechnungsbetrag wurde aktualisiert.';
            }

            if ($notificationMessages) {
                $stmt = $pdo->prepare("
                    INSERT INTO notifications (user_id, type, title, message, link, created_at)
                    VALUES (?, 'invoice_updated', ?, ?, ?, NOW())
                ");

                $stmt->execute([
                    (int)$oldInvoice['user_id'],
                    'Rechnung wurde aktualisiert',
                    implode(' ', $notificationMessages),
                    'invoice.php?token=' . $oldInvoice['public_token']
                ]);
            }

            writeActivityLog($pdo, 'Rechnung gespeichert: ' . $invoiceNumber, (int)$oldInvoice['user_id'], 'System', [
                'severity' => 'success',
                'target_type' => 'invoice',
                'target_id' => $invoiceId,
                'meta' => [
                    'old' => $oldInvoice,
                    'new' => [
                        'invoice_number' => $invoiceNumber,
                        'status' => $status,
                        'note' => $note,
                        'service_fee_waived' => $serviceFeeWaived ? 1 : 0,
                        'shopping_fee_waived' => $shoppingFeeWaived ? 1 : 0,
                        'subtotal' => $totals['subtotal'],
                        'service_fee' => $totals['service_fee'],
                        'shopping_fee' => $totals['shopping_fee'],
                        'total' => $totals['total']
                    ]
                ]
            ]);

            $pdo->commit();

            jsonResponse(true, 'Rechnung wurde aktualisiert.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            jsonResponse(false, 'Fehler beim Aktualisieren der Rechnung.');
        }
    }

    jsonResponse(false, 'Unbekannte Aktion.');
}

$token = trim($_GET['token'] ?? '');

if ($token !== '') {
    $pageTitle = 'Rechnung bearbeiten';

    $stmt = $pdo->prepare("
        SELECT 
            i.*,
            u.family_name,
            od.title AS order_date_title,
            od.deadline_at
        FROM invoices i
        INNER JOIN users u ON u.id = i.user_id
        INNER JOIN order_dates od ON od.id = i.order_date_id
        WHERE i.public_token = ?
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $invoice = $stmt->fetch();

    if (!$invoice) {
        http_response_code(404);
        exit('Rechnung nicht gefunden.');
    }

    $invoice['status'] = normalizeInvoiceStatus((string)$invoice['status']);

    $stmt = $pdo->prepare("
        SELECT 
            ii.*,
            COALESCE(pc.name, 'Ohne Kategorie') AS category_name
        FROM invoice_items ii
        LEFT JOIN products p ON p.id = ii.product_id
        LEFT JOIN product_categories pc ON pc.id = p.category_id
        WHERE ii.invoice_id = ?
        ORDER BY 
            CASE WHEN pc.name IS NULL THEN 1 ELSE 0 END ASC,
            COALESCE(pc.name, 'Ohne Kategorie') ASC,
            ii.product_name ASC
    ");
    $stmt->execute([(int)$invoice['id']]);
    $items = $stmt->fetchAll();

    $itemsByCategory = [];

    foreach ($items as $item) {
        $categoryName = trim((string)($item['category_name'] ?? ''));

        if ($categoryName === '') {
            $categoryName = 'Ohne Kategorie';
        }

        $itemsByCategory[$categoryName][] = $item;
    }

    ob_start();
    ?>

    <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 9999;">
        <div id="appToast" class="toast align-items-center text-bg-success border-0" role="alert">
            <div class="d-flex">
                <div class="toast-body" id="toastMessage">Aktion erfolgreich.</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>
    </div>

    <div class="mb-4 d-flex justify-content-between align-items-start gap-3 flex-wrap">
        <div>
            <h1 class="h3 mb-1">Rechnung <?= htmlspecialchars($invoice['invoice_number']) ?></h1>
            <p class="text-muted mb-0">
                <?= htmlspecialchars($invoice['family_name']) ?> ·
                <?= htmlspecialchars($invoice['order_date_title']) ?>
            </p>
        </div>
        
<div class="d-flex gap-2 flex-wrap">

    <button type="button"
            class="btn btn-success btn-save-invoice-top"
            data-invoice-id="<?= (int)$invoice['id'] ?>">
        <i class="bi bi-save me-1"></i>Speichern
    </button>

    <a href="invoice_pdf.php?token=<?= htmlspecialchars($invoice['public_token']) ?>"
       target="_blank"
       class="btn btn-primary">
        <i class="bi bi-file-earmark-pdf me-1"></i>PDF anzeigen
    </a>

    <a href="admin_invoices.php" class="btn btn-secondary">
        <i class="bi bi-arrow-left me-1"></i>Zurück
    </a>

</div>
    </div>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header">
            <h5 class="mb-0">
                <i class="bi bi-receipt me-2"></i>Rechnungsdetails
            </h5>
        </div>

        <div class="card-body">
            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <strong>Rechnungsnummer:</strong><br>
                    <input type="text" class="form-control form-control-sm invoice-number"
                           value="<?= htmlspecialchars($invoice['invoice_number']) ?>">
                </div>

                <div class="col-md-3">
                    <strong>Benutzer</strong><br>
                    <?= htmlspecialchars($invoice['family_name']) ?>
                </div>

                <div class="col-md-3">
                    <strong>Status:</strong><br>

                    <select class="form-select form-select-sm invoice-status">
                        <?php foreach ($statusOptions as $option): ?>
                            <option value="<?= htmlspecialchars($option) ?>" <?= $invoice['status'] === $option ? 'selected' : '' ?>>
                                <?= htmlspecialchars(invoiceStatusLabel($option)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <span class="badge mt-2 <?= invoiceStatusBadgeClass($invoice['status']) ?>">
                        <?= htmlspecialchars(invoiceStatusLabel($invoice['status'])) ?>
                    </span>
                </div>

                <div class="col-md-3">
                    <strong>Erstellt:</strong><br>
                    <?= date('d.m.Y H:i', strtotime($invoice['created_at'])) ?>
                </div>
            </div>

            <div class="alert alert-info">
                <strong>Hinweis:</strong>
                Änderungen an Menge oder Preis betreffen nur diese Rechnung. Die ursprüngliche Bestellung bleibt unverändert.
            </div>

            <table class="table table-hover align-middle">
                <thead>
                <tr>
                    <th>Produkt</th>
                    <th style="width: 180px;">Preis</th>
                    <th style="width: 160px;">Bestellt</th>
                    <th style="width: 180px;">Abgerechnet</th>
                    <th class="text-end">Summe</th>
                </tr>
                </thead>

                <tbody>
                <?php foreach ($itemsByCategory as $categoryName => $categoryItems): ?>
                    <tr class="invoice-category-row">
                        <td colspan="5">
                            <i class="bi bi-tag me-2"></i><?= htmlspecialchars(mb_strtoupper($categoryName, 'UTF-8')) ?>
                        </td>
                    </tr>

                    <?php foreach ($categoryItems as $item): ?>
                        <?php $unitShort = $item['unit'] === 'Kilogramm' ? 'Kg' : 'Stück'; ?>

                        <tr class="invoice-item-row">
                            <td><?= htmlspecialchars($item['product_name']) ?></td>

                            <td>
                                <div class="input-group input-group-sm">
                                    <input type="number" min="0" step="1" class="form-control invoice-item-price"
                                           data-item-id="<?= (int)$item['id'] ?>"
                                           value="<?= (int)$item['price'] ?>">
                                    <span class="input-group-text">Ar</span>
                                </div>
                            </td>

                            <td>
                                <?= (int)($item['original_quantity'] ?? $item['quantity']) ?>
                                <?= htmlspecialchars($unitShort) ?>
                            </td>

                            <td>
                                <div class="input-group input-group-sm">
                                    <input type="number" min="0" step="1" class="form-control invoice-item-quantity"
                                           data-item-id="<?= (int)$item['id'] ?>"
                                           value="<?= (int)$item['quantity'] ?>">
                                    <span class="input-group-text"><?= htmlspecialchars($unitShort) ?></span>
                                </div>
                            </td>

                            <td class="text-end invoice-row-total" data-item-id="<?= (int)$item['id'] ?>">
                                <?= number_format((int)$item['row_total'], 0, ',', '.') ?> Ariary
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>

                <?php if (!$items): ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">
                            Keine Positionen vorhanden.
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>

            <div class="row g-3 mt-3">
                <div class="col-md-3">
                    <strong>Zwischensumme:</strong><br>
                    <span id="invoiceSubtotal">
                        <?= number_format((int)$invoice['subtotal'], 0, ',', '.') ?> Ariary
                    </span>
                </div>

                <div class="col-md-3">
                    <strong>Servicegebühr:</strong><br>
                    <span id="invoiceServiceFee">
                        <?= (int)$invoice['service_fee'] === 0 ? 'Erlassen' : number_format((int)$invoice['service_fee'], 0, ',', '.') . ' Ariary' ?>
                    </span>

                    <div class="form-check mt-2">
                        <input class="form-check-input invoice-service-waived" type="checkbox" value="1"
                               id="serviceFeeWaived" <?= (int)($invoice['service_fee_waived'] ?? 0) === 1 || (int)$invoice['service_fee'] === 0 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="serviceFeeWaived">
                            Servicegebühr erlassen
                        </label>
                    </div>
                </div>

                <div class="col-md-3">
                    <strong>Einkaufsgebühr:</strong><br>
                    <span id="invoiceShoppingFee">
                        <?= (int)$invoice['shopping_fee'] === 0 ? 'Erlassen' : number_format((int)$invoice['shopping_fee'], 0, ',', '.') . ' Ariary' ?>
                    </span>

                    <div class="form-check mt-2">
                        <input class="form-check-input invoice-shopping-waived" type="checkbox" value="1"
                               id="shoppingFeeWaived" <?= (int)($invoice['shopping_fee_waived'] ?? 0) === 1 || (int)$invoice['shopping_fee'] === 0 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="shoppingFeeWaived">
                            Einkaufsgebühr erlassen
                        </label>
                    </div>
                </div>

                <div class="col-md-3">
                    <strong>Gesamt:</strong><br>
                    <span class="fs-5 text-primary" id="invoiceTotal">
                        <?= number_format((int)$invoice['total'], 0, ',', '.') ?> Ariary
                    </span>
                </div>
            </div>

            <div class="mt-4">
                <label class="form-label">Notiz</label>
                <textarea class="form-control invoice-note"
                          rows="3"><?= htmlspecialchars($invoice['note'] ?? '') ?></textarea>

                <button class="btn btn-success mt-3 btn-save-invoice" data-invoice-id="<?= (int)$invoice['id'] ?>">
                    <i class="bi bi-save me-1"></i>Speichern
                </button>
            </div>
        </div>
    </div>

    <style>
        .invoice-category-row td {
            background: #eef2f7 !important;
            color: #0f172a;
            border-top: 2px solid #cbd5e1;
            border-bottom: 1px solid #cbd5e1;
            font-weight: 800;
            letter-spacing: .04em;
            text-transform: uppercase;
            font-size: .82rem;
        }

        .invoice-item-row td:first-child {
            padding-left: 1.25rem;
        }

        body.dark-mode .invoice-category-row td {
            background: #1f2937 !important;
            color: #fff !important;
            border-color: #475569 !important;
        }
    </style>

<script>
    function formatAriary(value) {
        return Number(value).toLocaleString('de-DE') + ' Ariary';
    }

    function showToast(message, success = true) {
        const toastElement = document.getElementById('appToast');
        const toastMessage = document.getElementById('toastMessage');

        toastElement.classList.remove('text-bg-success', 'text-bg-danger');
        toastElement.classList.add(success ? 'text-bg-success' : 'text-bg-danger');

        toastMessage.textContent = message;

        new bootstrap.Toast(toastElement, {
            delay: 3000
        }).show();
    }

    function recalculateInvoicePreview() {
        let subtotal = 0;

        document.querySelectorAll('.invoice-row-total').forEach(totalCell => {

            const row = totalCell.closest('tr');

            const priceInput = row.querySelector('.invoice-item-price');
            const quantityInput = row.querySelector('.invoice-item-quantity');

            if (!priceInput || !quantityInput) {
                return;
            }

            const price = Number(priceInput.value || 0);
            const quantity = Number(quantityInput.value || 0);

            const rowTotal = price * quantity;

            subtotal += rowTotal;

            totalCell.textContent = formatAriary(rowTotal);
        });

        const serviceWaived =
            document.querySelector('.invoice-service-waived')?.checked ?? false;

        const shoppingWaived =
            document.querySelector('.invoice-shopping-waived')?.checked ?? false;

        const serviceFee =
            subtotal > 0 && !serviceWaived
                ? 15000
                : 0;

        const shoppingFee =
            subtotal > 0 && !shoppingWaived
                ? Math.round(subtotal * 0.10)
                : 0;

        const total =
            subtotal +
            serviceFee +
            shoppingFee;

        document.getElementById('invoiceSubtotal').textContent =
            formatAriary(subtotal);

        document.getElementById('invoiceServiceFee').textContent =
            serviceFee === 0
                ? 'Erlassen'
                : formatAriary(serviceFee);

        document.getElementById('invoiceShoppingFee').textContent =
            shoppingFee === 0
                ? 'Erlassen'
                : formatAriary(shoppingFee);

        document.getElementById('invoiceTotal').textContent =
            formatAriary(total);
    }

    document.addEventListener('input', function (e) {

        if (
            e.target.closest('.invoice-item-price') ||
            e.target.closest('.invoice-item-quantity')
        ) {
            recalculateInvoicePreview();
        }
    });

    document.addEventListener('change', function (e) {

        if (
            e.target.closest('.invoice-service-waived') ||
            e.target.closest('.invoice-shopping-waived')
        ) {
            recalculateInvoicePreview();
        }
    });

    function saveInvoice() {

        const invoiceId =
            document.querySelector('.btn-save-invoice')?.dataset.invoiceId
            ||
            document.querySelector('.btn-save-invoice-top')?.dataset.invoiceId;

        const formData = new FormData();

        formData.append('ajax_action', 'update_invoice');

        formData.append(
            'invoice_id',
            invoiceId
        );

        formData.append(
            'invoice_number',
            document.querySelector('.invoice-number').value
        );

        formData.append(
            'status',
            document.querySelector('.invoice-status').value
        );

        formData.append(
            'note',
            document.querySelector('.invoice-note').value
        );

        formData.append(
            'service_fee_waived',
            document.querySelector('.invoice-service-waived')?.checked
                ? 1
                : 0
        );

        formData.append(
            'shopping_fee_waived',
            document.querySelector('.invoice-shopping-waived')?.checked
                ? 1
                : 0
        );

        document.querySelectorAll('.invoice-item-quantity').forEach(input => {

            const itemId = input.dataset.itemId;

            formData.append(
                'items[' + itemId + '][quantity]',
                input.value
            );
        });

        document.querySelectorAll('.invoice-item-price').forEach(input => {

            const itemId = input.dataset.itemId;

            formData.append(
                'items[' + itemId + '][price]',
                input.value
            );
        });

        fetch('admin_invoices.php', {
            method: 'POST',
            body: formData
        })
            .then(response => response.json())
            .then(result => {

                showToast(
                    result.message,
                    result.success
                );

                if (result.success) {

                    setTimeout(() => {
                        location.reload();
                    }, 1000);
                }
            })
            .catch(() => {
                showToast(
                    'Fehler beim Speichern.',
                    false
                );
            });
    }

    document.querySelector('.btn-save-invoice')
        ?.addEventListener('click', saveInvoice);

    document.querySelector('.btn-save-invoice-top')
        ?.addEventListener('click', saveInvoice);
</script>

    <?php
    $content = ob_get_clean();
    require_once __DIR__ . '/style/layout.php';
    exit;
}

$pageTitle = 'Alle Rechnungen';

$search = trim($_GET['q'] ?? '');
$statusFilterRaw = trim($_GET['status'] ?? '');
$hasStatusFilter = $statusFilterRaw !== '';
$statusFilter = normalizeInvoiceStatus($statusFilterRaw);

$sql = "
    SELECT 
        i.*,
        u.family_name,
        od.title AS order_date_title
    FROM invoices i
    INNER JOIN users u ON u.id = i.user_id
    INNER JOIN order_dates od ON od.id = i.order_date_id
    WHERE 1 = 1
";

$params = [];

if ($search !== '') {
    $sql .= " AND (i.invoice_number LIKE ? OR u.family_name LIKE ?)";
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

if ($hasStatusFilter) {
    $sql .= " AND i.status = ?";
    $params[] = $statusFilter;
}

$sql .= " ORDER BY i.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$invoices = $stmt->fetchAll();

ob_start();
?>

<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 9999;">
    <div id="appToast" class="toast align-items-center text-bg-success border-0" role="alert">
        <div class="d-flex">
            <div class="toast-body" id="toastMessage">Aktion erfolgreich.</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<div class="mb-4">
    <h1 class="h3 mb-1">Alle Rechnungen</h1>
    <p class="text-muted mb-0">Hier verwaltest du alle Rechnungen und deren Zahlungsstatus.</p>
</div>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-body">
        <form method="get" class="row g-3">
            <div class="col-md-7">
                <label class="form-label">Suche</label>
                <input type="text" name="q" class="form-control"
                       placeholder="Benutzername oder Rechnungsnummer suchen..."
                       value="<?= htmlspecialchars($search) ?>">
            </div>

            <div class="col-md-3">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="">Alle Status</option>

                    <?php foreach ($statusOptions as $option): ?>
                        <option value="<?= htmlspecialchars($option) ?>" <?= $hasStatusFilter && $statusFilter === $option ? 'selected' : '' ?>>
                            <?= htmlspecialchars(invoiceStatusLabel($option)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2 d-flex align-items-end">
                <button class="btn btn-primary w-100" type="submit">
                    <i class="bi bi-search me-1"></i>Suchen
                </button>
            </div>
        </form>

        <?php if ($search !== '' || $hasStatusFilter): ?>
            <div class="mt-3">
                <a href="admin_invoices.php" class="btn btn-secondary btn-sm">
                    Filter zurücksetzen
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="card-header">
        <h5 class="mb-0">
            <i class="bi bi-receipt me-2"></i>Rechnungsliste
        </h5>
    </div>

    <div class="card-body p-0">
        <table class="table table-hover mb-0 align-middle">
            <thead>
            <tr>
                <th>Nummer</th>
                <th>Benutzer</th>
                <th>Termin</th>
                <th>Status</th>
                <th>Gesamt</th>
                <th>Erstellt</th>
                <th style="width: 170px;">Aktion</th>
            </tr>
            </thead>

            <tbody>
            <?php foreach ($invoices as $invoice): ?>
                <?php $currentStatus = normalizeInvoiceStatus((string)$invoice['status']); ?>

                <tr data-id="<?= (int)$invoice['id'] ?>">
                    <td><?= htmlspecialchars($invoice['invoice_number']) ?></td>
                    <td><?= htmlspecialchars($invoice['family_name']) ?></td>
                    <td><?= htmlspecialchars($invoice['order_date_title']) ?></td>

                    <td>
                        <span class="badge <?= invoiceStatusBadgeClass($currentStatus) ?>">
                            <?= htmlspecialchars(invoiceStatusLabel($currentStatus)) ?>
                        </span>
                    </td>

                    <td><?= number_format((int)$invoice['total'], 0, ',', '.') ?> Ariary</td>
                    <td><?= date('d.m.Y H:i', strtotime($invoice['created_at'])) ?></td>

                    <td>
                        <a href="admin_invoices.php?token=<?= htmlspecialchars($invoice['public_token']) ?>"
                           class="btn btn-info btn-sm" title="In Admin-Rechnung öffnen">
                            <i class="bi bi-eye"></i>
                        </a>

                        <a href="invoice_pdf.php?token=<?= htmlspecialchars($invoice['public_token']) ?>"
                           target="_blank" class="btn btn-primary btn-sm" title="PDF anzeigen">
                            <i class="bi bi-file-earmark-pdf"></i>
                        </a>

                        <button type="button" class="btn btn-danger btn-sm btn-delete-invoice-list"
                                data-invoice-id="<?= (int)$invoice['id'] ?>" title="Löschen">
                            <i class="bi bi-trash"></i>
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php if (!$invoices): ?>
                <tr>
                    <td colspan="7" class="text-center text-muted py-4">
                        Keine Rechnungen gefunden.
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    function showToast(message, success = true) {
        const toastElement = document.getElementById('appToast');
        const toastMessage = document.getElementById('toastMessage');

        toastElement.classList.remove('text-bg-success', 'text-bg-danger');
        toastElement.classList.add(success ? 'text-bg-success' : 'text-bg-danger');
        toastMessage.textContent = message;

        new bootstrap.Toast(toastElement, {delay: 3000}).show();
    }

    document.querySelectorAll('.btn-delete-invoice-list').forEach(button => {
        button.addEventListener('click', function () {
            const invoiceId = this.dataset.invoiceId;
            const row = this.closest('tr');

            if (!confirm('Diese Rechnung wirklich löschen?')) {
                return;
            }

            const formData = new FormData();
            formData.append('ajax_action', 'delete_invoice');
            formData.append('invoice_id', invoiceId);

            fetch('admin_invoices.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(result => {
                    showToast(result.message, result.success);

                    if (result.success) {
                        setTimeout(() => {
                            row.remove();
                        }, 700);
                    }
                })
                .catch(() => showToast('Fehler beim Löschen.', false));
        });
    });
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/style/layout.php';