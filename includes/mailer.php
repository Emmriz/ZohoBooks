<?php
// ============================================================
//  ZOHOBOOKS - Mailer (PHPMailer / SMTP)
// ============================================================

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}
require_once __DIR__ . '/../config/mail.php';

/**
 * Low-level SMTP send. Never throws — logs and returns false on failure
 * so a mail outage never breaks the page that triggered it.
 */
function sendMail(string $toEmail, string $toName, string $subject, string $bodyHtml, ?string $ccEmail = null, ?string $ccName = null): bool {
    if (!MAIL_ENABLED) return false;
    if (!$toEmail || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) return false;
    if (!class_exists(PHPMailer::class)) { error_log('Mailer: PHPMailer not installed (run composer install)'); return false; }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->SMTPSecure = MAIL_ENCRYPTION;
        $mail->Port       = MAIL_PORT;

        $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
        $mail->addAddress($toEmail, $toName);

        if ($ccEmail && filter_var($ccEmail, FILTER_VALIDATE_EMAIL) && strcasecmp($ccEmail, $toEmail) !== 0) {
            $mail->addCC($ccEmail, $ccName ?: '');
        }

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $bodyHtml;
        $mail->AltBody = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $bodyHtml)));

        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        error_log('Mail send failed to ' . $toEmail . ': ' . $mail->ErrorInfo);
        return false;
    }
}

/** Wraps notification body HTML in a branded card matching the app's current theme. */
function emailWrapper(string $title, string $bodyHtml): string {
    $theme   = getTheme();
    $appName = getSetting('app_name', APP_NAME);
    return '
    <div style="font-family:Arial,Helvetica,sans-serif;max-width:520px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;">
      <div style="background:' . $theme['primary'] . ';padding:24px 28px;">
        <h1 style="margin:0;color:#ffffff;font-size:18px;font-weight:700;">' . htmlspecialchars($appName) . '</h1>
      </div>
      <div style="padding:28px;">
        <h2 style="margin:0 0 16px;color:#1f2937;font-size:16px;">' . htmlspecialchars($title) . '</h2>
        <div style="color:#374151;font-size:14px;line-height:1.6;">' . $bodyHtml . '</div>
      </div>
      <div style="padding:16px 28px;background:#f9fafb;border-top:1px solid #e5e7eb;">
        <p style="margin:0;color:#9ca3af;font-size:11px;">This is an automated notification from ' . htmlspecialchars($appName) . '. Please do not reply to this email.</p>
      </div>
    </div>';
}

// ─── Leave Notifications ─────────────────────────────────────

function notifyLeaveActivated(array $staff, string $start, string $end, string $reason, ?array $actingUser = null): void {
    if (empty($staff['email'])) return;
    $name = trim(($staff['first_name'] ?? '') . ' ' . ($staff['last_name'] ?? ''));
    $body = '
      <p>Hi ' . htmlspecialchars($staff['first_name'] ?? '') . ',</p>
      <p>Your leave has been recorded with the following details:</p>
      <table style="width:100%;border-collapse:collapse;margin:14px 0;">
        <tr><td style="padding:6px 0;color:#6b7280;width:120px;">Start Date</td><td style="padding:6px 0;font-weight:600;">' . formatDate($start) . '</td></tr>
        <tr><td style="padding:6px 0;color:#6b7280;">End Date</td><td style="padding:6px 0;font-weight:600;">' . formatDate($end) . '</td></tr>
        <tr><td style="padding:6px 0;color:#6b7280;">Reason</td><td style="padding:6px 0;font-weight:600;">' . htmlspecialchars($reason ?: 'Not specified') . '</td></tr>
      </table>
      <p>Your status has been updated to <strong>On Leave</strong> and will automatically return to <strong>Active</strong> on ' . formatDate($end) . '.</p>'
      . ($actingUser['name'] ?? null ? '<p style="color:#9ca3af;font-size:12px;margin-top:16px;">Recorded by ' . htmlspecialchars($actingUser['name']) . '.</p>' : '');

    sendMail($staff['email'], $name, 'Leave Request Confirmed', emailWrapper('Leave Activated', $body), $actingUser['email'] ?? null, $actingUser['name'] ?? null);
}

function notifyLeaveRevoked(array $staff, string $start, string $end, ?array $actingUser = null): void {
    if (empty($staff['email'])) return;
    $name = trim(($staff['first_name'] ?? '') . ' ' . ($staff['last_name'] ?? ''));
    $body = '
      <p>Hi ' . htmlspecialchars($staff['first_name'] ?? '') . ',</p>
      <p>Your scheduled leave from <strong>' . formatDate($start) . '</strong> to <strong>' . formatDate($end) . '</strong> has been <strong>revoked</strong>.</p>
      <p>Your status has been set back to <strong>Active</strong>.</p>'
      . ($actingUser['name'] ?? null ? '<p style="color:#9ca3af;font-size:12px;margin-top:16px;">Revoked by ' . htmlspecialchars($actingUser['name']) . '.</p>' : '');

    sendMail($staff['email'], $name, 'Leave Revoked', emailWrapper('Leave Revoked', $body), $actingUser['email'] ?? null, $actingUser['name'] ?? null);
}

function notifyLeaveCompleted(array $staff, string $start, string $end, ?array $actingUser = null): void {
    if (empty($staff['email'])) return;
    $name = trim(($staff['first_name'] ?? '') . ' ' . ($staff['last_name'] ?? ''));
    $body = '
      <p>Hi ' . htmlspecialchars($staff['first_name'] ?? '') . ',</p>
      <p>Your leave period from <strong>' . formatDate($start) . '</strong> to <strong>' . formatDate($end) . '</strong> has ended.</p>
      <p>Your status has automatically been set back to <strong>Active</strong>. Welcome back!</p>'
      . ($actingUser['name'] ?? null ? '<p style="color:#9ca3af;font-size:12px;margin-top:16px;">Originally recorded by ' . htmlspecialchars($actingUser['name']) . '.</p>' : '');

    sendMail($staff['email'], $name, 'Leave Completed — Welcome Back', emailWrapper('Leave Completed', $body), $actingUser['email'] ?? null, $actingUser['name'] ?? null);
}
