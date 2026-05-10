<?php
require_once __DIR__ . '/config/config.php';

$currentUser = requireLogin($pdo);
$currentRank = (int)($currentUser['rank'] ?? 0);
$userId = (int)$currentUser['id'];
$canAdminView = $currentRank > 4;

$pageTitle = 'Meine Rechnungen';

function invoiceJsonResponse($success, $message, $data = [])
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => (bool)$success,
        'message' => (string)$message,
        'data' => $data
    ]);
    exit;
}

function invoiceStatusNormalize(string $status): string
{
    $status = mb_strtolower(trim($status), 'UTF-8');

    return in_array($status, ['offen', 'teilbezahlt', 'bezahlt', 'überfällig', 'storniert'], true)
        ? $status
        : 'offen';
}

function invoiceStatusLabel(string $status): string
{
    return match (invoiceStatusNormalize($status)) {
        'teilbezahlt' => 'Teilbezahlt',
        'bezahlt' => 'Bezahlt',
        'überfällig' => 'Überfällig',
        'storniert' => 'Storniert',
        default => 'Offen',
    };
}

function invoiceStatusBadgeClass(string $status): string
{
    return match (invoiceStatusNormalize($status)) {
        'teilbezahlt' => 'bg-info text-dark',
        'bezahlt' => 'bg-success',
        'überfällig' => 'bg-danger',
        'storniert' => 'bg-secondary',
        default => 'bg-warning text-dark',
    };
}

function invoiceFormatAriary($value): string
{
    return number_format((int)$value, 0, ',', '.') . ' Ariary';
}

function invoiceAuditLog(PDO $pdo, ?array $actor, string $action, array $options = []): void
{
    try {
        if (function_exists('auditLog')) {
            auditLog($pdo, $actor, $action, array_merge([
                'category' => 'invoice',
                'severity' => 'info'
            ], $options));
        }
    } catch (Throwable $e) {
        // Logging darf die eigentliche Aktion nicht blockieren.
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    if ($_POST['ajax_action'] === 'report_payment') {
        $invoiceId = (int)($_POST['invoice_id'] ?? 0);

        if ($invoiceId <= 0) {
            invoiceJsonResponse(false, 'Ungültige Rechnung.');
        }

        $stmt = $pdo->prepare("\n            SELECT \n                i.id,\n                i.invoice_number,\n                i.public_token,\n                i.status,\n                i.payment_reported_at,\n                i.user_id,\n                u.family_name\n            FROM invoices i\n            INNER JOIN users u ON u.id = i.user_id\n            WHERE i.id = ?\n            AND i.user_id = ?\n            LIMIT 1\n        ");
        $stmt->execute([$invoiceId, $userId]);
        $invoicePayment = $stmt->fetch();

        if (!$invoicePayment) {
            invoiceJsonResponse(false, 'Rechnung wurde nicht gefunden.');
        }

        $status = invoiceStatusNormalize((string)$invoicePayment['status']);

        if (in_array($status, ['bezahlt', 'storniert'], true)) {
            invoiceJsonResponse(false, 'Diese Rechnung kann nicht mehr gemeldet werden.');
        }

        if (!empty($invoicePayment['payment_reported_at'])) {
            invoiceJsonResponse(false, 'Die Zahlung wurde bereits gemeldet.');
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("UPDATE invoices SET payment_reported_at = NOW() WHERE id = ?");
            $stmt->execute([$invoiceId]);

            $stmt = $pdo->prepare("SELECT id FROM users WHERE rank > 4 AND is_locked = 0");
            $stmt->execute();
            $adminIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $title = 'Bezahlung prüfen';
            $message = 'Bestellung/Rechnung ' . $invoicePayment['invoice_number'] . ' prüfen - Bezahlung erfolgt?';
            $link = 'invoice.php?token=' . $invoicePayment['public_token'];

            $stmt = $pdo->prepare("\n                INSERT INTO notifications (user_id, type, title, message, link, created_at)\n                VALUES (?, 'payment_reported', ?, ?, ?, NOW())\n            ");

            foreach ($adminIds as $adminId) {
                $stmt->execute([(int)$adminId, $title, $message, $link]);
            }

            $pdo->commit();

            invoiceAuditLog($pdo, $currentUser, 'Zahlung gemeldet: ' . $invoicePayment['invoice_number'], [
                'severity' => 'success',
                'target_type' => 'invoice',
                'target_id' => $invoiceId,
                'meta' => [
                    'invoice_id' => $invoiceId,
                    'invoice_number' => $invoicePayment['invoice_number'],
                    'status' => $status,
                    'admin_notifications_sent' => count($adminIds)
                ]
            ]);

            invoiceJsonResponse(true, 'Danke, die Admins wurden benachrichtigt.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            invoiceAuditLog($pdo, $currentUser, 'Fehler beim Melden einer Zahlung', [
                'severity' => 'danger',
                'target_type' => 'invoice',
                'target_id' => $invoiceId,
                'meta' => [
                    'error' => $e->getMessage()
                ]
            ]);

            invoiceJsonResponse(false, 'Fehler beim Senden der Benachrichtigung.');
        }
    }

    invoiceJsonResponse(false, 'Unbekannte Aktion.');
}

