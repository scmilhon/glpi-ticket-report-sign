<?php
/**
 * Streams the PDF bytes for the signing page (token mode) or the
 * authenticated tab. Used by pdf.js to render the preview canvas.
 *
 * GET: id (reports_id), [t (signing token)]
 */

use GlpiPlugin\Glpiticketreportsign\Profile;
use GlpiPlugin\Glpiticketreportsign\Report;
use GlpiPlugin\Glpiticketreportsign\Security\Authorizer;
use GlpiPlugin\Glpiticketreportsign\Security\SigningToken;

include('../../../inc/includes.php');

$reportId = (int) ($_GET['id'] ?? 0);
$token    = (string) ($_GET['t'] ?? '');

if ($reportId <= 0) {
    http_response_code(400);
    exit('id missing');
}

$report = new Report();
if (!$report->getFromDB($reportId)) {
    http_response_code(404);
    exit('not found');
}

if ($token !== '') {
    $verified = SigningToken::verify($token);
    if ($verified === null) {
        http_response_code(403);
        exit('invalid token');
    }
    if ($verified['reports_id'] !== $reportId) {
        // A token authorizes its own report AND its full/condensed
        // sibling (same ticket+version) — the public sign page lets
        // the recipient preview both, not just whichever mode the
        // link happened to be minted for.
        $tokenReport = new Report();
        if (
            !$tokenReport->getFromDB($verified['reports_id'])
            || (int) $tokenReport->fields['tickets_id'] !== (int) $report->fields['tickets_id']
            || (int) $tokenReport->fields['version'] !== (int) $report->fields['version']
        ) {
            http_response_code(403);
            exit('invalid token');
        }
    }
} else {
    Session::checkLoginUser();
    if (!Authorizer::canActOnTicket((int) $report->fields['tickets_id'])) {
        http_response_code(403);
        exit('forbidden');
    }
}

$doc = new Document();
if (!$doc->getFromDB((int) $report->fields['documents_id'])) {
    http_response_code(404);
    exit('document missing');
}

$base = defined('GLPI_DOC_DIR') ? GLPI_DOC_DIR : null;
if ($base === null) {
    http_response_code(500);
    exit('storage not configured');
}
$abs = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . ltrim((string) $doc->fields['filepath'], '/\\');
if (!is_file($abs)) {
    http_response_code(404);
    exit('file missing');
}

header('Content-Type: application/pdf');
header('Content-Length: ' . filesize($abs));
header('Cache-Control: private, no-store');
readfile($abs);
