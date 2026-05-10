<?php
require_once __DIR__ . '/config/config.php';

$currentUser = requireLogin($pdo);
$currentRank = (int)($currentUser['rank'] ?? 1);

$pageTitle = 'Was kann die Website?';

function wc_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function wc_role_name(int $rank): string
{
    return match ($rank) {
        6 => 'WebDev',
        5 => 'Administrator',
        4 => 'Admin',
        3 => 'Leitung',
        2 => 'Erweitert',
        1 => 'Benutzer',
        default => 'Unbekannt',
    };
}

function wc_access_badge(int $currentRank, int $minRank): string
{
    if ($minRank <= 1) {
        return '<span class="badge bg-success">Alle Benutzer</span>';
    }

    $canAccess = $currentRank >= $minRank;
    $class = $canAccess ? 'bg-success' : 'bg-secondary';

    return '<span class="badge ' . $class . '">ab Rank ' . $minRank . ' · ' . wc_h(wc_role_name($minRank)) . '</span>';
}

function wc_status_badge(string $status): string
{
    return match ($status) {
        'active' => '<span class="badge bg-primary">Aktiv</span>',
        'partial' => '<span class="badge bg-warning text-dark">Teilweise</span>',
        'check' => '<span class="badge bg-danger">Prüfen</span>',
        'planned' => '<span class="badge bg-secondary">Nicht eingebaut</span>',
        default => '<span class="badge bg-light text-dark">Info</span>',
    };
}

