<?php
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$pageTitle = $pageTitle ?? 'Dashboard';
$content = $content ?? '';

$stmt = $pdo->prepare("
    SELECT theme_mode
    FROM users
    WHERE id = ?
");
$stmt->execute([$_SESSION['user_id']]);
$userSettings = $stmt->fetch();

$themeMode = $userSettings['theme_mode'] ?? 'auto';

if (!in_array($themeMode, ['auto', 'light', 'dark'], true)) {
    $themeMode = 'auto';
}
?>

<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="UTF-8">

    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> | Einkauf</title>

    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="icon" type="image/png" href="img/logo.png">

    <script>
        window.USER_THEME = <?= json_encode($themeMode) ?>;
    </script>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <link href="style/style.css" rel="stylesheet">
    <link href="style/app-ui.css" rel="stylesheet">
</head>

<body>

    <?php require_once __DIR__ . '/sidebar.php'; ?>

    <div class="content-wrapper">
        <?php require_once __DIR__ . '/topbar.php'; ?>

        <main class="content">
            <div class="container-fluid">
                <?= $content ?>
            </div>
        </main>

        <?php require_once __DIR__ . '/footer.php'; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const sidebarToggle = document.getElementById('sidebarToggle');
            const themeToggle = document.getElementById('themeToggle');
            const themeIcon = document.getElementById('themeIcon');

            if (localStorage.getItem('sidebar') === 'collapsed' && window.innerWidth > 768) {
                document.body.classList.add('sidebar-collapsed');
            }

            if (sidebarToggle) {
                sidebarToggle.addEventListener('click', function () {
                    if (window.innerWidth <= 768) {
                        document.body.classList.toggle('sidebar-mobile-open');
                        return;
                    }

                    document.body.classList.toggle('sidebar-collapsed');

                    localStorage.setItem(
                        'sidebar',
                        document.body.classList.contains('sidebar-collapsed') ? 'collapsed' : 'open'
                    );
                });
            }

            function applyTheme(mode) {
                const systemDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                let finalTheme = mode;

                if (mode === 'auto') {
                    finalTheme = systemDark ? 'dark' : 'light';
                }

                document.body.classList.toggle('dark-mode', finalTheme === 'dark');

                if (themeIcon) {
                    themeIcon.className = finalTheme === 'dark'
                        ? 'bi bi-sun-fill'
                        : 'bi bi-moon-fill';
                }
            }

            function saveTheme(mode) {
                const fd = new FormData();
                fd.append('theme_mode', mode);

                fetch('save_theme.php', {
                    method: 'POST',
                    body: fd
                });
            }

            let currentMode = window.USER_THEME || 'auto';

            applyTheme(currentMode);

            window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
                if (currentMode === 'auto') {
                    applyTheme('auto');
                }
            });

            if (themeToggle) {
                themeToggle.addEventListener('click', function () {
                    if (currentMode === 'light') {
                        currentMode = 'dark';
                    } else if (currentMode === 'dark') {
                        currentMode = 'auto';
                    } else {
                        currentMode = 'light';
                    }

                    applyTheme(currentMode);
                    saveTheme(currentMode);
                });
            }

            window.showAppToast = function (message, success = true) {
                const toast = document.createElement('div');
                toast.className = 'toast align-items-center text-bg-' + (success ? 'success' : 'danger') + ' border-0';
                toast.style.position = 'fixed';
                toast.style.top = '20px';
                toast.style.right = '20px';
                toast.style.zIndex = '9999';

                toast.innerHTML = `
                    <div class="d-flex">
                        <div class="toast-body">${message}</div>
                        <button type="button" class="btn-close btn-close-white me-2 m-auto"></button>
                    </div>
                `;

                document.body.appendChild(toast);

                const bsToast = new bootstrap.Toast(toast, {
                    delay: 3000
                });

                bsToast.show();

                toast.querySelector('button').addEventListener('click', function () {
                    toast.remove();
                });

                toast.addEventListener('hidden.bs.toast', function () {
                    toast.remove();
                });
            };

            document.getElementById('saveProfileBtn')?.addEventListener('click', function () {
                const familyNameInput = document.getElementById('family_nameInput');
                const avatarInput = document.getElementById('avatarInput');

                if (!familyNameInput) {
                    return;
                }

                const family_name = familyNameInput.value;
                const file = avatarInput?.files[0];

                const fd = new FormData();
                fd.append('family_name', family_name);

                if (file) {
                    fd.append('avatar', file);
                }

                fetch('profile/update.php', {
                    method: 'POST',
                    body: fd
                })
                    .then(response => response.json())
                    .then(result => {
                        window.showAppToast(result.message, result.success);

                        if (result.success) {
                            setTimeout(function () {
                                location.reload();
                            }, 600);
                        }
                    })
                    .catch(function () {
                        window.showAppToast('Profil konnte nicht gespeichert werden.', false);
                    });
            });

            document.getElementById('deleteAvatarBtn')?.addEventListener('click', function () {
                if (!confirm('Profilbild wirklich löschen?')) {
                    return;
                }

                fetch('profile/delete_avatar.php', {
                    method: 'POST'
                })
                    .then(response => response.json())
                    .then(result => {
                        window.showAppToast(result.message, result.success);

                        if (result.success) {
                            setTimeout(function () {
                                location.reload();
                            }, 600);
                        }
                    })
                    .catch(function () {
                        window.showAppToast('Profilbild konnte nicht gelöscht werden.', false);
                    });
            });
        });
    </script>

</body>

</html>