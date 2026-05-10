<?php
require_once __DIR__ . '/config/config.php';

$currentUser = requireLogin($pdo);
$currentRank = (int)($currentUser['rank'] ?? 0);

$pageTitle = 'Profil';

$sessionUserId = (int)$currentUser['id'];
$isWebDev = $currentRank === 6;

$userId = $isWebDev && isset($_GET['user_id'])
    ? (int)$_GET['user_id']
    : $sessionUserId;

$message = '';
$messageType = 'success';

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function formatDateTimeProfile($value): string
{
    if (!$value) {
        return '-';
    }

    return date('d.m.Y H:i', strtotime((string)$value));
}

function rankLabelProfile(int $rank): string
{
    return match ($rank) {
        6 => 'WebDev',
        5 => 'Administrator',
        4 => 'Admin',
        3 => 'Leitung',
        2 => 'Erweitert',
        1 => 'Benutzer',
        default => 'Benutzer',
    };
}

function rankBadgeClassProfile(int $rank): string
{
    return match (true) {
        $rank >= 6 => 'bg-dark',
        $rank >= 5 => 'bg-danger',
        $rank >= 4 => 'bg-primary',
        $rank >= 3 => 'bg-info text-dark',
        $rank >= 2 => 'bg-warning text-dark',
        default => 'bg-secondary',
    };
}

function writeProfileActivityLog(PDO $pdo, ?int $targetUserId, string $targetUserName, string $action, array $options = []): void
{
    global $currentUser;

    try {
        if (function_exists('auditLog')) {
            auditLog($pdo, $currentUser ?? null, $action, array_merge([
                'category' => 'user',
                'severity' => 'info',
                'target_type' => 'user',
                'target_id' => $targetUserId,
                'meta' => [
                    'target_user_id' => $targetUserId,
                    'target_user_name' => $targetUserName
                ]
            ], $options));

            return;
        }

        $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, user_name, action) VALUES (?, ?, ?)");
        $stmt->execute([$targetUserId, $targetUserName, $action]);
    } catch (Throwable $e) {
        // Logging darf das Profil nicht blockieren.
    }
}

