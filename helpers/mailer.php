<?php

/**
 * Sends an email. Uses SMTP when SMTP_HOST is defined, otherwise falls back to PHP mail().
 *
 * @param string      $toEmail
 * @param string      $toName
 * @param string      $subject
 * @param string      $htmlBody
 * @param array|null  $attachment  ['filename' => '...', 'data' => '...binary...', 'mime' => 'application/pdf']
 * @return bool
 */
function sendMail(string $toEmail, string $toName, string $subject, string $htmlBody, ?array $attachment = null): bool
{
    if ($toEmail === '') {
        return false;
    }

    $fromEmail = defined('MAIL_FROM')      ? MAIL_FROM      : 'noreply@example.com';
    $fromName  = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'Einkauf Support';

    if (defined('BREVO_API_KEY') && BREVO_API_KEY !== '') {
        return brevoApiSend($toEmail, $toName, $fromEmail, $fromName, $subject, $htmlBody);
    }

    [$contentHeaders, $body] = buildMimeParts($htmlBody, $attachment);

    if (defined('SMTP_HOST') && SMTP_HOST !== '') {
        return smtpSend($toEmail, $toName, $fromEmail, $fromName, $subject, $contentHeaders, $body);
    }

    $headers  = 'From: ' . mb_encode_mimeheader($fromName, 'UTF-8') . ' <' . $fromEmail . '>' . "\r\n";
    $headers .= 'Reply-To: ' . $fromEmail . "\r\n";
    $headers .= $contentHeaders;

    $safeToName = preg_replace('/["\r\n]/', '', $toName);
    $toHeader   = '"' . $safeToName . '" <' . $toEmail . '>';

    try {
        return mail($toHeader, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Sends via Brevo HTTP API (port 443 – works even when SMTP ports are blocked).
 */
function brevoApiSend(
    string $toEmail,
    string $toName,
    string $fromEmail,
    string $fromName,
    string $subject,
    string $htmlBody
): bool {
    $payload = json_encode([
        'sender'      => ['name' => $fromName, 'email' => $fromEmail],
        'to'          => [['email' => $toEmail, 'name' => $toName]],
        'subject'     => $subject,
        'htmlContent' => $htmlBody,
    ]);

    try {
        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => 1,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'api-key: ' . BREVO_API_KEY,
            ],
        ]);

        $resp    = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);

        if ($curlErr !== '') {
            error_log('Brevo API curl error: ' . $curlErr);
            return false;
        }

        $data = json_decode($resp, true);
        if ($httpCode >= 200 && $httpCode < 300) {
            return true;
        }

        error_log('Brevo API HTTP ' . $httpCode . ': ' . $resp);
        return false;
    } catch (Throwable $e) {
        error_log('Brevo API exception: ' . $e->getMessage());
        return false;
    }
}

/**
 * Builds MIME content headers and multipart body.
 * Returned headers do NOT include From/To/Subject/Date.
 *
 * @return array{0: string, 1: string}  [$headers, $body]
 */
function buildMimeParts(string $htmlBody, ?array $attachment): array
{
    $boundary      = '=_Part_' . md5(uniqid((string)mt_rand(), true));
    $innerBoundary = '=_Inner_' . md5(uniqid((string)mt_rand(), true));

    $headers  = 'MIME-Version: 1.0' . "\r\n";
    $headers .= 'Content-Type: multipart/mixed; boundary="' . $boundary . '"' . "\r\n";
    $headers .= 'X-Mailer: PHP/' . PHP_VERSION . "\r\n";

    $body  = '--' . $boundary . "\r\n";
    $body .= 'Content-Type: multipart/alternative; boundary="' . $innerBoundary . '"' . "\r\n\r\n";

    $body .= '--' . $innerBoundary . "\r\n";
    $body .= 'Content-Type: text/plain; charset=UTF-8' . "\r\n";
    $body .= 'Content-Transfer-Encoding: quoted-printable' . "\r\n\r\n";
    $body .= quoted_printable_encode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $htmlBody))) . "\r\n";

    $body .= '--' . $innerBoundary . "\r\n";
    $body .= 'Content-Type: text/html; charset=UTF-8' . "\r\n";
    $body .= 'Content-Transfer-Encoding: quoted-printable' . "\r\n\r\n";
    $body .= quoted_printable_encode($htmlBody) . "\r\n";

    $body .= '--' . $innerBoundary . "--\r\n";

    if ($attachment !== null && isset($attachment['data'], $attachment['filename'])) {
        $mime     = $attachment['mime'] ?? 'application/octet-stream';
        $filename = $attachment['filename'];
        $encoded  = chunk_split(base64_encode($attachment['data']));

        $body .= '--' . $boundary . "\r\n";
        $body .= 'Content-Type: ' . $mime . '; name="' . $filename . '"' . "\r\n";
        $body .= 'Content-Transfer-Encoding: base64' . "\r\n";
        $body .= 'Content-Disposition: attachment; filename="' . $filename . '"' . "\r\n\r\n";
        $body .= $encoded . "\r\n";
    }

    $body .= '--' . $boundary . "--\r\n";

    return [$headers, $body];
}

