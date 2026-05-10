<?php
require_once __DIR__ . '/../config/config.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit;
}

$userId = (int) $currentUser['id'];

/* Optional: Datei löschen */
$stmt = $pdo->prepare("SELECT avatar FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!empty($user['avatar'])) {
    $filePath = __DIR__ . '/../' . $user['avatar'];

    if (file_exists($filePath)) {
        unlink($filePath);
    }
}

/* DB reset */
$stmt = $pdo->prepare("UPDATE users SET avatar = NULL WHERE id = ?");
$stmt->execute([$userId]);

// Avatar wird nicht mehr in der Session gespeichert.

echo json_encode(['success' => true, 'message' => 'Profilbild gelöscht']);