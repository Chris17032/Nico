<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/audit_log.php';

$currentUser = requireRank($pdo, 5);
$pageTitle = 'Log';

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function logTableColumns(PDO $pdo): array
{
    return auditLogColumns($pdo);
}

function logColumnSelect(PDO $pdo): string
{
    $columns = logTableColumns($pdo);

    $select = [
        'id',
        'user_id',
        'user_name',
        'action',
        'created_at'
    ];

    $optional = [
        'category',
        'severity',
        'target_type',
        'target_id',
        'meta_json'
    ];

    foreach ($optional as $column) {
        if (isset($columns[$column])) {
            $select[] = $column;
        } else {
            $select[] = "NULL AS {$column}";
        }
    }

    return implode(', ', $select);
}

function inferLogCategory(string $action, ?string $category): string
{
    if ($category) {
        return $category;
    }

    $actionLower = mb_strtolower($action, 'UTF-8');

    if (str_contains($actionLower, 'login') || str_contains($actionLower, 'logout')) {
        return 'auth';
    }

    if (str_contains($actionLower, 'rechnung') || str_contains($actionLower, 'invoice')) {
        return 'invoice';
    }

    if (str_contains($actionLower, 'bestellung') || str_contains($actionLower, 'order')) {
        return 'order';
    }

    if (str_contains($actionLower, 'produkt') || str_contains($actionLower, 'preis')) {
        return 'product';
    }

    if (str_contains($actionLower, 'benutzer') || str_contains($actionLower, 'profil')) {
        return 'user';
    }

    if (str_contains($actionLower, 'system')) {
        return 'system';
    }

    return 'general';
}

function logCategoryLabel(string $category): string
{
    return match ($category) {
        'auth' => 'Login',
        'invoice' => 'Rechnung',
        'order' => 'Bestellung',
        'product' => 'Produkt',
        'user' => 'Benutzer',
        'system' => 'System',
        'security' => 'Sicherheit',
        default => 'Allgemein',
    };
}

function logSeverityLabel(?string $severity): string
{
    return match ($severity) {
        'success' => 'Erfolg',
        'warning' => 'Warnung',
        'danger' => 'Kritisch',
        default => 'Info',
    };
}

function logSeverityBadgeClass(?string $severity): string
{
    return match ($severity) {
        'success' => 'bg-success',
        'warning' => 'bg-warning text-dark',
        'danger' => 'bg-danger',
        default => 'bg-secondary',
    };
}

function logCategoryBadgeClass(string $category): string
{
    return match ($category) {
        'auth' => 'bg-info text-dark',
        'invoice' => 'bg-primary',
        'order' => 'bg-success',
        'product' => 'bg-warning text-dark',
        'user' => 'bg-secondary',
        'system' => 'bg-dark',
        'security' => 'bg-danger',
        default => 'bg-secondary',
    };
}

