
<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/app_version.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$ROLES = [
        6 => 'WebDev',
        5 => 'Administrator',
        4 => 'Admin',
        3 => 'Leitung',
        2 => 'Erweitert',
        1 => 'Benutzer',
];

$ALLOWED_RANKS = array_keys($ROLES);

function getRoleName($rank)
{
    global $ROLES;
    return $ROLES[$rank] ?? 'Unbekannt';
}

$dbHost = 'localhost';
$dbPort = '3306';
$dbName = 'nicosdev_einkauf';
$dbUser = 'nicosdev_einkauf';
$dbPass = 'qSus5mGc7&Brws*9';

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;port=$dbPort;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (PDOException $e) {
    die("Datenbankfehler – bitte Administrator kontaktieren.");
}

function appClearRememberLogin(PDO $pdo): void
{
    if (isset($_COOKIE['remember_login'])) {
        [$selector] = explode(':', $_COOKIE['remember_login'] . ':');

        if ($selector !== '') {
            $stmt = $pdo->prepare("DELETE FROM remember_tokens WHERE selector = ?");
            $stmt->execute([$selector]);
        }

        setcookie('remember_login', '', [
            'expires' => time() - 3600,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }
}

function appLogoutLocked(PDO $pdo): void
{
    appClearRememberLogin($pdo);
    session_unset();
    session_destroy();
    header('Location: login.php?locked=1');
    exit;
}

/* =========================
   REMEMBER LOGIN (Auto Login)
   Session speichert absichtlich nur user_id.
========================= */
if (!isset($_SESSION['user_id']) && isset($_COOKIE['remember_login'])) {
    [$selector, $token] = explode(':', $_COOKIE['remember_login'] . ':');

    if ($selector && $token) {
        $stmt = $pdo->prepare(" 
            SELECT rt.user_id, rt.token_hash, u.is_locked
            FROM remember_tokens rt
            JOIN users u ON u.id = rt.user_id
            WHERE rt.selector = ?
            AND rt.expires_at > NOW()
            LIMIT 1
        ");
        $stmt->execute([$selector]);
        $row = $stmt->fetch();

        if ($row && password_verify($token, $row['token_hash'])) {
            if ((int) $row['is_locked'] === 1) {
                appClearRememberLogin($pdo);
            } else {
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int) $row['user_id'];
            }
        }
    }
}

$currentUser = null;
$currentRank = 1;

if (isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare(" 
        SELECT id, family_name, avatar, role, `rank`, is_locked
        FROM users
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([(int) $_SESSION['user_id']]);
    $currentUser = $stmt->fetch();

    if (!$currentUser || (int) $currentUser['is_locked'] === 1) {
        appLogoutLocked($pdo);
    }

    $currentRank = (int) $currentUser['rank'];
}

function requireLogin(PDO $pdo): array
{
    global $currentUser;

    if (!$currentUser) {
        header('Location: login.php');
        exit;
    }

    return $currentUser;
}

function requireRank(PDO $pdo, int $minimumRank): array
{
    $user = requireLogin($pdo);

    if ((int) $user['rank'] < $minimumRank) {
        die('Kein Zugriff.');
    }

    return $user;
}

function requireExactRank(PDO $pdo, int $rank): array
{
    $user = requireLogin($pdo);

    if ((int) $user['rank'] !== $rank) {
        die('Kein Zugriff.');
    }

    return $user;
}

function currentUserName(): string
{
    global $currentUser;
    return $currentUser['family_name'] ?? 'System';
}

function currentUserId(): ?int
{
    global $currentUser;
    return $currentUser ? (int) $currentUser['id'] : null;
}

require_once dirname(__DIR__) . '/audit_log.php';