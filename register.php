<?php
require_once __DIR__ . '/config/config.php';

$message = '';
$recoveryCode = null;

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $familyName = trim($_POST['family_name'] ?? '');
    $pin = trim($_POST['pin'] ?? '');

    if (!preg_match('/^[0-9]{4,6}$/', $pin)) {
        $message = 'PIN muss 4-6 Zahlen haben.';
    } else {

        /* PIN Hash */
        $pinHash = password_hash($pin, PASSWORD_DEFAULT);

        /* Recovery Code automatisch erstellen */
        $recoveryCode = strtoupper(substr($familyName, 0, 3)) . '-' . rand(1000, 9999);

        $recoveryHash = password_hash($recoveryCode, PASSWORD_DEFAULT);

        try {
            $stmt = $pdo->prepare("
                INSERT INTO users (family_name, pin_hash, recovery_code_hash, role, rank)
                VALUES (?, ?, ?, 'family', 1)
            ");

            $stmt->execute([
                $familyName,
                $pinHash,
                $recoveryHash
            ]);

            writeActivityLog($pdo, 'Benutzer registriert: ' . $familyName, (int) $pdo->lastInsertId(), $familyName);

            $message = 'Familie erfolgreich erstellt!';

        } catch (PDOException $e) {
            $message = 'Fehler: Familie existiert bereits.';
            $recoveryCode = null;

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
    }
}
?>

<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="UTF-8">
    <title>Familie registrieren</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="style/style.css" rel="stylesheet">
</head>

<body class="login-bg">

    <div class="login-wrapper">
        <div class="login-card">

            <h3 class="login-title">Familie anlegen</h3>

            <?php if ($message): ?>
                <div class="alert alert-info">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <?php if ($recoveryCode): ?>
                <div class="alert alert-warning">
                    <strong>WICHTIG:</strong><br>
                    Recovery-Code:<br>
                    <strong style="font-size:18px"><?= $recoveryCode ?></strong><br><br>
                    👉 Diesen Code der Familie geben!
                </div>
            <?php endif; ?>

            <form method="post">

                <input name="family_name" class="form-control mb-3" placeholder="Familienname" required>

                <input type="password" name="pin" class="form-control mb-3" placeholder="PIN (4-6 Zahlen)"
                    pattern="[0-9]{4,6}" maxlength="6" required>

                <button class="btn btn-success w-100">
                    Erstellen
                </button>

            </form>

            <hr>
            <a href="index">Zurück</a>

        </div>
    </div>

</body>

</html>