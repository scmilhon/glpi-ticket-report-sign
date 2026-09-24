<?php
namespace GlpiPlugin\Glpiticketreportsign\Install;

use DBmysql;
use GlpiPlugin\Glpiticketreportsign\Config;
use GlpiPlugin\Glpiticketreportsign\Diagnosis\Diagnosis;
use GlpiPlugin\Glpiticketreportsign\Profile;
use GlpiPlugin\Glpiticketreportsign\Signature\SavedSignature;
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
        $this->createDiagnosisTable();
        $this->createSignaturesTable();
        $this->upgradeSchema();
        $this->migration->executeMigration();
        $this->reconcileTicketAttachments();
        $this->seedConfig();
        $this->seedNotificationTemplates();
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
            // 0.0.9: email logo/footer, editable separately from the
            // PDF's (Setup > Ticket report styles > Email branding).
            $this->migration->addField(Config::TABLE, 'email_logo_path', 'string', [
                'after' => 'draft_ttl_days',
            ]);
            $this->migration->addField(Config::TABLE, 'email_footer_text', 'text', [
                'after' => 'email_logo_path',
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
            `email_logo_path`          VARCHAR(255) NULL,
            `email_footer_text`        TEXT         NULL,
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
     * Seeds the 4 notification templates behind
     * ReportMailer::sendReportLink() (Setup > Notifications >
     * Notification templates) so their subject/body are admin-
     * editable instead of hardcoded — see
     * GlpiPlugin\Glpiticketreportsign\Mail\ReportMailer and
     * TemplateRenderer. Only the wording is templated; the recipient
     * and send timing stay exactly as the calling code already
     * decides them. Idempotent: skips a name that already exists, so
     * an admin's edits survive every reinstall/upgrade.
     */
    private function seedNotificationTemplates(): void
    {
        if (!$this->db->tableExists('glpi_notificationtemplates')) {
            return;
        }

        $wording = [
            \GlpiPlugin\Glpiticketreportsign\Mail\ReportMailer::TEMPLATE_TECH_SIGNED => [
                'es' => [
                    'title'  => 'Su informe de ticket está listo para firmar',
                    'intro'  => 'El técnico ha firmado el informe de su ticket. Por favor revise y firme su parte.',
                    'button' => 'Abrir el informe',
                    // Only shown when this email is the anti-forgery
                    // channel for an in-person signature (report born
                    // from a ticket resolution, not MTTO) — see
                    // ReportMailer::sendReportLink()'s $includeOtp and
                    // front/sign.form.php's token gate.
                    'otp'    => 'O si el técnico está con usted, indíquele este código para firmar en el sitio:',
                ],
                'en' => [
                    'title'  => 'Your ticket report is ready to sign',
                    'intro'  => 'The technician has signed your ticket report. Please review and sign your part.',
                    'button' => 'Open the report',
                    'otp'    => 'Or if the technician is with you, give them this code to sign in person:',
                ],
            ],
            \GlpiPlugin\Glpiticketreportsign\Mail\ReportMailer::TEMPLATE_CHOOSE_VERSION => [
                'es' => ['title' => 'Aquí está su informe', 'intro' => 'Esta es la versión del informe de su ticket que solicitó.', 'button' => 'Abrir el informe'],
                'en' => ['title' => 'Here is your report', 'intro' => 'Here is the version of your ticket report you asked for.', 'button' => 'Open the report'],
            ],
            \GlpiPlugin\Glpiticketreportsign\Mail\ReportMailer::TEMPLATE_CLOSED_UNSIGNED => [
                'es' => ['title' => 'Su ticket fue cerrado', 'intro' => 'Su ticket fue cerrado sin firma de su parte. Aquí tiene una copia del informe para sus registros.', 'button' => 'Ver informe'],
                'en' => ['title' => 'Your ticket was closed', 'intro' => 'Your ticket was closed without a signature on your end. Here is a copy of the report for your records.', 'button' => 'View report'],
            ],
        ];

        // Default (language='') is Spanish — this deployment's actual
        // operating language for client-facing mail; en_GB is kept as
        // an explicit, fully worked override rather than the other
        // way around, matching how this instance is really used.
        $subjectEs   = 'Informe del ticket ###reportsign.ticket_id##';
        $subjectEn   = 'Ticket ###reportsign.ticket_id## report';
        $expiryEs    = 'Este enlace expira en 72 horas.';
        $expiryEn    = 'This link expires in 72 hours.';
        $qrCaptionEs = 'Escanea para acceder al caso';
        $qrCaptionEn = 'Scan to access the case';

        foreach ($wording as $name => $langs) {
            // A plain "does it exist?" check here has, in practice,
            // still let this run twice back-to-back on GLPI 10 (its
            // console install command can re-trigger the plugin's
            // install hook once for a pending version update and
            // once for the explicit --force run) — so also sweep up
            // any accidental duplicate this same loop already
            // created, keeping only the oldest row. Never touches the
            // one it keeps, so admin edits still survive.
            $existingIds = array_column(iterator_to_array($this->db->request([
                'SELECT' => 'id',
                'FROM'   => 'glpi_notificationtemplates',
                'WHERE'  => ['itemtype' => 'Ticket', 'name' => $name],
                'ORDER'  => 'id ASC',
            ])), 'id');
            if ($existingIds !== []) {
                foreach (array_slice($existingIds, 1) as $extraId) {
                    $this->db->delete('glpi_notificationtemplatetranslations', ['notificationtemplates_id' => (int) $extraId]);
                    $this->db->delete('glpi_notificationtemplates', ['id' => (int) $extraId]);
                }
                continue;
            }

            $now = date('Y-m-d H:i:s');
            $this->insertRow('glpi_notificationtemplates', [
                'name'          => $name,
                'itemtype'      => 'Ticket',
                'comment'       => 'Ticket Report & Sign — tags: ##reportsign.ticket_id##, ##reportsign.link##.',
                'css'           => \GlpiPlugin\Glpiticketreportsign\Mail\EmailBranding::CSS,
                'date_creation' => $now,
                'date_mod'      => $now,
            ]);
            $templateId = $this->db->insertId();

            $es = $langs['es'];
            $otpEs = (string) ($es['otp'] ?? '');
            $this->insertRow('glpi_notificationtemplatetranslations', [
                'notificationtemplates_id' => $templateId,
                'language'                 => '',
                'subject'                  => $subjectEs,
                'content_text'             => \GlpiPlugin\Glpiticketreportsign\Mail\EmailBranding::text($es['title'], $es['intro'], $es['button'], $expiryEs, $otpEs),
                'content_html'             => \GlpiPlugin\Glpiticketreportsign\Mail\EmailBranding::body('Ticket', $es['title'], $es['intro'], $es['button'], $expiryEs, $qrCaptionEs, $otpEs),
            ]);

            $en = $langs['en'];
            $otpEn = (string) ($en['otp'] ?? '');
            $this->insertRow('glpi_notificationtemplatetranslations', [
                'notificationtemplates_id' => $templateId,
                'language'                 => 'en_GB',
                'subject'                  => $subjectEn,
                'content_text'             => \GlpiPlugin\Glpiticketreportsign\Mail\EmailBranding::text($en['title'], $en['intro'], $en['button'], $expiryEn, $otpEn),
                'content_html'             => \GlpiPlugin\Glpiticketreportsign\Mail\EmailBranding::body('Ticket', $en['title'], $en['intro'], $en['button'], $expiryEn, $qrCaptionEn, $otpEn),
            ]);
        }
    }

    /**
     * GLPI 10's DBmysql::quoteValue() only escapes a string if it
     * looks like a namespaced class name (its Sanitizer::
     * isNsClassOrCallableIdentifier() check) — everything else is
     * inserted raw, because GLPI 10 expects request input to already
     * be HTML-entity-sanitized by the time it reaches the DB layer.
     * GLPI 11/12's quoteValue() always escapes via $DB->escape(), so
     * a literal apostrophe (e.g. in "Segoe UI") breaks the query only
     * on GLPI 10. Same GLPI10-vs-11/12 detection already used
     * elsewhere in this plugin for the Kernel bootstrap difference.
     */
    private function insertRow(string $table, array $row): void
    {
        if (!class_exists(\Glpi\Kernel\Kernel::class)) {
            foreach ($row as $key => $value) {
                if (is_string($value)) {
                    $row[$key] = $this->db->escape($value);
                }
            }
        }
        $this->db->insert($table, $row);
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
        // 0.0.9: "full" (every follow-up/solution) vs "condensed"
        // (problem + diagnosis + matching solution only) — set at
        // generation time, preserved across re-renders on signing.
        $m->addField($t, 'mode', "string", ['after' => 'state', 'value' => \GlpiPlugin\Glpiticketreportsign\Pdf\ReportPdf::MODE_FULL]);
        // 0.0.9: set once the client acknowledges (via their own
        // emailed link) that a signature a technician collected on
        // their session really is theirs — see front/sign.php and
        // ajax/confirm_signature.php. Null until confirmed; never set
        // at all for a signature the client drew themselves.
        $m->addField($t, 'client_confirmed_at', "datetime", ['after' => 'signed_client_at']);

        $signlinks = 'glpi_plugin_glpiticketreportsign_signlinks';
        if ($this->db->tableExists($signlinks)) {
            // In-person anti-forgery token: a 6-digit code minted
            // alongside the long link (see SigningToken::mint()),
            // spoken/typed by the signer to the technician instead of
            // them following their own emailed link.
            $m->addField($signlinks, 'otp_code', "string", ['after' => 'recipient', 'value' => null]);
            // Display name for the recipient — pre-fills the "Name"
            // field in front/sign.form.php once the matching code is
            // verified (see SigningToken::mint()/verifyOtpForTicket()).
            $m->addField($signlinks, 'signer_name', "string", ['after' => 'otp_code', 'value' => null]);
        }
    }

    public function uninstall(): void
    {
        foreach ([
            'glpi_plugin_glpiticketreportsign_reports',
            'glpi_plugin_glpiticketreportsign_signlinks',
            Config::TABLE,
            Diagnosis::TABLE,
            SavedSignature::TABLE,
        ] as $table) {
            if ($this->db->tableExists($table)) {
                $this->db->doQuery('DROP TABLE `' . $table . '`');
            }
        }
        $this->dropNotificationTemplates();
        Profile::dropRights();
    }

    private function dropNotificationTemplates(): void
    {
        if (!$this->db->tableExists('glpi_notificationtemplates')) {
            return;
        }
        $names = [
            \GlpiPlugin\Glpiticketreportsign\Mail\ReportMailer::TEMPLATE_TECH_SIGNED,
            \GlpiPlugin\Glpiticketreportsign\Mail\ReportMailer::TEMPLATE_CHOOSE_VERSION,
            \GlpiPlugin\Glpiticketreportsign\Mail\ReportMailer::TEMPLATE_CLOSED_UNSIGNED,
            // Retired: the post-hoc "please confirm this signature"
            // flow was replaced by the in-person OTP gate (see
            // SigningToken::verifyOtpForTicket()). Kept as a literal
            // string (not a ReportMailer constant) purely so upgrading
            // installs still get this orphaned template cleaned up.
            'Informe de ticket: confirmar firma',
        ];
        foreach ($names as $name) {
            $tpl = new \NotificationTemplate();
            if ($tpl->getFromDBByCrit(['itemtype' => 'Ticket', 'name' => $name])) {
                $this->db->delete('glpi_notificationtemplatetranslations', ['notificationtemplates_id' => (int) $tpl->getID()]);
                $tpl->delete(['id' => $tpl->getID()], true);
            }
        }
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

    private function createDiagnosisTable(): void
    {
        if ($this->db->tableExists(Diagnosis::TABLE)) {
            return;
        }
        $sql = "CREATE TABLE `" . Diagnosis::TABLE . "` (
            `id`                INT UNSIGNED   NOT NULL AUTO_INCREMENT,
            `tickets_id`        INT UNSIGNED   NOT NULL,
            `itilsolutions_id`  INT UNSIGNED   NOT NULL DEFAULT 0,
            `itilfollowups_id`  INT UNSIGNED   NOT NULL,
            `users_id`          INT UNSIGNED   NOT NULL DEFAULT 0,
            `date_creation`     TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `tickets_id`       (`tickets_id`),
            KEY `itilsolutions_id` (`itilsolutions_id`),
            KEY `itilfollowups_id` (`itilfollowups_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $this->db->doQuery($sql);
    }

    private function createSignaturesTable(): void
    {
        if ($this->db->tableExists(SavedSignature::TABLE)) {
            return;
        }
        $sql = "CREATE TABLE `" . SavedSignature::TABLE . "` (
            `users_id`       INT UNSIGNED   NOT NULL,
            `signature_png`  LONGTEXT       NOT NULL,
            `signer_name`    VARCHAR(255)   NULL,
            `date_mod`       TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`users_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
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
