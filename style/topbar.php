<?php
$isAdmin = $currentRank > 4;

$pinResetMessage = '';
$pinResetType = 'danger';
$pinResetShowModal = false;
$generatedRecoveryCode = '';

if (!function_exists('writeActivityLog')) {
    function writeActivityLog(PDO $pdo, string $action, ?int $userId = null, ?string $userName = null): void
    {
        try {
            $logUserId = $userId ?? currentUserId();
            $logUserName = $userName ?? currentUserName();

            $stmt = $pdo->prepare("
                INSERT INTO activity_logs (user_id, user_name, action)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$logUserId, $logUserName, $action]);
        } catch (Throwable $e) {
            // Logging darf die eigentliche Aktion niemals blockieren.
        }
    }
}

/* =========================
   PIN ÄNDERN
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_own_pin']) && isset($_SESSION['user_id'])) {
    $pinResetShowModal = true;

    $newPin = trim($_POST['new_pin'] ?? '');
    $newPinConfirm = trim($_POST['new_pin_confirm'] ?? '');

    if ($newPin === '' || $newPinConfirm === '') {
        $pinResetMessage = 'Bitte beide PIN-Felder ausfüllen.';
    } elseif ($newPin !== $newPinConfirm) {
        $pinResetMessage = 'Die PINs stimmen nicht überein.';
    } elseif (!preg_match('/^[0-9]{4,6}$/', $newPin)) {
        $pinResetMessage = 'Die PIN muss aus 4 bis 6 Zahlen bestehen.';
    } else {
        $newPinHash = password_hash($newPin, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("UPDATE users SET pin_hash = ? WHERE id = ?");
        $stmt->execute([$newPinHash, (int)$_SESSION['user_id']]);

        $stmt = $pdo->prepare("DELETE FROM remember_tokens WHERE user_id = ?");
        $stmt->execute([(int)$_SESSION['user_id']]);

        writeActivityLog($pdo, 'Eigene PIN geändert');

        $pinResetMessage = 'Deine PIN wurde erfolgreich geändert.';
        $pinResetType = 'success';
    }
}

/* =========================
   RECOVERY CODE
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_own_recovery_code']) && isset($_SESSION['user_id'])) {
    $pinResetShowModal = true;

    $generatedRecoveryCode = strtoupper(bin2hex(random_bytes(4)));
    $recoveryHash = password_hash($generatedRecoveryCode, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("UPDATE users SET recovery_code_hash = ? WHERE id = ?");
    $stmt->execute([$recoveryHash, (int)$_SESSION['user_id']]);

    writeActivityLog($pdo, 'Eigener Recovery-Code neu generiert');

    $pinResetMessage = 'Neuer Recovery-Code wurde erstellt. Bitte sicher speichern.';
    $pinResetType = 'success';
}

/* =========================
   ADMIN NACHRICHT
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_admin_message']) && $isAdmin) {
    $adminMessage = trim($_POST['admin_message'] ?? '');

    if ($adminMessage !== '') {
        try {
            $stmt = $pdo->query("SELECT id FROM users");
            $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $stmtInsert = $pdo->prepare("
                INSERT INTO notifications (user_id, type, title, message, link, is_read, created_at)
                VALUES (?, 'admin_message', 'Wichtige Admin-Nachricht', ?, NULL, 0, NOW())
            ");

            foreach ($userIds as $targetUserId) {
                $stmtInsert->execute([(int)$targetUserId, $adminMessage]);
            }

            writeActivityLog(
                $pdo,
                'Admin-Nachricht an alle Benutzer gesendet: ' . mb_substr($adminMessage, 0, 180, 'UTF-8')
            );
        } catch (Throwable $e) {
            writeActivityLog($pdo, 'Fehler beim Senden einer Admin-Nachricht');
        }
    }

    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

/* =========================
   WARTUNGSMODUS
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_maintenance']) && $isAdmin) {
    $stmt = $pdo->prepare("
        SELECT setting_value
        FROM app_settings
        WHERE setting_key = 'maintenance_mode'
        LIMIT 1
    ");
    $stmt->execute();
    $currentMode = $stmt->fetchColumn();

    $newMode = $currentMode === '1' ? '0' : '1';

    $stmt = $pdo->prepare("
        INSERT INTO app_settings (setting_key, setting_value)
        VALUES ('maintenance_mode', ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    $stmt->execute([$newMode]);

    writeActivityLog(
        $pdo,
        $newMode === '1'
            ? 'Wartungsmodus aktiviert'
            : 'Wartungsmodus deaktiviert'
    );

    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = 'maintenance_mode' LIMIT 1");
    $stmt->execute();
    $maintenanceMode = $stmt->fetchColumn() === '1';
} catch (Throwable $e) {
    $maintenanceMode = false;
}

$maintenanceMessage = 'Aktuell wird am System gearbeitet. Es kann kurzzeitig zu Einschränkungen kommen.';

$notifications = [];
$unreadNotifications = 0;

if (isset($_SESSION['user_id'])) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([(int)$_SESSION['user_id']]);
        $unreadNotifications = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT id, type, title, message, link, is_read, created_at
            FROM notifications
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT 8
        ");
        $stmt->execute([(int)$_SESSION['user_id']]);
        $notifications = $stmt->fetchAll();
    } catch (Throwable $e) {
        $notifications = [];
        $unreadNotifications = 0;
    }
}
?>

<header class="topbar">
    <div class="topbar-left">
        <button type="button" class="topbar-btn sidebar-toggle" id="sidebarToggle" aria-label="Navigation umschalten">
            <i class="bi bi-list"></i>
        </button>

        <strong class="topbar-page-title">
            <?= htmlspecialchars($pageTitle ?? 'Dashboard', ENT_QUOTES, 'UTF-8') ?>
        </strong>

        <?php if ($maintenanceMode): ?>
            <div class="maintenance-status" title="<?= htmlspecialchars($maintenanceMessage, ENT_QUOTES, 'UTF-8') ?>">
                <span class="maintenance-status-dot"></span>
                <i class="bi bi-tools"></i>
                <span>Wartungsmodus aktiv</span>
            </div>
        <?php endif; ?>
    </div>

    <div class="topbar-right">
        <div class="dropdown notification-dropdown">
            <button type="button"
                    class="topbar-btn notification-toggle"
                    data-bs-toggle="dropdown"
                    data-bs-auto-close="outside"
                    title="Benachrichtigungen">
                <i class="bi bi-bell"></i>

                <?php if ($unreadNotifications > 0): ?>
                    <span class="notification-badge">
                        <?= $unreadNotifications > 99 ? '99+' : (int)$unreadNotifications ?>
                    </span>
                <?php endif; ?>
            </button>

            <div class="dropdown-menu dropdown-menu-end notification-menu">
                <div class="notification-header">
                    <strong>Benachrichtigungen</strong>

                    <div class="d-flex align-items-center gap-2">
                        <?php if ($unreadNotifications > 0): ?>
                            <button type="button" class="btn btn-link btn-sm p-0 btn-notifications-read-all">
                                alle gelesen
                            </button>
                        <?php endif; ?>

                        <?php if ($notifications): ?>
                            <button type="button"
                                    class="btn btn-link btn-sm p-0 text-danger btn-notifications-delete-all"
                                    onclick="return confirm('Alle Benachrichtigungen wirklich löschen?');">
                                alle löschen
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($notifications): ?>
                    <?php foreach ($notifications as $notification): ?>
                        <?php
                        $isAdminMessage = ($notification['type'] ?? '') === 'admin_message'
                            || stripos((string)($notification['title'] ?? ''), 'Admin') !== false;

                        $notificationLink = trim((string)($notification['link'] ?? ''));
                        if ($notificationLink === '') {
                            $notificationLink = '#';
                        }
                        ?>

                        <div class="notification-row <?= (int)$notification['is_read'] === 0 ? 'is-unread' : '' ?> <?= $isAdminMessage ? 'is-admin-message' : '' ?>">
                            <a href="<?= htmlspecialchars($notificationLink, ENT_QUOTES, 'UTF-8') ?>"
                               class="dropdown-item notification-item"
                               data-notification-id="<?= (int)$notification['id'] ?>">
                                <div class="notification-title">
                                    <?php if ($isAdminMessage): ?>
                                        <span class="badge bg-danger me-1">Admin</span>
                                    <?php endif; ?>

                                    <?= htmlspecialchars($notification['title'], ENT_QUOTES, 'UTF-8') ?>
                                </div>

                                <div class="notification-message">
                                    <?= htmlspecialchars($notification['message'], ENT_QUOTES, 'UTF-8') ?>
                                </div>

                                <small><?= date('d.m.Y H:i', strtotime($notification['created_at'])) ?></small>
                            </a>

                            <button type="button"
                                    class="notification-delete-btn btn-delete-notification"
                                    data-notification-id="<?= (int)$notification['id'] ?>"
                                    title="Benachrichtigung löschen"
                                    aria-label="Benachrichtigung löschen">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="dropdown-item text-muted small py-3">
                        Keine Benachrichtigungen vorhanden.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <button type="button" class="topbar-btn theme-toggle" id="themeToggle" title="Dark / Light Mode">
            <i class="bi bi-moon-fill" id="themeIcon"></i>
        </button>

        <div class="dropdown">
            <button type="button"
                    class="topbar-btn topbar-settings"
                    data-bs-toggle="dropdown"
                    data-bs-auto-close="outside"
                    title="Einstellungen">
                <i class="bi bi-gear"></i>
            </button>

            <div class="dropdown-menu dropdown-menu-end settings-menu">
                <a class="dropdown-item" href="profile.php">
                    <i class="bi bi-person-circle me-2"></i>
                    Profil bearbeiten
                </a>

                <button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#pinResetModal">
                    <i class="bi bi-shield-lock me-2"></i>
                    PIN-Zurücksetzen
                </button>

                        <div class="dropdown-divider"></div>

                <button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#supportTicketModal">
                    <i class="bi bi-headset me-2"></i>
                    Support / Feedback
                </button>

        <?php if ($isAdmin): ?>
                    <div class="dropdown-divider"></div>

                    <button type="button" class="dropdown-item text-danger" data-bs-toggle="modal" data-bs-target="#adminMessageModal">
                        <i class="bi bi-megaphone me-2"></i>
                        Admin-Nachricht senden
                    </button>

                    <form method="post" class="m-0">
                        <button type="submit"
                                name="toggle_maintenance"
                                value="1"
                                class="dropdown-item <?= $maintenanceMode ? 'text-success' : 'text-warning' ?>">
                            <i class="bi <?= $maintenanceMode ? 'bi-toggle-on' : 'bi-toggle-off' ?> me-2"></i>
                            <?= $maintenanceMode ? 'Wartungsmodus deaktivieren' : 'Wartungsmodus aktivieren' ?>
                        </button>
                    </form>
                <?php endif; ?>

                <div class="dropdown-divider"></div>

                <a class="dropdown-item text-danger" href="logout.php">
                    <i class="bi bi-box-arrow-right me-2"></i>
                    Logout
                </a>
            </div>
        </div>
    </div>
</header>

<div class="modal fade" id="pinResetModal" tabindex="-1" aria-labelledby="pinResetModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="pinResetModalLabel">
                    <i class="bi bi-shield-lock me-2"></i>PIN zurücksetzen
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
            </div>

            <div class="modal-body">
                <?php if ($pinResetMessage): ?>
                    <div class="alert alert-<?= htmlspecialchars($pinResetType, ENT_QUOTES, 'UTF-8') ?> py-2">
                        <?= htmlspecialchars($pinResetMessage, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>

                <?php if ($generatedRecoveryCode): ?>
                    <div class="alert alert-info py-2">
                        <div class="small text-muted mb-1">Dein neuer Recovery-Code:</div>
                        <div class="fs-5 fw-bold font-monospace"><?= htmlspecialchars($generatedRecoveryCode, ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="small mt-1">Dieser Code wird nur jetzt angezeigt.</div>
                    </div>
                <?php endif; ?>

                <form method="post" id="pinResetForm">
                    <div class="mb-3">
                        <label class="form-label">Neue PIN</label>
                        <input type="password"
                               name="new_pin"
                               class="form-control"
                               inputmode="numeric"
                               pattern="[0-9]{4,6}"
                               maxlength="6"
                               placeholder="4-6 Zahlen"
                               autocomplete="new-password">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Neue PIN wiederholen</label>
                        <input type="password"
                               name="new_pin_confirm"
                               class="form-control"
                               inputmode="numeric"
                               pattern="[0-9]{4,6}"
                               maxlength="6"
                               placeholder="PIN wiederholen"
                               autocomplete="new-password">
                    </div>

                    <button type="submit" name="reset_own_pin" value="1" class="btn btn-primary w-100">
                        PIN speichern
                    </button>
                </form>

                <hr>

                <form method="post" class="m-0">
                    <button type="submit"
                            name="generate_own_recovery_code"
                            value="1"
                            class="btn btn-outline-secondary w-100"
                            onclick="return confirm('Wirklich einen neuen Recovery-Code erstellen? Der alte Code wird ungültig.');">
                        <i class="bi bi-arrow-clockwise me-1"></i>
                        Neuen Recovery-Code generieren
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php if ($isAdmin): ?>
    <div class="modal fade" id="adminMessageModal" tabindex="-1" aria-labelledby="adminMessageModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form method="post" class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="adminMessageModalLabel">
                        <i class="bi bi-megaphone me-2"></i>Wichtige Admin-Nachricht
                    </h5>

                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>

                <div class="modal-body">
                    <div class="alert alert-warning py-2">
                        Diese Nachricht wird als wichtige Admin-Nachricht an alle Benutzer gesendet.
                    </div>

                    <label class="form-label">Nachricht</label>
                    <textarea
                        name="admin_message"
                        class="form-control"
                        rows="5"
                        maxlength="1000"
                        placeholder="z.B. Das System ist heute Abend kurzzeitig nicht verfügbar."
                        required></textarea>

                    <small class="text-muted d-block mt-2">
                        Die Nachricht erscheint bei allen Benutzern unter den Benachrichtigungen.
                    </small>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                        Abbrechen
                    </button>

                    <button type="submit"
                            name="send_admin_message"
                            value="1"
                            class="btn btn-danger"
                            onclick="return confirm('Admin-Nachricht wirklich an alle Benutzer senden?');">
                        <i class="bi bi-send me-1"></i>Nachricht senden
                    </button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<style>
    .notification-menu {
        width: min(420px, calc(100vw - 24px));
        padding: 0;
        overflow: hidden;
        border-radius: 14px;
    }

    .notification-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: .75rem;
        padding: .85rem 1rem;
        border-bottom: 1px solid rgba(148, 163, 184, .25);
    }

    .notification-row {
        position: relative;
        display: flex;
        align-items: stretch;
        border-bottom: 1px solid rgba(148, 163, 184, .18);
    }

    .notification-row:last-child {
        border-bottom: 0;
    }

    .notification-row.is-unread {
        background: rgba(37, 99, 235, .06);
    }

    .notification-row.is-admin-message {
        border-left: 4px solid #dc3545;
    }

    .notification-row .notification-item {
        padding: .8rem 2.7rem .8rem 1rem;
        flex: 1 1 auto;
        white-space: normal;
    }

    .notification-title {
        font-weight: 750;
        margin-bottom: .25rem;
    }

    .notification-message {
        color: #64748b;
        font-size: .88rem;
        line-height: 1.35;
    }

    .notification-item small {
        display: block;
        color: #94a3b8;
        margin-top: .25rem;
    }

    .notification-delete-btn {
        position: absolute;
        top: .55rem;
        right: .55rem;
        width: 1.85rem;
        height: 1.85rem;
        border: 0;
        border-radius: .45rem;
        background: transparent;
        color: #dc3545;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    .notification-delete-btn:hover {
        background: rgba(220, 53, 69, .12);
    }

    .maintenance-status {
        display: inline-flex;
        align-items: center;
        gap: .4rem;
        border-radius: 999px;
        padding: .35rem .7rem;
        background: #fff7ed;
        color: #9a3412;
        font-size: .78rem;
        font-weight: 750;
        border: 1px solid #fed7aa;
    }

    .maintenance-status-dot {
        width: 8px;
        height: 8px;
        border-radius: 999px;
        background: #f97316;
    }

    body.dark-mode .notification-menu {
        background: #1c232b;
        border-color: #303a45;
    }

    body.dark-mode .notification-header,
    body.dark-mode .notification-row {
        border-color: #303a45;
    }

    body.dark-mode .notification-row.is-unread {
        background: rgba(37, 99, 235, .16);
    }

    body.dark-mode .notification-message {
        color: #a7b0bc;
    }

    body.dark-mode .notification-delete-btn {
        color: #ff8a8a;
    }

    body.dark-mode .notification-delete-btn:hover {
        background: rgba(255, 138, 138, .14);
    }

    body.dark-mode .maintenance-status {
        background: rgba(249, 115, 22, .15);
        color: #fdba74;
        border-color: rgba(251, 146, 60, .35);
    }
</style>

<script>
    document.addEventListener('click', function (e) {
        const deleteOne = e.target.closest('.btn-delete-notification');
        const deleteAll = e.target.closest('.btn-notifications-delete-all');
        const item = e.target.closest('.notification-item');
        const readAll = e.target.closest('.btn-notifications-read-all');

        if (!item && !readAll && !deleteOne && !deleteAll) {
            return;
        }

        const formData = new FormData();

        if (deleteOne) {
            e.preventDefault();
            e.stopPropagation();
            formData.append('ajax_action', 'delete_notification');
            formData.append('notification_id', deleteOne.dataset.notificationId);
        } else if (deleteAll) {
            e.preventDefault();
            e.stopPropagation();
            formData.append('ajax_action', 'delete_all_notifications');
            formData.append('confirm_delete_all', '1');
        } else if (item) {
            formData.append('ajax_action', 'mark_read');
            formData.append('notification_id', item.dataset.notificationId);
        } else if (readAll) {
            e.preventDefault();
            formData.append('ajax_action', 'mark_all_read');
        }

        fetch('notifications.php', {
            method: 'POST',
            body: formData
        }).then(function () {
            if (readAll || deleteOne || deleteAll) {
                location.reload();
            }
        });
    });

    <?php if ($pinResetShowModal): ?>
        document.addEventListener('DOMContentLoaded', function () {
            const pinResetModalElement = document.getElementById('pinResetModal');

            if (pinResetModalElement) {
                const pinResetModal = new bootstrap.Modal(pinResetModalElement);
                pinResetModal.show();
            }
        });
    <?php endif; ?>

    /* Support Ticket Modal – listener set after DOM is ready so the element exists */
    document.addEventListener('DOMContentLoaded', function () {
        document.getElementById('supportTicketForm')?.addEventListener('submit', function (e) {
            e.preventDefault();
            const btn = this.querySelector('[type="submit"]');
            const fd  = new FormData(this);
            fd.append('ajax_action', 'submit_ticket');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Senden…';

            fetch('support.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    window.showAppToast(res.message, res.success);
                    if (res.success) {
                        document.getElementById('supportTicketForm').reset();
                        bootstrap.Modal.getInstance(document.getElementById('supportTicketModal'))?.hide();
                    } else {
                        btn.disabled = false;
                        btn.innerHTML = '<i class="bi bi-send me-1"></i>Absenden';
                    }
                })
                .catch(() => {
                    window.showAppToast('Fehler beim Senden.', false);
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-send me-1"></i>Absenden';
                });
        });
    });
</script>

<!-- Support Ticket Modal -->
<div class="modal fade" id="supportTicketModal" tabindex="-1" aria-labelledby="supportTicketModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="supportTicketModalLabel">
                    <i class="bi bi-headset me-2"></i>Support / Feedback
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
            </div>

            <form id="supportTicketForm">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Typ</label>
                        <select name="type" class="form-select">
                            <option value="feedback">Feedback</option>
                            <option value="bug">Bug / Fehler melden</option>
                            <option value="sonstiges">Sonstiges</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Betreff</label>
                        <input type="text" name="subject" class="form-control" maxlength="255"
                               placeholder="Kurze Beschreibung…" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Nachricht</label>
                        <textarea name="message" class="form-control" rows="5" maxlength="5000"
                                  placeholder="Was ist passiert? Was hast du erwartet? …" required></textarea>
                    </div>

                    <p class="text-muted small mb-0">
                        <i class="bi bi-info-circle me-1"></i>
                        Dein Ticket wird an die Admins weitergeleitet und so schnell wie möglich bearbeitet.
                    </p>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-send me-1"></i>Absenden
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>