<?php
/**
 * AJAX: regenerate a draft report PDF for a ticket. Always creates
 * a new version row — does not overwrite past versions, so the
 * timeline of generated reports remains audit-traceable.
 *
 * POST: tickets_id, _glpi_csrf_token
 */

use GlpiPlugin\Glpiticketreportsign\Pdf\ReportPdf;
use GlpiPlugin\Glpiticketreportsign\Pdf\ReportStorage;
use GlpiPlugin\Glpiticketreportsign\Profile;
use GlpiPlugin\Glpiticketreportsign\Report;
use GlpiPlugin\Glpiticketreportsign\Security\Authorizer;

include('../../../inc/includes.php');

header('Content-Type: application/json; charset=utf-8');

Session::checkLoginUser();
if (!empty($_POST['_glpi_csrf_token']) && method_exists(Session::class, 'validateCSRF')) {
    try { Session::validateCSRF($_POST); } catch (\Throwable $e) { /* soft-fail */ }
}
// Same predicate as front/generate.php.
if (!Profile::hasRight(CREATE)) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

$ticketId = (int) ($_POST['tickets_id'] ?? 0);
if ($ticketId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'tickets_id missing']);
    exit;
}
if (!Authorizer::canActOnTicket($ticketId)) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

$ticket = new Ticket();
if (!$ticket->getFromDB($ticketId)) {
    http_response_code(404);
    echo json_encode(['error' => 'ticket not found']);
    exit;
}

// Same state machine as front/generate.php / TicketTabBase — a
// closed or (when the feature is enabled) maintenance ticket can't
// get a new "generate" report version here.
if (Authorizer::isTicketClosed($ticketId)) {
    http_response_code(409);
    echo json_encode(['error' => 'ticket is closed']);
    exit;
}
if (Authorizer::isMaintenanceTicket($ticketId)) {
    http_response_code(409);
    echo json_encode(['error' => 'maintenance ticket — use the MTTO flow instead']);
    exit;
}

try {
    $bytes    = (new ReportPdf($ticket))->render();
    $reportId = ReportStorage::save($ticket, $bytes, Report::STATE_DRAFT);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

echo json_encode([
    'ok'         => true,
    'reports_id' => $reportId,
    'pdf_url'    => plugin_glpiticketreportsign_web_dir() . '/front/download.php?id=' . $reportId,
]);
