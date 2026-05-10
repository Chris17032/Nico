<?php
$avatar = $currentUser['avatar'] ?? null;
$currentRank = (int)($currentUser['rank'] ?? 1);
$currentPage = basename($_SERVER['PHP_SELF']);

if (!$avatar) {
    $avatar = 'https://ui-avatars.com/api/?name=' . urlencode($currentUser['family_name'] ?? 'User') . '&background=2563eb&color=fff';
}

function sidebarActive(string $file, string $currentPage): string
{
    return $currentPage === $file ? 'active' : '';
}
?>

<aside class="sidebar">
    <div class="brand">
        <img src="img/logo.png" class="brand-logo" alt="Logo">
        <span>Einkauf</span>
    </div>

    <div class="sidebar-user-box">
        <img src="<?= htmlspecialchars($avatar, ENT_QUOTES, 'UTF-8') ?>" class="sidebar-avatar" alt="Profilbild">

        <div class="sidebar-user-text">
            <small>Eingeloggt als</small>
            <strong><?= htmlspecialchars($currentUser['family_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></strong>
        </div>
    </div>

    <nav class="sidebar-nav">
        <a href="index" class="<?= sidebarActive('index.php', $currentPage) ?>">
            <i class="bi bi-house-door"></i>
            <span>Dashboard</span>
        </a>

        <a href="order" class="<?= sidebarActive('order.php', $currentPage) ?>">
            <i class="bi bi-cart-check"></i>
            <span>Meine Bestellung</span>
        </a>

        <div class="sidebar-section-title">
            <span>Rechnungen</span>
        </div>

        <a href="invoice" class="<?= sidebarActive('invoice.php', $currentPage) ?>">
            <i class="bi bi-file-earmark-text"></i>
            <span>Meine Rechnungen</span>
        </a>

        <?php if ($currentRank > 4): ?>
            <a href="admin_invoices" class="<?= sidebarActive('admin_invoices.php', $currentPage) ?>">
                <i class="bi bi-files"></i>
                <span>Alle Rechnungen</span>
            </a>

            <div class="sidebar-section-title">
                <span>Administration</span>
            </div>

            <a href="admin_orders" class="<?= sidebarActive('admin_orders.php', $currentPage) ?>">
                <i class="bi bi-clipboard-check"></i>
                <span>Bestell Übersicht</span>
            </a>

            <a href="dealer_export" class="<?= sidebarActive('dealer_export.php', $currentPage) ?>">
                <i class="bi bi-shop-window"></i>
                <span>Händler Export</span>
            </a>

            <a href="products" class="<?= sidebarActive('products.php', $currentPage) ?>">
                <i class="bi bi-box-seam"></i>
                <span>Produkte Verwalten</span>
            </a>

            <a href="employees" class="<?= sidebarActive('employees.php', $currentPage) ?>">
                <i class="bi bi-person-gear"></i>
                <span>Administration</span>
            </a>

            <a href="what_can_it_do" class="<?= sidebarActive('what_can_it_do.php', $currentPage) ?>">
                <i class="bi bi-stars"></i>
                <span>Funktionen</span>
            </a>

            <a href="statistics" class="<?= sidebarActive('statistics.php', $currentPage) ?>">
                <i class="bi bi-graph-up-arrow"></i>
                <span>Statistik</span>
            </a>

            <a href="log" class="<?= sidebarActive('log.php', $currentPage) ?>">
                <i class="bi bi-journal-text"></i>
                <span>Log</span>
            </a>

            <a href="admin_support" class="<?= sidebarActive('admin_support.php', $currentPage) ?>">
                <i class="bi bi-headset"></i>
                <span>Support Tickets</span>
                <?php
                try {
                    $sidebarTicketStmt = $pdo->query("SELECT COUNT(*) FROM support_tickets WHERE status = 'offen'");
                    $sidebarOpenTickets = (int)$sidebarTicketStmt->fetchColumn();
                    if ($sidebarOpenTickets > 0):
                ?>
                    <span class="badge bg-danger ms-auto"><?= $sidebarOpenTickets ?></span>
                <?php endif; } catch (Throwable $e) {} ?>
            </a>
        <?php endif; ?>

        <hr class="sidebar-divider">

        <a href="changelog" class="<?= sidebarActive('changelog.php', $currentPage) ?>">
            <i class="bi bi-clock-history"></i>
            <span>Changelog</span>
        </a>

        <a href="logout.php">
            <i class="bi bi-box-arrow-right"></i>
            <span>Abmelden</span>
        </a>
    </nav>
</aside>