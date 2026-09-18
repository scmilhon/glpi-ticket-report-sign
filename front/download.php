<?php
/**
 * Force-download a saved report PDF. Authenticated users only — for
 * external token-based access we use ajax/pdf_bytes.php which checks
 * the signing token instead.
 */

use GlpiPlugin\Glpiticketreportsign\Profile;
use GlpiPlugin\Glpiticketreportsign\Report;
use GlpiPlugin\Glpiticketreportsign\Security\Authorizer;

include('../../../inc/includes.php');

/** @var array $CFG_GLPI */
global $CFG_GLPI;

Session::checkLoginUser();
if (!Profile::hasRight(READ)) {
    Html::displayRightError();
}

$reportId = (int) ($_GET['id'] ?? 0);
$report   = new Report();
if ($reportId <= 0 || !$report->getFromDB($reportId)) {
    Html::displayErrorAndDie(__('Report not found', 'glpiticketreportsign'));
}
if (!Authorizer::canActOnTicket((int) $report->fields['tickets_id'])) {
    Html::displayRightError();
}

$doc = new Document();
if (!$doc->getFromDB((int) $report->fields['documents_id'])) {
    Html::displayErrorAndDie(__('Document missing', 'glpiticketreportsign'));
}

$base = GLPI_DOC_DIR;
$abs  = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . ltrim((string) $doc->fields['filepath'], '/\\');
if (!is_file($abs)) {
    Html::displayErrorAndDie(__('File missing on disk', 'glpiticketreportsign'));
}

$filename = (string) ($doc->fields['filename'] ?: 'report.pdf');
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
header('Content-Length: ' . filesize($abs));
header('Cache-Control: private, no-store');
readfile($abs);
