<?php
require_once __DIR__ . '/config/config.php';

// Auto-create table on first load
try {
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
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS email VARCHAR(255) NULL DEFAULT NULL AFTER family_name");
} catch (Throwable $e) {
    // Migrations must not crash the app
}

$currentUser = requireRank($pdo, 5);
$userId      = (int)$currentUser['id'];
$pageTitle   = 'Support Tickets';

function supportStatusBadge(string $status): string
{
    return match ($status) {
        'offen'          => 'bg-warning text-dark',
        'in_bearbeitung' => 'bg-info text-dark',
        'geschlossen'    => 'bg-success',
        default          => 'bg-secondary',
    };
}

function supportPriorityBadge(string $priority): string
{
    return match ($priority) {
        'hoch'    => 'bg-danger',
        'normal'  => 'bg-primary',
        'niedrig' => 'bg-secondary',
        default   => 'bg-secondary',
    };
}

$statusOptions   = ['offen' => 'Offen', 'in_bearbeitung' => 'In Bearbeitung', 'geschlossen' => 'Geschlossen'];
$priorityOptions = ['niedrig' => 'Niedrig', 'normal' => 'Normal', 'hoch' => 'Hoch'];
$typeOptions     = ['bug' => 'Bug', 'feedback' => 'Feedback', 'sonstiges' => 'Sonstiges'];

$filterStatus = $_GET['status'] ?? 'offen';
if (!isset($statusOptions[$filterStatus]) && $filterStatus !== 'alle') {
    $filterStatus = 'offen';
}

$detailId = (int)($_GET['id'] ?? 0);
$detail   = null;

