<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/dompdf/autoload.inc.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$currentUser = requireLogin($pdo);

$currentRank = (int)($currentUser['rank'] ?? 0);
$userId = (int)($currentUser['id'] ?? 0);
$isAdmin = $currentRank >= 4;

$token = trim($_GET['token'] ?? '');

if ($token === '') {
    exit('Ungültige Rechnung.');
}

function formatAriary($value): string
{
    return number_format((int)$value, 0, ',', '.') . ' Ariary';
}

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function formatDateGerman($value): string
{
    if (!$value) {
        return '-';
    }

    $timestamp = strtotime((string)$value);

    if (!$timestamp) {
        return '-';
    }

    return date('d.m.Y', $timestamp);
}

function formatDateTimeGerman($value): string
{
    if (!$value) {
        return '-';
    }

    $timestamp = strtotime((string)$value);

    if (!$timestamp) {
        return '-';
    }

    return date('d.m.Y H:i', $timestamp);
}

function invoiceStatusLabel(string $status): string
{
    $status = mb_strtolower(trim($status), 'UTF-8');

    return match ($status) {
        'teilbezahlt' => 'Teilbezahlt',
        'bezahlt' => 'Bezahlt',
        'überfällig' => 'Überfällig',
        'storniert' => 'Storniert',
        default => 'Offen',
    };
}

/* =========================
   LOGO FÜR PDF
========================= */
$logoPath = realpath(__DIR__ . '/img/rechnung.png');
$logoDataUri = '';

if ($logoPath && is_file($logoPath) && is_readable($logoPath)) {
    $logoMime = mime_content_type($logoPath) ?: 'image/png';
    $logoDataUri = 'data:' . $logoMime . ';base64,' . base64_encode(file_get_contents($logoPath));
}

/* =========================
   RECHNUNG LADEN
========================= */
$stmt = $pdo->prepare("
    SELECT 
        i.*,
        u.family_name,
        creator.family_name AS created_by_name,
        od.title AS order_date_title,
        od.deadline_at
    FROM invoices i
    INNER JOIN users u ON u.id = i.user_id
    LEFT JOIN users creator ON creator.id = i.created_by
    INNER JOIN order_dates od ON od.id = i.order_date_id
    WHERE i.public_token = ?
    LIMIT 1
");
$stmt->execute([$token]);
$invoice = $stmt->fetch();

if (!$invoice) {
    exit('Rechnung nicht gefunden.');
}

if (!$isAdmin && (int)$invoice['user_id'] !== $userId) {
    exit('Keine Berechtigung.');
}

/* =========================
   POSITIONEN LADEN
========================= */
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

$statusLabel = invoiceStatusLabel((string)$invoice['status']);

$serviceFeeText = ((int)$invoice['service_fee'] === 0)
    ? 'Erlassen'
    : formatAriary($invoice['service_fee']);

$shoppingFeeText = ((int)$invoice['shopping_fee'] === 0)
    ? 'Erlassen'
    : formatAriary($invoice['shopping_fee']);

$orderDateTitle = trim((string)($invoice['order_date_title'] ?? ''));

if ($orderDateTitle === '') {
    $orderDateTitle = '-';
}

$invoiceCreatorName = !empty($invoice['created_by_name'])
    ? $invoice['created_by_name']
    : 'System/Admin';

$html = '
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">

<style>
    @page {
        margin: 42px 52px 95px 52px;
    }

    body {
        font-family: DejaVu Sans, sans-serif;
        color: #111827;
        font-size: 11px;
        line-height: 1.42;
        background: #ffffff;
    }

    .page {
        width: 100%;
    }

    /* =========================
       KOPFBEREICH
    ========================= */
    .logo-top {
        width: 100%;
        text-align: center;
        margin-bottom: 34px;
    }

    .logo-img {
        width: 220px;
        height: auto;
    }

    .header-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 54px;
    }

    .header-table td {
        vertical-align: top;
    }

    .header-left {
        width: 45%;
        padding-right: 28px;
    }

    .header-right {
        width: 55%;
        text-align: right;
    }

    .recipient-sub {
        color: #111827;
        font-size: 11px;
        margin-bottom: 7px;
    }

    .recipient-name {
        color: #111827;
        font-size: 14px;
        font-weight: bold;
    }

    .invoice-meta {
        width: 330px;
        border-collapse: collapse;
        margin-left: auto;
    }

    .invoice-meta td {
        padding: 4px 0;
        font-size: 10.5px;
        vertical-align: top;
    }

    .invoice-meta td:first-child {
        color: #64748b;
        text-align: right;
        padding-right: 16px;
        width: 45%;
    }

    .invoice-meta td:last-child {
        color: #111827;
        font-weight: bold;
        text-align: right;
        width: 55%;
    }

    /* =========================
       TITEL / TEXT
    ========================= */
    h1 {
        font-size: 27px;
        font-weight: normal;
        color: #111827;
        margin: 0 0 22px 0;
    }

    .intro {
        margin-bottom: 20px;
        color: #111827;
        font-size: 11px;
        line-height: 1.55;
    }

    .intro p {
        margin: 0 0 9px 0;
    }

    /* =========================
       TABELLE
    ========================= */
    .items {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
        margin-top: 10px;
        margin-bottom: 22px;
    }

    .items thead {
        display: table-header-group;
    }

    .items tbody {
        display: table-row-group;
    }

    .items tr {
        page-break-inside: avoid;
    }

    .items td,
    .items th {
        page-break-inside: avoid;
    }

    .items th {
        background: #d9dce2;
        color: #111827;
        font-size: 9.5px;
        font-weight: bold;
        padding: 7px 8px;
        text-align: left;
        border-bottom: 1px solid #c5cad3;
    }

    .items td {
        padding: 7px 8px;
        border-bottom: 1px solid #e2e6ec;
        vertical-align: top;
        font-size: 10.5px;
    }

    .items th:nth-child(1),
