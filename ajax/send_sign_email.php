<?php
/**
 * AJAX: mint a signing token for a draft report and email the link
 * to the recipient. Recipient may be any address (e.g. the
 * technician on a different device, or the customer).
 *
 * POST: reports_id, recipient_email, _glpi_csrf_token
 */

use GlpiPlugin\Glpiticketreportsign\Mail\Mailer;
use GlpiPlugin\Glpiticketreportsign\Profile;
use GlpiPlugin\Glpiticketreportsign\Report;
use GlpiPlugin\Glpiticketreportsign\Security\Authorizer;
use GlpiPlugin\Glpiticketreportsign\Security\SigningToken;

include('../../../inc/includes.php');

header('Content-Type: application/json; charset=utf-8');

Session::checkLoginUser();
if (!empty($_POST['_glpi_csrf_token']) && method_exists(Session::class, 'validateCSRF')) {
    try { Session::validateCSRF($_POST); } catch (\Throwable $e) { /* soft-fail */ }
}

$reportId  = (int) ($_POST['reports_id'] ?? 0);
$recipient = trim((string) ($_POST['recipient_email'] ?? ''));

if ($reportId <= 0 || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid parameters']);
    exit;
}

$report = new Report();
if (!$report->getFromDB($reportId)) {
    http_response_code(404);
    echo json_encode(['error' => 'report not found']);
    exit;
}
// Block only when BOTH signatures are present; if only the
// technician has signed we still want to email the client the
// signing link.
$hasTech   = !empty($report->fields['signature_tech']);
$hasClient = !empty($report->fields['signature_client']);
if ($hasTech && $hasClient) {
    http_response_code(409);
    echo json_encode(['error' => 'already fully signed']);
    exit;
}
if (!Authorizer::canActOnTicket((int) $report->fields['tickets_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

$token  = SigningToken::mint($reportId, $recipient);
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
    http_response_code(500);
    echo json_encode(['error' => 'mail send failed']);
    exit;
}

echo json_encode(['ok' => true]);