if ($detailId > 0) {
    $stmt = $pdo->prepare("
        SELECT st.*, u.family_name
        FROM support_tickets st
        INNER JOIN users u ON u.id = st.user_id
        WHERE st.id = ?
        LIMIT 1
    ");
    $stmt->execute([$detailId]);
    $detail = $stmt->fetch();
}

/* Load tickets */
if ($filterStatus === 'alle') {
    $stmt = $pdo->query("
        SELECT st.*, u.family_name
        FROM support_tickets st
        INNER JOIN users u ON u.id = st.user_id
        ORDER BY
            CASE st.priority WHEN 'hoch' THEN 0 WHEN 'normal' THEN 1 ELSE 2 END ASC,
            st.created_at DESC
    ");
} else {
    $stmt = $pdo->prepare("
        SELECT st.*, u.family_name
        FROM support_tickets st
        INNER JOIN users u ON u.id = st.user_id
        WHERE st.status = ?
        ORDER BY
            CASE st.priority WHEN 'hoch' THEN 0 WHEN 'normal' THEN 1 ELSE 2 END ASC,
            st.created_at DESC
    ");
    $stmt->execute([$filterStatus]);
}

$tickets = $stmt->fetchAll();

$counts = [];
foreach (['offen', 'in_bearbeitung', 'geschlossen'] as $s) {
    $c = $pdo->prepare("SELECT COUNT(*) FROM support_tickets WHERE status = ?");
    $c->execute([$s]);
    $counts[$s] = (int)$c->fetchColumn();
}

$counts['alle'] = array_sum($counts);

ob_start();
?>

<div class="mb-4 d-flex justify-content-between align-items-start gap-3 flex-wrap">
    <div>
        <h1 class="h3 mb-1">Support Tickets</h1>
        <p class="text-muted mb-0">Benutzer-Feedback und Fehlermeldungen.</p>
    </div>
</div>

<?php if ($detail): ?>
<!-- DETAIL VIEW -->
<div class="mb-3">
    <a href="admin_support.php?status=<?= htmlspecialchars($filterStatus) ?>" class="btn btn-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Zurück zur Liste
    </a>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="bi bi-ticket-detailed me-2"></i>
                    Ticket #<?= $detail['id'] ?>
                    <span class="ms-2 badge <?= supportStatusBadge($detail['status']) ?>">
                        <?= htmlspecialchars($statusOptions[$detail['status']] ?? $detail['status']) ?>
                    </span>
                    <span class="ms-1 badge <?= supportPriorityBadge($detail['priority']) ?>">
                        <?= htmlspecialchars($priorityOptions[$detail['priority']] ?? $detail['priority']) ?>
                    </span>
                </h5>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <small class="text-muted">Von</small>
                    <div class="fw-bold"><?= htmlspecialchars($detail['family_name']) ?></div>
                </div>
                <div class="mb-3">
                    <small class="text-muted">Typ</small>
                    <div><?= htmlspecialchars($typeOptions[$detail['type']] ?? $detail['type']) ?></div>
                </div>
                <div class="mb-3">
                    <small class="text-muted">Betreff</small>
                    <div class="fw-bold"><?= htmlspecialchars($detail['subject']) ?></div>
                </div>
                <div class="mb-3">
                    <small class="text-muted">Nachricht</small>
                    <div class="border rounded p-3 bg-light" style="white-space:pre-wrap"><?= htmlspecialchars($detail['message']) ?></div>
                </div>
                <div class="text-muted small">
                    Erstellt: <?= date('d.m.Y H:i', strtotime($detail['created_at'])) ?>
                    <?php if ($detail['closed_at']): ?>
                        · Geschlossen: <?= date('d.m.Y H:i', strtotime($detail['closed_at'])) ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card shadow-sm border-0">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-pencil-square me-2"></i>Bearbeiten</h5>
            </div>
            <div class="card-body">
                <form id="ticketEditForm">
                    <input type="hidden" name="ticket_id" value="<?= $detail['id'] ?>">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Status</label>
                        <select name="status" class="form-select">
                            <?php foreach ($statusOptions as $val => $label): ?>
                                <option value="<?= $val ?>" <?= $detail['status'] === $val ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Priorität</label>
                        <select name="priority" class="form-select">
                            <?php foreach ($priorityOptions as $val => $label): ?>
                                <option value="<?= $val ?>" <?= $detail['priority'] === $val ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Admin-Notiz</label>
                        <textarea name="admin_note" class="form-control" rows="4" placeholder="Interne Notiz..."><?= htmlspecialchars($detail['admin_note'] ?? '') ?></textarea>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-fill">
                            <i class="bi bi-check2 me-1"></i>Speichern
                        </button>
                        <button type="button" class="btn btn-outline-danger btn-delete-ticket" data-id="<?= $detail['id'] ?>">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php else: ?>
<!-- LIST VIEW -->
<div class="mb-4 d-flex gap-2 flex-wrap">
    <?php
    $filterLabels = ['offen' => 'Offen', 'in_bearbeitung' => 'In Bearbeitung', 'geschlossen' => 'Geschlossen', 'alle' => 'Alle'];
    foreach ($filterLabels as $s => $l):
    ?>
        <a href="admin_support.php?status=<?= $s ?>"
           class="btn btn-sm <?= $filterStatus === $s ? 'btn-primary' : 'btn-outline-secondary' ?>">
            <?= $l ?>
            <span class="badge ms-1 <?= $filterStatus === $s ? 'bg-light text-dark' : 'bg-secondary' ?>">
                <?= $counts[$s] ?? 0 ?>
            </span>
        </a>
    <?php endforeach; ?>
</div>

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <table class="table table-hover mb-0 align-middle">
            <thead>
                <tr>
                    <th style="width:50px">#</th>
                    <th>Betreff</th>
                    <th>Von</th>
                    <th>Typ</th>
                    <th>Priorität</th>
                    <th>Status</th>
                    <th>Erstellt</th>
                    <th style="width:80px"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tickets as $t): ?>
                    <tr>
                        <td class="text-muted"><?= $t['id'] ?></td>
                        <td class="fw-semibold"><?= htmlspecialchars(mb_substr($t['subject'], 0, 60, 'UTF-8')) ?></td>
                        <td><?= htmlspecialchars($t['family_name']) ?></td>
                        <td><?= htmlspecialchars($typeOptions[$t['type']] ?? $t['type']) ?></td>
                        <td>
                            <span class="badge <?= supportPriorityBadge($t['priority']) ?>">
                                <?= htmlspecialchars($priorityOptions[$t['priority']] ?? $t['priority']) ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge <?= supportStatusBadge($t['status']) ?>">
                                <?= htmlspecialchars($statusOptions[$t['status']] ?? $t['status']) ?>
                            </span>
                        </td>
                        <td class="text-muted small"><?= date('d.m.Y H:i', strtotime($t['created_at'])) ?></td>
                        <td>
                            <a href="admin_support.php?id=<?= $t['id'] ?>&status=<?= htmlspecialchars($filterStatus) ?>"
                               class="btn btn-info btn-sm" title="Öffnen">
                                <i class="bi bi-eye"></i>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (!$tickets): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted py-5">
                            <i class="bi bi-check2-circle fs-2 d-block mb-2 text-success"></i>
                            Keine Tickets vorhanden.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<script>
<?php if ($detail): ?>
document.getElementById('ticketEditForm')?.addEventListener('submit', function (e) {
    e.preventDefault();
    const fd = new FormData(this);
    fd.append('ajax_action', 'update_ticket');

    fetch('support.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            window.showAppToast(res.message, res.success);
            if (res.success) setTimeout(() => location.reload(), 800);
        })
        .catch(() => window.showAppToast('Fehler beim Speichern.', false));
});

document.querySelector('.btn-delete-ticket')?.addEventListener('click', function () {
    if (!confirm('Ticket wirklich löschen?')) return;
    const fd = new FormData();
    fd.append('ajax_action', 'delete_ticket');
    fd.append('ticket_id', this.dataset.id);

    fetch('support.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            window.showAppToast(res.message, res.success);
            if (res.success) setTimeout(() => location.href = 'admin_support.php', 800);
        })
        .catch(() => window.showAppToast('Fehler.', false));
});
<?php endif; ?>
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/style/layout.php';
