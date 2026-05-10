<?php
require_once __DIR__ . '/../config/config.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit;
}

$userId = (int) $currentUser['id'];
$family_name = trim($_POST['family_name'] ?? '');

if ($family_name === '') {
    echo json_encode(['success' => false, 'message' => 'family_name darf nicht leer sein']);
    exit;
}

/* family_name */
$stmt = $pdo->prepare("UPDATE users SET family_name = ? WHERE id = ?");
$stmt->execute([$family_name, $userId]);

// Benutzername wird nicht mehr in der Session gespeichert.

/* Upload */
if (!empty($_FILES['avatar']['tmp_name'])) {

    $file = $_FILES['avatar'];
    $type = mime_content_type($file['tmp_name']);

    $allowed = ['image/jpeg', 'image/png', 'image/webp'];

    if (in_array($type, $allowed, true)) {

        $ext = match ($type) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp'
        };

        $dir = __DIR__ . '/upload/avatars/';

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filename = 'user_' . $userId . '_' . time() . '.' . $ext;
        $path = $dir . $filename;

        move_uploaded_file($file['tmp_name'], $path);

        $dbPath = 'profile/upload/avatars/' . $filename;

        $stmt = $pdo->prepare("UPDATE users SET avatar = ? WHERE id = ?");
        $stmt->execute([$dbPath, $userId]);

        // Avatar wird nicht mehr in der Session gespeichert.
    }
}

echo json_encode(['success' => true, 'message' => 'Profil gespeichert']);