/**
 * Sends a pre-built MIME message via SMTP using PHP socket functions.
 * Supports STARTTLS (port 587) and implicit SSL (port 465) with AUTH LOGIN.
 */
function smtpSend(
    string $toEmail,
    string $toName,
    string $fromEmail,
    string $fromName,
    string $subject,
    string $contentHeaders,
    string $body
): bool {
    $host    = SMTP_HOST;
    $port    = defined('SMTP_PORT')   ? (int)SMTP_PORT   : 587;
    $user    = defined('SMTP_USER')   ? SMTP_USER        : '';
    $pass    = defined('SMTP_PASS')   ? SMTP_PASS        : '';
    $secure  = defined('SMTP_SECURE') ? SMTP_SECURE      : 'tls';
    $timeout = 15;

    try {
        $sslContext = stream_context_create([
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ],
        ]);

        $prefix = ($secure === 'ssl') ? 'ssl://' : '';
        $sock   = @stream_socket_client(
            $prefix . $host . ':' . $port,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $sslContext
        );

        if (!$sock) {
            error_log("SMTP connect failed: $errno $errstr (host=$host port=$port)");
            return false;
        }

        stream_set_timeout($sock, $timeout);

        $read = static function () use ($sock): string {
            $data = '';
            while (!feof($sock)) {
                $line = fgets($sock, 512);
                if ($line === false) break;
                $data .= $line;
                if (strlen($line) >= 4 && $line[3] === ' ') break;
            }
            return $data;
        };

        $write = static function (string $cmd) use ($sock): void {
            fwrite($sock, $cmd . "\r\n");
        };

        $read(); // server greeting

        $localHost = trim((string)($_SERVER['SERVER_NAME'] ?? 'localhost')) ?: 'localhost';
        $write('EHLO ' . $localHost);
        $read();

        if ($secure === 'tls') {
            $write('STARTTLS');
            $read();
            stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $write('EHLO ' . $localHost);
            $read();
        }

        if ($user !== '') {
            $write('AUTH LOGIN');
            $read();
            $write(base64_encode($user));
            $read();
            $write(base64_encode($pass));
            $authResp = $read();
            if (strpos($authResp, '235') === false) {
                error_log("SMTP auth failed: $authResp (user=$user)");
                fclose($sock);
                return false;
            }
        }

        $write('MAIL FROM:<' . $fromEmail . '>');
        $read();

        $write('RCPT TO:<' . $toEmail . '>');
        $rcptResp = $read();
        if (strpos($rcptResp, '250') === false && strpos($rcptResp, '251') === false) {
            error_log("SMTP RCPT failed: $rcptResp");
            fclose($sock);
            return false;
        }

        $write('DATA');
        $read();

        $safeToName = preg_replace('/["\r\n]/', '', $toName);

        $message  = 'From: ' . mb_encode_mimeheader($fromName, 'UTF-8') . ' <' . $fromEmail . '>' . "\r\n";
        $message .= 'To: "' . $safeToName . '" <' . $toEmail . '>' . "\r\n";
        $message .= 'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=' . "\r\n";
        $message .= 'Date: ' . date('r') . "\r\n";
        $message .= 'Reply-To: ' . $fromEmail . "\r\n";
        $message .= $contentHeaders;
        $message .= "\r\n";
        $message .= $body;

        $message = str_replace("\r\n.", "\r\n..", $message);

        fwrite($sock, $message . "\r\n.\r\n");
        $dataResp = $read();

        $write('QUIT');
        fclose($sock);

        return strpos($dataResp, '250') !== false;
    } catch (Throwable $e) {
        error_log("SMTP exception: " . $e->getMessage());
        return false;
    }
}
