<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config/config.php';


date_default_timezone_set('Indian/Antananarivo');

$currentUser = requireLogin($pdo);
$userId = (int) $currentUser['id'];

$pageTitle = 'Dashboard';

function formatAriary($value)
{
    return number_format((int) $value, 0, ',', '.') . ' Ariary';
}

function appTimezone()
{
    return new DateTimeZone('Indian/Antananarivo');
}

function formatMadagascarDateTime($date)
{
    if (!$date) {
        return '-';
    }

    return (new DateTime($date, appTimezone()))->format('d.m.Y H:i');
}

function getGreeting()
{
    $localTime = new DateTime('now', appTimezone());
    $hour = (int) $localTime->format('H');

    if ($hour >= 3 && $hour < 11) {
        return ['Guten Morgen', '☀️'];
    }

    if ($hour >= 11 && $hour < 14) {
        return ['Guten Mittag', '🌤️'];
    }

    if ($hour >= 14 && $hour < 17) {
        return ['Guten Nachmittag', '🌤️'];
    }

    return ['Guten Abend', '🌙'];
}

function getUserSettings($pdo, $userId)
{
    $stmt = $pdo->prepare("
        SELECT discount_percent, service_fee_waived, shopping_fee_waived
        FROM users
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId]);

    return $stmt->fetch() ?: [];
}

function getCurrentOrder($pdo, $userId)
{
    $stmt = $pdo->prepare("
        SELECT 
            og.*,
            od.title AS order_date_title,
            od.deadline_at
        FROM order_groups og
        INNER JOIN order_dates od ON od.id = og.order_date_id
        WHERE og.user_id = ?
        AND od.archived = 0
        ORDER BY od.deadline_at ASC, og.created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$userId]);

    return $stmt->fetch();
}

function getNextOrderDeadline($pdo)
{
    $stmt = $pdo->prepare("
        SELECT id, title, deadline_at
        FROM order_dates
        WHERE archived = 0
        AND deadline_at >= ?
        ORDER BY deadline_at ASC
        LIMIT 1
    ");
    $stmt->execute([(new DateTime('now', appTimezone()))->format('Y-m-d H:i:s')]);

    return $stmt->fetch();
}

function formatDeadlineRemaining($deadlineAt)
{
    if (!$deadlineAt) {
        return '-';
    }

    $now = new DateTime('now', appTimezone());
    $deadline = new DateTime($deadlineAt, appTimezone());

    if ($deadline <= $now) {
        return 'abgelaufen';
    }

    $diff = $now->diff($deadline);

    if ($diff->days > 0) {
        return $diff->days . ' Tag' . ($diff->days === 1 ? '' : 'e');
    }

    if ($diff->h > 0) {
        return $diff->h . ' Std.';
    }

    return max(1, $diff->i) . ' Min.';
}

function getOpenInvoices($pdo, $userId)
{
    $stmt = $pdo->prepare("
        SELECT 
            i.*,
            od.title AS order_date_title
        FROM invoices i
        INNER JOIN order_dates od ON od.id = i.order_date_id
        WHERE i.user_id = ?
        AND i.status IN ('offen', 'abgeschlossen')
        ORDER BY i.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$userId]);

    return $stmt->fetchAll();
}

function getInvoiceCountByStatus($pdo, $userId, $status)
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM invoices
        WHERE user_id = ?
        AND status = ?
    ");
    $stmt->execute([$userId, $status]);

    return (int) $stmt->fetchColumn();
}

[$greeting, $emoji] = getGreeting();

$userSettings = getUserSettings($pdo, $userId);

$discountPercent = (int) ($userSettings['discount_percent'] ?? 0);
$serviceFeeWaived = (int) ($userSettings['service_fee_waived'] ?? 0) === 1;
$shoppingFeeWaived = (int) ($userSettings['shopping_fee_waived'] ?? 0) === 1;

$currentOrder = getCurrentOrder($pdo, $userId);
$nextOrderDeadline = getNextOrderDeadline($pdo);
$openInvoices = getOpenInvoices($pdo, $userId);

$openInvoiceCount = getInvoiceCountByStatus($pdo, $userId, 'offen');
$paidInvoiceCount = getInvoiceCountByStatus($pdo, $userId, 'bezahlt');

$subtotal = $currentOrder ? (int) $currentOrder['subtotal'] : 0;
$serviceFee = $currentOrder ? (int) $currentOrder['service_fee'] : 0;
$shoppingFee = $currentOrder ? (int) $currentOrder['shopping_fee'] : 0;
$total = $currentOrder ? (int) $currentOrder['total'] : 0;

$userName = trim((string) ($currentUser['family_name'] ?? ''));
if ($userName === '') {
    $userName = 'Benutzer';
}

