<?php
// Debug-Hilfe gegen weiße Seiten: Fehler werden angezeigt und in php_page_errors.log gespeichert.
if (!defined('APP_PAGE_DEBUG')) {
    define('APP_PAGE_DEBUG', true);
}
if (APP_PAGE_DEBUG) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}
if (!function_exists('app_page_debug_log')) {
    function app_page_debug_log($message) {
        @file_put_contents(__DIR__ . '/php_page_errors.log', '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL, FILE_APPEND);
    }
}
if (!function_exists('app_page_debug_render')) {
    function app_page_debug_render($title, $message, $file = '', $line = '') {
        if (PHP_SAPI === 'cli') { return; }
        if (!headers_sent()) { http_response_code(500); header('Content-Type: text/html; charset=utf-8'); }
        echo '<!doctype html><meta charset="utf-8"><div style="max-width:980px;margin:40px auto;padding:22px;border:2px solid #dc2626;border-radius:14px;background:#fff5f5;color:#111;font-family:Arial,sans-serif;line-height:1.45">';
        echo '<h1 style="margin:0 0 12px;color:#b91c1c">Fehler auf dieser Seite</h1>';
        echo '<p>Die Seite ist nicht mehr weiß, sondern zeigt jetzt den echten PHP-/Datenbankfehler an.</p>';
        echo '<p><strong>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . ':</strong> ' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
        if ($file !== '') { echo '<p><strong>Datei:</strong> ' . htmlspecialchars($file, ENT_QUOTES, 'UTF-8') . ($line !== '' ? ' Zeile ' . htmlspecialchars((string)$line, ENT_QUOTES, 'UTF-8') : '') . '</p>'; }
        echo '<p style="font-size:13px;color:#555">Zusätzlich wurde der Fehler in <code>php_page_errors.log</code> im selben Ordner gespeichert.</p>';
        echo '</div>';
    }
}
set_exception_handler(function($e) {
    app_page_debug_log(get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    app_page_debug_render(get_class($e), $e->getMessage(), $e->getFile(), $e->getLine());
    exit;
});
register_shutdown_function(function() {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        app_page_debug_log('Fatal error: ' . $e['message'] . ' in ' . $e['file'] . ':' . $e['line']);
        app_page_debug_render('Fatal error', $e['message'], $e['file'], $e['line']);
    }
});

require_once __DIR__ . '/config/config.php';

if (!isset($ROLES) || !is_array($ROLES)) {
    $ROLES = [
        6 => 'Web-Dev',
        5 => 'Admin',
        4 => 'Admin (eingeschränkt)'
    ];
}

if (!isset($ALLOWED_RANKS) || !is_array($ALLOWED_RANKS)) {
    $ALLOWED_RANKS = array_keys($ROLES);
}

function employeesPageRoleName($rank)
{
    global $ROLES;
    $rank = (int)$rank;
    return $ROLES[$rank] ?? ($rank <= 1 ? 'Benutzer' : 'Unbekannt');
}

function employeesPageFamilyInitials($name)
{
    $name = trim((string)$name);
    if ($name === '') return 'U';

    $parts = preg_split('/\s+/', $name);
    $initials = '';

    foreach ($parts as $part) {
        if ($part === '') continue;
        $initials .= strtoupper(substr($part, 0, 1));
        if (strlen($initials) >= 2) break;
    }

    return $initials ?: 'U';
}

function employeesPageFamilyAvatarPath($avatar)
{
    $avatar = trim((string)$avatar);
    return $avatar !== '' ? $avatar : null;
}

$currentUser = requireRank($pdo, 5);
$currentRank = (int)$currentUser['rank'];

function employeesPageJsonResponse($success, $message, $data = [])
{
    while (ob_get_level() > 0) { ob_end_clean(); }
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }

    echo json_encode([
        'success' => (bool)$success,
        'message' => (string)$message,
        'data' => $data
    ]);
    exit;
}

function employeesPageWriteActivityLog($pdo, $action, $targetUserId = null, $targetUserName = null, array $options = [])
{
    global $currentUser;

    try {
        if (function_exists('auditLog')) {
            auditLog($pdo, $currentUser ?? null, (string)$action, array_merge([
                'category' => 'user',
                'severity' => 'info',
                'target_type' => $targetUserId !== null ? 'user' : null,
                'target_id' => $targetUserId !== null ? (int)$targetUserId : null,
                'meta' => [
                    'target_user_id' => $targetUserId !== null ? (int)$targetUserId : null,
                    'target_user_name' => $targetUserName
                ]
            ], $options));

            return;
        }

        $logUserId = $targetUserId !== null ? (int)$targetUserId : (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null);
        $logUserName = $targetUserName !== null ? $targetUserName : ($currentUser['family_name'] ?? 'System');

        $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, user_name, action) VALUES (?, ?, ?)");
        $stmt->execute([$logUserId, $logUserName, $action]);
    } catch (Throwable $e) {
        // Logging darf die eigentliche Aktion niemals blockieren.
    }
}

