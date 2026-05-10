<?php
require_once __DIR__ . '/config/config.php';

if (isset($_COOKIE['remember_login'])) {
    [$selector] = explode(':', $_COOKIE['remember_login']);

    $stmt = $pdo->prepare("DELETE FROM remember_tokens WHERE selector = ?");
    $stmt->execute([$selector]);

    setcookie('remember_login', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}

session_destroy();

header('Location: login.php');
exit;