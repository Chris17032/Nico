<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/dompdf/autoload.inc.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$currentUser = requireRank($pdo, 5);

$documentCreatorName = !empty($currentUser['family_name'])
    ? $currentUser['family_name']
    : 'System/Admin';

$orderDateId = isset($_GET['order_date_id']) ? (int)$_GET['order_date_id'] : 0;

if ($orderDateId <= 0) {
    exit('Ungültiger Bestelltermin.');
}

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function formatAriary($value): string
{
    return number_format((float)$value, 0, ',', '.') . ' Ariary';
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

function unitLabelShort(string $unit): string
{
    return match ($unit) {
        'Kilogramm' => 'kg',
        'Gramm' => 'g',
        'Liter' => 'l',
        'Milliliter' => 'ml',
        'Cups' => 'Cups',
        'Staude' => 'Staude',
        default => 'Stück',
    };
}

function formatQuantityDealer($quantity, string $unit): string
{
    $quantity = (float)$quantity;

    $formatted = fmod($quantity, 1.0) === 0.0
        ? number_format($quantity, 0, ',', '.')
        : number_format($quantity, 2, ',', '.');

    return $formatted . ' ' . unitLabelShort($unit);
}

/* =========================
   LOGO FÜR PDF
========================= */
$logoPath = realpath(__DIR__ . '/img/haendler.png');
$logoDataUri = '';

if ($logoPath && is_file($logoPath) && is_readable($logoPath)) {
    $logoMime = mime_content_type($logoPath) ?: 'image/png';
    $logoDataUri = 'data:' . $logoMime . ';base64,' . base64_encode(file_get_contents($logoPath));
}

/* =========================
   BESTELLTERMIN LADEN
========================= */
$stmt = $pdo->prepare("
    SELECT *
    FROM order_dates
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$orderDateId]);
$orderDate = $stmt->fetch();

if (!$orderDate) {
    exit('Bestelltermin nicht gefunden.');
}

$orderDateTitle = trim((string)($orderDate['title'] ?? ''));

if ($orderDateTitle === '') {
    $orderDateTitle = '-';
}

/* =========================
   PRODUKTE FÜR HÄNDLER LADEN
========================= */
$stmt = $pdo->prepare("
    SELECT 
        COALESCE(pc.name, 'Ohne Kategorie') AS category_name,
        oi.product_id,
        oi.product_name,
        oi.unit,
        oi.price,
        SUM(oi.quantity) AS total_quantity,
        SUM(oi.row_total) AS total_amount
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
$stmt->execute([$orderDateId]);
$productSummary = $stmt->fetchAll();

$productsByCategory = [];

foreach ($productSummary as $product) {
    $categoryName = trim((string)($product['category_name'] ?? ''));

    if ($categoryName === '') {
        $categoryName = 'Ohne Kategorie';
    }

    if (!isset($productsByCategory[$categoryName])) {
        $productsByCategory[$categoryName] = [];
    }

    $productsByCategory[$categoryName][] = $product;
}

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
        font-size: 10.5px;
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
        margin-bottom: 34px;
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
        margin: 0 0 18px 0;
    }

    .intro {
        margin-bottom: 18px;
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
        font-size: 9px;
        font-weight: bold;
        padding: 7px 7px;
        text-align: left;
        border-bottom: 1px solid #c5cad3;
    }

    .items td {
        padding: 7px 7px;
        border-bottom: 1px solid #e2e6ec;
        vertical-align: top;
        font-size: 10px;
    }

    .items th:nth-child(1),
    .items td:nth-child(1) {
        width: 9%;
    }

    .items th:nth-child(2),
    .items td:nth-child(2) {
        width: 39%;
    }

    .items th:nth-child(3),
    .items td:nth-child(3) {
        width: 16%;
    }

    .items th:nth-child(4),
    .items td:nth-child(4) {
        width: 18%;
        text-align: right;
    }

    .items th:nth-child(5),
    .items td:nth-child(5) {
        width: 18%;
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
        padding: 6px 7px;
        border-bottom: 1px solid #dce1e8;
    }

    .product-name {
        font-weight: bold;
        color: #111827;
    }

    .muted {
        color: #64748b;
        font-size: 9px;
    }

    .text-right {
        text-align: right;
    }

    .text-center {
        text-align: center;
    }

    .closing-note {
        margin-top: 26px;
        padding-top: 14px;
        border-top: 1px solid #e5e7eb;
        color: #111827;
        font-size: 10.5px;
        line-height: 1.55;
        page-break-inside: avoid;
    }

    .closing-note p {
        margin: 0 0 8px 0;
    }

    .closing-note strong {
        font-weight: bold;
    }
