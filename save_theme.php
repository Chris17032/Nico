<?php
require_once __DIR__ . '/config/config.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit;
}

$mode = $_POST['theme_mode'] ?? 'auto';

if (!in_array($mode, ['auto', 'light', 'dark'], true)) {
    $mode = 'auto';
}

$stmt = $pdo->prepare("
    UPDATE users
    SET theme_mode = ?
    WHERE id = ?
");
$stmt->execute([$mode, $_SESSION['user_id']]);

echo json_encode(['success' => true]);