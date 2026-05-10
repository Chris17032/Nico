<?php
require_once __DIR__ . '/config/config.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit;
}

function jsonResponse($success, $message = '')
{
    header('Content-Type: application/json');
    echo json_encode([
        'success' => (bool) $success,
        'message' => (string) $message
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['ajax_action'])) {
    jsonResponse(false, 'Ungültige Anfrage.');
}

$userId = (int) $currentUser['id'];
$action = $_POST['ajax_action'];

if ($action === 'mark_read') {
    $notificationId = (int) ($_POST['notification_id'] ?? 0);

    if ($notificationId <= 0) {
        jsonResponse(false, 'Ungültige Benachrichtigung.');
    }

    $stmt = $pdo->prepare("
        UPDATE notifications
        SET is_read = 1, read_at = NOW()
        WHERE id = ? AND user_id = ?
    ");
    $stmt->execute([$notificationId, $userId]);

    jsonResponse(true, 'Benachrichtigung wurde gelesen.');
}

if ($action === 'mark_all_read') {
    $stmt = $pdo->prepare("
        UPDATE notifications
        SET is_read = 1, read_at = NOW()
        WHERE user_id = ? AND is_read = 0
    ");
    $stmt->execute([$userId]);

    jsonResponse(true, 'Alle Benachrichtigungen wurden gelesen.');
}

if ($action === 'delete_notification') {
    $notificationId = (int) ($_POST['notification_id'] ?? 0);

    if ($notificationId <= 0) {
        jsonResponse(false, 'Ungültige Benachrichtigung.');
    }

    $stmt = $pdo->prepare("
        DELETE FROM notifications
        WHERE id = ? AND user_id = ?
    ");
    $stmt->execute([$notificationId, $userId]);

    jsonResponse(true, 'Benachrichtigung wurde gelöscht.');
}

if ($action === 'delete_all_notifications') {
    if (($_POST['confirm_delete_all'] ?? '') !== '1') {
        jsonResponse(false, 'Löschen wurde nicht bestätigt.');
    }

    $stmt = $pdo->prepare("
        DELETE FROM notifications
        WHERE user_id = ?
    ");
    $stmt->execute([$userId]);

    jsonResponse(true, 'Alle Benachrichtigungen wurden gelöscht.');
}

jsonResponse(false, 'Unbekannte Aktion.');