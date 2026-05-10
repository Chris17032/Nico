<?php

if (!defined('APP_VERSION')) {
    define('APP_VERSION', '0.9.1');
}

/*
 * E-Mail-Adressen die bei einem neuen Support-Ticket benachrichtigt werden.
 * Hier beliebig viele Adressen eintragen.
 */
if (!defined('SUPPORT_ADMIN_EMAILS')) {
    define('SUPPORT_ADMIN_EMAILS', [
        'support@nnewton.de',
        // 'weitere@email.de',
    ]);
}

/*
 * Absende-Adresse für alle System-E-Mails.
 */
if (!defined('MAIL_FROM')) {
    define('MAIL_FROM', 'support@nnewton.de');
}

if (!defined('MAIL_FROM_NAME')) {
    define('MAIL_FROM_NAME', 'Einkauf Support');
}

/*
 * SMTP-Konfiguration für den E-Mail-Versand.
 * Tragt hier die Zugangsdaten eures Mailservers ein.
 * SMTP_SECURE: 'tls' (Port 587, empfohlen), 'ssl' (Port 465), oder '' (Port 25, kein TLS)
 */
if (!defined('SMTP_HOST')) {
    define('SMTP_HOST', 'mail.nnewton.de');    // z.B. mail.nnewton.de oder smtp.gmail.com
}

if (!defined('SMTP_PORT')) {
    define('SMTP_PORT', 587);
}

if (!defined('SMTP_SECURE')) {
    define('SMTP_SECURE', 'tls');              // 'tls', 'ssl', oder ''
}

if (!defined('SMTP_USER')) {
    define('SMTP_USER', 'support@nnewton.de'); // Euer E-Mail-Benutzername
}

if (!defined('SMTP_PASS')) {
    define('SMTP_PASS', '');                   // Euer E-Mail-Passwort hier eintragen!
}

if (!defined('APP_VERSION_NAME')) {
    define('APP_VERSION_NAME', 'Beta');
}

if (!defined('APP_BUILD_DATE')) {
    define('APP_BUILD_DATE', '2026-05-10');
}

$APP_CHANGELOG = [
    [
        'version' => '0.9.1',
        'name' => 'Support Update',
        'date' => '2026-05-10',
        'type' => 'Aktuell',
        'changes' => [
            'Support-Ticket-System eingebaut – Feedback und Fehlermeldungen direkt im Portal senden.',
            'E-Mail-Benachrichtigung an Admins bei neuen Support-Tickets.',
            'E-Mail-Versand bei Rechnungserstellung und -aktualisierung (PDF im Anhang).',
            'E-Mail-Adressen pro Benutzer in der Benutzerverwaltung hinterlegbar.',
            'E-Mail-Testseite für Admins eingebaut.',
            'Changelog-Link in Sidebar für alle Benutzer ergänzt.',
        ],
    ],
    [
        'version' => '0.9.0',
        'name' => 'Beta',
        'date' => '2026-05-01',
        'type' => 'Feature',
        'changes' => [
            'Dashboard überarbeitet und Benutzername aus der Datenbank geladen.',
            'EAT-Zeitzone für Madagascar / East Africa Time eingebaut.',
            'Bestellungen mit Kategorien, Live-Zwischensumme und Live-Gesamtbetrag verbessert.',
            'Admin-Bestellungen mit Benutzeransicht und Einzelbestellungs-Bearbeitung erweitert.',
            'Rechnungs-Benachrichtigungen nur bei echten Änderungen eingebaut.',
            'Sidebar, Topbar, Footer und Mobile-Darstellung verbessert.',
            'Profilbild-Verwaltung inklusive runder Vorschau und Zuschnitt vorbereitet.',
        ],
    ],
    [
        'version' => '0.8.0',
        'name' => 'Admin Update',
        'date' => '2026-04-30',
        'type' => 'Feature',
        'changes' => [
            'Account Management mit Rollen, Ranks und Benutzer-Sperre erweitert.',
            'Rank-Prüfung auf Datenbankbasis statt Session-Rank umgesetzt.',
            'Recovery-Code-Verwaltung eingebaut.',
            'Aktivitätslogs für wichtige Aktionen ergänzt.',
        ],
    ],
    [
        'version' => '0.7.0',
        'name' => 'Rechnungen',
        'date' => '2026-04-29',
        'type' => 'Feature',
        'changes' => [
            'Rechnungserstellung aus Bestellungen hinzugefügt.',
            'PDF-Anzeige für Rechnungen eingebaut.',
            'Zahlung-melden-Funktion mit Admin-Benachrichtigung ergänzt.',
        ],
    ],
    [
        'version' => '0.5.0',
        'name' => 'Bestellsystem',
        'date' => '2026-04-28',
        'type' => 'Basis',
        'changes' => [
            'Bestelltermine, Produkte und Benutzerbestellungen eingeführt.',
            'Gebühren, Rabatte und Gesamtberechnung ergänzt.',
            'Archivierte und aktive Bestellungen getrennt dargestellt.',
        ],
    ],
    [
        'version' => '0.1.0',
        'name' => 'Start',
        'date' => '2026-04-27',
        'type' => 'Initial',
        'changes' => [
            'Erste Grundversion der Website erstellt.',
            'Login, Layout und erste Seitenstruktur angelegt.',
        ],
    ],
];

function appVersionLabel()
{
    return 'Version ' . APP_VERSION . ' · ' . APP_VERSION_NAME;
}

function appVersionFullLabel()
{
    return 'Version ' . APP_VERSION . ' · ' . APP_VERSION_NAME . ' · Build ' . APP_BUILD_DATE;
}