function saveCroppedAvatar(PDO $pdo, int $userId): bool
{
    $croppedAvatar = $_POST['cropped_avatar'] ?? '';

    if ($croppedAvatar === '' || !str_starts_with($croppedAvatar, 'data:image/png;base64,')) {
        return false;
    }

    $base64 = substr($croppedAvatar, strlen('data:image/png;base64,'));
    $imageData = base64_decode($base64);

    if ($imageData === false) {
        return false;
    }

    $dir = __DIR__ . '/profile/upload/avatars/';

    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $filename = 'user_' . $userId . '_' . time() . '.png';
    $targetPath = $dir . $filename;
    $dbPath = 'profile/upload/avatars/' . $filename;

    $stmt = $pdo->prepare("SELECT avatar FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $oldUser = $stmt->fetch();

    if (!empty($oldUser['avatar'])) {
        $oldPath = __DIR__ . '/' . $oldUser['avatar'];

        if (file_exists($oldPath)) {
            unlink($oldPath);
        }
    }

    file_put_contents($targetPath, $imageData);

    $stmt = $pdo->prepare("UPDATE users SET avatar = ? WHERE id = ?");
    $stmt->execute([$dbPath, $userId]);

    return true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isWebDev && $userId !== $sessionUserId) {
        die('Kein Zugriff.');
    }

    if (isset($_POST['delete_avatar'])) {
        $stmt = $pdo->prepare("SELECT family_name, avatar FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $profileUserForDelete = $stmt->fetch();

        if (!empty($profileUserForDelete['avatar'])) {
            $filePath = __DIR__ . '/' . $profileUserForDelete['avatar'];

            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }

        $stmt = $pdo->prepare("UPDATE users SET avatar = NULL WHERE id = ?");
        $stmt->execute([$userId]);

        writeProfileActivityLog(
            $pdo,
            $userId,
            $profileUserForDelete['family_name'] ?? 'System',
            'Profilbild gelöscht: ' . ($profileUserForDelete['family_name'] ?? 'Unbekannt'),
            [
                'severity' => 'warning',
                'meta' => [
                    'target_user_id' => $userId,
                    'target_user_name' => $profileUserForDelete['family_name'] ?? 'System',
                    'old_avatar' => $profileUserForDelete['avatar'] ?? null
                ]
            ]
        );

        $message = 'Profilbild wurde gelöscht.';
    }

    if (isset($_POST['save_profile'])) {
        $familyName = trim($_POST['family_name'] ?? '');

        if ($familyName === '') {
            $message = 'Benutzername darf nicht leer sein.';
            $messageType = 'danger';
        } else {
            $stmt = $pdo->prepare("
                SELECT id
                FROM users
                WHERE family_name = ?
                AND id != ?
                LIMIT 1
            ");
            $stmt->execute([$familyName, $userId]);

            if ($stmt->fetch()) {
                $message = 'Dieser Benutzername ist bereits vergeben.';
                $messageType = 'danger';
            } else {
                $stmt = $pdo->prepare("
                    SELECT family_name
                    FROM users
                    WHERE id = ?
                    LIMIT 1
                ");
                $stmt->execute([$userId]);
                $oldUserData = $stmt->fetch();

                $stmt = $pdo->prepare("
                    UPDATE users
                    SET family_name = ?
                    WHERE id = ?
                ");
                $stmt->execute([$familyName, $userId]);

                $avatarChanged = saveCroppedAvatar($pdo, $userId);

                $changes = [];

                if (($oldUserData['family_name'] ?? '') !== $familyName) {
                    $changes[] = 'Benutzername geändert von "' . ($oldUserData['family_name'] ?? '') . '" zu "' . $familyName . '"';
                }

                if ($avatarChanged) {
                    $changes[] = 'Profilbild geändert';
                }

                if ($changes) {
                    writeProfileActivityLog(
                        $pdo,
                        $userId,
                        $familyName,
                        'Profil aktualisiert: ' . implode('; ', $changes),
                        [
                            'severity' => 'success',
                            'meta' => [
                                'target_user_id' => $userId,
                                'old_family_name' => $oldUserData['family_name'] ?? null,
                                'new_family_name' => $familyName,
                                'avatar_changed' => $avatarChanged,
                                'changes' => $changes
                            ]
                        ]
                    );
                }

                $message = 'Profil wurde gespeichert.';
            }
        }
    }
}

$stmt = $pdo->prepare("
    SELECT 
        id,
        family_name,
        avatar,
        role,
        created_at,
        rank,
        theme_mode,
        is_locked
    FROM users
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    die('Benutzer nicht gefunden.');
}

$avatar = $user['avatar']
    ?: 'https://ui-avatars.com/api/?name=' . urlencode($user['family_name'] ?? 'User') . '&background=6c757d&color=fff';

$isViewingOwnProfile = $userId === $sessionUserId;
$userRank = (int)($user['rank'] ?? 0);
$isLocked = (int)($user['is_locked'] ?? 0) === 1;

ob_start();
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.css">

<style>
    .profile-page-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 1rem;
        flex-wrap: wrap;
        margin-bottom: 1.5rem;
    }

    .profile-hero {
        position: relative;
        overflow: hidden;
        border-radius: 1.5rem;
        background:
            radial-gradient(circle at top left, rgba(13, 110, 253, .35), transparent 32%),
            linear-gradient(135deg, #0f172a 0%, #1e293b 55%, #0f172a 100%);
        color: #fff;
        padding: 2rem;
        margin-bottom: 1.5rem;
        box-shadow: 0 18px 45px rgba(15, 23, 42, .22);
    }

    .profile-hero::after {
        content: "";
        position: absolute;
        width: 260px;
        height: 260px;
        border-radius: 999px;
        right: -90px;
        bottom: -110px;
        background: rgba(255, 255, 255, .08);
    }

    .profile-hero-content {
        position: relative;
        z-index: 2;
        display: flex;
        align-items: center;
        gap: 1.25rem;
        flex-wrap: wrap;
    }

    .profile-avatar-xl {
        width: 132px;
        height: 132px;
        border-radius: 999px;
        object-fit: cover;
        border: 4px solid rgba(255, 255, 255, .85);
        box-shadow: 0 12px 30px rgba(0, 0, 0, .25);
        background: #fff;
    }

    .profile-name {
        font-size: 2rem;
        font-weight: 800;
        line-height: 1.1;
        margin: 0 0 .45rem;
    }

    .profile-subline {
        color: rgba(255, 255, 255, .78);
        margin: 0;
    }

    .profile-meta-badges {
        display: flex;
        flex-wrap: wrap;
        gap: .45rem;
        margin-top: .85rem;
    }

    .profile-edit-card {
        border: 0;
        border-radius: 1.25rem;
        overflow: hidden;
        box-shadow: 0 14px 36px rgba(15, 23, 42, .08);
    }

    .profile-edit-header {
        padding: 1.35rem 1.5rem;
        border-bottom: 1px solid rgba(148, 163, 184, .2);
        background: linear-gradient(135deg, #f8fafc 0%, #eef2ff 100%);
    }

    .profile-edit-header h5 {
        margin: 0;
        font-weight: 800;
    }

    .profile-edit-header p {
        margin: .25rem 0 0;
        color: #64748b;
        font-size: .92rem;
    }

    .profile-edit-body {
        padding: 1.5rem;
    }

    .profile-edit-layout {
        display: grid;
        grid-template-columns: 230px 1fr;
        gap: 1.75rem;
        align-items: start;
    }

    .avatar-panel {
        border-radius: 1.1rem;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        padding: 1.25rem;
        text-align: center;
    }

    .profile-preview {
        width: 148px;
        height: 148px;
        border-radius: 50%;
        object-fit: cover;
        border: 4px solid #fff;
        box-shadow: 0 10px 25px rgba(15, 23, 42, .16);
        background: #fff;
        margin-bottom: 1rem;
    }

    .avatar-help {
        color: #64748b;
        font-size: .86rem;
        margin-bottom: 0;
    }

    .profile-form-panel {
        min-width: 0;
    }

    .form-section {
        border-radius: 1.1rem;
        border: 1px solid #e2e8f0;
        padding: 1.25rem;
        margin-bottom: 1rem;
        background: #fff;
    }

    .form-section-title {
        display: flex;
        align-items: center;
        gap: .5rem;
        font-weight: 800;
        margin-bottom: 1rem;
        color: #0f172a;
    }

    .form-section-title i {
        color: #0d6efd;
    }

    .crop-area {
        border-radius: 1.1rem;
        border: 1px dashed #94a3b8;
        background: #f8fafc;
        padding: 1rem;
    }

    .cropper-wrap {
        max-width: 100%;
    }

    .cropper-wrap img {
        max-width: 100%;
        display: block;
        border-radius: .75rem;
    }

    .round-preview {
        width: 140px;
        height: 140px;
        border-radius: 50%;
        overflow: hidden;
        border: 3px solid #0dcaf0;
        background: #f8f9fa;
        margin: 0 auto;
    }

    .profile-actions {
        display: flex;
        gap: .75rem;
        flex-wrap: wrap;
        justify-content: flex-end;
        padding-top: .5rem;
    }

    .profile-actions .btn {
        border-radius: .75rem;
        font-weight: 600;
        padding: .65rem 1rem;
    }

    body.dark-mode .profile-edit-card,
    body.dark-mode .form-section {
        background: #111827;
        color: #e5e7eb;
    }

    body.dark-mode .profile-edit-header {
        background: linear-gradient(135deg, #111827 0%, #1f2937 100%);
        border-bottom-color: rgba(255, 255, 255, .08);
    }

    body.dark-mode .profile-edit-header p,
    body.dark-mode .text-muted,
    body.dark-mode .avatar-help {
        color: #9ca3af !important;
    }
    body.dark-mode .form-text {
    color: #e5e7eb !important;
}

    body.dark-mode .avatar-panel,
    body.dark-mode .crop-area {
        background: #0f172a;
        border-color: #374151;
    }

    body.dark-mode .form-section {
        border-color: #374151;
    }

    body.dark-mode .form-section-title {
        color: #f9fafb;
    }

    body.dark-mode .round-preview {
        background: #111827;
    }

    @media (max-width: 992px) {
        .profile-edit-layout {
            grid-template-columns: 1fr;
        }

        .avatar-panel {
            max-width: 340px;
            margin: 0 auto;
            width: 100%;
        }
    }

    @media (max-width: 768px) {
        .profile-hero {
            padding: 1.5rem;
        }

        .profile-avatar-xl {
            width: 104px;
            height: 104px;
        }

        .profile-name {
            font-size: 1.55rem;
        }

        .profile-edit-body {
            padding: 1rem;
        }

        .form-section {
            padding: 1rem;
        }

        .profile-actions {
            justify-content: stretch;
        }

        .profile-actions .btn {
            width: 100%;
        }
    }
</style>

<div class="profile-page-header">
    <div>
        <h1 class="h3 mb-1">
            <?= $isViewingOwnProfile ? 'Mein Profil' : 'Benutzerprofil' ?>
        </h1>
    </div>

    <div class="d-flex gap-2 flex-wrap">
        <?php if (!$isViewingOwnProfile && $isWebDev): ?>
            <a href="employees.php" class="btn btn-secondary">
                <i class="bi bi-arrow-left me-1"></i>
                Zurück
            </a>
        <?php else: ?>
            <a href="index" class="btn btn-secondary">
                <i class="bi bi-arrow-left me-1"></i>
                Zurück
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-<?= h($messageType) ?>">
        <?= h($message) ?>
    </div>
<?php endif; ?>

<div class="profile-hero">
    <div class="profile-hero-content">
        <img src="<?= h($avatar) ?>" class="profile-avatar-xl" alt="Profilbild">

        <div>
            <h2 class="profile-name">
                <?= h($user['family_name'] ?? 'Benutzer') ?>
            </h2>

            <p class="profile-subline">
                Mitglied seit <?= h(formatDateTimeProfile($user['created_at'] ?? null)) ?>
            </p>

            <div class="profile-meta-badges">
                <span class="badge <?= h(rankBadgeClassProfile($userRank)) ?>">
                    <?= h(rankLabelProfile($userRank)) ?>
                </span>

                <span class="badge <?= $isLocked ? 'bg-danger' : 'bg-success' ?>">
                    <?= $isLocked ? 'Gesperrt' : 'Aktiv' ?>
                </span>
            </div>
        </div>
    </div>
</div>

<div class="card profile-edit-card">
    <div class="profile-edit-header">
        <h5>
            <i class="bi bi-pencil-square me-2"></i>
            Profil bearbeiten
        </h5>
    </div>

    <div class="profile-edit-body">
        <form method="post" enctype="multipart/form-data" id="profileForm">
            <input type="hidden" name="cropped_avatar" id="croppedAvatar">

            <div class="profile-edit-layout">
                <div class="avatar-panel">
                    <img src="<?= h($avatar) ?>" class="profile-preview" alt="Profilbild">

                    <div class="fw-bold mb-1">
                        Aktuelles Profilbild
                    </div>
                </div>

                <div class="profile-form-panel">
                    <div class="form-section">
                        <div class="form-section-title">
                            <i class="bi bi-person"></i>
                            Benutzername
                        </div>

                        <label class="form-label">Anzeigename</label>
                        <input type="text"
                               name="family_name"
                               class="form-control form-control-lg"
                               value="<?= h($user['family_name'] ?? '') ?>"
                               required>
                    </div>

                    <div class="form-section">
                        <div class="form-section-title">
                            <i class="bi bi-image"></i>
                            Profilbild
                        </div>

                        <label class="form-label">Neues Bild auswählen</label>
                        <input type="file"
                               id="avatarInput"
                               class="form-control"
                               accept="image/jpeg,image/png,image/webp">

                        <div class="form-text">
                            Erlaubt sind JPG, PNG oder WEBP. Der aktuelle Zuschnitt wird beim Speichern automatisch übernommen.
                        </div>

                        <div id="cropArea" class="crop-area d-none mt-4">
                            <div class="alert alert-info py-2 mb-3">
                                Bild zuschneiden und danach einfach auf <strong>Speichern</strong> klicken.
                            </div>

                            <div class="row g-4 align-items-start">
                                <div class="col-lg-8">
                                    <div class="cropper-wrap">
                                        <img id="cropImage" alt="Bild zuschneiden">
                                    </div>
                                </div>

                                <div class="col-lg-4 text-center">
                                    <label class="form-label">Vorschau</label>

                                    <div class="round-preview">
                                        <div id="roundPreview" style="width:140px;height:140px;"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="profile-actions">
                        <?php if (!empty($user['avatar'])): ?>
                            <button type="submit"
                                    name="delete_avatar"
                                    class="btn btn-outline-danger"
                                    onclick="return confirm('Profilbild wirklich löschen?')">
                                <i class="bi bi-trash me-1"></i>
                                Profilbild löschen
                            </button>
                        <?php endif; ?>

                        <button type="submit" name="save_profile" class="btn btn-primary">
                            <i class="bi bi-save me-1"></i>
                            Änderungen speichern
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.js"></script>

<script>
    let cropper = null;

    const avatarInput = document.getElementById('avatarInput');
    const cropImage = document.getElementById('cropImage');
    const cropArea = document.getElementById('cropArea');
    const croppedAvatar = document.getElementById('croppedAvatar');
    const profileForm = document.getElementById('profileForm');

    avatarInput?.addEventListener('change', function () {
        const file = this.files[0];

        if (!file) {
            return;
        }

        const allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];

        if (!allowedTypes.includes(file.type)) {
            alert('Bitte nur JPG, PNG oder WEBP hochladen.');
            this.value = '';
            return;
        }

        const reader = new FileReader();

        reader.onload = function (e) {
            cropImage.src = e.target.result;
            cropArea.classList.remove('d-none');

            if (cropper) {
                cropper.destroy();
            }

            cropper = new Cropper(cropImage, {
                aspectRatio: 1,
                viewMode: 1,
                dragMode: 'move',
                autoCropArea: 1,
                responsive: true,
                preview: '#roundPreview'
            });
        };

        reader.readAsDataURL(file);
    });

    profileForm?.addEventListener('submit', function () {
        if (!cropper || avatarInput.files.length === 0) {
            return;
        }

        const canvas = cropper.getCroppedCanvas({
            width: 500,
            height: 500,
            imageSmoothingEnabled: true,
            imageSmoothingQuality: 'high'
        });

        croppedAvatar.value = canvas.toDataURL('image/png');
    });
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/style/layout.php';