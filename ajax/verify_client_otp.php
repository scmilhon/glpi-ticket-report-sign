<?php
/**
 * AJAX: technician checks a signer's 6-digit code before
 * front/sign.form.php reveals the "Name" field and signature canvas
 * for the client side. Read-only — does NOT consume the code; that
 * only happens once the signature is actually saved (see
 * front/sign.submit.php).
 *
 * POST: reports_id, otp_code
 */

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

$reportId = (int) ($_POST['reports_id'] ?? 0);
$otp      = trim((string) ($_POST['otp_code'] ?? ''));

$report = new Report();
if ($reportId <= 0 || !$report->getFromDB($reportId)) {
    http_response_code(404);
    echo json_encode(['error' => 'report not found']);
    exit;
}
$ticketId = (int) $report->fields['tickets_id'];

if (!Profile::hasRight(UPDATE) || !Authorizer::canActOnTicket($ticketId)) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

$verified = SigningToken::verifyOtpForTicket($ticketId, $otp);
if ($verified === null) {
    http_response_code(403);
    echo json_encode(['error' => 'invalid or expired code']);
    exit;
}

echo json_encode(['ok' => true, 'name' => $verified['signer_name']]);