.items td:nth-child(1) {
    width: 7%;
}

.items th:nth-child(2),
.items td:nth-child(2) {
    width: 29%;
}

.items th:nth-child(3),
.items td:nth-child(3) {
    width: 13%;
    text-align: center;
}

.items th:nth-child(4),
.items td:nth-child(4) {
    width: 13%;
    text-align: center;
}

.items th:nth-child(5),
.items td:nth-child(5) {
    width: 12%;
}

.items th:nth-child(6),
.items td:nth-child(6) {
    width: 13%;
    text-align: right;
}

.items th:nth-child(7),
.items td:nth-child(7) {
    width: 13%;
    text-align: right;
}

    .category-row {
        page-break-after: avoid;
    }

    .category-row td {
        background: #f1f3f6;
        color: #475569;
        font-size: 9px;
        text-transform: uppercase;
        letter-spacing: .8px;
        font-weight: bold;
        padding: 6px 8px;
        border-bottom: 1px solid #dce1e8;
    }

    .product-name {
        font-weight: bold;
        color: #111827;
    }

    /* =========================
       SUMMENBEREICH
    ========================= */
    .totals-wrapper {
        width: 100%;
        border-collapse: collapse;
        margin-top: 18px;
        page-break-inside: avoid;
    }

    .totals-wrapper td {
        vertical-align: top;
    }

    .payment-note {
        width: 56%;
        padding-right: 36px;
        color: #111827;
        font-size: 10.5px;
        line-height: 1.55;
    }

    .totals-area {
        width: 44%;
    }

    .totals {
        width: 100%;
        border-collapse: collapse;
    }

    .totals td {
        padding: 7px 8px;
        border-bottom: 1px solid #e2e6ec;
        font-size: 10.5px;
    }

    .totals td:first-child {
        color: #111827;
        font-weight: bold;
    }

    .totals td:last-child {
        text-align: right;
        color: #111827;
        font-weight: bold;
    }

    .totals .total td {
        background: #d9dce2;
        border-top: 2px solid #aeb4bf;
        border-bottom: 0;
        font-size: 12px;
        font-weight: bold;
        padding-top: 8px;
        padding-bottom: 8px;
    }

    .closing {
        margin-top: 28px;
        color: #111827;
        font-size: 10.5px;
        line-height: 1.55;
        page-break-inside: avoid;
    }

    .closing p {
        margin: 0 0 8px 0;
    }

    .muted {
        color: #64748b;
    }

    .text-right {
        text-align: right;
    }

    .text-center {
        text-align: center;
    }
</style>
</head>

<body>
<div class="page">

<div class="logo-top">';

if ($logoDataUri !== '') {
    $html .= '
    <img src="' . e($logoDataUri) . '" class="logo-img" alt="Rechnung">';
}

$html .= '
</div>

<table class="header-table">
    <tr>
        <td class="header-left">
            <div class="recipient-sub">Rechnungsempfänger</div>
            <div class="recipient-name">' . e($invoice['family_name']) . '</div>
        </td>

        <td class="header-right">
            <table class="invoice-meta">
                <tr>
                    <td>Rechnungsnummer:</td>
                    <td>' . e($invoice['invoice_number']) . '</td>
                </tr>
                <tr>
                    <td>Bestelltermin:</td>
                    <td>' . e($orderDateTitle) . '</td>
                </tr>
                <tr>
                    <td>Bestellschluss:</td>
                    <td>' . e(formatDateTimeGerman($invoice['deadline_at'])) . '</td>
                </tr>
            </table>
        </td>
    </tr>
</table>

<h1>Rechnung</h1>

<div class="intro">
    <p>Hallo ' . e($invoice['family_name']) . ',</p>
    <p>vielen Dank für deine Bestellung zum Bestelltermin <strong>' . e($orderDateTitle) . '</strong>. Deine bestellten Produkte wurden erfasst und gemeinsam mit den anfallenden Gebühren berechnet. Nachfolgend findest du die vollständige Übersicht deiner Produkte und Kosten. Falls dir ein Fehler auffällt oder etwas nicht korrekt berechnet wurde, wende dich bitte an die Betreiber dieses Portals.</p>
</div>

