<?php
namespace GlpiPlugin\Glpiticketreportsign;

use CommonGLPI;
use Profile as GlpiProfile;
use ProfileRight;

/**
 * Adds a "Ticket reports" tab to the Profile form so admins can
 * grant or revoke this plugin's right per profile, no SQL required.
 *
 * The single right `plugin_glpiticketreportsign_report` covers
 *   READ   — view existing reports on a ticket
 *   CREATE — generate a new report version
 *   UPDATE — capture a signature on an existing report
 *
 * Implementation notes:
 *
 * 1. We extend GlpiProfile (the GLPI core class). GLPI 11's tab
 *    discovery for Profile registers the plugin's tab provider via
 *    Plugin::registerClass(addtabon=>'Profile') only when the
 *    registered class is itself a Profile subclass. Extending
 *    CommonGLPI silently makes the tab disappear from the Profile
 *    form — that's the bug the user hit.
 *
 * 2. We deliberately do NOT use Profile::displayRightsChoiceMatrix().
 *    That helper queries the rights-providing class's own database
 *    table (via maybeDeleted → getEmpty → listFields) — and since
 *    this plugin doesn't have its own table, the helper crashes with
 *    "Table glpi_plugin_glpiticketreportsign_profiles doesn't exist". A
 *    plain HTML form with three checkboxes avoids the issue.
 */
class Profile extends GlpiProfile
{
    public const RIGHTNAME = 'plugin_glpiticketreportsign_report';

    /**
     * $rightname is deliberately NOT redeclared here: the parent
     * Profile class already defaults it to 'profile' in both GLPI 11
     * (untyped property) and GLPI 12 (typed `string` property), and a
     * child override must match the parent's typing exactly. Since
     * this plugin folder is shared between both GLPI versions,
     * redeclaring it (typed or not) breaks whichever version doesn't
     * match. Inheriting the parent's value achieves the same result
     * — visible to anyone who can edit GLPI profiles; the actual
     * grant/revoke is done in front/profile.form.php using GLPI's
     * standard `update` right on the Profile itemtype.
     */

    public static function getTypeName($nb = 0): string
    {
        return __('Ticket reports', 'glpiticketreportsign');
    }

    /**
     * Tabler icon shown next to the tab name on Setup > Profiles
     * > Ticket reports — keeps it visually consistent with the
     * Report tab on each ticket.
     */
    public static function getIcon(): string
    {
        return 'ti ti-signature';
    }

