<?php
/**
 * AJAX: receive the CLIENT signature PNG via the public/token-signed
 * page (front/sign.php). The technician's signature was already
 * captured and persisted on the report row; we merge in the client
 * signature, regenerate the PDF and mark the report fully signed.
 *
 * Authenticated POSTs (same browser session) also pass through here
 * — they're treated identically as a "client signature submission",
 * which keeps the public flow simple.
 *
 * POST: reports_id, signature (data:image/png;base64,...),
 *       signer_name, [token]
 */

use GlpiPlugin\Glpiticketreportsign\Pdf\ReportPdf;
use GlpiPlugin\Glpiticketreportsign\Pdf\ReportStorage;
use GlpiPlugin\Glpiticketreportsign\Report;
use GlpiPlugin\Glpiticketreportsign\Security\Authorizer;
use GlpiPlugin\Glpiticketreportsign\Security\SigningToken;

include('../../../inc/includes.php');

header('Content-Type: application/json; charset=utf-8');

$reportId  = (int) ($_POST['reports_id'] ?? 0);
$signature = (string) ($_POST['signature'] ?? '');
$signerNm  = trim((string) ($_POST['signer_name'] ?? ''));
$token     = (string) ($_POST['token'] ?? '');

if ($reportId <= 0 || $signature === '') {
    http_response_code(400);
    echo json_encode(['error' => 'missing parameters']);
    exit;
}

$report = new Report();
if (!$report->getFromDB($reportId)) {
    http_response_code(404);
    echo json_encode(['error' => 'report not found']);
    exit;
}

if ($token !== '') {
    $verified = SigningToken::verify($token);
    if ($verified === null || $verified['reports_id'] !== $reportId) {
        http_response_code(403);
        echo json_encode(['error' => 'invalid or expired token']);
        exit;
    }
    // Token IS the anti-CSRF / anti-replay credential (HMAC, single-use, TTL).
} else {
    Session::checkLoginUser();
    if (!empty($_POST['_glpi_csrf_token']) && method_exists(Session::class, 'validateCSRF')) {
        try { Session::validateCSRF($_POST); } catch (\Throwable $e) { /* soft-fail */ }
    }
    // This endpoint always signs the CLIENT side (see docblock) — the
    // authenticated fallback must therefore require the actual
    // requester, not just anyone who canActOnTicket() (which is also
    // true for the assigned technician). Using the broader check here
    // would let a technician forge the client's signature themselves.
    if (!Authorizer::isTicketRequester((int) $report->fields['tickets_id'])) {
        http_response_code(403);
        echo json_encode(['error' => 'forbidden']);
        exit;
    }
}
if (empty($_POST['_glpiticketreportsign_client_confirms'])) {
    http_response_code(400);
    echo json_encode(['error' => 'client confirmation checkbox missing']);
    exit;
}

$ticket = new Ticket();
if (!$ticket->getFromDB((int) $report->fields['tickets_id'])) {
    http_response_code(404);
    echo json_encode(['error' => 'ticket not found']);
    exit;
}

// This endpoint is the "client signs" path. Keep each row's own
// existing tech signature (if any), set/replace the client signature
// on every row of the version group (full + condensed, when both
// exist) so signing once signs both attachments.
$version  = (int) $report->fields['version'];
$siblings = Report::rowsForVersion((int) $report->fields['tickets_id'], $version);
if ($siblings === []) {
    $siblings = [$report->fields];
}

// The client is about to attest to content "as signed by the
// technician" — if the ticket's substantive content changed since
// the technician actually signed, that precondition no longer holds.
// A legacy row with no stored hash (signed before this check
// existed) is trusted as-is. See ReportPdf::contentFingerprint().
foreach ($siblings as $row) {
    $rowTechSig  = (string) ($row['signature_tech']    ?? '');
    $rowTechHash = (string) ($row['content_hash_tech'] ?? '');
    if ($rowTechSig === '' || $rowTechHash === '') {
        continue;
    }
    $rowMode = (string) ($row['mode'] ?? ReportPdf::MODE_FULL);
    if ($rowTechHash !== ReportPdf::contentFingerprint($ticket, $rowMode)) {
        http_response_code(409);
        echo json_encode(['error' => 'ticket content changed since the technician signed; ask them to sign again']);
        exit;
    }
}

try {
    $now = date('Y-m-d H:i:s');
    foreach ($siblings as $row) {
        $rowExistingTech     = (string) ($row['signature_tech']     ?? '');
        $rowExistingTechNm   = (string) ($row['signer_name']        ?? '');
        $rowExistingTechHash = (string) ($row['content_hash_tech']  ?? '');
        $rowMode             = (string) ($row['mode'] ?? ReportPdf::MODE_FULL);

        $bytes = (new ReportPdf(
            $ticket,
            $rowExistingTech ?: null,
            $rowExistingTechNm ?: null,
            $signature,
            $signerNm ?: null,
            mode: $rowMode,
        ))->render();

        ReportStorage::save(
            $ticket,
            $bytes,
            Report::STATE_SIGNED,
            (int) $row['id'],
            [
                'signature_tech'        => $rowExistingTech ?: null,
                'signature_client'      => $signature,
                'signer_name'           => $rowExistingTechNm ?: null,
                'signer_client_name'    => $signerNm ?: null,
                'signed_client_at'      => $now,
                // Drawn by the client themselves through their own
                // channel (token link or their own GLPI session) —
                // self-confirming, unlike a signature a technician
                // captures on their own session (see
                // front/sign.submit.php / front/sign.php).
                'client_confirmed_at'   => $now,
                'signed_ip'             => $_SERVER['REMOTE_ADDR'] ?? null,
                'content_hash_tech'     => $rowExistingTech !== '' ? $rowExistingTechHash : null,
                'content_hash_client'   => ReportPdf::contentFingerprint($ticket, $rowMode),
            ]
        );
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

// If there's only one version (no full/condensed choice to offer),
// there's nothing left for the token to authorize — consume it now.
// Otherwise leave it valid so ajax/choose_report_email.php can use it
// for the "which version would you like emailed?" step; that
// endpoint consumes it once the choice is made.
if ($token !== '' && count($siblings) <= 1) {
    SigningToken::consume($token);
}

$siblingsOut = [];
foreach ($siblings as $row) {
    $siblingsOut[] = ['id' => (int) $row['id'], 'mode' => (string) ($row['mode'] ?? ReportPdf::MODE_FULL)];
}

echo json_encode([
    'ok'         => true,
    'pdf_url'    => plugin_glpiticketreportsign_web_dir() . '/front/download.php?id=' . $reportId,
    'siblings'   => $siblingsOut,
    'token'      => $token !== '' && count($siblings) > 1 ? $token : null,
]);
