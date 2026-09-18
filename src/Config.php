<?php
namespace GlpiPlugin\Glpiticketreportsign;

/**
 * Single-row configuration store for PDF styling and draft TTL.
 *
 * The table glpi_plugin_glpiticketreportsign_config holds one row (id=1).
 * defaults() below is only ever used to seed that row on a fresh
 * install (Installer::seedConfig()) or as a fallback if the row is
 * somehow missing when read — it is NOT re-applied on every read once
 * a row exists (see get()), so admin-entered values (including an
 * intentionally empty disclaimer) are never silently overwritten by a
 * later change to this file's defaults.
 *
 * Read with Config::get(), update with Config::save(array). Both
 * are static and read/write directly via $DB — no CommonDBTM
 * ceremony.
 */
class Config
{
    public const TABLE      = 'glpi_plugin_glpiticketreportsign_config';
    public const RIGHTNAME  = 'plugin_glpiticketreportsign_config';

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return [
            // Header company info — admin-edited, never pulled from
            // the GLPI database. Empty strings render as blank cells
            // in the PDF header.
            'company_name'        => '',
            'company_nit'         => '',
            'company_address'     => '',
            'company_website'     => '',

            // Path to the logo file, relative to GLPI_ROOT so the
            // plugin can reuse logos already shipped with / uploaded
            // into the GLPI install. Default points at the stock
            // GLPI logo so a fresh install renders something.
            'logo_path'           => 'pics/logos/logo-GLPI-100-grey.png',

            // No shipped wording on purpose — a legal disclaimer is
            // specific to whoever runs this instance. Left empty, the
            // section is simply skipped on the PDF (see
            // ReportPdf::renderDisclaimerBlock()); admins opt in by
            // writing their own text on the config screen, which is
            // saved only here in the database, never in plugin code.
            'disclaimer_title'    => '',
            'disclaimer_body'     => '',
            'footer_text'         => 'Página {page}/{pages}',
            'section_header_bg'   => '#E6E6E6',
            'ticket_id_color'     => '#DC0000',
            'draft_ttl_days'      => 15,
        ];
    }

    /** @return array<string,mixed> */
    public static function get(): array
    {
        global $DB;
        if (!$DB->tableExists(self::TABLE)) {
            return self::defaults();
        }
        $row = $DB->request(['FROM' => self::TABLE, 'LIMIT' => 1])->current();
        if (!is_array($row)) {
            return self::defaults();
        }
        return array_merge(self::defaults(), $row);
    }

    /** @param array<string,mixed> $values */
    public static function save(array $values): void
    {
        global $DB;
        $allowed = array_keys(self::defaults());
        $clean   = [];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $values)) {
                $clean[$k] = $values[$k];
            }
        }

        if (self::exists()) {
            $DB->update(self::TABLE, $clean, ['id' => 1]);
        } else {
            $clean['id'] = 1;
            $DB->insert(self::TABLE, $clean);
        }
    }

    public static function exists(): bool
    {
        global $DB;
        $row = $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => self::TABLE,
            'WHERE' => ['id' => 1],
        ])->current();
        return is_array($row) && (int) $row['cpt'] > 0;
    }

    /**
     * Idempotent check whether the active session has a given bit
     * on plugin_glpiticketreportsign_config. Same pattern as Profile::hasRight,
     * with a direct DB fallback when the session was opened before
     * the right was registered.
     */
    public static function hasRight(int $bit): bool
    {
        $profileId = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
        if ($profileId <= 0) {
            return false;
        }
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
}
