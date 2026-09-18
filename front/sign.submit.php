<?php
/**
 * Receives one signature from sign.form.php — either the technician
 * or the client side, indicated by the `which` field — and saves
 * only that side, preserving whatever the other side already had.
 *
 * The PDF is regenerated on every save with the current set of
 * signatures. State stays at "signed" once at least one side has
 * signed; it never reverts to draft.
 *
 * Redirects back to the form page (Html::back) so the user stays
 * in context — the toast confirms what happened.
 */

use GlpiPlugin\Glpiticketreportsign\Pdf\ReportPdf;
use GlpiPlugin\Glpiticketreportsign\Pdf\ReportStorage;
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

$reportId   = (int) ($_POST['reports_id'] ?? 0);
$which      = (string) ($_POST['which'] ?? '');
$sigTech    = (string) ($_POST['signature_tech']   ?? '');
$sigClient  = (string) ($_POST['signature_client'] ?? '');
$techName   = trim((string) ($_POST['signer_tech_name']   ?? ''));
$clientName = trim((string) ($_POST['signer_client_name'] ?? ''));

$report = new Report();
if ($reportId <= 0 || !$report->getFromDB($reportId)) {
    Session::addMessageAfterRedirect(__('Report not found', 'glpiticketreportsign'), false, ERROR);
    Html::back();
}
$ticketId = (int) $report->fields['tickets_id'];

// Permission model:
//   - Technician with UPDATE right → can sign either side.
//   - Ticket requester (client)    → can sign ONLY the client side.
$isRequester   = Authorizer::isTicketRequester($ticketId);
$isTechSession = !$isRequester
    && \GlpiPlugin\Glpiticketreportsign\Profile::hasRight(UPDATE)
    && Authorizer::canActOnTicket($ticketId);

if (!$isTechSession && !$isRequester) {
    Html::displayRightError();
}
if ($which !== 'tech' && $which !== 'client') {
    Session::addMessageAfterRedirect(__('Missing signature side.', 'glpiticketreportsign'), false, ERROR);
    Html::back();
}
if ($isRequester && $which === 'tech') {
    Session::addMessageAfterRedirect(
        __('Clients can only sign the client side.', 'glpiticketreportsign'),
        false,
        ERROR
    );
    Html::back();
}

$ticket = new Ticket();
if (!$ticket->getFromDB((int) $report->fields['tickets_id'])) {
    Session::addMessageAfterRedirect(__('Ticket not found', 'glpiticketreportsign'), false, ERROR);
    Html::back();
}

// Take whichever side the user is updating; preserve the other.
$existingTech     = (string) ($report->fields['signature_tech']     ?? '');
$existingClient   = (string) ($report->fields['signature_client']   ?? '');
$existingTechNm   = (string) ($report->fields['signer_name']        ?? '');
$existingClientNm = (string) ($report->fields['signer_client_name'] ?? '');

// Workflow guard: a client signature is only accepted once the
// technician's is already on file. Mirrors the disabled state on
// the form so a crafted POST can't bypass the rule.
if ($which === 'client' && $existingTech === '') {
    Session::addMessageAfterRedirect(
        __('The client can only sign after the technician has signed the report.', 'glpiticketreportsign'),
        false,
        ERROR
    );
    Html::back();
}

if ($which === 'tech') {
    if ($sigTech === '') {
        Session::addMessageAfterRedirect(__('No technician signature received.', 'glpiticketreportsign'), false, ERROR);
        Html::back();
    }
    $finalTech     = $sigTech;
    $finalTechNm   = $techName !== '' ? $techName : $existingTechNm;
    $finalClient   = $existingClient;
    $finalClientNm = $existingClientNm;
} else {
    if ($sigClient === '') {
        Session::addMessageAfterRedirect(__('No client signature received.', 'glpiticketreportsign'), false, ERROR);
        Html::back();
    }
    $finalTech     = $existingTech;
    $finalTechNm   = $existingTechNm;
    $finalClient   = $sigClient;
    $finalClientNm = $clientName !== '' ? $clientName : $existingClientNm;
}

try {
    $bytes = (new ReportPdf(
        $ticket,
        $finalTech   ?: null,
        $finalTechNm ?: null,
        $finalClient ?: null,
        $finalClientNm ?: null,
    ))->render();

    $now = date('Y-m-d H:i:s');
    $fields = [
        'signature_tech'         => $finalTech     ?: null,
        'signature_client'       => $finalClient   ?: null,
        'signer_name'            => $finalTechNm   ?: null,
        'signer_client_name'     => $finalClientNm ?: null,
        'signed_ip'              => $_SERVER['REMOTE_ADDR'] ?? null,
    ];
    if ($which === 'tech') {
        $fields['signed_at']       = $now;
        $fields['signer_users_id'] = (int) $_SESSION['glpiID'];
    } else {
        $fields['signed_client_at']        = $now;
        $fields['signer_client_users_id']  = (int) $_SESSION['glpiID'];
    }

    ReportStorage::save($ticket, $bytes, Report::STATE_SIGNED, $reportId, $fields);
    Session::addMessageAfterRedirect(
        $which === 'tech'
            ? __('Technician signature saved.', 'glpiticketreportsign')
            : __('Client signature saved.', 'glpiticketreportsign'),
        false,
        INFO
    );
} catch (\Throwable $e) {
    \Toolbox::logInFile('glpiticketreportsign_error', 'sign: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    Session::addMessageAfterRedirect(
        __('Failed to save signature:', 'glpiticketreportsign') . ' ' . $e->getMessage(),
        false,
        ERROR
    );
}

Html::back();
