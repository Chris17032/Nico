<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/helpers/mailer.php';

$currentUser = requireLogin($pdo);
$userId      = (int)$currentUser['id'];
$currentRank = (int)($currentUser['rank'] ?? 1);
$isAdmin     = $currentRank > 4;

function supportJsonResponse(bool $success, string $message, array $data = []): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => $success, 'message' => $message, 'data' => $data]);
    exit;
}

/* =========================
   AJAX ACTIONS
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    $action = $_POST['ajax_action'];

    /* --- Submit new ticket --- */
    if ($action === 'submit_ticket') {
        $type    = in_array($_POST['type'] ?? '', ['bug', 'feedback', 'sonstiges'], true) ? $_POST['type'] : 'sonstiges';
        $subject = trim($_POST['subject'] ?? '');
        $message = trim($_POST['message'] ?? '');

        if ($subject === '' || mb_strlen($subject, 'UTF-8') > 255) {
            supportJsonResponse(false, 'Bitte einen Betreff angeben (max. 255 Zeichen).');
        }

        if ($message === '' || mb_strlen($message, 'UTF-8') > 5000) {
            supportJsonResponse(false, 'Bitte eine Nachricht angeben (max. 5000 Zeichen).');
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO support_tickets (user_id, type, subject, message, status, priority, created_at)
                VALUES (?, ?, ?, ?, 'offen', 'normal', NOW())
            ");
            $stmt->execute([$userId, $type, $subject, $message]);

            $ticketId = (int)$pdo->lastInsertId();

            // Notify all admins
            $adminStmt = $pdo->prepare("SELECT id FROM users WHERE rank > 4 AND is_locked = 0");
            $adminStmt->execute();
            $adminIds = $adminStmt->fetchAll(PDO::FETCH_COLUMN);

            $notifyStmt = $pdo->prepare("
                INSERT INTO notifications (user_id, type, title, message, link, created_at)
                VALUES (?, 'support_ticket', ?, ?, ?, NOW())
            ");

            foreach ($adminIds as $adminId) {
                $notifyStmt->execute([
                    (int)$adminId,
                    'Neues Support-Ticket',
                    $currentUser['family_name'] . ': ' . mb_substr($subject, 0, 80, 'UTF-8'),
                    'admin_support.php?id=' . $ticketId
                ]);
            }

            if (function_exists('auditLog')) {
                auditLog($pdo, $currentUser, 'Support-Ticket erstellt: ' . $subject, [
                    'category' => 'support',
                    'severity' => 'info',
                    'target_type' => 'support_ticket',
                    'target_id' => $ticketId,
                ]);
            }

            // Send email to configured admin addresses
            $typeLabels = ['bug' => 'Bug / Fehler', 'feedback' => 'Feedback', 'sonstiges' => 'Sonstiges'];
            $typeLabel  = $typeLabels[$type] ?? $type;
            $adminEmails = defined('SUPPORT_ADMIN_EMAILS') ? SUPPORT_ADMIN_EMAILS : [];

            foreach ($adminEmails as $adminEmail) {
                if (trim($adminEmail) === '') continue;
                $htmlEmail = '
                    <p><strong>Neues Support-Ticket #' . $ticketId . '</strong></p>
                    <table style="border-collapse:collapse;font-size:14px;">
                        <tr><td style="padding:4px 12px 4px 0;color:#64748b;">Von:</td><td><strong>' . htmlspecialchars($currentUser['family_name'], ENT_QUOTES, 'UTF-8') . '</strong></td></tr>
                        <tr><td style="padding:4px 12px 4px 0;color:#64748b;">Typ:</td><td>' . htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8') . '</td></tr>
                        <tr><td style="padding:4px 12px 4px 0;color:#64748b;">Betreff:</td><td>' . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . '</td></tr>
                    </table>
                    <p style="margin-top:16px;background:#f4f6f9;padding:12px;border-left:3px solid #2563eb;">' . nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8')) . '</p>
                    <p><a href="https://einkauf.nnewton.de/admin_support.php?id=' . $ticketId . '">Ticket im System öffnen →</a></p>
                ';
                sendMail(trim($adminEmail), 'Admin', 'Neues Support-Ticket: ' . $subject, $htmlEmail);
            }

            supportJsonResponse(true, 'Dein Ticket wurde erfolgreich gesendet. Wir melden uns bald!');
        } catch (Throwable $e) {
            supportJsonResponse(false, 'Fehler beim Senden des Tickets.');
        }
    }

    /* --- Admin: update ticket status/note --- */
    if ($action === 'update_ticket' && $isAdmin) {
        $ticketId  = (int)($_POST['ticket_id'] ?? 0);
        $status    = in_array($_POST['status'] ?? '', ['offen', 'in_bearbeitung', 'geschlossen'], true) ? $_POST['status'] : 'offen';
        $priority  = in_array($_POST['priority'] ?? '', ['niedrig', 'normal', 'hoch'], true) ? $_POST['priority'] : 'normal';
        $adminNote = trim($_POST['admin_note'] ?? '');

        if ($ticketId <= 0) {
            supportJsonResponse(false, 'Ungültiges Ticket.');
        }

        $stmt = $pdo->prepare("SELECT id, status FROM support_tickets WHERE id = ? LIMIT 1");
        $stmt->execute([$ticketId]);
        $ticket = $stmt->fetch();

        if (!$ticket) {
            supportJsonResponse(false, 'Ticket nicht gefunden.');
        }

        $closedAt = ($status === 'geschlossen' && $ticket['status'] !== 'geschlossen') ? 'NOW()' : 'closed_at';

        $stmt = $pdo->prepare("
            UPDATE support_tickets
            SET status = ?, priority = ?, admin_note = ?,
                closed_at = " . ($status === 'geschlossen' ? 'COALESCE(closed_at, NOW())' : 'closed_at') . ",
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$status, $priority, $adminNote ?: null, $ticketId]);

        supportJsonResponse(true, 'Ticket wurde aktualisiert.');
    }

    /* --- Admin: delete ticket --- */
    if ($action === 'delete_ticket' && $isAdmin) {
        $ticketId = (int)($_POST['ticket_id'] ?? 0);

        if ($ticketId <= 0) {
            supportJsonResponse(false, 'Ungültiges Ticket.');
        }

        $stmt = $pdo->prepare("DELETE FROM support_tickets WHERE id = ?");
        $stmt->execute([$ticketId]);

        supportJsonResponse(true, 'Ticket wurde gelöscht.');
    }

    supportJsonResponse(false, 'Unbekannte Aktion.');
}

http_response_code(404);
exit;
