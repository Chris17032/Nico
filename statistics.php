<?php
require_once __DIR__ . '/config/config.php';

define('APP_EAT_TIMEZONE', 'Indian/Antananarivo');
date_default_timezone_set(APP_EAT_TIMEZONE);

$currentUser = requireRank($pdo, 5);
$pageTitle = 'Statistiken';

function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function formatAriary($value)
{
    return number_format((float) $value, 0, ',', '.') . ' Ariary';
}

function tableExists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
            AND table_name = ?
        ");
        $stmt->execute([$table]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
            AND table_name = ?
            AND column_name = ?
        ");
        $stmt->execute([$table, $column]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function getMinOrderDate(PDO $pdo): string
{
    try {
        $stmt = $pdo->query("SELECT MIN(created_at) FROM order_groups WHERE created_at IS NOT NULL");
        $value = $stmt->fetchColumn();

        if ($value) {
            return (new DateTimeImmutable($value, new DateTimeZone(APP_EAT_TIMEZONE)))->format('Y-m-d 00:00:00');
        }
    } catch (Exception $e) {
    }

    return (new DateTimeImmutable('-12 months', new DateTimeZone(APP_EAT_TIMEZONE)))->format('Y-m-d 00:00:00');
}

function getRange(PDO $pdo, int $days): array
{
    $timezone = new DateTimeZone(APP_EAT_TIMEZONE);
    $end = new DateTimeImmutable('tomorrow', $timezone);

    if ($days === 0) {
        $start = new DateTimeImmutable(getMinOrderDate($pdo), $timezone);
    } else {
        $start = (new DateTimeImmutable('today', $timezone))->modify('-' . ($days - 1) . ' days');
    }

    return [
        'start' => $start->format('Y-m-d 00:00:00'),
        'end' => $end->format('Y-m-d 00:00:00'),
    ];
}

function mysqlPeriodExpression(string $group): string
{
    if ($group === 'month') {
        return "DATE_FORMAT(created_at, '%Y-%m')";
    }

    if ($group === 'week') {
        return "YEARWEEK(created_at, 3)";
    }

    return "DATE(created_at)";
}

function mysqlPeriodExpressionWithAlias(string $group, string $alias): string
{
    if ($group === 'month') {
        return "DATE_FORMAT($alias.created_at, '%Y-%m')";
    }

    if ($group === 'week') {
        return "YEARWEEK($alias.created_at, 3)";
    }

    return "DATE($alias.created_at)";
}

function buildPeriods(string $start, string $end, string $group): array
{
    $timezone = new DateTimeZone(APP_EAT_TIMEZONE);
    $startDate = new DateTimeImmutable($start, $timezone);
    $endDate = new DateTimeImmutable($end, $timezone);

    if ($group === 'month') {
        $current = $startDate->modify('first day of this month')->setTime(0, 0);
        $step = '+1 month';
    } elseif ($group === 'week') {
        $current = $startDate->modify('monday this week')->setTime(0, 0);
        $step = '+1 week';
    } else {
        $current = $startDate->setTime(0, 0);
        $step = '+1 day';
    }

    $periods = [];

    while ($current < $endDate) {
        if ($group === 'month') {
            $key = $current->format('Y-m');
            $label = $current->format('m.Y');
        } elseif ($group === 'week') {
            $key = $current->format('o') . $current->format('W');
            $label = 'KW ' . $current->format('W') . ' · ' . $current->format('Y');
        } else {
            $key = $current->format('Y-m-d');
            $label = $current->format('d.m.');
        }

        $periods[$key] = [
            'label' => $label,
            'revenue' => 0,
            'subtotal' => 0,
            'orders' => 0,
            'buyers' => 0,
            'ordered_products' => 0,
            'unique_products' => 0,
            'average_order' => 0,
            'highest_order' => 0,
            'logins' => 0,
        ];

        $current = $current->modify($step);
    }

    return $periods;
}

function fetchMainStats(PDO $pdo, array $range, string $group, array $periods): array
{
    $periodExpr = mysqlPeriodExpressionWithAlias($group, 'og');

    try {
        $stmt = $pdo->prepare("
            SELECT
                $periodExpr AS period_key,
                COUNT(DISTINCT og.id) AS orders,
                COUNT(DISTINCT og.user_id) AS buyers,
                COALESCE(SUM(og.total), 0) AS revenue,
                COALESCE(SUM(og.subtotal), 0) AS subtotal,
                COALESCE(AVG(NULLIF(og.total, 0)), 0) AS average_order,
                COALESCE(MAX(og.total), 0) AS highest_order
            FROM order_groups og
            WHERE og.created_at >= ?
            AND og.created_at < ?
            AND EXISTS (
                SELECT 1
                FROM order_items oi
                WHERE oi.order_group_id = og.id
            )
            GROUP BY period_key
            ORDER BY MIN(og.created_at) ASC
        ");
        $stmt->execute([$range['start'], $range['end']]);

        foreach ($stmt->fetchAll() as $row) {
            $key = (string) $row['period_key'];

            if (!isset($periods[$key])) {
                continue;
            }

            $periods[$key]['orders'] = (int) $row['orders'];
            $periods[$key]['buyers'] = (int) $row['buyers'];
            $periods[$key]['revenue'] = (float) $row['revenue'];
            $periods[$key]['subtotal'] = (float) $row['subtotal'];
            $periods[$key]['average_order'] = (float) $row['average_order'];
            $periods[$key]['highest_order'] = (float) $row['highest_order'];
        }
    } catch (Exception $e) {
    }

    return $periods;
}

function fetchProductStats(PDO $pdo, array $range, string $group, array $periods): array
{
    $periodExpr = mysqlPeriodExpressionWithAlias($group, 'og');

    try {
        $stmt = $pdo->prepare("
            SELECT
                $periodExpr AS period_key,
                COALESCE(SUM(oi.quantity), 0) AS ordered_products,
                COUNT(DISTINCT oi.product_id) AS unique_products
            FROM order_items oi
            INNER JOIN order_groups og ON og.id = oi.order_group_id
            WHERE og.created_at >= ?
            AND og.created_at < ?
            GROUP BY period_key
            ORDER BY MIN(og.created_at) ASC
        ");
        $stmt->execute([$range['start'], $range['end']]);

        foreach ($stmt->fetchAll() as $row) {
            $key = (string) $row['period_key'];

            if (!isset($periods[$key])) {
                continue;
            }

            $periods[$key]['ordered_products'] = (float) $row['ordered_products'];
            $periods[$key]['unique_products'] = (int) $row['unique_products'];
        }
    } catch (Exception $e) {
    }

    return $periods;
}

function fetchLoginStats(PDO $pdo, array $range, string $group, array $periods): array
{
    try {
        if (
            tableExists($pdo, 'activity_logs')
            && columnExists($pdo, 'activity_logs', 'created_at')
            && columnExists($pdo, 'activity_logs', 'action')
        ) {
            $periodExpr = mysqlPeriodExpressionWithAlias($group, 'al');

            $stmt = $pdo->prepare("
                SELECT
                    $periodExpr AS period_key,
                    COUNT(*) AS logins
                FROM activity_logs al
                WHERE al.created_at >= ?
                AND al.created_at < ?
                AND (
                    LOWER(al.action) LIKE '%login%'
                    OR LOWER(al.action) LIKE '%eingeloggt%'
                    OR LOWER(al.action) LIKE '%angemeldet%'
                    OR LOWER(al.action) LIKE '%anmeldung%'
                )
                GROUP BY period_key
                ORDER BY MIN(al.created_at) ASC
            ");
            $stmt->execute([$range['start'], $range['end']]);

            foreach ($stmt->fetchAll() as $row) {
                $key = (string) $row['period_key'];

                if (isset($periods[$key])) {
                    $periods[$key]['logins'] = (int) $row['logins'];
                }
            }

            return $periods;
        }

        if (
            tableExists($pdo, 'login_logs')
            && columnExists($pdo, 'login_logs', 'created_at')
        ) {
            $periodExpr = mysqlPeriodExpressionWithAlias($group, 'll');

            $stmt = $pdo->prepare("
                SELECT
                    $periodExpr AS period_key,
                    COUNT(*) AS logins
                FROM login_logs ll
                WHERE ll.created_at >= ?
                AND ll.created_at < ?
                GROUP BY period_key
                ORDER BY MIN(ll.created_at) ASC
            ");
            $stmt->execute([$range['start'], $range['end']]);

            foreach ($stmt->fetchAll() as $row) {
                $key = (string) $row['period_key'];

                if (isset($periods[$key])) {
                    $periods[$key]['logins'] = (int) $row['logins'];
                }
            }

            return $periods;
        }

        if (
            tableExists($pdo, 'users')
            && columnExists($pdo, 'users', 'last_login')
        ) {
            $periodExpr = str_replace('created_at', 'last_login', mysqlPeriodExpressionWithAlias($group, 'u'));

            $stmt = $pdo->prepare("
                SELECT
                    $periodExpr AS period_key,
                    COUNT(*) AS logins
                FROM users u
                WHERE u.last_login IS NOT NULL
                AND u.last_login >= ?
                AND u.last_login < ?
                GROUP BY period_key
            ");
            $stmt->execute([$range['start'], $range['end']]);

            foreach ($stmt->fetchAll() as $row) {
                $key = (string) $row['period_key'];

                if (isset($periods[$key])) {
                    $periods[$key]['logins'] = (int) $row['logins'];
                }
            }
        }
    } catch (Exception $e) {
    }

    return $periods;
}

function fetchTopProducts(PDO $pdo, array $range, string $group, array $periodKeys): array
{
    $result = [
        'labels' => [],
        'series' => [],
    ];

    try {
        $stmt = $pdo->prepare("
            SELECT
                oi.product_id,
                oi.product_name,
                SUM(oi.quantity) AS total_quantity
            FROM order_items oi
            INNER JOIN order_groups og ON og.id = oi.order_group_id
            WHERE og.created_at >= ?
            AND og.created_at < ?
            AND oi.product_id IS NOT NULL
            GROUP BY oi.product_id, oi.product_name
            ORDER BY total_quantity DESC
            LIMIT 5
        ");
        $stmt->execute([$range['start'], $range['end']]);
        $products = $stmt->fetchAll();

        if (!$products) {
            return $result;
        }

        $ids = array_map(static fn($row) => (int) $row['product_id'], $products);
        $names = [];

        foreach ($products as $product) {
            $names[(int) $product['product_id']] = $product['product_name'];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $periodExpr = mysqlPeriodExpressionWithAlias($group, 'og');

        $params = array_merge([$range['start'], $range['end']], $ids);

        $stmt = $pdo->prepare("
            SELECT
                oi.product_id,
                $periodExpr AS period_key,
                SUM(oi.quantity) AS quantity
            FROM order_items oi
            INNER JOIN order_groups og ON og.id = oi.order_group_id
            WHERE og.created_at >= ?
            AND og.created_at < ?
            AND oi.product_id IN ($placeholders)
            GROUP BY oi.product_id, period_key
        ");
        $stmt->execute($params);

        foreach ($products as $product) {
            $productId = (int) $product['product_id'];
            $result['series'][$productId] = [
                'label' => $names[$productId],
                'data' => array_fill(0, count($periodKeys), 0),
            ];
        }

        $keyIndex = array_flip($periodKeys);

        foreach ($stmt->fetchAll() as $row) {
            $productId = (int) $row['product_id'];
            $periodKey = (string) $row['period_key'];

            if (isset($result['series'][$productId], $keyIndex[$periodKey])) {
                $result['series'][$productId]['data'][$keyIndex[$periodKey]] = (float) $row['quantity'];
            }
        }

        $result['series'] = array_values($result['series']);
    } catch (Exception $e) {
    }

    return $result;
}

function fetchTopCategories(PDO $pdo, array $range, string $group, array $periodKeys): array
{
    $result = [
        'labels' => [],
        'series' => [],
    ];

    try {
        $stmt = $pdo->prepare("
            SELECT
                COALESCE(pc.name, 'Ohne Kategorie') AS category_name,
                SUM(oi.row_total) AS total_value
            FROM order_items oi
            INNER JOIN order_groups og ON og.id = oi.order_group_id
            LEFT JOIN products p ON p.id = oi.product_id
            LEFT JOIN product_categories pc ON pc.id = p.category_id
            WHERE og.created_at >= ?
            AND og.created_at < ?
            GROUP BY COALESCE(pc.name, 'Ohne Kategorie')
            ORDER BY total_value DESC
            LIMIT 5
        ");
        $stmt->execute([$range['start'], $range['end']]);
        $categories = $stmt->fetchAll();

        if (!$categories) {
            return $result;
        }

        $names = array_map(static fn($row) => (string) $row['category_name'], $categories);
        $placeholders = implode(',', array_fill(0, count($names), '?'));
        $periodExpr = mysqlPeriodExpressionWithAlias($group, 'og');

        $params = array_merge([$range['start'], $range['end']], $names);

        $stmt = $pdo->prepare("
            SELECT
                COALESCE(pc.name, 'Ohne Kategorie') AS category_name,
                $periodExpr AS period_key,
                SUM(oi.row_total) AS value
            FROM order_items oi
            INNER JOIN order_groups og ON og.id = oi.order_group_id
            LEFT JOIN products p ON p.id = oi.product_id
            LEFT JOIN product_categories pc ON pc.id = p.category_id
            WHERE og.created_at >= ?
            AND og.created_at < ?
            AND COALESCE(pc.name, 'Ohne Kategorie') IN ($placeholders)
            GROUP BY COALESCE(pc.name, 'Ohne Kategorie'), period_key
        ");
        $stmt->execute($params);

        foreach ($names as $name) {
            $result['series'][$name] = [
                'label' => $name,
                'data' => array_fill(0, count($periodKeys), 0),
            ];
        }

        $keyIndex = array_flip($periodKeys);

        foreach ($stmt->fetchAll() as $row) {
            $name = (string) $row['category_name'];
            $periodKey = (string) $row['period_key'];

            if (isset($result['series'][$name], $keyIndex[$periodKey])) {
                $result['series'][$name]['data'][$keyIndex[$periodKey]] = (float) $row['value'];
            }
        }

        $result['series'] = array_values($result['series']);
    } catch (Exception $e) {
    }

    return $result;
}

$allowedRanges = [30, 90, 180, 365, 0];
$days = isset($_GET['days']) ? (int) $_GET['days'] : 365;

if (!in_array($days, $allowedRanges, true)) {
    $days = 365;
}

$allowedGroups = ['day', 'week', 'month'];
$group = $_GET['group'] ?? 'month';

if (!in_array($group, $allowedGroups, true)) {
    $group = 'month';
}

if ($days <= 90 && !isset($_GET['group'])) {
    $group = 'day';
} elseif ($days <= 180 && !isset($_GET['group'])) {
    $group = 'week';
} elseif (!isset($_GET['group'])) {
    $group = 'month';
}

$range = getRange($pdo, $days);
$periods = buildPeriods($range['start'], $range['end'], $group);

$periods = fetchMainStats($pdo, $range, $group, $periods);
$periods = fetchProductStats($pdo, $range, $group, $periods);
$periods = fetchLoginStats($pdo, $range, $group, $periods);

$periodKeys = array_keys($periods);
$labels = array_column($periods, 'label');

$revenueValues = array_column($periods, 'revenue');
$subtotalValues = array_column($periods, 'subtotal');
$orderValues = array_column($periods, 'orders');
$buyerValues = array_column($periods, 'buyers');
$orderedProductsValues = array_column($periods, 'ordered_products');
$uniqueProductsValues = array_column($periods, 'unique_products');
$averageOrderValues = array_column($periods, 'average_order');
$highestOrderValues = array_column($periods, 'highest_order');
$loginValues = array_column($periods, 'logins');

$topProducts = fetchTopProducts($pdo, $range, $group, $periodKeys);
$topCategories = fetchTopCategories($pdo, $range, $group, $periodKeys);

$totalRevenue = array_sum($revenueValues);
$totalOrders = array_sum($orderValues);
$totalProducts = array_sum($orderedProductsValues);
$totalBuyers = count($buyerValues) ? max($buyerValues) : 0;
$totalLogins = array_sum($loginValues);

$averageRevenuePerOrder = $totalOrders > 0 ? $totalRevenue / $totalOrders : 0;

ob_start();
?>

<div class="statistics-page">

    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-4">
        <div>
            <h1 class="h3 mb-1">Statistiken</h1>
            <p class="text-muted mb-0">
                Entwicklung über alle Bestellungen hinweg.
            </p>
        </div>

        <form method="get" class="statistics-filter">
            <div class="filter-box">
                <label>Zeitraum</label>
                <select name="days" class="form-select" onchange="this.form.submit()">
                    <option value="30" <?= $days === 30 ? 'selected' : '' ?>>Letzte 30 Tage</option>
                    <option value="90" <?= $days === 90 ? 'selected' : '' ?>>Letzte 90 Tage</option>
                    <option value="180" <?= $days === 180 ? 'selected' : '' ?>>Letzte 180 Tage</option>
                    <option value="365" <?= $days === 365 ? 'selected' : '' ?>>Letzte 365 Tage</option>
                    <option value="0" <?= $days === 0 ? 'selected' : '' ?>>Alle Bestellungen</option>
                </select>
            </div>

            <div class="filter-box">
                <label>Gruppierung</label>
                <select name="group" class="form-select" onchange="this.form.submit()">
                    <option value="day" <?= $group === 'day' ? 'selected' : '' ?>>Täglich</option>
                    <option value="week" <?= $group === 'week' ? 'selected' : '' ?>>Wöchentlich</option>
                    <option value="month" <?= $group === 'month' ? 'selected' : '' ?>>Monatlich</option>
                </select>
            </div>
        </form>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12 col-lg-3">
            <div class="stat-summary-card primary">
                <span>Bestellwert</span>
                <strong><?= formatAriary($totalRevenue) ?></strong>
                <small>Alle gefilterten Bestellungen</small>
            </div>
        </div>

        <div class="col-12 col-lg-3">
            <div class="stat-summary-card">
                <span>Bestellungen</span>
                <strong><?= number_format($totalOrders, 0, ',', '.') ?></strong>
                <small>Mit bestellten Produkten</small>
            </div>
        </div>

        <div class="col-12 col-lg-3">
            <div class="stat-summary-card">
                <span>Bestellte Produkte</span>
                <strong><?= number_format($totalProducts, 0, ',', '.') ?></strong>
                <small>Gesamtmenge</small>
            </div>
        </div>

        <div class="col-12 col-lg-3">
            <div class="stat-summary-card">
                <span>Ø Bestellwert</span>
                <strong><?= formatAriary($averageRevenuePerOrder) ?></strong>
                <small>Pro Bestellung</small>
            </div>
        </div>
    </div>

    <div class="chart-grid">
        <div class="stat-chart-card wide">
            <div class="chart-head">
                <div>
                    <h5>Bestellwert und Bestellungen</h5>
                    <p>Zeigt, ob mehr Bestellungen auch wirklich mehr Umsatz bringen.</p>
                </div>
            </div>
            <div class="chart-wrapper">
                <canvas id="revenueOrdersChart"></canvas>
            </div>
        </div>

        <div class="stat-chart-card">
            <div class="chart-head">
                <div>
                    <h5>Besteller und Bestellungen</h5>
                    <p>Vergleich zwischen aktiven Bestellern und Bestellanzahl.</p>
                </div>
            </div>
            <div class="chart-wrapper">
                <canvas id="buyersOrdersChart"></canvas>
            </div>
        </div>

        <div class="stat-chart-card">
            <div class="chart-head">
                <div>
                    <h5>Produkte</h5>
                    <p>Bestellte Menge im Vergleich zu unterschiedlichen Produkten.</p>
                </div>
            </div>
            <div class="chart-wrapper">
                <canvas id="productsChart"></canvas>
            </div>
        </div>

        <div class="stat-chart-card">
            <div class="chart-head">
                <div>
                    <h5>Bestellwert pro Bestellung</h5>
                    <p>Durchschnittlicher Wert im Vergleich zur höchsten Bestellung.</p>
                </div>
            </div>
            <div class="chart-wrapper">
                <canvas id="orderValueQualityChart"></canvas>
            </div>
        </div>

        <div class="stat-chart-card">
            <div class="chart-head">
                <div>
                    <h5>Produktsumme und Gesamtwert</h5>
                    <p>Vergleich zwischen reiner Produktsumme und finalem Bestellwert.</p>
                </div>
            </div>
            <div class="chart-wrapper">
                <canvas id="subtotalRevenueChart"></canvas>
            </div>
        </div>

        <div class="stat-chart-card wide">
            <div class="chart-head">
                <div>
                    <h5>Top Produkte im Verlauf</h5>
                    <p>Die 5 meistbestellten Produkte im ausgewählten Zeitraum.</p>
                </div>
            </div>
            <div class="chart-wrapper">
                <canvas id="topProductsChart"></canvas>
            </div>
        </div>

        <div class="stat-chart-card wide">
            <div class="chart-head">
                <div>
                    <h5>Top Kategorien im Verlauf</h5>
                    <p>Die stärksten Kategorien nach Bestellwert.</p>
                </div>
            </div>
            <div class="chart-wrapper">
                <canvas id="topCategoriesChart"></canvas>
            </div>
        </div>

        <div class="stat-chart-card wide">
            <div class="chart-head">
                <div>
                    <h5>Logins und Bestellungen</h5>
                    <p>Zeigt, ob mehr Aktivität auch zu mehr Bestellungen führt.</p>
                </div>
            </div>
            <div class="chart-wrapper">
                <canvas id="loginsOrdersChart"></canvas>
            </div>

            <?php if ($totalLogins <= 0): ?>
                <div class="alert alert-warning mt-3 mb-0">
                    Es wurden keine Login-Daten gefunden. Damit dieses Diagramm echte Werte zeigt,
                    müssen Logins in <code>activity_logs</code>, <code>login_logs</code> oder <code>users.last_login</code> gespeichert werden.
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<style>
.statistics-page {
    --stat-border: #e5e7eb;
    --stat-muted: #64748b;
    --stat-text: #111827;
    --stat-soft: #f8fafc;
    --stat-card: #ffffff;
}

.statistics-filter {
    display: flex;
    gap: .75rem;
    flex-wrap: wrap;
    align-items: end;
}

.filter-box {
    min-width: 190px;
}

.filter-box label {
    display: block;
    font-size: .8rem;
    font-weight: 800;
    color: var(--stat-muted);
    margin-bottom: .25rem;
}

.stat-summary-card {
    height: 100%;
    border: 1px solid var(--stat-border);
    background: linear-gradient(180deg, #ffffff, #f8fafc);
    border-radius: 1.1rem;
    padding: 1.15rem;
    box-shadow: 0 .125rem .65rem rgba(15,23,42,.06);
}

.stat-summary-card.primary {
    background: linear-gradient(135deg, #1d4ed8, #0f172a);
    color: #fff;
}

.stat-summary-card span {
    display: block;
    color: var(--stat-muted);
    font-size: .9rem;
    margin-bottom: .35rem;
}

.stat-summary-card.primary span,
.stat-summary-card.primary small {
    color: #dbeafe;
}

.stat-summary-card strong {
    display: block;
    font-size: clamp(1.25rem, 2vw, 1.75rem);
    line-height: 1.1;
    color: var(--stat-text);
    margin-bottom: .35rem;
}

.stat-summary-card.primary strong {
    color: #fff;
}

.stat-summary-card small {
    color: var(--stat-muted);
}

.chart-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1.25rem;
}

.stat-chart-card {
    background: var(--stat-card);
    border: 1px solid var(--stat-border);
    border-radius: 1.15rem;
    padding: 1.25rem;
    box-shadow: 0 .125rem .65rem rgba(15,23,42,.06);
}

.stat-chart-card.wide {
    grid-column: 1 / -1;
}

.chart-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1rem;
}

.chart-head h5 {
    margin: 0 0 .25rem;
    font-weight: 800;
}

.chart-head p {
    margin: 0;
    color: var(--stat-muted);
    font-size: .9rem;
}

.chart-wrapper {
    position: relative;
    width: 100%;
    height: 350px;
}

body.dark-mode .statistics-page {
    --stat-border: #475569;
    --stat-muted: #cbd5e1;
    --stat-text: #f8fafc;
    --stat-soft: #1f2937;
    --stat-card: #2b3035;
}

body.dark-mode .stat-summary-card,
body.dark-mode .stat-chart-card {
    background: #2b3035 !important;
    color: #f8fafc !important;
    border-color: #475569 !important;
}

body.dark-mode .stat-summary-card strong {
    color: #f8fafc;
}

body.dark-mode .stat-summary-card.primary {
    background: linear-gradient(135deg, #1d4ed8, #020617) !important;
}

@media (max-width: 992px) {
    .chart-grid {
        grid-template-columns: 1fr;
    }

    .statistics-filter,
    .filter-box {
        width: 100%;
    }
}

@media (max-width: 768px) {
    .chart-wrapper {
        height: 290px;
    }
}
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
const chartLabels = <?= json_encode($labels, JSON_UNESCAPED_UNICODE) ?>;

const revenueData = <?= json_encode($revenueValues, JSON_UNESCAPED_UNICODE) ?>;
const subtotalData = <?= json_encode($subtotalValues, JSON_UNESCAPED_UNICODE) ?>;
const orderData = <?= json_encode($orderValues, JSON_UNESCAPED_UNICODE) ?>;
const buyerData = <?= json_encode($buyerValues, JSON_UNESCAPED_UNICODE) ?>;
const orderedProductsData = <?= json_encode($orderedProductsValues, JSON_UNESCAPED_UNICODE) ?>;
const uniqueProductsData = <?= json_encode($uniqueProductsValues, JSON_UNESCAPED_UNICODE) ?>;
const averageOrderData = <?= json_encode($averageOrderValues, JSON_UNESCAPED_UNICODE) ?>;
const highestOrderData = <?= json_encode($highestOrderValues, JSON_UNESCAPED_UNICODE) ?>;
const loginData = <?= json_encode($loginValues, JSON_UNESCAPED_UNICODE) ?>;

const topProductSeries = <?= json_encode($topProducts['series'], JSON_UNESCAPED_UNICODE) ?>;
const topCategorySeries = <?= json_encode($topCategories['series'], JSON_UNESCAPED_UNICODE) ?>;

const colors = [
    'rgba(37, 99, 235, 1)',
    'rgba(22, 163, 74, 1)',
    'rgba(249, 115, 22, 1)',
    'rgba(168, 85, 247, 1)',
    'rgba(236, 72, 153, 1)',
    'rgba(14, 165, 233, 1)',
    'rgba(234, 179, 8, 1)'
];

function formatAriary(value) {
    return new Intl.NumberFormat('de-DE', {
        maximumFractionDigits: 0
    }).format(value) + ' Ariary';
}

function formatNumber(value) {
    return new Intl.NumberFormat('de-DE', {
        maximumFractionDigits: 0
    }).format(value);
}

function dataset(label, data, color, yAxisID = 'y') {
    return {
        label: label,
        data: data,
        borderColor: color,
        backgroundColor: color.replace('1)', '0.08)'),
        pointBackgroundColor: color,
        pointBorderColor: '#fff',
        pointBorderWidth: 2,
        pointRadius: 3,
        pointHoverRadius: 6,
        borderWidth: 3,
        tension: 0.35,
        fill: false,
        yAxisID: yAxisID
    };
}

function createLineChart(canvasId, datasets, formatter, secondFormatter = null) {
    const canvas = document.getElementById(canvasId);

    if (!canvas) {
        return;
    }

    const hasSecondAxis = datasets.some(item => item.yAxisID === 'y1');

    new Chart(canvas.getContext('2d'), {
        type: 'line',
        data: {
            labels: chartLabels,
            datasets: datasets
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false
            },
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        usePointStyle: true,
                        boxWidth: 8,
                        padding: 18
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const usedFormatter = context.dataset.yAxisID === 'y1' && secondFormatter
                                ? secondFormatter
                                : formatter;

                            return context.dataset.label + ': ' + usedFormatter(context.parsed.y || 0);
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: {
                        display: false
                    },
                    ticks: {
                        maxRotation: 45,
                        minRotation: 0,
                        autoSkip: true,
                        maxTicksLimit: 10
                    }
                },
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return formatter(value);
                        }
                    }
                },
                y1: {
                    display: hasSecondAxis,
                    position: 'right',
                    beginAtZero: true,
                    grid: {
                        drawOnChartArea: false
                    },
                    ticks: {
                        callback: function(value) {
                            return secondFormatter ? secondFormatter(value) : value;
                        }
                    }
                }
            }
        }
    });
}

function createDynamicLineChart(canvasId, series, formatter, colorOffset = 0) {
    const datasets = series.map((item, index) => {
        return dataset(
            item.label,
            item.data,
            colors[(index + colorOffset) % colors.length],
            'y'
        );
    });

    createLineChart(canvasId, datasets, formatter);
}

createLineChart(
    'revenueOrdersChart',
    [
        dataset('Bestellwert', revenueData, colors[0], 'y'),
        dataset('Bestellungen', orderData, colors[2], 'y1')
    ],
    formatAriary,
    formatNumber
);

createLineChart(
    'buyersOrdersChart',
    [
        dataset('Besteller', buyerData, colors[3], 'y'),
        dataset('Bestellungen', orderData, colors[5], 'y')
    ],
    formatNumber
);

createLineChart(
    'productsChart',
    [
        dataset('Bestellte Produkte', orderedProductsData, colors[1], 'y'),
        dataset('Unterschiedliche Produkte', uniqueProductsData, colors[4], 'y1')
    ],
    formatNumber,
    formatNumber
);

createLineChart(
    'orderValueQualityChart',
    [
        dataset('Ø Bestellwert', averageOrderData, colors[2], 'y'),
        dataset('Höchste Bestellung', highestOrderData, colors[6], 'y')
    ],
    formatAriary
);

createLineChart(
    'subtotalRevenueChart',
    [
        dataset('Produktsumme', subtotalData, colors[1], 'y'),
        dataset('Gesamtwert', revenueData, colors[0], 'y')
    ],
    formatAriary
);

createDynamicLineChart(
    'topProductsChart',
    topProductSeries,
    formatNumber,
    0
);

createDynamicLineChart(
    'topCategoriesChart',
    topCategorySeries,
    formatAriary,
    2
);

createLineChart(
    'loginsOrdersChart',
    [
        dataset('Logins', loginData, colors[2], 'y'),
        dataset('Bestellungen', orderData, colors[0], 'y1')
    ],
    formatNumber,
    formatNumber
);
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/style/layout.php';