</style>
</head>

<body>
<div class="page">

<div class="logo-top">';

if ($logoDataUri !== '') {
    $html .= '
    <img src="' . e($logoDataUri) . '" class="logo-img" alt="Händlerliste">';
}

$html .= '
</div>

<table class="header-table">
    <tr>
        <td class="header-left">
            <div class="recipient-sub">Export für Händler</div>
            <div class="recipient-name">Händlerliste</div>
        </td>

        <td class="header-right">
            <table class="invoice-meta">
                <tr>
                    <td>Bestelltermin:</td>
                    <td>' . e($orderDateTitle) . '</td>
                </tr>
                <tr>
                    <td>Bestellschluss:</td>
                    <td>' . e(formatDateTimeGerman($orderDate['deadline_at'])) . '</td>
                </tr>
                <tr>
                    <td>Erstellt von:</td>
                    <td>' . e($documentCreatorName) . '</td>
                </tr>
                <tr>
                    <td>Exportiert am:</td>
                    <td>' . e(date('d.m.Y H:i')) . '</td>
                </tr>
            </table>
        </td>
    </tr>
</table>

<h1>Händlerliste</h1>

<div class="intro">
    <p>Sehr geehrte Damen und Herren,</p>
    <p>für den Bestelltermin <strong>' . e($orderDateTitle) . '</strong> wurden die nachfolgenden Produkte nach Kategorie zusammengefasst. Die Liste dient als Grundlage für die Vorbereitung und Zusammenstellung der Waren.</p>
</div>

<table class="items">
    <thead>
        <tr>
            <th>Nr.</th>
            <th>Produkt</th>
            <th>Einheit</th>
            <th>Gesamtmenge</th>
            <th>Preis</th>
        </tr>
    </thead>
    <tbody>';

$position = 1;

if (!$productsByCategory) {
    $html .= '
        <tr>
            <td colspan="5" class="text-center muted">Keine Produkte vorhanden.</td>
        </tr>';
}

foreach ($productsByCategory as $categoryName => $products) {
    $html .= '
        <tr class="category-row">
            <td colspan="5">' . e($categoryName) . '</td>
        </tr>';

    foreach ($products as $product) {
        $html .= '
        <tr>
            <td>' . $position . '</td>
            <td>
                <div class="product-name">' . e($product['product_name']) . '</div>
            </td>
            <td>' . e(unitLabelShort((string)$product['unit'])) . '</td>
            <td class="text-right"><strong>' . e(formatQuantityDealer($product['total_quantity'], (string)$product['unit'])) . '</strong></td>
            <td class="text-right">' . formatAriary($product['price']) . '</td>
        </tr>';

        $position++;
    }
}

$html .= '
    </tbody>
</table>

<div class="closing-note">
    <p>Vielen Dank für Ihre Unterstützung bei der Vorbereitung unserer Bestellung.</p>
    <p>Falls beim Zusammenstellen Fragen entstehen, Produkte ersetzt werden müssen oder Mengen nicht wie angegeben verfügbar sind, freuen wir uns über eine kurze Rückmeldung.</p>
    <p>Mit freundlichen Grüßen<br><strong>' . e($documentCreatorName) . '</strong></p>
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

/* =========================
   PDF-FUSSZEILE
========================= */
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

$footerTitle = 'Händlerliste ' . $orderDateTitle;

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
    $footerTitle
) {
    $canvas->line($footerLeft, $lineY, $footerRight, $lineY, $lineColor, 0.5);

    $leftText = $footerTitle;
    $centerText = 'Automatisch elektronisch erstellt';
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

$filename = 'Haendlerliste_' . preg_replace('/[^A-Za-z0-9_\-]/', '_', (string)$orderDateTitle) . '.pdf';

$dompdf->stream($filename, [
    'Attachment' => isset($_GET['download']) && $_GET['download'] === '1'
]);

exit;