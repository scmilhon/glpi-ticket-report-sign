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

use GlpiPlugin\Glpiticketreportsign\Mail\Recipients;
use GlpiPlugin\Glpiticketreportsign\Mail\ReportMailer;
use GlpiPlugin\Glpiticketreportsign\Pdf\ReportPdf;
use GlpiPlugin\Glpiticketreportsign\Pdf\ReportStorage;
use GlpiPlugin\Glpiticketreportsign\Report;
use GlpiPlugin\Glpiticketreportsign\Security\Authorizer;
use GlpiPlugin\Glpiticketreportsign\Signature\SavedSignature;

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
//   - Technician with UPDATE right → can sign either side (this
//     covers the common in-person visit: the client is right there
//     and signs on the technician's own device/session).
//   - Ticket requester (client)    → can sign ONLY the client side.
// Forgery risk (a technician drawing the client's signature without
// them actually being present) is handled BEFORE the fact for a
// report born from a ticket resolution (status Solved/Closed, not an
// MTTO report): the technician must type the signer's 6-digit code —
// emailed only to that signer, or to whatever address the technician
// enters for a walk-in signer who isn't an existing requester (see
// ajax/mint_walkin_token.php) — before the client signature is
// accepted below. See SigningToken::verifyOtpForTicket().
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

// In-person OTP gate: only for a report born from a ticket resolution
// (status Solved or Closed) that isn't an MTTO report — see
// front/sign.form.php (renders the code field) and
// SigningToken::verifyOtpForTicket()/invalidateAllForTicket().
$isMtto         = (int) ($report->fields['computers_id'] ?? 0) > 0;
$statusGated    = in_array((int) $ticket->fields['status'], [Ticket::SOLVED, Ticket::CLOSED], true);
$otpFlowApplies = !$isMtto && $statusGated;

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
} else {
    if ($sigClient === '') {
        Session::addMessageAfterRedirect(__('No client signature received.', 'glpiticketreportsign'), false, ERROR);
        Html::back();
    }
}

// The technician can't draw the client's signature on a gated report
// without first typing the signer's 6-digit code (emailed to them, or
// to whatever address the technician entered for a walk-in signer —
// see ajax/mint_walkin_token.php). The requester signing from their
// own session/link is unaffected — this only restricts the
// technician-session path.
$otpVerification = null;
if ($which === 'client' && $isTechSession && $otpFlowApplies) {
    $otpCode          = trim((string) ($_POST['otp_code'] ?? ''));
    $otpVerification  = \GlpiPlugin\Glpiticketreportsign\Security\SigningToken::verifyOtpForTicket($ticketId, $otpCode);
    if ($otpVerification === null) {
        Session::addMessageAfterRedirect(
            __('Invalid or expired verification code. Ask the signer to check their email again.', 'glpiticketreportsign'),
            false,
            ERROR
        );
        Html::back();
    }
}

// Signing applies to the whole version group (full + condensed, when
// both exist — see OnSolutionAdded) so the technician signs once and
// both attachments end up signed, instead of only whichever row's
// "Sign" link they happened to click.
$version  = (int) $report->fields['version'];
$siblings = \GlpiPlugin\Glpiticketreportsign\Report::rowsForVersion($ticketId, $version);
if ($siblings === []) {
    $siblings = [$report->fields];
}

// The client is about to attest to content "as signed by the
// technician" — if the ticket's substantive content changed since
// the technician actually signed, that precondition no longer holds.
// Checked up front, for every sibling row, before anything is
// written: a legacy row with no stored hash (signed before this
// check existed) is trusted as-is, never blocked. See
// ReportPdf::contentFingerprint().
if ($which === 'client') {
    foreach ($siblings as $row) {
        $rowTechSig  = (string) ($row['signature_tech']     ?? '');
        $rowTechHash = (string) ($row['content_hash_tech']  ?? '');
        if ($rowTechSig === '' || $rowTechHash === '') {
            continue;
        }
        $rowMode = (string) ($row['mode'] ?? ReportPdf::MODE_FULL);
        if ($rowTechHash !== ReportPdf::contentFingerprint($ticket, $rowMode)) {
            Session::addMessageAfterRedirect(
                __('The ticket content changed since the technician signed. Ask them to review and sign again before you continue.', 'glpiticketreportsign'),
                false,
                ERROR
            );
            Html::back();
        }
    }
}