$deadlineRemaining = $nextOrderDeadline ? formatDeadlineRemaining($nextOrderDeadline['deadline_at']) : '-';

ob_start();
?>

<div class="mb-4">
    <h1 class="h3 mb-1">
        <?= htmlspecialchars($greeting) ?>,
        <?= htmlspecialchars($userName) ?>
        <?= $emoji ?>
    </h1>
    <p class="text-muted mb-0">
        Hier siehst du deine wichtigsten Informationen auf einen Blick.
    </p>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="small-box bg-primary">
            <div class="inner">
                <p>Nächster Bestellschluss</p>
                <h3><?= htmlspecialchars($deadlineRemaining) ?></h3>
                <?php if ($nextOrderDeadline): ?>
                    <small><?= htmlspecialchars($nextOrderDeadline['title']) ?> ·
                        <?= formatMadagascarDateTime($nextOrderDeadline['deadline_at']) ?> EAT</small>
                <?php else: ?>
                    <small>Kein aktiver Termin</small>
                <?php endif; ?>
            </div>
            <div class="icon">
                <i class="bi bi-alarm"></i>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="small-box bg-success">
            <div class="inner">
                <p>Offene Rechnungen</p>
                <h3><?= $openInvoiceCount ?></h3>
            </div>
            <div class="icon">
                <i class="bi bi-receipt"></i>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="small-box bg-warning">
            <div class="inner">
                <p>Bezahlte Rechnungen</p>
                <h3><?= $paidInvoiceCount ?></h3>
            </div>
            <div class="icon">
                <i class="bi bi-check-circle"></i>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header d-flex justify-content-between align-items-center gap-3">
                <h5 class="mb-0">
                    <i class="bi bi-cart-check me-2"></i>Aktive Bestellung
                </h5>

                <a href="order" class="btn btn-primary btn-sm">
                    <i class="bi bi-pencil-square me-1"></i>
                    Öffnen
                </a>
            </div>

            <div class="card-body">
                <?php if ($currentOrder): ?>
                    <div class="mb-3">
                        <strong>Termin:</strong><br>
                        <?= htmlspecialchars($currentOrder['order_date_title']) ?><br>
                        <small class="text-muted">
                            Bestellschluss:
                            <?= formatMadagascarDateTime($currentOrder['deadline_at']) ?> EAT
                        </small>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <strong>Zwischensumme:</strong><br>
                            <?= formatAriary($subtotal) ?>
                        </div>

                        <div class="col-md-6">
                            <strong>Servicegebühr:</strong><br>
                            <?= $serviceFeeWaived ? 'Erlassen' : formatAriary($serviceFee) ?>
                        </div>

                        <div class="col-md-6">
                            <strong>Einkaufsgebühr:</strong><br>
                            <?= $shoppingFeeWaived ? 'Erlassen' : formatAriary($shoppingFee) ?>
                        </div>

                        <div class="col-md-6">
                            <strong>Gesamt:</strong><br>
                            <span class="fs-5 text-primary">
                                <?= formatAriary($total) ?>
                            </span>
                        </div>
                    </div>

                    <?php if ($discountPercent > 0): ?>
                        <div class="alert alert-info mt-3 mb-0">
                            Familienrabatt aktiv: <?= $discountPercent ?>%
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="text-center text-muted py-4">
                        Du hast aktuell keine aktive Bestellung.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header d-flex justify-content-between align-items-center gap-3">
                <h5 class="mb-0">
                    <i class="bi bi-receipt me-2"></i>Offene Rechnungen
                </h5>

                <a href="invoice" class="btn btn-primary btn-sm">
                    <i class="bi bi-eye me-1"></i>
                    Alle anzeigen
                </a>
            </div>

            <div class="card-body p-0">
                <?php if ($openInvoices): ?>
                    <table class="table table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th>Nummer</th>
                                <th>Termin</th>
                                <th>Status</th>
                                <th class="text-end">Gesamt</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($openInvoices as $invoice): ?>
                                <tr>
                                    <td>
                                        <a href="invoice?token=<?= htmlspecialchars($invoice['public_token']) ?>">
                                            <?= htmlspecialchars($invoice['invoice_number']) ?>
                                        </a>
                                    </td>
                                    <td><?= htmlspecialchars($invoice['order_date_title']) ?></td>
                                    <td>
                                        <?php
                                        $statusClass = 'bg-warning text-dark';

                                        if ($invoice['status'] === 'abgeschlossen') {
                                            $statusClass = 'bg-success';
                                        }
                                        ?>
                                        <span class="badge <?= $statusClass ?>">
                                            <?= htmlspecialchars($invoice['status']) ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <?= formatAriary($invoice['total']) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="p-4 text-center text-muted">
                        Keine offenen Rechnungen vorhanden.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/style/layout.php';