function employeesPageCreateRecoveryCode($familyName)
{
    $prefix = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $familyName), 0, 3));
    return ($prefix ?: 'FAM') . '-' . random_int(1000, 9999);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    $action = $_POST['ajax_action'];

    if ($action === 'create_family') {
        $familyName = trim($_POST['family_name'] ?? '');
        $pin = trim($_POST['pin'] ?? '');

        if ($familyName === '') {
            employeesPageJsonResponse(false, 'Bitte Benutzernamen eingeben.');
        }

        if (!preg_match('/^[0-9]{4,6}$/', $pin)) {
            employeesPageJsonResponse(false, 'PIN muss 4-6 Zahlen haben.');
        }

        $pinHash = password_hash($pin, PASSWORD_DEFAULT);
        $recoveryCode = employeesPageCreateRecoveryCode($familyName);
        $recoveryHash = password_hash($recoveryCode, PASSWORD_DEFAULT);

        try {
            $stmt = $pdo->prepare("
                INSERT INTO users (family_name, pin_hash, recovery_code_hash, role, `rank`)
                VALUES (?, ?, ?, 'family', 1)
            ");
            $stmt->execute([$familyName, $pinHash, $recoveryHash]);
            $newUserId = (int)$pdo->lastInsertId();

            employeesPageWriteActivityLog($pdo, 'Benutzer erstellt: ' . $familyName, $newUserId, $familyName, [
                'severity' => 'success',
                'target_type' => 'user',
                'target_id' => $newUserId,
                'meta' => [
                    'created_user_id' => $newUserId,
                    'family_name' => $familyName,
                    'rank' => 1,
                    'role' => 'family',
                    'recovery_code_created' => true
                ]
            ]);

            employeesPageJsonResponse(true, 'Account wurde erstellt.', [
                'family_name' => $familyName,
                'recovery_code' => $recoveryCode
            ]);
        } catch (Exception $e) {
            employeesPageJsonResponse(false, 'Fehler: Account konnte nicht erstellt werden.');
        }
    }

    if ($action === 'update_family') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $familyName = trim($_POST['family_name'] ?? '');
        $rank = (int)($_POST['rank'] ?? 1);
        $discount = max(0, min(100, (int)($_POST['discount_percent'] ?? 0)));
        $serviceFeeWaived = (int)($_POST['service_fee_waived'] ?? 0);
        $shoppingFeeWaived = (int)($_POST['shopping_fee_waived'] ?? 0);

        if ($userId <= 0) {
            employeesPageJsonResponse(false, 'Ungültiger Benutzer.');
        }

        if ($familyName === '') {
            employeesPageJsonResponse(false, 'Benutzername darf nicht leer sein.');
        }

        $stmt = $pdo->prepare("
            SELECT family_name, `rank`, discount_percent, service_fee_waived, shopping_fee_waived
            FROM users
            WHERE id = ?
        ");
        $stmt->execute([$userId]);
        $existingUser = $stmt->fetch();

        if (!$existingUser) {
            employeesPageJsonResponse(false, 'Account wurde nicht gefunden.');
        }

        $targetCurrentRank = (int)$existingUser['rank'];
        $allowedRanksForThisUser = $GLOBALS['ALLOWED_RANKS'];

        if ($targetCurrentRank <= 1) {
            $allowedRanksForThisUser[] = $targetCurrentRank;
        }

        if (!in_array($rank, $allowedRanksForThisUser, true)) {
            employeesPageJsonResponse(false, 'Ungültige Rolle ausgewählt.');
        }

        $changes = [];
        $oldFamilyName = (string)$existingUser['family_name'];

        if ($oldFamilyName !== $familyName) {
            $changes[] = 'Name: "' . $oldFamilyName . '" → "' . $familyName . '"';
        }

        if ((int)$existingUser['rank'] !== $rank) {
            $changes[] = 'Rolle: ' . employeesPageRoleName((int)$existingUser['rank']) . ' → ' . employeesPageRoleName($rank);
        }

        if ((int)$existingUser['discount_percent'] !== $discount) {
            $changes[] = 'Rabatt: ' . (int)$existingUser['discount_percent'] . '% → ' . $discount . '%';
        }

        if ((int)$existingUser['service_fee_waived'] !== $serviceFeeWaived) {
            $changes[] = 'Servicegebühr: ' . ((int)$existingUser['service_fee_waived'] === 1 ? 'erlassen' : 'nicht erlassen') . ' → ' . ($serviceFeeWaived === 1 ? 'erlassen' : 'nicht erlassen');
        }

        if ((int)$existingUser['shopping_fee_waived'] !== $shoppingFeeWaived) {
            $changes[] = 'Einkaufsgebühr: ' . ((int)$existingUser['shopping_fee_waived'] === 1 ? 'erlassen' : 'nicht erlassen') . ' → ' . ($shoppingFeeWaived === 1 ? 'erlassen' : 'nicht erlassen');
        }

        if (!$changes) {
            employeesPageJsonResponse(true, 'Keine Änderungen erkannt.', [
                'user_id' => $userId,
                'family_name' => $familyName,
                'initials' => employeesPageFamilyInitials($familyName),
                'rank' => $rank,
                'role_name' => employeesPageRoleName($rank),
                'discount_percent' => $discount,
                'service_fee_waived' => $serviceFeeWaived,
                'shopping_fee_waived' => $shoppingFeeWaived
            ]);
        }

        $stmt = $pdo->prepare("
            UPDATE users
            SET family_name = ?, `rank` = ?, discount_percent = ?, service_fee_waived = ?, shopping_fee_waived = ?
            WHERE id = ?
        ");
        $stmt->execute([$familyName, $rank, $discount, $serviceFeeWaived, $shoppingFeeWaived, $userId]);

        employeesPageWriteActivityLog(
            $pdo,
            'Benutzer aktualisiert: ' . $oldFamilyName . ' | ' . implode('; ', $changes),
            $userId,
            $familyName,
            [
                'severity' => 'warning',
                'target_type' => 'user',
                'target_id' => $userId,
                'meta' => [
                    'old' => $existingUser,
                    'new' => [
                        'family_name' => $familyName,
                        'rank' => $rank,
                        'discount_percent' => $discount,
                        'service_fee_waived' => $serviceFeeWaived,
                        'shopping_fee_waived' => $shoppingFeeWaived
                    ],
                    'changes' => $changes
                ]
            ]
        );

        employeesPageJsonResponse(true, 'Benutzer wurde gespeichert.', [
            'user_id' => $userId,
            'family_name' => $familyName,
            'initials' => employeesPageFamilyInitials($familyName),
            'rank' => $rank,
            'role_name' => employeesPageRoleName($rank),
            'discount_percent' => $discount,
            'service_fee_waived' => $serviceFeeWaived,
            'shopping_fee_waived' => $shoppingFeeWaived
        ]);
    }

    if ($action === 'reset_recovery_code') {
        $userId = (int)($_POST['user_id'] ?? 0);

        $stmt = $pdo->prepare("SELECT family_name FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $family = $stmt->fetch();

        if (!$family) {
            employeesPageJsonResponse(false, 'Benutzer wurde nicht gefunden.');
        }

        $newRecoveryCode = employeesPageCreateRecoveryCode($family['family_name']);
        $newRecoveryHash = password_hash($newRecoveryCode, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("UPDATE users SET recovery_code_hash = ? WHERE id = ?");
        $stmt->execute([$newRecoveryHash, $userId]);

        employeesPageWriteActivityLog($pdo, 'Recovery-Code neu generiert für: ' . $family['family_name'], $userId, $family['family_name'], [
            'severity' => 'warning',
            'target_type' => 'user',
            'target_id' => $userId,
            'meta' => [
                'recovery_code_reset' => true,
                'target_user_id' => $userId,
                'target_user_name' => $family['family_name']
            ]
        ]);

        employeesPageJsonResponse(true, 'Recovery-Code wurde zurückgesetzt.', [
            'user_id' => $userId,
            'recovery_code' => $newRecoveryCode
        ]);
    }

    if ($action === 'toggle_lock_family') {
        $userId = (int)($_POST['user_id'] ?? 0);

        if ($userId <= 0) {
            employeesPageJsonResponse(false, 'Ungültiger Benutzer.');
        }

        if ($userId === (int)$currentUser['id']) {
            employeesPageJsonResponse(false, 'Du kannst deinen eigenen Account nicht sperren.');
        }

        $stmt = $pdo->prepare("SELECT family_name, is_locked FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user) {
            employeesPageJsonResponse(false, 'Benutzer wurde nicht gefunden.');
        }

        $newStatus = (int)$user['is_locked'] === 1 ? 0 : 1;

        $stmt = $pdo->prepare("UPDATE users SET is_locked = ? WHERE id = ?");
        $stmt->execute([$newStatus, $userId]);

        employeesPageWriteActivityLog(
            $pdo,
            ($newStatus === 1 ? 'Benutzer gesperrt: ' : 'Benutzer entsperrt: ') . $user['family_name'],
            $userId,
            $user['family_name'],
            [
                'category' => 'security',
                'severity' => $newStatus === 1 ? 'danger' : 'success',
                'target_type' => 'user',
                'target_id' => $userId,
                'meta' => [
                    'old_is_locked' => (int)$user['is_locked'],
                    'new_is_locked' => $newStatus
                ]
            ]
        );

        employeesPageJsonResponse(true, $newStatus === 1 ? 'Account wurde gesperrt.' : 'Account wurde entsperrt.', [
            'user_id' => $userId,
            'is_locked' => $newStatus
        ]);
    }

    if ($action === 'delete_family') {
        $userId = (int)($_POST['user_id'] ?? 0);

        if ($userId <= 0) {
            employeesPageJsonResponse(false, 'Ungültiger Benutzer.');
        }

        if ($userId === (int)$currentUser['id']) {
            employeesPageJsonResponse(false, 'Du kannst deinen eigenen Account hier nicht löschen.');
        }

        $stmt = $pdo->prepare("SELECT family_name FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $deleteUser = $stmt->fetch();

        if (!$deleteUser) {
            employeesPageJsonResponse(false, 'Account wurde nicht gefunden.');
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("DELETE FROM invoice_items WHERE invoice_id IN (SELECT id FROM invoices WHERE user_id = ?)");
            $stmt->execute([$userId]);

            $stmt = $pdo->prepare("DELETE FROM invoices WHERE user_id = ?");
            $stmt->execute([$userId]);

            $stmt = $pdo->prepare("DELETE FROM order_groups WHERE user_id = ?");
            $stmt->execute([$userId]);

            $stmt = $pdo->prepare("DELETE FROM orders WHERE user_id = ?");
            $stmt->execute([$userId]);

            $stmt = $pdo->prepare("DELETE FROM remember_tokens WHERE user_id = ?");
            $stmt->execute([$userId]);

            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$userId]);

            $pdo->commit();

            employeesPageWriteActivityLog($pdo, 'Benutzer gelöscht: ' . $deleteUser['family_name'], $userId, $deleteUser['family_name'], [
                'severity' => 'danger',
                'target_type' => 'user',
                'target_id' => $userId,
                'meta' => [
                    'deleted_user_id' => $userId,
                    'deleted_user_name' => $deleteUser['family_name'],
                    'related_data_deleted' => [
                        'invoice_items' => true,
                        'invoices' => true,
                        'order_groups' => true,
                        'orders' => true,
                        'remember_tokens' => true
                    ]
                ]
            ]);

            employeesPageJsonResponse(true, 'Account wurde gelöscht.', ['user_id' => $userId]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            employeesPageWriteActivityLog($pdo, 'Fehler beim Löschen eines Benutzers', $userId, $deleteUser['family_name'] ?? null, [
                'severity' => 'danger',
                'target_type' => 'user',
                'target_id' => $userId,
                'meta' => [
                    'error' => $e->getMessage(),
                    'target_user_id' => $userId
                ]
            ]);

            employeesPageJsonResponse(false, 'Fehler beim Löschen des Accounts.');
        }
    }

    employeesPageJsonResponse(false, 'Unbekannte Aktion.');
}

$pageTitle = 'Account Management';

$families = $pdo->query("
    SELECT id, family_name, role, `rank`, avatar, discount_percent, service_fee_waived, shopping_fee_waived, is_locked, created_at
    FROM users
    ORDER BY `rank` DESC, family_name ASC
")->fetchAll();

$familyGroupCounts = [];
foreach ($families as $familyCountItem) {
    $groupRank = (int)$familyCountItem['rank'];
    if (!isset($familyGroupCounts[$groupRank])) {
        $familyGroupCounts[$groupRank] = 0;
    }
    $familyGroupCounts[$groupRank]++;
}

ob_start();
?>

<div class="modal fade" id="createFamilyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" id="createFamilyForm">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-person-plus me-2"></i>Account erstellen
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Benutzername</label>
                    <input type="text" name="family_name" class="form-control" placeholder="z.B. Benutzer Müller" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">PIN</label>
                    <input type="password" name="pin" class="form-control" placeholder="4-6 Zahlen" pattern="[0-9]{4,6}" maxlength="6" required>
                    <small class="text-muted">Der PIN muss aus 4 bis 6 Zahlen bestehen.</small>
                </div>

                <div id="createdRecoveryBox" class="alert alert-warning d-none mb-0">
                    <strong>Recovery-Code:</strong><br>
                    <span id="createdRecoveryCode" class="fs-5"></span><br>
                    <small>Diesen Code direkt dem Benutzer geben. Danach ist er nicht mehr sichtbar.</small>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Schließen</button>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save me-1"></i>Account erstellen
                </button>
            </div>
        </form>
    </div>
</div>

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
        <h1 class="h3 mb-1">Account Management</h1>
        <p class="text-muted mb-0">Benutzer direkt bearbeiten. Gespeichert wird erst über den Speichern-Button pro Benutzer.</p>
    </div>

    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createFamilyModal">
        <i class="bi bi-person-plus me-1"></i>Account erstellen
    </button>
</div>

<div id="recoveryCodeBox" class="alert alert-warning d-none">
    <strong>Neuer Recovery-Code:</strong><br>
    <span id="recoveryCodeText" class="fs-5"></span><br>
    <small>Diesen Code direkt dem Benutzer geben. Danach ist er nicht mehr sichtbar.</small>
</div>

<div class="card shadow-sm border-0 employees-card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h5 class="mb-0">
            <i class="bi bi-people me-2"></i>Benutzerübersicht
        </h5>
        <span class="text-muted small">Änderungen pro Zeile speichern</span>
    </div>

    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle employees-table">
                <thead>
                    <tr>
                        <th>Benutzer</th>
                        <th>Rolle</th>
                        <th>Rabatt</th>
                        <th>Servicegebühr</th>
                        <th>Einkaufsgebühr</th>
                        <th>Registriert</th>
                        <th class="text-end" style="width: 280px;">Aktion</th>
                    </tr>
                </thead>

                <tbody>
                    <?php $currentGroupRank = null; ?>
                    <?php foreach ($families as $family): ?>
                        <?php if ($currentGroupRank !== (int)$family['rank']): ?>
                            <?php $currentGroupRank = (int)$family['rank']; ?>
                            <tr class="family-group-row">
                                <td colspan="7">
                                    <span class="family-group-title"><?= htmlspecialchars(employeesPageRoleName($currentGroupRank)) ?></span>
                                    <span class="family-group-count">
                                        <?= (int)($familyGroupCounts[$currentGroupRank] ?? 0) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endif; ?>

                        <tr
                            data-id="<?= (int)$family['id'] ?>"
                            data-family-name="<?= htmlspecialchars($family['family_name'], ENT_QUOTES, 'UTF-8') ?>"
                            data-rank="<?= (int)$family['rank'] ?>"
                            data-discount="<?= (int)$family['discount_percent'] ?>"
                            data-service="<?= (int)$family['service_fee_waived'] ?>"
                            data-shopping="<?= (int)$family['shopping_fee_waived'] ?>"
                            data-locked="<?= (int)$family['is_locked'] ?>"
                        >
                            <td>
                                <div class="family-name-cell">
                                    <?php $avatarPath = employeesPageFamilyAvatarPath($family['avatar'] ?? ''); ?>

                                    <?php if ($avatarPath): ?>
                                        <img
                                            src="<?= htmlspecialchars($avatarPath) ?>"
                                            class="family-avatar"
                                            alt="Profilbild von <?= htmlspecialchars($family['family_name']) ?>"
                                        >
                                    <?php else: ?>
                                        <span class="family-avatar family-avatar-initials">
                                            <?= htmlspecialchars(employeesPageFamilyInitials($family['family_name'])) ?>
                                        </span>
                                    <?php endif; ?>

                                    <div class="family-name-meta">
                                        <input
                                            type="text"
                                            class="form-control form-control-sm family-name-input editable-family-field"
                                            value="<?= htmlspecialchars($family['family_name']) ?>"
                                            autocomplete="off"
                                        >
                                        <span class="lock-badge">
                                            <?php if ((int)$family['is_locked'] === 1): ?>
                                                <span class="badge bg-danger mt-1">Gesperrt</span>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                </div>
                            </td>

                            <td style="width: 190px;">
                                <select class="form-select form-select-sm family-rank editable-family-field">
                                    <?php if (!isset($ROLES[(int)$family['rank']])): ?>
                                        <option value="<?= (int)$family['rank'] ?>" selected><?= htmlspecialchars(employeesPageRoleName($family['rank'])) ?></option>
                                    <?php endif; ?>

                                    <?php foreach ($ROLES as $rankValue => $roleLabel): ?>
                                        <option value="<?= (int)$rankValue ?>" <?= (int)$family['rank'] === (int)$rankValue ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($roleLabel) ?>
                                        </option>
                                    <?php endforeach; ?>

                                    <?php if ((int)$family['rank'] <= 1): ?>
                                        <option value="<?= (int)$family['rank'] ?>" selected>Benutzer</option>
                                    <?php endif; ?>
                                </select>
                            </td>

                            <td style="width: 140px;">
                                <div class="input-group input-group-sm">
                                    <input type="number" class="form-control family-discount editable-family-field" min="0" max="100" value="<?= (int)$family['discount_percent'] ?>">
                                    <span class="input-group-text">%</span>
                                </div>
                            </td>

                            <td>
                                <div class="form-check form-switch">
                                    <input
                                        class="form-check-input family-service editable-family-field"
                                        type="checkbox"
                                        id="service_<?= (int)$family['id'] ?>"
                                        <?= (int)$family['service_fee_waived'] === 1 ? 'checked' : '' ?>
                                    >
                                    <label class="form-check-label" for="service_<?= (int)$family['id'] ?>">
                                        erlassen
                                    </label>
                                </div>
                            </td>

                            <td>
                                <div class="form-check form-switch">
                                    <input
                                        class="form-check-input family-shopping editable-family-field"
                                        type="checkbox"
                                        id="shopping_<?= (int)$family['id'] ?>"
                                        <?= (int)$family['shopping_fee_waived'] === 1 ? 'checked' : '' ?>
                                    >
                                    <label class="form-check-label" for="shopping_<?= (int)$family['id'] ?>">
                                        erlassen
                                    </label>
                                </div>
                            </td>

                            <td><?= date('d.m.Y', strtotime($family['created_at'])) ?></td>

                            <td class="text-end">
                                <span class="save-state text-muted small me-2"></span>

                                <button type="button" class="btn btn-success btn-sm btn-save-family d-none" title="Änderungen speichern">
                                    <i class="bi bi-check"></i>
                                </button>

                                <button type="button" class="btn btn-outline-secondary btn-sm btn-reset-row d-none" title="Änderungen verwerfen">
                                    <i class="bi bi-arrow-counterclockwise"></i>
                                </button>

                                <button type="button" class="btn btn-secondary btn-sm btn-reset-recovery" title="Recovery-Code zurücksetzen">
                                    <i class="bi bi-key"></i>
                                </button>

                                <?php if ($currentRank === 6): ?>
                                    <a href="profile.php?user_id=<?= (int)$family['id'] ?>" class="btn btn-info btn-sm" title="Profilbild ändern">
                                        <i class="bi bi-image"></i>
                                    </a>
                                <?php endif; ?>

                                <button type="button" class="btn btn-dark btn-sm btn-toggle-lock" title="<?= (int)$family['is_locked'] === 1 ? 'Entsperren' : 'Sperren' ?>">
                                    <i class="bi <?= (int)$family['is_locked'] === 1 ? 'bi-unlock' : 'bi-lock' ?>"></i>
                                </button>

                                <button type="button" class="btn btn-danger btn-sm btn-delete-family" title="Account löschen">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (!$families): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">Noch keine Benutzer vorhanden.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<style>
.family-name-cell {
    display: flex;
    align-items: center;
    gap: .75rem;
    min-width: 260px;
}
.family-avatar {
    width: 38px;
    height: 38px;
    border-radius: 999px;
    object-fit: cover;
    flex: 0 0 auto;
}
.family-avatar-initials {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #0d6efd;
    color: #fff;
    font-weight: 700;
}
.family-name-meta {
    min-width: 190px;
}
.family-group-row td {
    background: #f1f5f9 !important;
    color: #334155;
    font-size: .8rem;
    font-weight: 700;
    letter-spacing: .04em;
    text-transform: uppercase;
}
.family-group-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 1.6rem;
    height: 1.6rem;
    margin-left: .45rem;
    border-radius: 999px;
    background: #e2e8f0;
    color: #0f172a;
    font-size: .78rem;
}
.employees-table th {
    white-space: nowrap;
}
.employees-table td {
    vertical-align: middle;
}
.save-state {
    display: inline-block;
    min-width: 78px;
}
.save-state.changed {
    color: #f59e0b !important;
}
.save-state.saving {
    color: #f59e0b !important;
}
.save-state.saved {
    color: #22c55e !important;
}
.save-state.error {
    color: #ef4444 !important;
}
tr.family-row-changed td {
    background: rgba(245, 158, 11, .08) !important;
}
body.dark-mode .family-group-row td {
    background: #1f2937 !important;
    color: #e5e7eb;
}
body.dark-mode .family-group-count {
    background: #374151;
    color: #f8fafc;
}
body.dark-mode .employees-card,
body.dark-mode .employees-card .card-header {
    background: #2b3035 !important;
    color: #d6d8db !important;
    border-color: #454d55 !important;
}
body.dark-mode tr.family-row-changed td {
    background: rgba(245, 158, 11, .15) !important;
}
</style>

<script>
const ROLE_LABELS = <?= json_encode($ROLES, JSON_UNESCAPED_UNICODE) ?>;
const CAN_CHANGE_PROFILE_IMAGE = <?= $currentRank === 6 ? 'true' : 'false' ?>;
let hasUnsavedFamilyChanges = false;

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

    new bootstrap.Toast(toastElement, { delay: 3500 }).show();
}

function readJsonResponse(response) {
    return response.text().then(text => {
        try {
            return JSON.parse(text);
        } catch (e) {
            console.error('Keine gültige JSON-Antwort:', text);
            return {
                success: false,
                message: 'Server-Antwort war ungültig. Bitte Seite neu laden.',
                data: {}
            };
        }
    });
}

function setRowSaveState(row, state, text) {
    const stateEl = row.querySelector('.save-state');
    if (!stateEl) return;

    stateEl.classList.remove('changed', 'saving', 'saved', 'error');
    if (state) stateEl.classList.add(state);
    stateEl.textContent = text || '';

    if (state === 'saved') {
        setTimeout(() => {
            if (stateEl.textContent === text) {
                stateEl.classList.remove('saved');
                stateEl.textContent = '';
            }
        }, 1800);
    }
}

function collectRowData(row) {
    return {
        ajax_action: 'update_family',
        user_id: row.dataset.id,
        family_name: row.querySelector('.family-name-input').value,
        rank: row.querySelector('.family-rank').value,
        discount_percent: row.querySelector('.family-discount').value,
        service_fee_waived: row.querySelector('.family-service').checked ? 1 : 0,
        shopping_fee_waived: row.querySelector('.family-shopping').checked ? 1 : 0
    };
}

function rowHasChanges(row) {
    const data = collectRowData(row);

    return String(data.family_name) !== String(row.dataset.familyName || '')
        || String(data.rank) !== String(row.dataset.rank || '')
        || String(data.discount_percent) !== String(row.dataset.discount || '')
        || String(data.service_fee_waived) !== String(row.dataset.service || '')
        || String(data.shopping_fee_waived) !== String(row.dataset.shopping || '');
}

function updateGlobalUnsavedState() {
    hasUnsavedFamilyChanges = !!document.querySelector('tr.family-row-changed');
}

function markRowChanged(row) {
    if (rowHasChanges(row)) {
        row.classList.add('family-row-changed');
        row.querySelector('.btn-save-family')?.classList.remove('d-none');
        row.querySelector('.btn-reset-row')?.classList.remove('d-none');
        setRowSaveState(row, 'changed', 'Geändert');
    } else {
        clearRowChanged(row);
    }

    updateGlobalUnsavedState();
}

function clearRowChanged(row) {
    row.classList.remove('family-row-changed');
    row.querySelector('.btn-save-family')?.classList.add('d-none');
    row.querySelector('.btn-reset-row')?.classList.add('d-none');
    setRowSaveState(row, null, '');
    updateGlobalUnsavedState();
}

function resetFamilyRow(row) {
    row.querySelector('.family-name-input').value = row.dataset.familyName || '';
    row.querySelector('.family-rank').value = row.dataset.rank || '';
    row.querySelector('.family-discount').value = row.dataset.discount || '0';
    row.querySelector('.family-service').checked = String(row.dataset.service || '0') === '1';
    row.querySelector('.family-shopping').checked = String(row.dataset.shopping || '0') === '1';
    clearRowChanged(row);
}

function saveFamilyRow(row) {
    const data = collectRowData(row);
    const formData = new FormData();
    const oldRank = String(row.dataset.rank || '');
    const newRank = String(data.rank || '');
    const rankChanged = oldRank !== newRank;

    Object.keys(data).forEach(key => formData.append(key, data[key]));

    setRowSaveState(row, 'saving', 'Speichert…');

    fetch('employees.php', { method: 'POST', body: formData })
        .then(readJsonResponse)
        .then(result => {
            if (!result.success) {
                setRowSaveState(row, 'error', 'Fehler');
                showToast(result.message, false);
                return;
            }

            row.dataset.familyName = result.data.family_name;
            row.dataset.rank = result.data.rank;
            row.dataset.discount = result.data.discount_percent;
            row.dataset.service = result.data.service_fee_waived;
            row.dataset.shopping = result.data.shopping_fee_waived;

            const initialsAvatar = row.querySelector('.family-avatar-initials');
            if (initialsAvatar && result.data.initials) {
                initialsAvatar.textContent = result.data.initials;
            }

            clearRowChanged(row);
            setRowSaveState(row, 'saved', 'Gespeichert');

            if (rankChanged) {
                showToast('Rolle gespeichert. Seite wird aktualisiert…', true);
                setTimeout(() => location.reload(), 700);
            }
        })
        .catch(() => {
            setRowSaveState(row, 'error', 'Fehler');
            showToast('Fehler beim Speichern.', false);
        });
}

window.addEventListener('beforeunload', function (e) {
    if (!hasUnsavedFamilyChanges) return;

    e.preventDefault();
    e.returnValue = '';
});

document.getElementById('createFamilyForm')?.addEventListener('submit', function (e) {
    e.preventDefault();

    const formData = new FormData(this);
    formData.append('ajax_action', 'create_family');

    fetch('employees.php', { method: 'POST', body: formData })
        .then(readJsonResponse)
        .then(result => {
            showToast(result.message, result.success);

            if (!result.success) {
                return;
            }

            document.getElementById('createdRecoveryCode').textContent = result.data.recovery_code;
            document.getElementById('createdRecoveryBox').classList.remove('d-none');

            this.querySelector('button[type="submit"]').disabled = true;
            this.querySelector('button[type="submit"]').innerHTML = '<i class="bi bi-check me-1"></i>Account erstellt';
        })
        .catch(() => showToast('Fehler beim Erstellen.', false));
});

document.getElementById('createFamilyModal')?.addEventListener('hidden.bs.modal', function () {
    const recoveryBox = document.getElementById('createdRecoveryBox');
    const recoveryCode = document.getElementById('createdRecoveryCode');
    const form = document.getElementById('createFamilyForm');
    const submitButton = form?.querySelector('button[type="submit"]');

    if (recoveryBox && !recoveryBox.classList.contains('d-none')) {
        location.reload();
        return;
    }

    recoveryBox?.classList.add('d-none');
    if (recoveryCode) recoveryCode.textContent = '';
    form?.reset();

    if (submitButton) {
        submitButton.disabled = false;
        submitButton.innerHTML = '<i class="bi bi-save me-1"></i>Account erstellen';
    }
});

document.addEventListener('change', function (e) {
    if (e.target.closest('.editable-family-field')) {
        const row = e.target.closest('tr[data-id]');
        if (row) markRowChanged(row);
    }
});

document.addEventListener('input', function (e) {
    if (e.target.closest('.editable-family-field')) {
        const row = e.target.closest('tr[data-id]');
        if (row) markRowChanged(row);
    }
});

document.addEventListener('click', function (e) {
    const saveFamilyButton = e.target.closest('.btn-save-family');
    const resetRowButton = e.target.closest('.btn-reset-row');
    const resetRecoveryButton = e.target.closest('.btn-reset-recovery');
    const lockButton = e.target.closest('.btn-toggle-lock');
    const deleteButton = e.target.closest('.btn-delete-family');

    if (saveFamilyButton) {
        const row = saveFamilyButton.closest('tr[data-id]');
        if (row) saveFamilyRow(row);
    }

    if (resetRowButton) {
        const row = resetRowButton.closest('tr[data-id]');
        if (row) resetFamilyRow(row);
    }

    if (resetRecoveryButton) {
        const row = resetRecoveryButton.closest('tr');

        if (!confirm('Recovery-Code für diesen Benutzer wirklich zurücksetzen?')) return;

        const formData = new FormData();
        formData.append('ajax_action', 'reset_recovery_code');
        formData.append('user_id', row.dataset.id);

        fetch('employees.php', { method: 'POST', body: formData })
            .then(readJsonResponse)
            .then(result => {
                showToast(result.message, result.success);
                if (!result.success) return;

                document.getElementById('recoveryCodeText').textContent = result.data.recovery_code;
                document.getElementById('recoveryCodeBox').classList.remove('d-none');
                window.scrollTo({ top: 0, behavior: 'smooth' });
            })
            .catch(() => showToast('Fehler beim Zurücksetzen.', false));
    }

    if (lockButton) {
        const row = lockButton.closest('tr');
        const familyName = row.querySelector('.family-name-input')?.value.trim() || 'Benutzer';

        if (!confirm('Account "' + familyName + '" sperren/entsperren?')) return;

        const formData = new FormData();
        formData.append('ajax_action', 'toggle_lock_family');
        formData.append('user_id', row.dataset.id);

        fetch('employees.php', { method: 'POST', body: formData })
            .then(readJsonResponse)
            .then(result => {
                showToast(result.message, result.success);
                if (!result.success) return;

                row.dataset.locked = String(result.data.is_locked);
                row.querySelector('.lock-badge').innerHTML =
                    parseInt(result.data.is_locked) === 1 ? '<span class="badge bg-danger mt-1">Gesperrt</span>' : '';

                lockButton.title = parseInt(result.data.is_locked) === 1 ? 'Entsperren' : 'Sperren';
                lockButton.innerHTML = '<i class="bi ' + (parseInt(result.data.is_locked) === 1 ? 'bi-unlock' : 'bi-lock') + '"></i>';
            })
            .catch(() => showToast('Fehler beim Sperren/Entsperren.', false));
    }

    if (deleteButton) {
        const row = deleteButton.closest('tr');
        const familyName = row.querySelector('.family-name-input')?.value.trim() || 'Benutzer';

        if (!confirm('Account "' + familyName + '" wirklich löschen? Alle Bestellungen werden ebenfalls gelöscht.')) return;

        const formData = new FormData();
        formData.append('ajax_action', 'delete_family');
        formData.append('user_id', row.dataset.id);

        fetch('employees.php', { method: 'POST', body: formData })
            .then(readJsonResponse)
            .then(result => {
                showToast(result.message, result.success);
                if (result.success) row.remove();
            })
            .catch(() => showToast('Fehler beim Löschen.', false));
    }
});
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/style/layout.php';
