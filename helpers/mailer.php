<?php

/**
 * Sends an email from support@nnewton.de.
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

    $fromEmail = 'support@nnewton.de';
    $fromName  = 'Einkauf Support';

    $boundary = '=_Part_' . md5(uniqid((string)mt_rand(), true));

    $headers  = 'From: ' . mb_encode_mimeheader($fromName, 'UTF-8') . ' <' . $fromEmail . '>' . "\r\n";
    $headers .= 'Reply-To: ' . $fromEmail . "\r\n";
    $headers .= 'MIME-Version: 1.0' . "\r\n";
    $headers .= 'Content-Type: multipart/mixed; boundary="' . $boundary . '"' . "\r\n";
    $headers .= 'X-Mailer: PHP/' . PHP_VERSION . "\r\n";

    $innerBoundary = '=_Inner_' . md5(uniqid((string)mt_rand(), true));

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

    $safeToName = preg_replace('/["\r\n]/', '', $toName);
    $toHeader   = '"' . $safeToName . '" <' . $toEmail . '>';

    try {
        return mail($toHeader, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
    } catch (Throwable $e) {
        return false;
    }
}
