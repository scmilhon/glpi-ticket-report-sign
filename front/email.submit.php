<?php
/**
 * Form-POST handler for the email-link page.
 */

use GlpiPlugin\Glpiticketreportsign\Mail\Mailer;
use GlpiPlugin\Glpiticketreportsign\Profile;
use GlpiPlugin\Glpiticketreportsign\Report;
use GlpiPlugin\Glpiticketreportsign\Security\Authorizer;
use GlpiPlugin\Glpiticketreportsign\Security\SigningToken;

ob_start();
include('../../../inc/includes.php');
ob_end_clean();

/** @var array $CFG_GLPI */
global $CFG_GLPI;

Session::checkLoginUser();
if (!empty($_POST['_glpi_csrf_token']) && method_exists(Session::class, 'validateCSRF')) {
    try { Session::validateCSRF($_POST); } catch (\Throwable $e) { /* soft-fail */ }
}
if (!Profile::hasRight(UPDATE)) {
    Session::addMessageAfterRedirect(
        __('Your profile does not have permission to send signing emails.', 'glpiticketreportsign'),
        false,
        ERROR
    );
    Html::back();
}

$reportId  = (int) ($_POST['reports_id'] ?? 0);
$recipient = trim((string) ($_POST['recipient_email'] ?? ''));

$report = new Report();
if ($reportId <= 0 || !$report->getFromDB($reportId)) {
    Session::addMessageAfterRedirect(__('Report not found', 'glpiticketreportsign'), false, ERROR);
    Html::back();
}
if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
    Session::addMessageAfterRedirect(__('Invalid email address.', 'glpiticketreportsign'), false, ERROR);
    Html::back();
}
if (!Authorizer::canActOnTicket((int) $report->fields['tickets_id'])) {
    Html::displayRightError();
}

try {
    $token   = SigningToken::mint($reportId, $recipient);
    $signUrl = rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/')
             . plugin_glpiticketreportsign_web_dir(false)
             . '/front/sign.php?t=' . urlencode($token);

    $subject = sprintf(__('Sign report for ticket #%d', 'glpiticketreportsign'), (int) $report->fields['tickets_id']);
    $body    = sprintf(
        '<p>%s</p><p><a href="%s">%s</a></p><p style="color:#888;font-size:12px">%s</p>',
        htmlspecialchars(__('You have been asked to sign a service report.', 'glpiticketreportsign')),
        htmlspecialchars($signUrl, ENT_QUOTES),
        htmlspecialchars(__('Open the report and sign', 'glpiticketreportsign')),
        htmlspecialchars(__('This link is single-use and expires in 7 days.', 'glpiticketreportsign'))
    );

    if (!Mailer::sendSigningLink($recipient, $subject, $body)) {
        throw new \RuntimeException('Mailer::sendSigningLink returned false (check GLPI SMTP configuration).');
    }
    Session::addMessageAfterRedirect(__('Signing link sent.', 'glpiticketreportsign'), false, INFO);
} catch (\Throwable $e) {
    \Toolbox::logInFile('glpiticketreportsign_error', 'email: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    Session::addMessageAfterRedirect(
        __('Failed to send email:', 'glpiticketreportsign') . ' ' . $e->getMessage(),
        false,
        ERROR
    );
}

Html::back();