$token = trim($_GET['token'] ?? '');

if ($token !== '') {
    $sql = "\n        SELECT \n            i.*,\n            u.family_name,\n            od.title AS order_date_title,\n            od.deadline_at\n        FROM invoices i\n        INNER JOIN users u ON u.id = i.user_id\n        INNER JOIN order_dates od ON od.id = i.order_date_id\n        WHERE i.public_token = ?\n    ";

    $params = [$token];

    if (!$canAdminView) {
        $sql .= " AND i.user_id = ?";
        $params[] = $userId;
    }

    $sql .= " LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $invoice = $stmt->fetch();

    if (!$invoice) {
        http_response_code(404);
        exit('Rechnung nicht gefunden.');
    }

    $invoice['status'] = invoiceStatusNormalize((string)$invoice['status']);

    $stmt = $pdo->prepare("\n        SELECT \n            ii.*,\n            COALESCE(pc.name, 'Ohne Kategorie') AS category_name\n        FROM invoice_items ii\n        LEFT JOIN products p ON p.id = ii.product_id\n        LEFT JOIN product_categories pc ON pc.id = p.category_id\n        WHERE ii.invoice_id = ?\n        ORDER BY \n            CASE WHEN pc.name IS NULL THEN 1 ELSE 0 END ASC,\n            COALESCE(pc.name, 'Ohne Kategorie') ASC,\n            ii.product_name ASC\n    ");
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
            <a href="invoice_pdf.php?token=<?= htmlspecialchars($invoice['public_token']) ?>" target="_blank" class="btn btn-primary">
                <i class="bi bi-file-earmark-pdf me-1"></i>PDF anzeigen
            </a>

            <?php if ((int)$invoice['user_id'] === $userId && !in_array($invoice['status'], ['bezahlt', 'storniert'], true)): ?>
                <button type="button" class="btn btn-success btn-report-payment" data-invoice-id="<?= (int)$invoice['id'] ?>"
                    <?= !empty($invoice['payment_reported_at']) ? 'disabled' : '' ?>>
                    <i class="bi bi-check2-circle me-1"></i>
                    <?= !empty($invoice['payment_reported_at']) ? 'Zahlung gemeldet' : 'Bezahlt' ?>
                </button>
            <?php endif; ?>

            <a href="<?= $canAdminView ? 'admin_invoices.php' : 'invoice.php' ?>" class="btn btn-secondary">
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
                    <?= htmlspecialchars($invoice['invoice_number']) ?>
                </div>

                <div class="col-md-3">
                    <strong>Familie:</strong><br>
                    <?= htmlspecialchars($invoice['family_name']) ?>
                </div>

                <div class="col-md-3">
                    <strong>Status:</strong><br>
                    <span class="badge <?= invoiceStatusBadgeClass($invoice['status']) ?>">
                        <?= htmlspecialchars(invoiceStatusLabel($invoice['status'])) ?>
                    </span>
                </div>

                <div class="col-md-3">
                    <strong>Erstellt:</strong><br>
                    <?= date('d.m.Y H:i', strtotime($invoice['created_at'])) ?>
                </div>
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
                                <td><?= invoiceFormatAriary($item['price']) ?></td>
                                <td>
                                    <?= (int)($item['original_quantity'] ?? $item['quantity']) ?>
                                    <?= htmlspecialchars($unitShort) ?>
                                </td>
                                <td><?= (int)$item['quantity'] ?> <?= htmlspecialchars($unitShort) ?></td>
                                <td class="text-end"><?= invoiceFormatAriary($item['row_total']) ?></td>
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
                    <?= invoiceFormatAriary($invoice['subtotal']) ?>
                </div>

                <div class="col-md-3">
                    <strong>Servicegebühr:</strong><br>
                    <?= (int)$invoice['service_fee'] === 0 ? 'Erlassen' : invoiceFormatAriary($invoice['service_fee']) ?>
                </div>

                <div class="col-md-3">
                    <strong>Einkaufsgebühr:</strong><br>
                    <?= (int)$invoice['shopping_fee'] === 0 ? 'Erlassen' : invoiceFormatAriary($invoice['shopping_fee']) ?>
                </div>

                <div class="col-md-3">
                    <strong>Gesamt:</strong><br>
                    <span class="fs-5 text-primary">
                        <?= invoiceFormatAriary($invoice['total']) ?>
                    </span>
                </div>
            </div>

            <?php if (!empty($invoice['note'])): ?>
                <div class="mt-4">
                    <strong>Notiz:</strong><br>
                    <?= nl2br(htmlspecialchars($invoice['note'])) ?>
                </div>
            <?php endif; ?>
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

            new bootstrap.Toast(toastElement, { delay: 3000 }).show();
        }

        document.querySelector('.btn-report-payment')?.addEventListener('click', function () {
            if (!confirm('Zahlung wirklich als erfolgt melden?')) {
                return;
            }

            const button = this;
            const formData = new FormData();
            formData.append('ajax_action', 'report_payment');
            formData.append('invoice_id', button.dataset.invoiceId);

            button.disabled = true;

            fetch('invoice.php', {
                method: 'POST',
                body: formData
            })
                .then(response => response.json())
                .then(result => {
                    showToast(result.message, result.success);

                    if (result.success) {
                        button.innerHTML = '<i class="bi bi-check2-circle me-1"></i>Zahlung gemeldet';
                    } else {
                        button.disabled = false;
                    }
                })
                .catch(() => {
                    showToast('Fehler beim Senden.', false);
                    button.disabled = false;
                });
        });
    </script>

    <?php
    $content = ob_get_clean();
    require_once __DIR__ . '/style/layout.php';
    exit;
}

