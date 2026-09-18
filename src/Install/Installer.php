<?php
namespace GlpiPlugin\Glpiticketreportsign\Install;

use DBmysql;
use GlpiPlugin\Glpiticketreportsign\Config;
use GlpiPlugin\Glpiticketreportsign\Profile;
use Migration;

/**
 * Schema:
 *   glpi_plugin_glpiticketreportsign_reports — one row per generated report
 *     (a ticket may have several versions: regenerated when the
 *     follow-up list changes, or after a signature is collected).
 *
 *   glpi_plugin_glpiticketreportsign_signlinks — short-lived HMAC-signed
 *     tokens emailed to a signer; one row per outstanding link.
 *     Used as a server-side allow-list so a token can be revoked
 *     or marked single-use.
 */
class Installer
{
    private DBmysql $db;
    private Migration $migration;

    public function __construct()
    {
        global $DB;
        $this->db        = $DB;
        $this->migration = new Migration(\PLUGIN_GLPITICKETREPORTSIGN_VERSION);
    }

    public function install(): void
    {
        $this->migrateLegacyPluginKey();
        $this->createReportsTable();
        $this->createSignLinksTable();
        $this->createConfigTable();
        $this->upgradeSchema();
        $this->migration->executeMigration();
        $this->reconcileTicketAttachments();
        $this->seedConfig();
        $this->compileLocales();

        Profile::initRights();
    }

    /**
     * One-time migration for installs running this plugin under its
     * old key ("ticketreport", before the rename to
     * "glpiticketreportsign"). RENAME TABLE is an in-place, near-
     * instant metadata operation (no row copy), so every existing
     * report, signature and signing token survives untouched under
     * its new table name. Profile rights are re-pointed the same way
     * so nobody has to re-grant access after upgrading. No-ops once
     * the new names are already in place (fresh installs never see
     * the old tables/rights at all).
     */
    private function migrateLegacyPluginKey(): void
    {
        $tableRenames = [
            'glpi_plugin_ticketreport_reports'   => 'glpi_plugin_glpiticketreportsign_reports',
            'glpi_plugin_ticketreport_signlinks' => 'glpi_plugin_glpiticketreportsign_signlinks',
            'glpi_plugin_ticketreport_config'    => Config::TABLE,
        ];
        foreach ($tableRenames as $old => $new) {
            if ($this->db->tableExists($old) && !$this->db->tableExists($new)) {
                $this->db->doQuery('RENAME TABLE `' . $old . '` TO `' . $new . '`');
            }
        }

        $rightRenames = [
            'plugin_ticketreport_report' => Profile::RIGHTNAME,
            'plugin_ticketreport_config' => Config::RIGHTNAME,
        ];
        foreach ($rightRenames as $old => $new) {
            $this->db->update('glpi_profilerights', ['name' => $new], ['name' => $old]);
        }

        // Drop the orphaned CronTask row registered under the old
        // namespace — hook.php's install() re-registers it fresh
        // under the new itemtype right after this runs. Any custom
        // frequency the admin set is not preserved (a fresh row gets
        // the default daily schedule again); everything else
        // (reports, signatures, tokens, granted rights) is.
        $this->db->delete('glpi_crontasks', [
            'itemtype' => 'GlpiPlugin\\TicketReport\\Cron\\CleanupDrafts',
        ]);
    }

    /**
     * GLPI 11 reads compiled .mo files, not raw .po sources. We
     * compile in-process so the plugin works after a plain "copy
     * the folder and click Upgrade" — no msgfmt on the host needed.
     */
    private function compileLocales(): void
    {
        \GlpiPlugin\Glpiticketreportsign\Install\PoToMo::compileAll(
            __DIR__ . '/../../locales'
        );
    }

