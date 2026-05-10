<?php
require_once __DIR__ . '/config/config.php';

$message = '';
$type = 'danger';

if (!function_exists('writeActivityLog')) {
    function writeActivityLog(PDO $pdo, string $action, ?int $userId = null, ?string $userName = null): void
    {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO activity_logs (user_id, user_name, action)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$userId, $userName ?? 'System', $action]);
        } catch (Throwable $e) {
            // Logging darf die eigentliche Aktion niemals blockieren.
        }
    }
}

function oldValue(string $key): string
{
    return htmlspecialchars((string)($_POST[$key] ?? ''), ENT_QUOTES, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $family = trim($_POST['family_name'] ?? '');
    $code = trim($_POST['recovery_code'] ?? '');
    $pin = trim($_POST['new_pin'] ?? '');
    $pin2 = trim($_POST['new_pin_confirm'] ?? '');

    if ($family === '' || $code === '' || $pin === '' || $pin2 === '') {
        $message = 'Bitte alle Felder ausfüllen.';
    } elseif ($pin !== $pin2) {
        $message = 'Die neue PIN und die Wiederholung stimmen nicht überein.';
    } elseif (!preg_match('/^[0-9]{4,6}$/', $pin)) {
        $message = 'Die PIN muss aus 4 bis 6 Zahlen bestehen.';
    } else {
        $stmt = $pdo->prepare("
            SELECT id, family_name, recovery_code_hash
            FROM users
            WHERE family_name = ?
            LIMIT 1
        ");
        $stmt->execute([$family]);
        $user = $stmt->fetch();

        if (!$user || empty($user['recovery_code_hash']) || !password_verify($code, $user['recovery_code_hash'])) {
            $message = 'Name oder Recovery-Code ist falsch.';
        } else {
            $newHash = password_hash($pin, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare("
                UPDATE users
                SET pin_hash = ?
                WHERE id = ?
            ");
            $stmt->execute([$newHash, (int)$user['id']]);

            $stmt = $pdo->prepare("DELETE FROM remember_tokens WHERE user_id = ?");
            $stmt->execute([(int)$user['id']]);

            writeActivityLog($pdo, 'PIN geändert per Recovery-Code', (int)$user['id'], $user['family_name']);

            $message = 'Deine PIN wurde erfolgreich geändert. Du kannst dich jetzt wieder anmelden.';
            $type = 'success';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="UTF-8">
    <title>PIN zurücksetzen</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="style/style.css" rel="stylesheet">
</head>

<body class="login-bg">

    <div class="login-wrapper">
        <div class="login-card">

            <?php if (!empty($message)): ?>
                <div class="alert alert-<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?> py-2">
                    <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <?php if ($type === 'success'): ?>

                <a href="login.php" class="btn btn-primary w-100">
                    Zur Anmeldung
                </a>

            <?php else: ?>

                <form method="post" autocomplete="off">
                    <div class="mb-3">
                        <label class="form-label">Name</label>
                        <input
                            type="text"
                            name="family_name"
                            class="form-control"
                            value="<?= oldValue('family_name') ?>"
                            required
                            autofocus
                            autocomplete="username">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Recovery-Code</label>
                        <input
                            type="text"
                            name="recovery_code"
                            class="form-control"
                            value="<?= oldValue('recovery_code') ?>"
                            required
                            autocomplete="off">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Neue PIN</label>
                        <input
                            type="password"
                            name="new_pin"
                            class="form-control"
                            inputmode="numeric"
                            pattern="[0-9]{4,6}"
                            maxlength="6"
                            required
                            autocomplete="new-password">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">PIN wiederholen</label>
                        <input
                            type="password"
                            name="new_pin_confirm"
                            class="form-control"
                            inputmode="numeric"
                            pattern="[0-9]{4,6}"
                            maxlength="6"
                            required
                            autocomplete="new-password">
                    </div>

                    <button class="btn btn-primary w-100" type="submit">
                        Neue PIN speichern
                    </button>
                </form>

            <?php endif; ?>

            <div class="login-footer">
                <a href="login.php">Zurück zum Login</a>
            </div>
        </div>
    </div>

    <script>
        document.querySelectorAll('input[name="new_pin"], input[name="new_pin_confirm"]').forEach((input) => {
            input.addEventListener('input', function () {
                this.value = this.value.replace(/\D/g, '').slice(0, 6);
            });
        });
    </script>

</body>

</html>