<table class="items">
<thead>
    <tr>
        <th>Nr.</th>
        <th>Produkt</th>
        <th>Bestellte Menge</th>
        <th>Gelieferte Menge</th>
        <th>Einheit</th>
        <th>Einzelpreis</th>
        <th>Gesamtpreis</th>
    </tr>
</thead>
    <tbody>';

$position = 1;

if (!$itemsByCategory) {
    $html .= '
        <tr>
            <td colspan="7" class="text-center muted">Keine Produkte vorhanden.</td>
        </tr>';
}

foreach ($itemsByCategory as $categoryName => $categoryItems) {
    $html .= '
        <tr class="category-row">
            <td colspan="7">' . e($categoryName) . '</td>
        </tr>';

    foreach ($categoryItems as $item) {
        $html .= '
        <tr>
    <td>' . $position . '</td>

    <td>
        <div class="product-name">' . e($item['product_name']) . '</div>
    </td>

    <td class="text-center">
        ' . (int)($item['original_quantity'] ?? $item['quantity']) . '
    </td>

    <td class="text-center">
        ' . (int)$item['quantity'] . '
    </td>

    <td>' . e($item['unit']) . '</td>

    <td class="text-right">' . formatAriary($item['price']) . '</td>

    <td class="text-right">' . formatAriary($item['row_total']) . '</td>
</tr>';

        $position++;
    }
}

$html .= '
    </tbody>
</table>

<table class="totals-wrapper">
    <tr>
        <td class="payment-note">';

if (!empty($invoice['note'])) {
    $html .= nl2br(e($invoice['note']));
} else {
    $html .= '
            Bitte begleiche den Rechnungsbetrag entsprechend der vereinbarten Zahlungsweise.
            Für Rückfragen zur Sammelbestellung stehen wir gerne zur Verfügung.';
}

$html .= '
        </td>

        <td class="totals-area">
            <table class="totals">
                <tr>
                    <td>Zwischensumme</td>
                    <td>' . formatAriary($invoice['subtotal']) . '</td>
                </tr>
                <tr>
                    <td>Servicegebühr</td>
                    <td>' . e($serviceFeeText) . '</td>
                </tr>
                <tr>
                    <td>Einkaufsgebühr</td>
                    <td>' . e($shoppingFeeText) . '</td>
                </tr>
                <tr class="total">
                    <td>Rechnungsbetrag</td>
                    <td>' . formatAriary($invoice['total']) . '</td>
                </tr>
            </table>
        </td>
    </tr>
</table>

<div class="closing">
    <p>Mit freundlichen Grüßen</p>
    <p><strong>' . e($invoiceCreatorName) . '</strong></p>
</div>

</div>

</body>
</html>';

$options = new Options();
$options->set('isRemoteEnabled', false);
$options->set('isHtml5ParserEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$canvas = $dompdf->getCanvas();
$fontMetrics = $dompdf->getFontMetrics();

$font = $fontMetrics->getFont('DejaVu Sans', 'normal');
$fontBold = $fontMetrics->getFont('DejaVu Sans', 'bold');

$pageWidth = $canvas->get_width();
$pageHeight = $canvas->get_height();

$footerLeft = 52;
$footerRight = $pageWidth - 52;

$lineY = $pageHeight - 64;
$textY = $pageHeight - 47;

$footerMuted = [0.42, 0.48, 0.58];
$footerDark = [0.08, 0.11, 0.18];
$lineColor = [0.86, 0.89, 0.93];

$footerInvoiceNumber = (string)$invoice['invoice_number'];

$canvas->page_script(function ($pageNumber, $pageCount, $canvas, $fontMetrics) use (
    $font,
    $fontBold,
    $pageWidth,
    $footerLeft,
    $footerRight,
    $lineY,
    $textY,
    $footerMuted,
    $footerDark,
    $lineColor,
    $footerInvoiceNumber
) {
    $canvas->line($footerLeft, $lineY, $footerRight, $lineY, $lineColor, 0.5);

    $leftText = 'Rechnung ' . $footerInvoiceNumber;
    $centerText = 'Automatisch elektronisch erstellt · ohne Unterschrift gültig';
    $rightText = 'Seite ' . $pageNumber . ' / ' . $pageCount;

    $centerWidth = $fontMetrics->getTextWidth($centerText, $font, 7.5);
    $rightWidth = $fontMetrics->getTextWidth($rightText, $font, 7.5);

    $canvas->text(
        $footerLeft,
        $textY,
        $leftText,
        $fontBold,
        8,
        $footerDark
    );

    $canvas->text(
        ($pageWidth - $centerWidth) / 2,
        $textY,
        $centerText,
        $font,
        7.5,
        $footerMuted
    );

    $canvas->text(
        $footerRight - $rightWidth,
        $textY,
        $rightText,
        $font,
        7.5,
        $footerMuted
    );
});

$filename = 'Rechnung_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', (string)$invoice['invoice_number']) . '.pdf';

$download = isset($_GET['download']) && $_GET['download'] === '1';

$dompdf->stream($filename, [
    'Attachment' => $download
]);

exit;