    /**
     * Right check that survives the "freshly installed plugin" case.
     *
     * Session::haveRight() only sees rights that were loaded at login
     * time. When the plugin is installed mid-session — or the right
     * is granted via the Profile tab while a tech is logged in — the
     * session has no record of the new right and haveRight() returns
     * false even after the DB row exists. We fall back to a direct
     * lookup so the user doesn't have to log out and back in.
     */
    public static function hasRight(int $bit): bool
    {
        $profileId = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
        if ($profileId <= 0) {
            return false;
        }

        // Session-level cache when GLPI loaded it at login.
        if (isset($_SESSION['glpiactiveprofile']['_rights'][self::RIGHTNAME])) {
            return ((int) $_SESSION['glpiactiveprofile']['_rights'][self::RIGHTNAME] & $bit) !== 0;
        }

        global $DB;
        $row = $DB->request([
            'SELECT' => 'rights',
            'FROM'   => 'glpi_profilerights',
            'WHERE'  => ['profiles_id' => $profileId, 'name' => self::RIGHTNAME],
            'LIMIT'  => 1,
        ])->current();

        return is_array($row) && ((int) $row['rights'] & $bit) !== 0;
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): array|string
    {
        if ($item instanceof GlpiProfile) {
            return self::createTabEntry(self::getTypeName());
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if (!$item instanceof GlpiProfile) {
            return false;
        }
        self::renderRightsForm($item);
        return true;
    }

    private static function renderRightsForm(GlpiProfile $profile): void
    {
        $profileId = (int) $profile->getID();
        $canEdit   = \Session::haveRight('profile', UPDATE);

        $url       = plugin_glpiticketreportsign_web_dir() . '/front/profile.form.php';
        // Removed in GLPI 12 (CSRF moved to Sec-Fetch-Site/Origin header
        // validation) — guard so the tab still renders there.
        $csrf      = method_exists(\Session::class, 'getNewCSRFToken')
            ? \Session::getNewCSRFToken()
            : '';

        // One row per right registered by this plugin. Adding more
        // rights later is a matter of extending this array.
        $rights = [
            self::RIGHTNAME        => __('Plugin access', 'glpiticketreportsign'),
            \GlpiPlugin\Glpiticketreportsign\Config::RIGHTNAME => __('Edit document styles', 'glpiticketreportsign'),
        ];

        echo '<form method="post" action="' . htmlspecialchars($url) . '" class="p-3">';
        echo '<input type="hidden" name="profiles_id" value="' . $profileId . '">';
        echo '<input type="hidden" name="_glpi_csrf_token" value="' . htmlspecialchars($csrf, ENT_QUOTES) . '">';

        echo '<table class="table table-sm" style="max-width:700px">';
        echo '<thead><tr><th>' . self::getTypeName() . '</th>'
           . '<th class="text-center">' . __('Read') . '</th>'
           . '<th class="text-center">' . __('Create') . '</th>'
           . '<th class="text-center">' . __('Update') . '</th>'
           . '</tr></thead><tbody>';

        foreach ($rights as $rightName => $label) {
            $current = self::currentRights($profileId, $rightName);
            echo '<tr>';
            echo '<td>' . $label . '</td>';
            foreach ([
                'r' => READ,
                'c' => CREATE,
                'u' => UPDATE,
            ] as $name => $bit) {
                $checked  = ($current & $bit) ? 'checked' : '';
                $disabled = $canEdit ? '' : 'disabled';
                // Right name encoded into the input name so the
                // save handler knows which row to update.
                echo '<td class="text-center">'
                   . '<input type="checkbox" class="form-check-input" '
                   . 'name="rights[' . htmlspecialchars($rightName, ENT_QUOTES) . '][' . $name . ']" '
                   . 'value="1" ' . $checked . ' ' . $disabled . '>'
                   . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table>';

        if ($canEdit) {
            echo '<div class="mt-2">';
            echo '<button type="submit" class="btn btn-primary">' . __('Save') . '</button>';
            echo '</div>';
        } else {
            echo '<div class="text-muted small">' . __('You do not have permission to edit profile rights.', 'glpiticketreportsign') . '</div>';
        }

        echo '</form>';
    }

    private static function currentRights(int $profileId, string $rightName): int
    {
        global $DB;
        $row = $DB->request([
            'SELECT' => 'rights',
            'FROM'   => 'glpi_profilerights',
            'WHERE'  => ['profiles_id' => $profileId, 'name' => $rightName],
            'LIMIT'  => 1,
        ])->current();
        return is_array($row) ? (int) $row['rights'] : 0;
    }

    /**
     * Add a row in glpi_profilerights for every existing profile
     * that doesn't already have one for our right. Idempotent — safe
     * across upgrades. Grants full rights (READ|CREATE|UPDATE) to
     * the super-admin profile (id=4) by default.
     */
    public static function initRights(): void
    {
        global $DB;

        // Both plugin rights — kept here as the single source of truth.
        $allRights = [
            self::RIGHTNAME                                 => READ | CREATE | UPDATE,
            \GlpiPlugin\Glpiticketreportsign\Config::RIGHTNAME      => READ | UPDATE,
        ];

        foreach ($allRights as $right => $superAdminValue) {
            $existing = [];
            foreach ($DB->request([
                'SELECT' => 'profiles_id',
                'FROM'   => 'glpi_profilerights',
                'WHERE'  => ['name' => $right],
            ]) as $row) {
                $existing[(int) $row['profiles_id']] = true;
            }

            foreach ($DB->request(['SELECT' => 'id', 'FROM' => 'glpi_profiles']) as $p) {
                $pid = (int) $p['id'];
                if (isset($existing[$pid])) {
                    continue;
                }
                $DB->insert('glpi_profilerights', [
                    'profiles_id' => $pid,
                    'name'        => $right,
                    'rights'      => 0,
                ]);
            }

            // Grant to super-admin profile (id=4) by default.
            $DB->update(
                'glpi_profilerights',
                ['rights' => $superAdminValue],
                ['profiles_id' => 4, 'name' => $right]
            );
        }
    }

    public static function dropRights(): void
    {
        global $DB;
        $DB->delete('glpi_profilerights', [
            'name' => [self::RIGHTNAME, \GlpiPlugin\Glpiticketreportsign\Config::RIGHTNAME],
        ]);
    }

    public static function createFirstAccess(int $profileId): void
    {
        if ($profileId <= 0) {
            return;
        }
        global $DB;

        $grants = [
            self::RIGHTNAME                                 => READ | CREATE | UPDATE,
            \GlpiPlugin\Glpiticketreportsign\Config::RIGHTNAME      => READ | UPDATE,
        ];
        foreach ($grants as $right => $value) {
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
    }
}
