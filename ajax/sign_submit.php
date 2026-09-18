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
    if (!Authorizer::canActOnTicket((int) $report->fields['tickets_id'])) {
        http_response_code(403);
        echo json_encode(['error' => 'forbidden']);
        exit;
    }
}

$ticket = new Ticket();
if (!$ticket->getFromDB((int) $report->fields['tickets_id'])) {
    http_response_code(404);
    echo json_encode(['error' => 'ticket not found']);
    exit;
}

$existingTech   = (string) ($report->fields['signature_tech']   ?? '');
$existingClient = (string) ($report->fields['signature_client'] ?? '');
$existingTechNm = (string) ($report->fields['signer_name']        ?? '');

try {
    // This endpoint is the "client signs" path. Keep existing tech
    // signature (if any), set/replace client signature.
    $bytes = (new ReportPdf(
        $ticket,
        $existingTech ?: null,
        $existingTechNm ?: null,
        $signature,
        $signerNm ?: null,
    ))->render();

    ReportStorage::save(
        $ticket,
        $bytes,
        Report::STATE_SIGNED,
        $reportId,
        [
            'signature_tech'        => $existingTech ?: null,
            'signature_client'      => $signature,
            'signer_name'           => $existingTechNm ?: null,
            'signer_client_name'    => $signerNm ?: null,
            'signed_client_at'      => date('Y-m-d H:i:s'),
            'signed_ip'             => $_SERVER['REMOTE_ADDR'] ?? null,
        ]
    );
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

if ($token !== '') {
    SigningToken::consume($token);
}

echo json_encode([
    'ok'      => true,
    'pdf_url' => plugin_glpiticketreportsign_web_dir() . '/front/download.php?id=' . $reportId,
]);
