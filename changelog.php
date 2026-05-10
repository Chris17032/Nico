<?php
require_once __DIR__ . '/config/config.php';

if (function_exists('requireLogin')) {
    $currentUser = requireLogin($pdo);
} else {
    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, family_name, `rank`, avatar, is_locked FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([(int)$_SESSION['user_id']]);
    $currentUser = $stmt->fetch();

    if (!$currentUser || (int)($currentUser['is_locked'] ?? 0) === 1) {
        session_unset();
        session_destroy();
        header('Location: login.php');
        exit;
    }
}

$pageTitle = 'Changelog';

if (!isset($APP_CHANGELOG) || !is_array($APP_CHANGELOG)) {
    $APP_CHANGELOG = [];
}

$currentRank = (int)($currentUser['rank'] ?? 1);

ob_start();
?>

<div class="mb-4 d-flex justify-content-between align-items-start gap-3 flex-wrap">
    <div>
        <h1 class="h3 mb-1">Changelog</h1>
        <p class="text-muted mb-0">
            Übersicht über Versionen, Änderungen und neue Funktionen.
        </p>
    </div>

    <div class="text-end">
        <span class="badge bg-primary fs-6">
            <?= htmlspecialchars(function_exists('appVersionFullLabel') ? appVersionFullLabel() : ('Version ' . (defined('APP_VERSION') ? APP_VERSION : 'unbekannt'))) ?>
        </span>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="small-box bg-primary">
            <div class="inner">
                <p>Aktuelle Version</p>
                <h3><?= htmlspecialchars(defined('APP_VERSION') ? APP_VERSION : '-') ?></h3>
            </div>
            <div class="icon"><i class="bi bi-code-slash"></i></div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="small-box bg-success">
            <div class="inner">
                <p>Status</p>
                <h3><?= htmlspecialchars(defined('APP_VERSION_NAME') ? APP_VERSION_NAME : '-') ?></h3>
            </div>
            <div class="icon"><i class="bi bi-check-circle"></i></div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="small-box bg-warning">
            <div class="inner">
                <p>Build</p>
                <h3><?= htmlspecialchars(defined('APP_BUILD_DATE') ? APP_BUILD_DATE : '-') ?></h3>
            </div>
            <div class="icon"><i class="bi bi-calendar-event"></i></div>
        </div>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="card-header">
        <h5 class="mb-0">
            <i class="bi bi-clock-history me-2"></i>Versionsverlauf
        </h5>
    </div>

    <div class="card-body">
        <?php if ($APP_CHANGELOG): ?>
            <div class="version-timeline">
                <?php foreach ($APP_CHANGELOG as $entry): ?>
                    <div class="version-entry">
                        <div class="version-entry-header">
                            <div>
                                <h5 class="mb-1">
                                    Version <?= htmlspecialchars($entry['version'] ?? '') ?>
                                    <?php if (!empty($entry['name'])): ?>
                                        <span class="text-muted">· <?= htmlspecialchars($entry['name']) ?></span>
                                    <?php endif; ?>
                                </h5>
                                <small class="text-muted">
                                    <?= htmlspecialchars($entry['date'] ?? '-') ?>
                                </small>
                            </div>

                            <span class="badge <?= ($entry['type'] ?? '') === 'Aktuell' ? 'bg-success' : 'bg-secondary' ?>">
                                <?= htmlspecialchars($entry['type'] ?? 'Update') ?>
                            </span>
                        </div>

                        <?php if (!empty($entry['changes']) && is_array($entry['changes'])): ?>
                            <ul class="mb-0 mt-3">
                                <?php foreach ($entry['changes'] as $change): ?>
                                    <li><?= htmlspecialchars($change) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="text-muted text-center py-4">
                Noch keine Changelog-Einträge vorhanden.
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
.version-timeline {
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.version-entry {
    border: 1px solid #dee2e6;
    border-left: 4px solid #0d6efd;
    border-radius: 8px;
    padding: 16px;
    background: #ffffff;
}

.version-entry-header {
    display: flex;
    justify-content: space-between;
    align-items: start;
    gap: 12px;
    flex-wrap: wrap;
}

.version-entry ul {
    padding-left: 1.2rem;
}

.version-entry li {
    margin-bottom: 5px;
}

body.dark-mode .version-entry {
    background: #2b3035;
    border-color: #454d55;
    border-left-color: #6ea8fe;
    color: #d6d8db;
}

body.dark-mode .version-entry h5,
body.dark-mode .version-entry li {
    color: #d6d8db;
}
</style>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/style/layout.php';
