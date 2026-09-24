<?php
/**
 * AJAX: a technician types an email address for someone signing in
 * person who isn't one of the ticket's existing requesters — mints a
 * fresh sign link + 6-digit code for that address (same mechanism as
 * the automatic "technician signed" email) and sends it, so the same
 * anti-forgery property holds: the code only ever reaches that
 * person's own inbox. The technician then asks them for the code and
 * types it into the field front/sign.form.php renders — see
 * SigningToken::verifyOtpForTicket() / front/sign.submit.php.
 *
 * POST: reports_id, email, name
 */

use GlpiPlugin\Glpiticketreportsign\Mail\ReportMailer;
use GlpiPlugin\Glpiticketreportsign\Profile;
use GlpiPlugin\Glpiticketreportsign\Report;
use GlpiPlugin\Glpiticketreportsign\Security\Authorizer;

include('../../../inc/includes.php');

header('Content-Type: application/json; charset=utf-8');

Session::checkLoginUser();
if (!empty($_POST['_glpi_csrf_token']) && method_exists(Session::class, 'validateCSRF')) {
    try { Session::validateCSRF($_POST); } catch (\Throwable $e) { /* soft-fail */ }
}

$reportId = (int) ($_POST['reports_id'] ?? 0);
$email    = trim((string) ($_POST['email'] ?? ''));
$name     = trim((string) ($_POST['name'] ?? ''));

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
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid email']);
    exit;
}
if ($name === '') {
    http_response_code(400);
    echo json_encode(['error' => 'missing name']);
    exit;
}

try {
    $sent = ReportMailer::sendReportLink($reportId, $email, $ticketId, ReportMailer::TEMPLATE_TECH_SIGNED, true, $name);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

echo json_encode(['ok' => (bool) $sent]);
