<?php
/**
 * AJAX: the client just signed via the public token page
 * (front/sign.php) and picked which version (full/condensed) they
 * want emailed to them as their final copy. Finds the matching
 * report row in the same ticket+version group, emails a 72h view
 * link (see ReportMailer), and consumes the original signing token —
 * ajax/sign_submit.php deliberately left it unconsumed for this step.
 *
 * POST: token, reports_id (the CHOSEN row's id)
 */

use GlpiPlugin\Glpiticketreportsign\Mail\ReportMailer;
use GlpiPlugin\Glpiticketreportsign\Report;
use GlpiPlugin\Glpiticketreportsign\Security\SigningToken;

include('../../../inc/includes.php');

header('Content-Type: application/json; charset=utf-8');

$token          = (string) ($_POST['token'] ?? '');
$chosenReportId = (int) ($_POST['reports_id'] ?? 0);

if ($token === '' || $chosenReportId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'missing parameters']);
    exit;
}

$verified = SigningToken::verify($token);
if ($verified === null) {
    http_response_code(403);
    echo json_encode(['error' => 'invalid or expired token']);
    exit;
}

$originalReport = new Report();
if (!$originalReport->getFromDB($verified['reports_id'])) {
    http_response_code(404);
    echo json_encode(['error' => 'report not found']);
    exit;
}

$chosen = new Report();
if (!$chosen->getFromDB($chosenReportId)) {
    http_response_code(404);
    echo json_encode(['error' => 'chosen report not found']);
    exit;
}

// The chosen row must belong to the SAME ticket+version the token
// was minted for — otherwise a tampered reports_id could pull in an
// unrelated report the recipient was never authorized to see.
if (
    (int) $chosen->fields['tickets_id'] !== (int) $originalReport->fields['tickets_id']
    || (int) $chosen->fields['version'] !== (int) $originalReport->fields['version']
) {
    http_response_code(403);
    echo json_encode(['error' => 'report does not belong to this signing group']);
    exit;
}

try {
    $sent = ReportMailer::sendReportLink(
        $chosenReportId,
        $verified['recipient'],
        (int) $chosen->fields['tickets_id'],
        ReportMailer::TEMPLATE_CHOOSE_VERSION
    );
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

SigningToken::consume($token);

echo json_encode(['ok' => (bool) $sent]);
