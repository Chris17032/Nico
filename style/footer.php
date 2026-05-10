<?php
$appVersion = defined('APP_VERSION') ? APP_VERSION : '0.9.0';
?>

<footer class="app-footer">
    <div>
        Created with love by Elias and friends
        <span class="footer-heart">♥</span>
    </div>

    <div>
        Version <?= htmlspecialchars($appVersion, ENT_QUOTES, 'UTF-8') ?> · Beta
    </div>
</footer>