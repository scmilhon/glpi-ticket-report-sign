<?php
/**
 * AJAX: tells public/js/pending-signature-redirect.js whether the
 * current (logged-in) user has a client signature pending on a
 * ticket — i.e. they are a requester on it and at least one report
 * has the technician's signature but not theirs yet. Used to
 * auto-open the Report tab on page load, without disturbing any
 * other tab-selection behaviour when nothing is pending.
 *
 * GET: tickets_id
 */

use GlpiPlugin\Glpiticketreportsign\Report;
use GlpiPlugin\Glpiticketreportsign\Security\Authorizer;

include('../../../inc/includes.php');

header('Content-Type: application/json; charset=utf-8');

Session::checkLoginUser();

$ticketId = (int) ($_GET['tickets_id'] ?? 0);
if ($ticketId <= 0) {
    echo json_encode(['pending' => false]);
    exit;
}

if (!Authorizer::isTicketRequester($ticketId)) {
    echo json_encode(['pending' => false]);
    exit;
}

$pending = false;
foreach (Report::listForTicket($ticketId) as $r) {
    if (!empty($r['signature_tech']) && empty($r['signature_client'])) {
        $pending = true;
        break;
    }
}

echo json_encode([
    'pending'  => $pending,
    'forcetab' => 'GlpiPlugin\\Glpiticketreportsign\\Integration\\TicketTab$1',
]);