    private function createConfigTable(): void
    {
        if ($this->db->tableExists(Config::TABLE)) {
            // Idempotent column add for installs that ran an
            // earlier schema (1.5.x had logo_filename without the
            // path concept).
            $this->migration->addField(Config::TABLE, 'logo_path', 'string', [
                'after' => 'company_website',
                'value' => 'pics/logos/logo-GLPI-100-grey.png',
            ]);
            return;
        }
        $sql = "CREATE TABLE `" . Config::TABLE . "` (
            `id`                       INT UNSIGNED NOT NULL DEFAULT 1,
            `company_name`             VARCHAR(255) NULL,
            `company_nit`              VARCHAR(64)  NULL,
            `company_address`          VARCHAR(255) NULL,
            `company_website`          VARCHAR(255) NULL,
            `logo_path`                VARCHAR(255) NULL,
            `disclaimer_title`         VARCHAR(255) NULL,
            `disclaimer_body`          LONGTEXT     NULL,
            `footer_text`              VARCHAR(255) NULL,
            `section_header_bg`        VARCHAR(7)   NULL,
            `ticket_id_color`          VARCHAR(7)   NULL,
            `draft_ttl_days`           INT UNSIGNED NOT NULL DEFAULT 15,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $this->db->doQuery($sql);
    }

    /**
     * Populates the singleton config row with defaults on first install,
     * idempotent on subsequent upgrades.
     */
    private function seedConfig(): void
    {
        if (!$this->db->tableExists(Config::TABLE) || Config::exists()) {
            return;
        }
        $row        = Config::defaults();
        $row['id']  = 1;
        $this->db->insert(Config::TABLE, $row);
    }

    /**
     * One-time cleanup applied on every install/upgrade so existing
     * reports converge on the current attachment policy:
     *
     *   - One Document_Item per VERSION of the report is kept on the
     *     ticket — but only when that version has a technician
     *     signature on file. Unsigned drafts are unlinked.
     *   - When the same version has multiple historical Document
     *     rows (a previous bug attached the PDF on every save),
     *     only the most recently signed one keeps its link.
     *
     * Idempotent.
     */
    private function reconcileTicketAttachments(): void
    {
        if (!$this->db->tableExists('glpi_plugin_glpiticketreportsign_reports')) {
            return;
        }

        $ticketIds = [];
        foreach ($this->db->request([
            'SELECT' => 'tickets_id',
            'FROM'   => 'glpi_plugin_glpiticketreportsign_reports',
        ]) as $row) {
            $ticketIds[(int) $row['tickets_id']] = true;
        }
        $ticketIds = array_keys($ticketIds);

        foreach ($ticketIds as $tid) {
            // Group reports by version, picking the most recent
            // signed row per version (and noting all Document IDs
            // for cleanup).
            $perVersion = []; // version => report row to keep (or null)
            $allDocIds  = [];
            foreach ($this->db->request([
                'FROM'  => 'glpi_plugin_glpiticketreportsign_reports',
                'WHERE' => ['tickets_id' => $tid],
                'ORDER' => 'date_mod ASC',
            ]) as $r) {
                $version = (int) $r['version'];
                $docId   = (int) $r['documents_id'];
                if ($docId > 0) {
                    $allDocIds[$docId] = true;
                }
                if (!empty($r['signature_tech'])) {
                    // Later rows replace earlier ones (ORDER ASC).
                    $perVersion[$version] = $r;
                }
            }

            if ($allDocIds === []) {
                continue;
            }

            // Drop every existing plugin-owned Document_Item link
            // for this ticket — we'll re-create exactly the ones
            // we want next.
            $this->db->delete('glpi_documents_items', [
                'documents_id' => array_keys($allDocIds),
                'itemtype'     => 'Ticket',
                'items_id'     => $tid,
            ]);

            if ($perVersion === []) {
                continue;
            }

            $ticket = new \Ticket();
            if (!$ticket->getFromDB($tid)) {
                continue;
            }

            foreach ($perVersion as $row) {
                $this->db->insert('glpi_documents_items', [
                    'documents_id'      => (int) $row['documents_id'],
                    'itemtype'          => 'Ticket',
                    'items_id'          => $tid,
                    'entities_id'       => (int) $ticket->fields['entities_id'],
                    'is_recursive'      => 0,
                    'date_creation'     => date('Y-m-d H:i:s'),
                    'date_mod'          => date('Y-m-d H:i:s'),
                    'users_id'          => 0,
                    'timeline_position' => \CommonITILObject::TIMELINE_RIGHT,
                ]);
            }
        }
    }

