<?php
$appVersion = defined('APP_VERSION') ? APP_VERSION : '0.9.0';
?>

<footer class="app-footer">
    <div>
        Created with love by Elias and friends
        <span class="footer-heart">♥</span>
        &nbsp;·&nbsp;
        <a href="changelog" class="footer-link">Changelog</a>
        &nbsp;·&nbsp;
        <button type="button" class="footer-link footer-support-link" data-bs-toggle="modal" data-bs-target="#supportTicketModal">
            Support
        </button>
    </div>

    <div>
        Version <?= htmlspecialchars($appVersion, ENT_QUOTES, 'UTF-8') ?> · Beta
    </div>
</footer>

<style>
    .footer-link {
        color: inherit;
        text-decoration: none;
        opacity: .7;
        background: none;
        border: none;
        padding: 0;
        font-size: inherit;
        cursor: pointer;
    }

    .footer-link:hover {
        opacity: 1;
        text-decoration: underline;
    }
</style>