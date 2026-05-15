<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Lokale Credentials laden (nicht auf GitHub – siehe .gitignore)
// Muss VOR app_version.php geladen werden, damit SMTP_HOST_LOCAL etc. definiert sind
if (file_exists(__DIR__ . '/local.php')) {
    require_once __DIR__ . '/local.php';
}

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

$dbHost = defined('DB_HOST') ? DB_HOST : 'localhost';
$dbPort = defined('DB_PORT') ? DB_PORT : '3306';
$dbName = defined('DB_NAME') ? DB_NAME : 'einkauf';
$dbUser = defined('DB_USER') ? DB_USER : 'einkauf';
$dbPass = defined('DB_PASS') ? DB_PASS : '';

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

// Run pending schema migrations silently
try {
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS email VARCHAR(255) NULL DEFAULT NULL AFTER family_name");
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS theme_mode VARCHAR(10) NULL DEFAULT 'auto'");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS support_tickets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            type ENUM('bug','feedback','sonstiges') NOT NULL DEFAULT 'sonstiges',
            subject VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            status ENUM('offen','in_bearbeitung','geschlossen') NOT NULL DEFAULT 'offen',
            priority ENUM('niedrig','normal','hoch') NOT NULL DEFAULT 'normal',
            admin_note TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            closed_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            type VARCHAR(50) NOT NULL DEFAULT 'info',
            title VARCHAR(255) NOT NULL,
            message TEXT NULL,
            link VARCHAR(500) NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            read_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_unread (user_id, is_read)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS activity_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            user_name VARCHAR(255) NULL,
            action TEXT NOT NULL,
            ip VARCHAR(45) NULL,
            user_agent VARCHAR(500) NULL,
            category VARCHAR(50) NULL,
            severity VARCHAR(20) NULL DEFAULT 'info',
            target_type VARCHAR(50) NULL,
            target_id INT NULL,
            meta JSON NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (user_id),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS app_settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value TEXT NULL,
            updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS remember_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            selector VARCHAR(64) NOT NULL UNIQUE,
            token_hash VARCHAR(255) NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_selector (selector),
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (Throwable $migrationEx) {
    // Schema migrations must not crash the app
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