function wc_card(string $icon, string $title, string $description, int $minRank, string $status, array $items, int $currentRank): string
{
    $extraClass = match ($status) {
        'partial' => 'wc-card-partial',
        'check' => 'wc-card-check',
        'planned' => 'wc-card-planned',
        default => '',
    };

    ob_start();
    ?>
    <div class="col-lg-6">
        <div class="card shadow-sm border-0 h-100 wc-card <?= wc_h($extraClass) ?>">
            <div class="card-header d-flex justify-content-between align-items-start gap-3 flex-wrap">
                <h5 class="mb-0">
                    <i class="bi <?= wc_h($icon) ?> me-2"></i><?= wc_h($title) ?>
                </h5>

                <div class="d-flex gap-2 flex-wrap">
                    <?= wc_status_badge($status) ?>
                    <?= wc_access_badge($currentRank, $minRank) ?>
                </div>
            </div>

            <div class="card-body">
                <p class="text-muted mb-3"><?= wc_h($description) ?></p>

                <ul class="wc-list mb-0">
                    <?php foreach ($items as $item): ?>
                        <li><?= wc_h($item) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

$features = [
    [
        'icon' => 'bi-speedometer2',
        'title' => 'Dashboard',
        'description' => 'Startseite mit Übersicht für den angemeldeten Benutzer.',
        'rank' => 1,
        'status' => 'active',
        'items' => [
            'Persönliche Begrüßung abhängig von der Tageszeit.',
            'Anzeige des nächsten Bestellschlusses.',
            'Anzeige der aktiven Bestellung.',
            'Anzeige offener und bezahlter Rechnungen als Anzahl.',
            'Benutzer sehen ihre eigenen Bestell- und Rechnungsinformationen.',
            'Eigene Beträge dürfen für den angemeldeten Benutzer sichtbar sein.',
        ],
    ],
    [
        'icon' => 'bi-cart-check',
        'title' => 'Meine Bestellung',
        'description' => 'Benutzer können ihre eigene Bestellung für offene Bestelltermine bearbeiten.',
        'rank' => 1,
        'status' => 'active',
        'items' => [
            'Produkte nach Kategorien anzeigen.',
            'Mengen eintragen und speichern.',
            'Mengen über Plus-/Minus-Buttons ändern.',
            'Unten wird angezeigt, wie viele Positionen ausgewählt sind.',
            'Hinweis unten: Änderungen werden erst nach dem Speichern übernommen.',
            'Bestehende Bestellung kann gelöscht werden.',
            'Bestellung ist an aktive Bestelltermine gebunden.',
        ],
    ],
    [
        'icon' => 'bi-receipt',
        'title' => 'Meine Rechnungen',
        'description' => 'Benutzer können ihre Rechnungen ansehen und Zahlungen melden.',
        'rank' => 1,
        'status' => 'active',
        'items' => [
            'Eigene Rechnungen anzeigen.',
            'Rechnungen werden im Format R-Jahr-Bestellgruppe angezeigt, zum Beispiel R-2026-75.',
            'Rechnungspositionen nach Kategorien anzeigen.',
            'Zahlung melden.',
            'Bei gemeldeter Zahlung werden Admins benachrichtigt.',
            'Statuswerte: Offen, Teilbezahlt, Bezahlt, Überfällig und Storniert.',
            'Admins können diese Datei nur zur Ansicht nutzen, aber nicht darüber bearbeiten.',
        ],
    ],
    [
        'icon' => 'bi-filetype-pdf',
        'title' => 'PDF-Rechnungen',
        'description' => 'Rechnungen werden serverseitig als PDF erzeugt.',
        'rank' => 1,
        'status' => 'active',
        'items' => [
            'PDFs haben ein eigenes A4-Rechnungslayout.',
            'PDFs werden über invoice_pdf.php erzeugt.',
            'Dompdf wird für echte PDF-Dateien verwendet.',
            'Die PDF-Rechnung nutzt die Rechnungsnummer aus der Datenbank, zum Beispiel R-2026-75.',
        ],
    ],
    [
        'icon' => 'bi-person-circle',
        'title' => 'Profil',
        'description' => 'Benutzer verwalten ihr eigenes Profil.',
        'rank' => 1,
        'status' => 'active',
        'items' => [
            'Benutzername ändern.',
            'Profilbild hochladen.',
            'Profilbild zuschneiden.',
            'Profilbild löschen.',
            'Der Zuschnitt wird beim Speichern automatisch übernommen.',
            'WebDev kann über user_id auch andere Profile öffnen.',
            'Profil ist bewusst schlank gehalten, ohne Bestellstatistiken und ohne Lieblingsprodukte.',
        ],
    ],
    [
        'icon' => 'bi-bell',
        'title' => 'Benachrichtigungen',
        'description' => 'Interne Hinweise innerhalb der Website.',
        'rank' => 1,
        'status' => 'active',
        'items' => [
            'Benachrichtigungen anzeigen.',
            'Einzelne Benachrichtigung als gelesen markieren.',
            'Alle Benachrichtigungen als gelesen markieren.',
            'Einzelne Benachrichtigung löschen.',
            'Alle Benachrichtigungen löschen.',
            'Benachrichtigungen laufen intern über die Website.',
        ],
    ],
    [
        'icon' => 'bi-key',
        'title' => 'Login, PIN und Recovery',
        'description' => 'Zugangssystem mit PIN und Wiederherstellung.',
        'rank' => 1,
        'status' => 'active',
        'items' => [
            'Login über Benutzername und PIN.',
            'Angemeldet bleiben über Remember-Login.',
            'PIN vergessen über Recovery-Code.',
            'Gesperrte Accounts werden abgefangen und können sich nicht anmelden.',
            'Login-Versuche werden geloggt, inklusive technischer Daten.',
            'Erfolgreiche Logins, falsche PINs, unbekannte Benutzer und gesperrte Login-Versuche werden protokolliert.',
        ],
    ],
    [
        'icon' => 'bi-moon-stars',
        'title' => 'Design / Theme',
        'description' => 'Darstellung kann gespeichert werden.',
        'rank' => 1,
        'status' => 'active',
        'items' => [
            'Theme wird pro Benutzer gespeichert.',
            'Darkmode und Lightmode sind eingebaut.',
            'Die Oberfläche wurde an vielen Stellen modernisiert.',
        ],
    ],
    [
        'icon' => 'bi-box-seam',
        'title' => 'Produkte verwalten',
        'description' => 'Adminbereich für Produkte und Kategorien.',
        'rank' => 5,
        'status' => 'active',
        'items' => [
            'Produktkategorien anlegen, bearbeiten, deaktivieren, aktivieren und löschen.',
            'Produkte anlegen, bearbeiten, deaktivieren, aktivieren und löschen.',
            'Produktname, Kategorie, Einheit und Preis verwalten.',
            'Produkte können für Bestellungen verfügbar oder unsichtbar gemacht werden.',
            'Änderungen können im Admin-Log protokolliert werden.',
        ],
    ],
    [
        'icon' => 'bi-people',
        'title' => 'Administration',
        'description' => 'Adminbereich für Benutzerkonten.',
        'rank' => 5,
        'status' => 'active',
        'items' => [
            'Benutzer erstellen.',
            'Benutzerdaten bearbeiten.',
            'Rank / Rolle verwalten.',
            'Benutzer sperren und entsperren.',
            'Recovery-Code zurücksetzen.',
            'Benutzer löschen.',
            'Beim Löschen werden zugehörige Daten berücksichtigt.',
            'Benutzeränderungen können im Admin-Log protokolliert werden.',
        ],
    ],
    [
        'icon' => 'bi-file-earmark-text',
        'title' => 'Admin-Rechnungen',
        'description' => 'Zentrale Rechnungsverwaltung für Administratoren.',
        'rank' => 5,
        'status' => 'active',
        'items' => [
            'Alle Rechnungen anzeigen.',
            'Nach Benutzername oder Rechnungsnummer suchen.',
            'Nach Status filtern.',
            'Rechnung in Admin-Ansicht öffnen.',
            'Rechnungspositionen bearbeiten.',
            'Mengen und Preise anpassen.',
            'Servicegebühr und Einkaufsgebühr pro Rechnung erlassen.',
            'PDF öffnen.',
            'Rechnung löschen.',
            'Offene oder teilbezahlte Rechnungen können nach 7 Tagen automatisch auf Überfällig gesetzt werden, wenn die Admin-Rechnungsseite ausgeführt wird.',
            'Rechnungsänderungen und Statusänderungen können im Admin-Log protokolliert werden.',
        ],
    ],
    [
        'icon' => 'bi-calendar-event',
        'title' => 'Bestellübersicht',
        'description' => 'Adminbereich für Bestelltermine, aktuelle Bestellungen und Rechnungserstellung.',
        'rank' => 5,
        'status' => 'active',
        'items' => [
            'Neue Bestelltermine anlegen.',
            'Bestelltermine bearbeiten.',
            'Bestelltermine archivieren und wiederherstellen.',
            'Bestelltermine löschen.',
            'Aktuelle Bestellungen pro Termin anzeigen.',
            'Bestellungen nach Produkten zusammenfassen.',
            'Alle bestellten Produkte mit Gesamtmengen anzeigen.',
            'Informationen für den Großhändler vorbereiten.',
            'Bestellungen nach Benutzern anzeigen.',
            'Rechnungen für einen Bestelltermin erstellen.',
            'Neue Rechnungen werden im Format R-Jahr-Bestellgruppe erzeugt, zum Beispiel R-2026-75.',
            'Aktionen können im Admin-Log protokolliert werden.',
        ],
    ],
    [
        'icon' => 'bi-activity',
        'title' => 'Admin-Logs',
        'description' => 'Logseite für Aktivitäten und technische Daten.',
        'rank' => 5,
        'status' => 'active',
        'items' => [
            'Logseite zeigt Aktivitätslogs an.',
            'Filter nach Suche, Kategorie, Schweregrad, Zeitraum und Anzahl.',
            'CSV-Export ist möglich.',
            'IP-Adresse und Browser/User-Agent können gespeichert werden.',
            'Logs können Kategorie, Schweregrad, Zieltyp, Ziel-ID und JSON-Zusatzdaten enthalten.',
            'Alte Logs erhalten keine technischen Daten nachträglich.',
            'Die Details-Ansicht wurde bewusst reduziert beziehungsweise kann weggelassen werden.',
        ],
    ],
    [
        'icon' => 'bi-clock-history',
        'title' => 'Changelog',
        'description' => 'Eigene Seite für Änderungsverlauf.',
        'rank' => 1,
        'status' => 'active',
        'items' => [
            'Changelog-Seite ist vorhanden.',
            'Versionierung ist über app_version.php eingebunden.',
            'Dient als sichtbare Änderungsübersicht für Benutzer.',
        ],
    ],
    [
        'icon' => 'bi-credit-card',
        'title' => 'Zahlung',
        'description' => 'Keine direkte Zahlungsintegration.',
        'rank' => 1,
        'status' => 'planned',
        'items' => [
            'Keine Kreditkarte.',
            'Kein Mobile Money.',
            'Kein PayPal.',
            'Keine Bank-API.',
            'Benutzer können Zahlung nur melden; Admins setzen den Status manuell.',
        ],
    ],
];

$activeCount = count(array_filter($features, static fn($feature) => $feature['status'] === 'active'));
$partialCount = count(array_filter($features, static fn($feature) => $feature['status'] === 'partial'));
$checkCount = count(array_filter($features, static fn($feature) => $feature['status'] === 'check'));
$plannedCount = count(array_filter($features, static fn($feature) => $feature['status'] === 'planned'));
$adminCount = count(array_filter($features, static fn($feature) => (int)$feature['rank'] >= 5));

ob_start();
?>

<div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-4">
    <div>
        <h1 class="h3 mb-1">Was kann diese Website?</h1>
    </div>

    <div>
        <span class="badge bg-primary fs-6">
            Dein Rank: <?= (int)$currentRank ?> · <?= wc_h(wc_role_name($currentRank)) ?>
        </span>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="small-box bg-primary">
            <div class="inner">
                <p>Aktiv</p>
                <h3><?= (int)$activeCount ?></h3>
            </div>
            <div class="icon"><i class="bi bi-check-circle"></i></div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="small-box bg-warning">
            <div class="inner">
                <p>Teilweise / Prüfen</p>
                <h3><?= (int)($partialCount + $checkCount) ?></h3>
            </div>
            <div class="icon"><i class="bi bi-exclamation-triangle"></i></div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="small-box bg-success">
            <div class="inner">
                <p>Adminbereiche</p>
                <h3><?= (int)$adminCount ?></h3>
            </div>
            <div class="icon"><i class="bi bi-shield-check"></i></div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="small-box bg-secondary">
            <div class="inner">
                <p>Nicht eingebaut</p>
                <h3><?= (int)$plannedCount ?></h3>
            </div>
            <div class="icon"><i class="bi bi-x-circle"></i></div>
        </div>
    </div>
</div>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-header">
        <h5 class="mb-0"><i class="bi bi-person-lock me-2"></i>Berechtigungen</h5>
    </div>

    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Rank</th>
                        <th>Rolle</th>
                        <th>Typische Rechte</th>
                        <th>Für dich</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ([1, 2, 3, 4, 5, 6] as $rank): ?>
                        <tr>
                            <td><strong><?= (int)$rank ?></strong></td>
                            <td><?= wc_h(wc_role_name($rank)) ?></td>
                            <td>
                                <?php if ($rank === 1): ?>
                                    Bestellen, eigene Rechnungen ansehen, Profil bearbeiten und Benachrichtigungen nutzen.
                                <?php elseif ($rank === 2): ?>
                                    Erweiterter Benutzer-Rang, aktuell hauptsächlich als Reserve nutzbar.
                                <?php elseif ($rank === 3): ?>
                                    Leitungsrang, aktuell hauptsächlich als Reserve nutzbar.
                                <?php elseif ($rank === 4): ?>
                                    Admin-nahe Stufe; einzelne Geld-/Übersichtsfunktionen können hier angebunden werden.
                                <?php elseif ($rank === 5): ?>
                                    Administrator: Produkte, Bestellübersicht, Rechnungen, Benutzer, Logs und Adminbereiche.
                                <?php else: ?>
                                    WebDev: höchste Stufe und Sonderzugriff auf bestimmte Profil-/Verwaltungsfunktionen.
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($currentRank >= $rank): ?>
                                    <span class="badge bg-success">Verfügbar</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Nicht verfügbar</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="row g-4">
    <?php foreach ($features as $feature): ?>
        <?= wc_card(
            $feature['icon'],
            $feature['title'],
            $feature['description'],
            (int)$feature['rank'],
            $feature['status'],
            $feature['items'],
            $currentRank
        ) ?>
    <?php endforeach; ?>
</div>

<div class="card shadow-sm border-0 mt-4">
    <div class="card-header">
        <h5 class="mb-0"><i class="bi bi-ban me-2"></i>Was die Website aktuell nicht kann</h5>
    </div>

    <div class="card-body">
        <ul class="wc-list mb-0">
            <li>Keine aktive Mehrsprachigkeit.</li>
            <li>Keine direkte Online-Zahlung.</li>
            <li>Keine automatische externe E-Mail-, WhatsApp- oder Telegram-Benachrichtigung.</li>
            <li>Keine echte Lagerbestandsverwaltung.</li>
            <li>Keine automatische Großhändler-Schnittstelle.</li>
            <li>Keine automatische Hintergrundausführung ohne Seitenaufruf oder Cronjob.</li>
            <li>Alte Logs bekommen keine IP- oder Browserdaten nachträglich.</li>
        </ul>
    </div>
</div>

<style>
.wc-card .card-header h5 {
    font-weight: 800;
    color: #1f2937;
}

.wc-card-partial {
    border-left: 5px solid #ffc107 !important;
}

.wc-card-check {
    border-left: 5px solid #dc3545 !important;
}

.wc-card-planned {
    opacity: .86;
}

.wc-list {
    padding-left: 1.1rem;
}

.wc-list li {
    margin-bottom: .4rem;
}

body.dark-mode .wc-card .card-header h5 {
    color: #ffffff !important;
}

body.dark-mode .wc-list li {
    color: #d6d8db;
}

body.dark-mode .alert-warning {
    background: #332701;
    color: #fff3cd;
}
</style>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/style/layout.php';