try {
    $now = date('Y-m-d H:i:s');
    $savedTechSig    = null;
    $savedTechNm     = null;
    $primaryReportId = $reportId; // fallback if no mode=full sibling is found (shouldn't happen)
    $discardedStaleSignature = false;

    foreach ($siblings as $row) {
        $rowId             = (int) $row['id'];
        $rowMode           = (string) ($row['mode'] ?? ReportPdf::MODE_FULL);
        $rowExistingTech   = (string) ($row['signature_tech']     ?? '');
        $rowExistingClient = (string) ($row['signature_client']   ?? '');
        $rowExistingTechNm = (string) ($row['signer_name']        ?? '');
        $rowExistingClientNm = (string) ($row['signer_client_name'] ?? '');
        $rowExistingTechHash   = (string) ($row['content_hash_tech']   ?? '');
        $rowExistingClientHash = (string) ($row['content_hash_client'] ?? '');

        // A signature attests to the ticket's content AS IT WAS at
        // the moment it was drawn — not to whatever the ticket says
        // now. Before carrying an already-signed side forward onto a
        // fresh render, make sure nothing substantive changed under
        // it since it signed; if it did, that side's signature (and
        // its confirmation/timestamp) is discarded rather than
        // silently stamped onto different content. See
        // ReportPdf::contentFingerprint().
        $currentHash = ReportPdf::contentFingerprint($ticket, $rowMode);

        if ($which === 'tech') {
            $rowFinalTech     = $sigTech;
            $rowFinalTechNm   = $techName !== '' ? $techName : $rowExistingTechNm;
            $rowFinalClient   = $rowExistingClient;
            $rowFinalClientNm = $rowExistingClientNm;
            $rowFinalClientHash = $rowExistingClientHash;
            if ($rowFinalClient !== '' && $rowExistingClientHash !== '' && $rowExistingClientHash !== $currentHash) {
                $rowFinalClient     = '';
                $rowFinalClientNm   = '';
                $rowFinalClientHash = '';
                $discardedStaleSignature = true;
            }
        } else {
            // The technician side can't be stale here — the pre-flight
            // check above already rejected the whole request if it was.
            $rowFinalTech     = $rowExistingTech;
            $rowFinalTechNm   = $rowExistingTechNm;
            $rowFinalTechHash = $rowExistingTechHash;
            $rowFinalClient   = $sigClient;
            $rowFinalClientNm = $clientName !== '' ? $clientName : $rowExistingClientNm;
        }

        $bytes = (new ReportPdf(
            $ticket,
            $rowFinalTech   ?: null,
            $rowFinalTechNm ?: null,
            $rowFinalClient ?: null,
            $rowFinalClientNm ?: null,
            mode: $rowMode,
        ))->render();

        $fields = [
            'signature_tech'     => $rowFinalTech     ?: null,
            'signature_client'   => $rowFinalClient   ?: null,
            'signer_name'        => $rowFinalTechNm   ?: null,
            'signer_client_name' => $rowFinalClientNm ?: null,
            'signed_ip'          => $_SERVER['REMOTE_ADDR'] ?? null,
        ];
        if ($rowMode === ReportPdf::MODE_FULL) {
            $primaryReportId = $rowId;
        }
        if ($which === 'tech') {
            $fields['signed_at']         = $now;
            $fields['signer_users_id']   = (int) $_SESSION['glpiID'];
            $fields['content_hash_tech']   = $currentHash;
            $fields['content_hash_client'] = $rowFinalClient !== '' ? $rowFinalClientHash : null;
            $savedTechSig = $rowFinalTech;
            $savedTechNm  = $rowFinalTechNm;
        } else {
            $fields['signed_client_at']       = $now;
            $fields['signer_client_users_id'] = (int) $_SESSION['glpiID'];
            // Self-confirming: either the actual requester drew it
            // from their own session (same reasoning as
            // ajax/sign_submit.php's public token path), or a
            // technician drew it in person after the signer's own
            // 6-digit code proved they were actually present
            // ($otpVerification above) — there's no more separate
            // post-hoc acknowledgement step to defer to.
            $fields['client_confirmed_at'] = $now;
            $fields['content_hash_client'] = $currentHash;
            $fields['content_hash_tech']   = $rowFinalTech !== '' ? $rowFinalTechHash : null;
        }
        if ($rowFinalTech === '') {
            $fields['signed_at']       = null;
            $fields['signer_users_id'] = 0;
        }
        if ($rowFinalClient === '') {
            $fields['signed_client_at']     = null;
            $fields['client_confirmed_at']  = null;
            $fields['signer_client_users_id'] = 0;
        }

        ReportStorage::save($ticket, $bytes, Report::STATE_SIGNED, $rowId, $fields);
    }

    if ($discardedStaleSignature) {
        Session::addMessageAfterRedirect(
            __('The ticket content changed since the other party signed — their signature was cleared and needs to be collected again.', 'glpiticketreportsign'),
            false,
            WARNING
        );
    }

    // Remember this signature under the acting user so their next
    // report pre-loads it instead of forcing a redraw. Only the
    // technician side is reusable this way — the client is a
    // different person on every ticket.
    if ($which === 'tech' && $savedTechSig) {
        SavedSignature::save((int) $_SESSION['glpiID'], $savedTechSig, (string) $savedTechNm);
    }

    // The technician's signature is what makes the report ready for
    // the client — automatically send them a 72h link to view (and,
    // if it's their turn, sign) it. Failure here is logged but never
    // blocks the technician's own save.
    if ($which === 'tech') {
        foreach (Recipients::requesterContacts($ticketId) as $contact) {
            try {
                ReportMailer::sendReportLink(
                    $primaryReportId,
                    $contact['email'],
                    $ticketId,
                    ReportMailer::TEMPLATE_TECH_SIGNED,
                    $otpFlowApplies,
                    $contact['name']
                );
            } catch (\Throwable $e) {
                \Toolbox::logInFile('glpiticketreportsign_error', 'auto-email: ' . $e->getMessage());
            }
        }
    }

    // A technician just captured the CLIENT's signature in person,
    // gated by the signer's own 6-digit code — that code (and every
    // other outstanding link/code for this ticket, both report
    // versions) has now served its purpose and can't be reused for a
    // different signer later.
    if ($which === 'client' && $otpVerification !== null) {
        \GlpiPlugin\Glpiticketreportsign\Security\SigningToken::invalidateAllForTicket($ticketId);
    }

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