$stmt = $pdo->prepare("\n    SELECT \n        i.*,\n        u.family_name,\n        od.title AS order_date_title\n    FROM invoices i\n    INNER JOIN users u ON u.id = i.user_id\n    INNER JOIN order_dates od ON od.id = i.order_date_id\n    WHERE i.user_id = ?\n    ORDER BY i.created_at DESC\n");
$stmt->execute([$userId]);
$invoices = $stmt->fetchAll();

ob_start();
?>

<div class="mb-4">
    <h1 class="h3 mb-1">Meine Rechnungen</h1>
    <p class="text-muted mb-0">Hier siehst du deine Rechnungen.</p>
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
                    <th>Termin</th>
                    <th>Status</th>
                    <th>Gesamt</th>
                    <th>Erstellt</th>
                    <th style="width: 120px;">Aktion</th>
                </tr>
            </thead>

            <tbody>
                <?php foreach ($invoices as $invoice): ?>
                    <?php $status = invoiceStatusNormalize((string)$invoice['status']); ?>
                    <tr>
                        <td><?= htmlspecialchars($invoice['invoice_number']) ?></td>
                        <td><?= htmlspecialchars($invoice['order_date_title']) ?></td>
                        <td>
                            <span class="badge <?= invoiceStatusBadgeClass($status) ?>">
                                <?= htmlspecialchars(invoiceStatusLabel($status)) ?>
                            </span>
                        </td>
                        <td><?= invoiceFormatAriary($invoice['total']) ?></td>
                        <td><?= date('d.m.Y H:i', strtotime($invoice['created_at'])) ?></td>
                        <td>
                            <a href="invoice.php?token=<?= htmlspecialchars($invoice['public_token']) ?>" class="btn btn-info btn-sm" title="Ansehen">
                                <i class="bi bi-eye"></i>
                            </a>

                            <a href="invoice_pdf.php?token=<?= htmlspecialchars($invoice['public_token']) ?>" target="_blank" class="btn btn-primary btn-sm" title="PDF anzeigen">
                                <i class="bi bi-file-earmark-pdf"></i>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (!$invoices): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">
                            Keine Rechnungen vorhanden.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/style/layout.php';
