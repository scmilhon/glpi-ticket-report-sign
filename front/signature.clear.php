<?php
/**
 * Deletes the current user's saved reusable signature (see
 * src/Signature/SavedSignature.php). Redirects back to whatever
 * sign.form.php page the request came from.
 *
 * POST: reports_id (only used to build the back-link), _glpi_csrf_token
 */

use GlpiPlugin\Glpiticketreportsign\Signature\SavedSignature;

ob_start();
include('../../../inc/includes.php');
ob_end_clean();

Session::checkLoginUser();
if (!empty($_POST['_glpi_csrf_token']) && method_exists(Session::class, 'validateCSRF')) {
    try { Session::validateCSRF($_POST); } catch (\Throwable $e) { /* soft-fail */ }
}

SavedSignature::clear((int) $_SESSION['glpiID']);

Session::addMessageAfterRedirect(
    __('Saved signature removed. Draw a new one next time.', 'glpiticketreportsign'),
    false,
    INFO
);

Html::back();