    /**
     * Idempotent column additions for installs that ran an older
     * version of the schema. The 1.0 release stored a single
     * signature; this revision splits it into a technician and a
     * client signature, persisted as base64 PNG so the PDF can be
     * regenerated when either party signs.
     */
    private function upgradeSchema(): void
    {
        $m = $this->migration;
        $t = 'glpi_plugin_glpiticketreportsign_reports';
        if (!$this->db->tableExists($t)) {
            return;
        }
        $m->addField($t, 'signature_tech',         "longtext",  ['after' => 'signed_ip']);
        $m->addField($t, 'signature_client',       "longtext",  ['after' => 'signature_tech']);
        $m->addField($t, 'signer_client_name',     "string",    ['after' => 'signature_client']);
        $m->addField($t, 'signer_client_users_id', "int",       ['after' => 'signer_client_name', 'value' => 0]);
        $m->addField($t, 'signed_client_at',       "datetime",  ['after' => 'signer_client_users_id']);
        // 1.9.0: MTTO reports link to a Computer asset.
        $m->addField($t, 'computers_id',           "int",       ['after' => 'tickets_id',         'value' => 0]);
    }

    public function uninstall(): void
    {
        foreach ([
            'glpi_plugin_glpiticketreportsign_reports',
            'glpi_plugin_glpiticketreportsign_signlinks',
            Config::TABLE,
        ] as $table) {
            if ($this->db->tableExists($table)) {
                $this->db->doQuery('DROP TABLE `' . $table . '`');
            }
        }
        Profile::dropRights();
    }

    private function createReportsTable(): void
    {
        if ($this->db->tableExists('glpi_plugin_glpiticketreportsign_reports')) {
            return;
        }
        $charset = 'utf8mb4';
        $coll    = 'utf8mb4_unicode_ci';
        $sql = "CREATE TABLE `glpi_plugin_glpiticketreportsign_reports` (
            `id`              INT UNSIGNED       NOT NULL AUTO_INCREMENT,
            `tickets_id`      INT UNSIGNED       NOT NULL DEFAULT 0,
            `documents_id`    INT UNSIGNED       NOT NULL DEFAULT 0,
            `version`         INT UNSIGNED       NOT NULL DEFAULT 1,
            `state`           VARCHAR(16)        NOT NULL DEFAULT 'draft',
            `signer_name`     VARCHAR(255)       DEFAULT NULL,
            `signer_users_id` INT UNSIGNED       DEFAULT NULL,
            `signed_at`       TIMESTAMP NULL     DEFAULT NULL,
            `signed_ip`       VARCHAR(64)        DEFAULT NULL,
            `created_users_id` INT UNSIGNED      NOT NULL DEFAULT 0,
            `date_creation`   TIMESTAMP NULL     DEFAULT NULL,
            `date_mod`        TIMESTAMP NULL     DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `tickets_id` (`tickets_id`),
            KEY `state`      (`state`),
            KEY `documents_id` (`documents_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$coll}";
        $this->db->doQuery($sql);
    }

    private function createSignLinksTable(): void
    {
        if ($this->db->tableExists('glpi_plugin_glpiticketreportsign_signlinks')) {
            return;
        }
        $sql = "CREATE TABLE `glpi_plugin_glpiticketreportsign_signlinks` (
            `id`            INT UNSIGNED   NOT NULL AUTO_INCREMENT,
            `reports_id`    INT UNSIGNED   NOT NULL,
            `token_hash`    CHAR(64)       NOT NULL,
            `recipient`     VARCHAR(255)   NOT NULL,
            `expires_at`    TIMESTAMP NULL DEFAULT NULL,
            `consumed_at`   TIMESTAMP NULL DEFAULT NULL,
            `created_users_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `token_hash` (`token_hash`),
            KEY `reports_id` (`reports_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $this->db->doQuery($sql);
    }
}
