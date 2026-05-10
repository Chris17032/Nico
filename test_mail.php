<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/helpers/mailer.php';

$currentUser = requireRank($pdo, 5);
$pageTitle   = 'E-Mail Test';

$result  = null;
$toEmail = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_test'])) {
    $toEmail = trim($_POST['to_email'] ?? '');

    if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        $result = ['success' => false, 'message' => 'Bitte eine gültige E-Mail-Adresse eingeben.'];
    } else {
        $html = '
            <h2 style="font-size:20px;margin-bottom:16px;">Test-E-Mail ✓</h2>
            <p>Diese E-Mail wurde über das Einkauf-Portal gesendet.</p>
            <table style="border-collapse:collapse;font-size:14px;margin-top:12px;">
                <tr><td style="padding:4px 12px 4px 0;color:#64748b;">Absender:</td><td><strong>' . htmlspecialchars(defined('MAIL_FROM') ? MAIL_FROM : 'support@nnewton.de', ENT_QUOTES, 'UTF-8') . '</strong></td></tr>
                <tr><td style="padding:4px 12px 4px 0;color:#64748b;">Empfänger:</td><td>' . htmlspecialchars($toEmail, ENT_QUOTES, 'UTF-8') . '</td></tr>
                <tr><td style="padding:4px 12px 4px 0;color:#64748b;">Methode:</td><td>' . (defined('SMTP_HOST') && SMTP_HOST !== '' ? 'SMTP (' . htmlspecialchars(SMTP_HOST, ENT_QUOTES, 'UTF-8') . ')' : 'PHP mail()') . '</td></tr>
                <tr><td style="padding:4px 12px 4px 0;color:#64748b;">Zeitpunkt:</td><td>' . date('d.m.Y H:i:s') . '</td></tr>
            </table>
            <p style="margin-top:20px;color:#64748b;font-size:13px;">Wenn du diese E-Mail erhältst, funktioniert der E-Mail-Versand korrekt.</p>
        ';

        $sent = sendMail($toEmail, 'Test', 'Test-E-Mail vom Einkauf-Portal', $html);

        $method = (defined('SMTP_HOST') && SMTP_HOST !== '') ? 'SMTP (' . SMTP_HOST . ':' . (defined('SMTP_PORT') ? SMTP_PORT : 587) . ')' : 'PHP mail()';

        $result = [
            'success' => $sent,
            'message' => $sent
                ? 'E-Mail wurde erfolgreich über ' . $method . ' an ' . $toEmail . ' übergeben. Bitte Posteingang (und Spam) prüfen.'
                : 'E-Mail-Versand über ' . $method . ' fehlgeschlagen. Prüfe SMTP_HOST, SMTP_USER und SMTP_PASS in config/app_version.php.',
        ];
    }
}

ob_start();
?>

<div class="mb-4">
    <h1 class="h3 mb-1">E-Mail Test</h1>
    <p class="text-muted mb-0">Prüfe ob der E-Mail-Versand vom Server funktioniert.</p>
</div>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card shadow-sm border-0">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-envelope-check me-2"></i>Test-E-Mail senden</h5>
            </div>
            <div class="card-body">
                <?php if ($result !== null): ?>
                    <div class="alert alert-<?= $result['success'] ? 'success' : 'danger' ?> mb-3">
                        <i class="bi bi-<?= $result['success'] ? 'check-circle' : 'exclamation-triangle' ?> me-2"></i>
                        <?= htmlspecialchars($result['message']) ?>
                    </div>
                <?php endif; ?>

                <form method="post">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Empfänger-E-Mail</label>
                        <input type="email" name="to_email" class="form-control"
                               value="<?= htmlspecialchars($toEmail) ?>"
                               placeholder="deine@email.de" required>
                        <div class="form-text">Gib deine eigene E-Mail-Adresse ein um zu prüfen ob Mails ankommen.</div>
                    </div>

                    <button type="submit" name="send_test" value="1" class="btn btn-primary">
                        <i class="bi bi-send me-1"></i>Test-Mail senden
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card shadow-sm border-0">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-gear me-2"></i>Aktuelle Konfiguration</h5>
            </div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tbody>
                        <tr>
                            <td class="text-muted">Versandmethode</td>
                            <td>
                                <?php if (defined('SMTP_HOST') && SMTP_HOST !== ''): ?>
                                    <span class="badge bg-success">SMTP</span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark">PHP mail()</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php if (defined('SMTP_HOST') && SMTP_HOST !== ''): ?>
                        <tr>
                            <td class="text-muted">SMTP Host</td>
                            <td><code><?= htmlspecialchars(SMTP_HOST) ?></code></td>
                        </tr>
                        <tr>
                            <td class="text-muted">SMTP Port / TLS</td>
                            <td><code><?= defined('SMTP_PORT') ? (int)SMTP_PORT : 587 ?> / <?= htmlspecialchars(defined('SMTP_SECURE') ? SMTP_SECURE : 'tls') ?></code></td>
                        </tr>
                        <tr>
                            <td class="text-muted">SMTP Benutzer</td>
                            <td><code><?= htmlspecialchars(defined('SMTP_USER') ? SMTP_USER : '-') ?></code></td>
                        </tr>
                        <tr>
                            <td class="text-muted">SMTP Passwort</td>
                            <td><code><?= (defined('SMTP_PASS') && SMTP_PASS !== '') ? '●●●●●●●●' : '<span class="text-danger">nicht gesetzt</span>' ?></code></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td class="text-muted">Absender</td>
                            <td><code><?= htmlspecialchars(defined('MAIL_FROM') ? MAIL_FROM : 'support@nnewton.de') ?></code></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Absender-Name</td>
                            <td><code><?= htmlspecialchars(defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'Einkauf Support') ?></code></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Admin-E-Mails (Tickets)</td>
                            <td>
                                <?php
                                $adminEmails = defined('SUPPORT_ADMIN_EMAILS') ? SUPPORT_ADMIN_EMAILS : [];
                                foreach ($adminEmails as $em):
                                    if (trim($em) === '') continue;
                                ?>
                                    <code class="d-block"><?= htmlspecialchars($em) ?></code>
                                <?php endforeach; ?>
                                <?php if (!$adminEmails): ?>
                                    <span class="text-muted small">Keine konfiguriert</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted">PHP Version</td>
                            <td><code><?= PHP_VERSION ?></code></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Server</td>
                            <td><code><?= htmlspecialchars($_SERVER['SERVER_NAME'] ?? '-') ?></code></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card shadow-sm border-0 mt-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>SMTP einrichten</h5>
            </div>
            <div class="card-body small text-muted">
                <p class="mb-2">Öffne <code>config/app_version.php</code> und trage dein E-Mail-Passwort ein:</p>
                <pre class="bg-light rounded p-2 mb-2" style="font-size:.8rem;">define('SMTP_HOST', 'mail.nnewton.de');
define('SMTP_PORT', 587);
define('SMTP_SECURE', 'tls');
define('SMTP_USER', 'support@nnewton.de');
define('SMTP_PASS', '<strong>DEIN PASSWORT</strong>');</pre>
                <p class="mb-2">Den SMTP-Host und Port findest du in den Einstellungen deines Hosting-Anbieters (Plesk → E-Mail-Adressen → Einstellungen).</p>
                <p class="mb-0"><strong>Admin-E-Mails ändern:</strong><br>
                    In <code>config/app_version.php</code> unter <code>SUPPORT_ADMIN_EMAILS</code> beliebig viele Adressen eintragen.</p>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/style/layout.php';
