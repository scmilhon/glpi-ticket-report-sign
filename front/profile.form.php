<?php
/**
 * Save the per-profile right toggles posted from the Ticket reports
 * tab on Setup > Profiles. Plain form POST, redirects back to the
 * Profile form via Html::back so the admin stays in context.
 *
 * Requires `profile` UPDATE — same gate GLPI uses for any profile
 * configuration screen.
 *
 * Form shape:
 *   _POST['rights'] = [
 *     'plugin_glpiticketreportsign_report' => ['r' => '1', 'c' => '1', 'u' => '1'],
 *     'plugin_glpiticketreportsign_config' => ['r' => '1', 'u' => '1'],
 *   ]
 * Missing entries default to 0.
 */

use GlpiPlugin\Glpiticketreportsign\Config;
use GlpiPlugin\Glpiticketreportsign\Profile;

ob_start();
include('../../../inc/includes.php');
ob_end_clean();

/** @var array $CFG_GLPI */
global $CFG_GLPI;

Session::checkLoginUser();
Session::checkRight('profile', UPDATE);

if (!empty($_POST['_glpi_csrf_token']) && method_exists(Session::class, 'validateCSRF')) {
    try { Session::validateCSRF($_POST); } catch (\Throwable $e) { /* soft-fail */ }
}

$profileId = (int) ($_POST['profiles_id'] ?? 0);
if ($profileId <= 0) {
    Session::addMessageAfterRedirect(__('Invalid profile.', 'glpiticketreportsign'), false, ERROR);
    Html::back();
}

$posted = $_POST['rights'] ?? [];
if (!is_array($posted)) {
    $posted = [];
}

// Whitelist the right names so a crafty POST can't write arbitrary
// rights into other plugin scopes.
$knownRights = [Profile::RIGHTNAME, Config::RIGHTNAME];
global $DB;

foreach ($knownRights as $right) {
    $bits  = $posted[$right] ?? [];
    $value = 0;
    if (!empty($bits['r'])) $value |= READ;
    if (!empty($bits['c'])) $value |= CREATE;
    if (!empty($bits['u'])) $value |= UPDATE;

    $exists = $DB->request([
        'COUNT' => 'cpt',
        'FROM'  => 'glpi_profilerights',
        'WHERE' => ['profiles_id' => $profileId, 'name' => $right],
    ])->current();

    if (is_array($exists) && (int) $exists['cpt'] > 0) {
        $DB->update(
            'glpi_profilerights',
            ['rights' => $value],
            ['profiles_id' => $profileId, 'name' => $right]
        );
    } else {
        $DB->insert(
            'glpi_profilerights',
            ['profiles_id' => $profileId, 'name' => $right, 'rights' => $value]
        );
    }
}

Session::addMessageAfterRedirect(__('Permissions updated.', 'glpiticketreportsign'), false, INFO);
Html::back();
