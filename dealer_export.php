<?php
require_once __DIR__ . '/config/config.php';

$currentUser = requireRank($pdo, 5);

$pageTitle = 'Händler Export';

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function formatAriary($value): string
{
    return number_format((float)$value, 0, ',', '.') . ' Ariary';
}

function formatQuantityDealer($quantity, string $unit): string
{
    $quantity = (float)$quantity;

    $formatted = fmod($quantity, 1.0) === 0.0
        ? number_format($quantity, 0, ',', '.')
        : number_format($quantity, 2, ',', '.');

    return $formatted . ' ' . unitLabelShort($unit);
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

function formatDateTimeLocalDealer($value): string
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

/* =========================
   BESTELLTERMINE LADEN
========================= */
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
            SELECT COUNT(oi.id)
            FROM order_groups og
            INNER JOIN order_items oi ON oi.order_group_id = og.id
            WHERE og.order_date_id = od.id
        ) AS item_count
    FROM order_dates od
    ORDER BY od.archived ASC, od.deadline_at DESC
")->fetchAll();

$selectedOrderDateId = isset($_GET['order_date_id']) ? (int)$_GET['order_date_id'] : 0;

if ($selectedOrderDateId <= 0 && $orderDates) {
    $selectedOrderDateId = (int)$orderDates[0]['id'];
}

$selectedOrderDate = null;

foreach ($orderDates as $date) {
    if ((int)$date['id'] === $selectedOrderDateId) {
        $selectedOrderDate = $date;
        break;
    }
}

/* =========================
   HÄNDLERLISTE LADEN
========================= */
$productSummary = [];
$productDetails = [];
$overall = [
    'product_count' => 0,
    'family_count' => 0,
    'total_quantity_rows' => 0,
    'estimated_total' => 0
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
            COUNT(DISTINCT og.user_id) AS family_count
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
        $key = ($detail['category_name'] ?? 'Ohne Kategorie')
            . '|'
            . (int)$detail['product_id']
            . '|'
            . $detail['product_name']
            . '|'
            . $detail['unit']
            . '|'
            . (int)$detail['price'];

        $productDetails[$key][] = $detail;
    }

    $stmt = $pdo->prepare("
        SELECT
            COUNT(DISTINCT oi.product_id) AS product_count,
            COUNT(DISTINCT og.user_id) AS family_count,
            COUNT(oi.id) AS total_quantity_rows,
            COALESCE(SUM(oi.row_total), 0) AS estimated_total
        FROM order_items oi
        INNER JOIN order_groups og ON og.id = oi.order_group_id
        WHERE og.order_date_id = ?
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

    if (!isset($productsByCategory[$categoryName])) {
        $productsByCategory[$categoryName] = [];
    }

    $productsByCategory[$categoryName][] = $product;
}

ob_start();
?>

<div class="mb-4 d-flex justify-content-between align-items-start gap-3 flex-wrap">
    <div>
        <h1 class="h3 mb-1">Händler Export</h1>
        <p class="text-muted mb-0">
            Übersicht aller bestellten Produkte für den Händler.
        </p>
    </div>

    <?php if ($selectedOrderDate): ?>
        <a
            href="dealer_export_pdf.php?order_date_id=<?= (int)$selectedOrderDateId ?>"
            class="btn btn-danger"
            target="_blank">
            <i class="bi bi-file-earmark-pdf me-1"></i>
            PDF exportieren
        </a>
    <?php endif; ?>
</div>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-md-8">
                <label class="form-label">Bestelltermin auswählen</label>
                <select name="order_date_id" class="form-select">
                    <?php foreach ($orderDates as $date): ?>
                        <option value="<?= (int)$date['id'] ?>" <?= (int)$date['id'] === $selectedOrderDateId ? 'selected' : '' ?>>
                            <?= h($date['title']) ?>
                            —
                            <?= h(formatDateTimeLocalDealer($date['deadline_at'])) ?>
                            <?= (int)$date['archived'] === 1 ? ' — archiviert' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-4">
                <button class="btn btn-primary w-100" type="submit">
                    <i class="bi bi-search me-1"></i>
                    Anzeigen
                </button>
            </div>
        </form>
    </div>
</div>

<?php if (!$selectedOrderDate): ?>

    <div class="alert alert-info">
        Es wurde noch kein Bestelltermin gefunden.
    </div>

<?php else: ?>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <div class="text-muted small">Bestelltermin</div>
                    <div class="fw-bold"><?= h($selectedOrderDate['title']) ?></div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <div class="text-muted small">Bestellschluss</div>
                    <div class="fw-bold"><?= h(formatDateTimeLocalDealer($selectedOrderDate['deadline_at'])) ?></div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <div class="text-muted small">Familien</div>
                    <div class="fw-bold"><?= (int)$overall['family_count'] ?></div>
                </div>
            </div>
        </div>

        <div class="col-md-3">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <div class="text-muted small">Geschätzter Warenwert</div>
                    <div class="fw-bold"><?= formatAriary($overall['estimated_total']) ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-header bg-white">
            <h5 class="mb-0">
                <i class="bi bi-shop me-2"></i>
                Händlerliste
            </h5>
        </div>

        <div class="card-body p-0">
            <?php if (!$productsByCategory): ?>
                <div class="p-4 text-muted">
                    Für diesen Bestelltermin sind keine Produkte vorhanden.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead>
                        <tr>
                            <th>Produkt</th>
                            <th>Einheit</th>
                            <th class="text-end">Gesamtmenge</th>
                            <th class="text-end">Preis</th>
                            <th class="text-end">Warenwert</th>
                            <th>Bestellt von</th>
                        </tr>
                        </thead>

                        <tbody>
                        <?php foreach ($productsByCategory as $categoryName => $products): ?>
                            <tr class="table-light">
                                <td colspan="6" class="fw-bold text-uppercase">
                                    <i class="bi bi-tag me-2"></i><?= h($categoryName) ?>
                                </td>
                            </tr>

                            <?php foreach ($products as $product): ?>
                                <?php
                                $detailKey = ($product['category_name'] ?? 'Ohne Kategorie')
                                    . '|'
                                    . (int)$product['product_id']
                                    . '|'
                                    . $product['product_name']
                                    . '|'
                                    . $product['unit']
                                    . '|'
                                    . (int)$product['price'];

                                $details = $productDetails[$detailKey] ?? [];
                                ?>

                                <tr>
                                    <td>
                                        <strong><?= h($product['product_name']) ?></strong>
                                    </td>

                                    <td><?= h(unitLabelShort((string)$product['unit'])) ?></td>

                                    <td class="text-end fw-bold">
                                        <?= h(formatQuantityDealer($product['total_quantity'], (string)$product['unit'])) ?>
                                    </td>

                                    <td class="text-end">
                                        <?= formatAriary($product['price']) ?>
                                    </td>

                                    <td class="text-end fw-bold">
                                        <?= formatAriary($product['total_amount']) ?>
                                    </td>

                                    <td class="small text-muted">
                                        <?php foreach ($details as $index => $detail): ?>
                                            <?= $index > 0 ? ' · ' : '' ?>
                                            <?= h($detail['family_name']) ?>:
                                            <?= h(formatQuantityDealer($detail['quantity'], (string)$detail['unit'])) ?>
                                        <?php endforeach; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php endif; ?>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/style/layout.php';