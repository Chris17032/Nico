<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config/config.php';

if (file_exists(__DIR__ . '/audit_log.php')) {
    require_once __DIR__ . '/audit_log.php';
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$message = '';

if (isset($_GET['locked'])) {
    if (function_exists('auditSystemLog')) {
        auditSystemLog($pdo, 'Login-Seite wegen gesperrtem Account aufgerufen', [
            'category' => 'security',
            'severity' => 'warning',
            'target_type' => 'login',
            'target_id' => null,
            'meta' => [
                'reason' => 'locked_redirect',
                'source' => 'login.php?locked=1'
            ]
        ]);
    }

    $_SESSION['login_message'] = 'Dein Account wurde deaktiviert. Bitte melde dich beim Administrator.';
    header('Location: login.php');
    exit;
}

if (!empty($_SESSION['login_message'])) {
    $message = $_SESSION['login_message'];
    unset($_SESSION['login_message']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $family_name = trim($_POST['family_name'] ?? '');
    $pin = trim($_POST['pin'] ?? '');
    $remember = isset($_POST['remember']);

    if ($family_name === '' || $pin === '') {
        if (function_exists('auditLog')) {
            auditLog($pdo, null, 'Login-Versuch mit leeren Pflichtfeldern', [
                'user_id' => null,
                'user_name' => $family_name !== '' ? $family_name : 'Unbekannt',
                'category' => 'auth',
                'severity' => 'warning',
                'target_type' => 'login',
                'target_id' => null,
                'meta' => [
                    'entered_name' => $family_name,
                    'family_name_empty' => $family_name === '',
                    'pin_empty' => $pin === '',
                    'remember_login' => $remember
                ]
            ]);
        }

        $message = 'Überprüfe deine Eingaben.';
    } else {
        $stmt = $pdo->prepare("
            SELECT
                id,
                family_name,
                role,
                rank,
                avatar,
                pin_hash,
                is_locked
            FROM users
            WHERE family_name = ?
            LIMIT 1
        ");
        $stmt->execute([$family_name]);
        $user = $stmt->fetch();

        if (!$user) {
            if (function_exists('auditLog')) {
                auditLog($pdo, null, 'Login-Versuch mit unbekanntem Benutzer', [
                    'user_id' => null,
                    'user_name' => $family_name !== '' ? $family_name : 'Unbekannt',
                    'category' => 'auth',
                    'severity' => 'danger',
                    'target_type' => 'login',
                    'target_id' => null,
                    'meta' => [
                        'entered_name' => $family_name,
                        'user_found' => false,
                        'remember_login' => $remember
                    ]
                ]);
            }

            $message = 'Überprüfe deine Eingaben.';
        } elseif ((int)$user['is_locked'] === 1) {
            if (function_exists('auditLog')) {
                auditLog($pdo, $user, 'Login-Versuch trotz gesperrtem Account', [
                    'category' => 'security',
                    'severity' => 'warning',
                    'target_type' => 'user',
                    'target_id' => (int)$user['id'],
                    'meta' => [
                        'entered_name' => $family_name,
                        'user_found' => true,
                        'account_locked' => true,
                        'remember_login' => $remember
                    ]
                ]);
            }

            $_SESSION['login_message'] = 'Dein Account wurde deaktiviert. Bitte melde dich beim Administrator.';
            header('Location: login.php');
            exit;
        } elseif (empty($user['pin_hash'])) {
            if (function_exists('auditLog')) {
                auditLog($pdo, $user, 'Login-Versuch ohne gesetzte PIN', [
                    'category' => 'security',
                    'severity' => 'danger',
                    'target_type' => 'user',
                    'target_id' => (int)$user['id'],
                    'meta' => [
                        'entered_name' => $family_name,
                        'pin_hash_missing' => true,
                        'remember_login' => $remember
                    ]
                ]);
            }

            $message = 'Überprüfe deine Eingaben.';
        } elseif (password_verify($pin, $user['pin_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$user['id'];

            if ($remember) {
                try {
                    $selector = bin2hex(random_bytes(16));
                    $token = bin2hex(random_bytes(32));
                    $tokenHash = password_hash($token, PASSWORD_DEFAULT);
                    $expires = date('Y-m-d H:i:s', time() + (60 * 60 * 24 * 28));

                    $stmt = $pdo->prepare("
                        INSERT INTO remember_tokens (user_id, selector, token_hash, expires_at)
                        VALUES (?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        (int)$user['id'],
                        $selector,
                        $tokenHash,
                        $expires
                    ]);

                    setcookie('remember_login', $selector . ':' . $token, [
                        'expires' => time() + (60 * 60 * 24 * 28),
                        'path' => '/',
                        'httponly' => true,
                        'samesite' => 'Lax'
                    ]);

                    if (function_exists('auditLog')) {
                        auditLog($pdo, $user, 'Remember-Login Token erstellt', [
                            'category' => 'auth',
                            'severity' => 'info',
                            'target_type' => 'user',
                            'target_id' => (int)$user['id'],
                            'meta' => [
                                'expires_at' => $expires,
                                'selector_prefix' => substr($selector, 0, 8)
                            ]
                        ]);
                    }
                } catch (Throwable $e) {
                    if (function_exists('auditLog')) {
                        auditLog($pdo, $user, 'Fehler beim Erstellen des Remember-Login Tokens', [
                            'category' => 'security',
                            'severity' => 'danger',
                            'target_type' => 'user',
                            'target_id' => (int)$user['id'],
                            'meta' => [
                                'error' => $e->getMessage()
                            ]
                        ]);
                    }

                    $message = 'Login war erfolgreich, aber "Angemeldet bleiben" konnte nicht gespeichert werden.';
                }
            }

            if ($message === '') {
                if (function_exists('auditLog')) {
                    auditLog($pdo, $user, 'Login erfolgreich', [
                        'category' => 'auth',
                        'severity' => 'success',
                        'target_type' => 'user',
                        'target_id' => (int)$user['id'],
                        'meta' => [
                            'remember_login' => $remember,
                            'rank' => (int)$user['rank'],
                            'role' => $user['role'] ?? null
                        ]
                    ]);
                }

                header('Location: index.php');
                exit;
            }
        } else {
            if (function_exists('auditLog')) {
                auditLog($pdo, $user, 'Fehlgeschlagener Login-Versuch mit falscher PIN', [
                    'category' => 'auth',
                    'severity' => 'danger',
                    'target_type' => 'user',
                    'target_id' => (int)$user['id'],
                    'meta' => [
                        'entered_name' => $family_name,
                        'user_found' => true,
                        'reason' => 'wrong_pin',
                        'remember_login' => $remember
                    ]
                ]);
            }

            $message = 'Überprüfe deine Eingaben.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="UTF-8">
    <title>Login</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="icon" type="image/png" href="img/logo.png">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="style/style.css" rel="stylesheet">

    <style>
        .login-brand {
            text-align: center;
            margin-bottom: 1.35rem;
        }

        .login-brand img {
            width: 100%;
            max-width: 260px;
            height: auto;
            display: block;
            margin: 0 auto;
            object-fit: contain;
        }

        @media (max-width: 480px) {
            .login-brand img {
                max-width: 220px;
            }
        }
    </style>
</head>

<body class="login-bg">

    <div class="login-wrapper">
        <div class="login-card">

            <div class="login-brand">
                <img src="img/loginpic.png" alt="Login">
            </div>

            <?php if (!empty($message)): ?>
                <div class="alert alert-danger py-2">
                    <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <form method="post" autocomplete="off">
                <div class="mb-3">
                    <label class="form-label">Name</label>
                    <input
                        type="text"
                        name="family_name"
                        class="form-control"
                        required
                        autofocus
                        autocomplete="username">
                </div>

                <div class="mb-3">
                    <label class="form-label">PIN</label>
                    <input
                        type="password"
                        name="pin"
                        class="form-control"
                        inputmode="numeric"
                        pattern="[0-9]{4,6}"
                        maxlength="6"
                        required
                        autocomplete="current-password">
                </div>

                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="remember" id="remember">
                    <label class="form-check-label" for="remember">
                        Angemeldet bleiben
                    </label>
                </div>

                <button class="btn btn-primary w-100" type="submit">
                    Einloggen
                </button>
            </form>

            <div class="login-footer">
                <a href="forgot_pin.php">PIN vergessen?</a>
            </div>
        </div>
    </div>

</body>

</html>