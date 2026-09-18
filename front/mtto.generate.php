<?php
/**
 * Generates an MTTO (maintenance) report draft: the same content
 * as the regular report plus an EQUIPO INFORMÁTICO section sourced
 * from the selected Computer asset in GLPI.
 *
 * POST: tickets_id, computers_id, _glpi_csrf_token
 */

use Computer;
use GlpiPlugin\Glpiticketreportsign\Pdf\ReportPdf;
use GlpiPlugin\Glpiticketreportsign\Pdf\ReportStorage;
use GlpiPlugin\Glpiticketreportsign\Profile;
use GlpiPlugin\Glpiticketreportsign\Report;
use GlpiPlugin\Glpiticketreportsign\Security\Authorizer;

ob_start();
include('../../../inc/includes.php');
ob_end_clean();

/** @var array $CFG_GLPI */
global $CFG_GLPI;

Session::checkLoginUser();
if (!empty($_POST['_glpi_csrf_token']) && method_exists(Session::class, 'validateCSRF')) {
    try { Session::validateCSRF($_POST); } catch (\Throwable $e) { /* soft-fail */ }
}
if (!Profile::hasRight(CREATE)) {
    Session::addMessageAfterRedirect(
        __('Your profile does not have permission to generate reports.', 'glpiticketreportsign'),
        false,
        ERROR
    );
    Html::back();
}

$ticketId    = (int) ($_POST['tickets_id']   ?? 0);
$computersId = (int) ($_POST['computers_id'] ?? 0);

if ($ticketId <= 0 || $computersId <= 0) {
    Session::addMessageAfterRedirect(__('Missing ticket id', 'glpiticketreportsign'), false, ERROR);
    Html::back();
}
if (!Authorizer::canActOnTicket($ticketId)) {
    Session::addMessageAfterRedirect(__('You are not authorized to generate the report for this ticket.', 'glpiticketreportsign'), false, ERROR);
    Html::back();
}

$ticket = new Ticket();
if (!$ticket->getFromDB($ticketId)) {
    Session::addMessageAfterRedirect(__('Ticket not found', 'glpiticketreportsign'), false, ERROR);
    Html::back();
}
// Closed lock doesn't apply when the ticket is MTTO-eligible —
// the MTTO flow exists precisely for resolved/closed maintenance.
if (Authorizer::isTicketClosed($ticketId) && !Authorizer::isMaintenanceTicket($ticketId)) {
    Session::addMessageAfterRedirect(
        __('Cannot generate a report on a closed ticket.', 'glpiticketreportsign'),
        false,
        ERROR
    );
    Html::back();
}
if (!Authorizer::isMaintenanceTicket($ticketId)) {
    Session::addMessageAfterRedirect(
        __('Available once the ticket is resolved with solution type "Mantenimiento Preventivo".', 'glpiticketreportsign'),
        false,
        ERROR
    );
    Html::back();
}

$computer = new Computer();
if (!$computer->getFromDB($computersId)) {
    Session::addMessageAfterRedirect(__('Computer not found', 'glpiticketreportsign'), false, ERROR);
    Html::back();
}

try {
    $bytes = (new ReportPdf(
        $ticket,
        null, null, null, null,
        $computer
    ))->render();

    ReportStorage::save(
        $ticket,
        $bytes,
        Report::STATE_DRAFT,
        null,
        ['computers_id' => $computersId]
    );
    Session::addMessageAfterRedirect(
        __('MTTO report generated.', 'glpiticketreportsign'),
        false,
        INFO
    );
} catch (\Throwable $e) {
    \Toolbox::logInFile('glpiticketreportsign_error', 'mtto-generate: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    Session::addMessageAfterRedirect(
        __('Failed to generate report:', 'glpiticketreportsign') . ' ' . $e->getMessage(),
        false,
        ERROR
    );
}

// root_doc (relative), not url_base — see the note above in
// front/generate.php about url_base resolving wrong in some setups
// and landing users on the wrong page.
Html::redirect(rtrim((string) ($CFG_GLPI['root_doc'] ?? ''), '/') . '/front/ticket.form.php?id=' . $ticketId);
