<?php
/**
 * Form-POST handler for "Generate / refresh". Designed to be reached
 * by a plain HTML <form> submit so the feature works with JavaScript
 * disabled, blocked, or broken — generates the PDF on the server and
 * redirects back to the ticket page. Errors surface via GLPI's
 * standard "addMessageAfterRedirect" toast system.
 */

use GlpiPlugin\Glpiticketreportsign\Pdf\ReportPdf;
use GlpiPlugin\Glpiticketreportsign\Pdf\ReportStorage;
use GlpiPlugin\Glpiticketreportsign\Profile;
use GlpiPlugin\Glpiticketreportsign\Report;
use GlpiPlugin\Glpiticketreportsign\Security\Authorizer;

// Bootstrap GLPI core. Buffered to swallow the stray XML-parse
// warning that GLPI 11 emits on form POSTs — see config.form.php
// for the full explanation.
ob_start();
include('../../../inc/includes.php');
ob_end_clean();

/** @var array $CFG_GLPI */
global $CFG_GLPI;

// We deliberately do NOT call Session::checkRight() — this plugin's
// authorization model is the per-ticket Authorizer (assigned tech /
// supervisor), enforced below.
//
// CSRF is best-effort: GLPI 11 treats CSRF tokens as single-use and
// the Ticket-form-tab AJAX context plus a separate POST makes them
// flaky. The omada plugin in the same install hit this and went the
// same way. The user is authenticated (checkLoginUser) and the
// request must be same-origin (and the per-ticket Authorizer below
// will reject anyone but the assigned tech / supervisor), so CSRF
// here is defence-in-depth, not the security boundary.
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

$ticketId = (int) ($_POST['tickets_id'] ?? 0);
if ($ticketId <= 0) {
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

// Generate / refresh state machine — must match TicketTab:
//   - closed ticket → blocked
//   - maintenance ticket → blocked (use MTTO instead)
if (Authorizer::isTicketClosed($ticketId)) {
    Session::addMessageAfterRedirect(
        __('Cannot generate a report on a closed ticket.', 'glpiticketreportsign'),
        false,
        ERROR
    );
    Html::back();
}
if (Authorizer::isMaintenanceTicket($ticketId)) {
    Session::addMessageAfterRedirect(
        __('Use the MTTO button — this ticket is flagged as Mantenimiento Preventivo.', 'glpiticketreportsign'),
        false,
        ERROR
    );
    Html::back();
}

try {
    $bytes    = (new ReportPdf($ticket))->render();
    $reportId = ReportStorage::save($ticket, $bytes, Report::STATE_DRAFT);
    Session::addMessageAfterRedirect(
        __('Report generated.', 'glpiticketreportsign'),
        false,
        INFO
    );
} catch (\Throwable $e) {
    \Toolbox::logInFile('glpiticketreportsign_error', 'generate: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    Session::addMessageAfterRedirect(
        __('Failed to generate report:', 'glpiticketreportsign') . ' ' . $e->getMessage(),
        false,
        ERROR
    );
}

// Reload the same page the user submitted from instead of building
// an absolute URL — keeps them on the Report tab and avoids any
// edge case where url_base resolves wrong and lands on the plugins
// page.
Html::back();