$search = trim($_GET['search'] ?? '');
$categoryFilter = trim($_GET['category'] ?? '');
$severityFilter = trim($_GET['severity'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
$limit = (int)($_GET['limit'] ?? 100);

if (!in_array($limit, [50, 100, 250, 500, 1000], true)) {
    $limit = 100;
}

$allowedCategories = [
    '',
    'auth',
    'invoice',
    'order',
    'product',
    'user',
    'system',
    'security',
    'general'
];

$allowedSeverities = [
    '',
    'info',
    'success',
    'warning',
    'danger'
];

if (!in_array($categoryFilter, $allowedCategories, true)) {
    $categoryFilter = '';
}

if (!in_array($severityFilter, $allowedSeverities, true)) {
    $severityFilter = '';
}

$params = [];
$whereParts = [];
$columns = logTableColumns($pdo);

if ($search !== '') {
    $searchParts = [
        'user_name LIKE ?',
        'action LIKE ?'
    ];

    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';

    if (isset($columns['target_type'])) {
        $searchParts[] = 'target_type LIKE ?';
        $params[] = '%' . $search . '%';
    }

    $whereParts[] = '(' . implode(' OR ', $searchParts) . ')';
}

if ($categoryFilter !== '' && isset($columns['category'])) {
    $whereParts[] = 'category = ?';
    $params[] = $categoryFilter;
}

if ($severityFilter !== '' && isset($columns['severity'])) {
    $whereParts[] = 'severity = ?';
    $params[] = $severityFilter;
}

if ($dateFrom !== '') {
    $whereParts[] = 'created_at >= ?';
    $params[] = $dateFrom . ' 00:00:00';
}

if ($dateTo !== '') {
    $whereParts[] = 'created_at <= ?';
    $params[] = $dateTo . ' 23:59:59';
}

$whereSql = $whereParts ? 'WHERE ' . implode(' AND ', $whereParts) : '';
$selectSql = logColumnSelect($pdo);

try {
    $stmt = $pdo->prepare("
        SELECT {$selectSql}
        FROM activity_logs
        {$whereSql}
        ORDER BY created_at DESC
        LIMIT {$limit}
    ");
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $countStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM activity_logs
        {$whereSql}
    ");
    $countStmt->execute($params);
    $filteredLogs = (int)$countStmt->fetchColumn();

    $totalLogs = (int)$pdo->query("SELECT COUNT(*) FROM activity_logs")->fetchColumn();
} catch (Throwable $e) {
    $logs = [];
    $filteredLogs = 0;
    $totalLogs = 0;
    $logPageError = $e->getMessage();
}

ob_start();
?>

<style>
    .simple-log-page .page-title {
        margin-bottom: 1rem;
    }

    .simple-log-page .page-title h1 {
        margin: 0;
        font-size: 1.45rem;
        font-weight: 700;
    }

    .simple-log-page .page-title p {
        margin: .25rem 0 0;
        color: #6c757d;
    }

    .simple-log-action {
        max-width: 900px;
        overflow-wrap: anywhere;
    }

    .simple-log-page .table td {
        vertical-align: middle;
    }

    .simple-log-page .badge {
        font-weight: 600;
    }
</style>

<div class="simple-log-page">
    <div class="page-title">
        <h1>Log</h1>
        <p><?= (int)$filteredLogs ?> Einträge angezeigt · <?= (int)$totalLogs ?> Einträge gesamt</p>
    </div>

    <?php if (!empty($logPageError)): ?>
        <div class="alert alert-danger">
            <strong>Log konnte nicht geladen werden:</strong><br>
            <?= h($logPageError) ?>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header">
            <strong>Filter</strong>
        </div>

        <div class="card-body">
            <form method="get" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label">Suche</label>
                    <input type="text"
                           name="search"
                           class="form-control"
                           value="<?= h($search) ?>"
                           placeholder="Benutzer, Aktion oder Ziel">
                </div>

                <div class="col-md-2">
                    <label class="form-label">Kategorie</label>
                    <select name="category" class="form-select">
                        <option value="">Alle</option>

                        <?php foreach (array_filter($allowedCategories) as $category): ?>
                            <option value="<?= h($category) ?>" <?= $categoryFilter === $category ? 'selected' : '' ?>>
                                <?= h(logCategoryLabel($category)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label">Schweregrad</label>
                    <select name="severity" class="form-select">
                        <option value="">Alle</option>

                        <?php foreach (array_filter($allowedSeverities) as $severity): ?>
                            <option value="<?= h($severity) ?>" <?= $severityFilter === $severity ? 'selected' : '' ?>>
                                <?= h(logSeverityLabel($severity)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label">Von</label>
                    <input type="date" name="date_from" class="form-control" value="<?= h($dateFrom) ?>">
                </div>

                <div class="col-md-2">
                    <label class="form-label">Bis</label>
                    <input type="date" name="date_to" class="form-control" value="<?= h($dateTo) ?>">
                </div>

                <div class="col-md-2">
                    <label class="form-label">Anzahl</label>
                    <select name="limit" class="form-select">
                        <option value="50" <?= $limit === 50 ? 'selected' : '' ?>>50</option>
                        <option value="100" <?= $limit === 100 ? 'selected' : '' ?>>100</option>
                        <option value="250" <?= $limit === 250 ? 'selected' : '' ?>>250</option>
                        <option value="500" <?= $limit === 500 ? 'selected' : '' ?>>500</option>
                        <option value="1000" <?= $limit === 1000 ? 'selected' : '' ?>>1000</option>
                    </select>
                </div>

                <div class="col-md-2">
                    <button class="btn btn-primary w-100" type="submit">
                        Anzeigen
                    </button>
                </div>

                <div class="col-md-2">
                    <a href="log.php" class="btn btn-outline-secondary w-100">
                        Zurücksetzen
                    </a>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <strong>Aktivitätslog</strong>

            <span class="text-muted small">
                Neueste Einträge zuerst
            </span>
        </div>

        <div class="card-body p-0">
            <?php if ($logs): ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                        <tr>
                            <th>Zeitpunkt</th>
                            <th>Benutzer</th>
                            <th>Kategorie</th>
                            <th>Schweregrad</th>
                            <th>Aktion</th>
                        </tr>
                        </thead>

                        <tbody>
                        <?php foreach ($logs as $log): ?>
                            <?php
                            $category = inferLogCategory((string)($log['action'] ?? ''), $log['category'] ?? null);
                            $severity = $log['severity'] ?? 'info';
                            ?>
                            <tr>
                                <td>
                                    <strong><?= h(date('d.m.Y', strtotime($log['created_at'] ?? 'now'))) ?></strong><br>
                                    <span class="text-muted small">
                                        <?= h(date('H:i:s', strtotime($log['created_at'] ?? 'now'))) ?>
                                    </span>
                                </td>

                                <td>
                                    <strong><?= h($log['user_name'] ?? 'Unbekannt') ?></strong>

                                    <?php if (!empty($log['user_id'])): ?>
                                        <div class="text-muted small">
                                            ID <?= (int)$log['user_id'] ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <span class="badge <?= h(logCategoryBadgeClass($category)) ?>">
                                        <?= h(logCategoryLabel($category)) ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="badge <?= h(logSeverityBadgeClass($severity)) ?>">
                                        <?= h(logSeverityLabel($severity)) ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="simple-log-action">
                                        <?= h((string)($log['action'] ?? '')) ?>
                                    </div>

                                    <?php if (!empty($log['target_type']) || !empty($log['target_id'])): ?>
                                        <div class="text-muted small mt-1">
                                            Ziel:
                                            <?= h($log['target_type'] ?? '-') ?>

                                            <?php if (!empty($log['target_id'])): ?>
                                                #<?= (int)$log['target_id'] ?>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="p-4 text-muted">
                    Keine Logeinträge gefunden.
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/style/layout.php';