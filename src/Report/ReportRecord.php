<?php
namespace GlpiPlugin\Glpiticketreportsign\Report;

use CommonDBTM;

/**
 * Persistence for one generated report tied to a ticket. The actual
 * PDF bytes live as a normal GLPI Document, referenced via
 * `documents_id`; this row only tracks state, version, signer and
 * audit fields. Storing the PDF as a Document means it appears in
 * the ticket's standard "Documents" tab automatically.
 *
 * This class deliberately does NOT declare $rightname. GLPI 12 typed
 * CommonDBTM's static $rightname as `string` (untyped before), and a
 * child class redeclaring it must match the parent's type exactly —
 * see https://github.com/glpi-project/glpi/issues/25399. The actual
 * `Report` class used by the rest of the plugin is one of the two
 * leaf files next to this one (Report.php for GLPI <12, Report.glpi12.php
 * for GLPI 12+), each adding only the $rightname declaration with the
 * type its running GLPI expects; setup.php's autoloader picks the
 * matching one at runtime via reflection. Everything else lives here
 * so the two leaves never drift apart.
 */
abstract class ReportRecord extends CommonDBTM
{
    public const STATE_DRAFT  = 'draft';
    public const STATE_SIGNED = 'signed';

    public static function getTypeName($nb = 0): string
    {
        return _n('Report', 'Reports', $nb, 'glpiticketreportsign');
    }

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_glpiticketreportsign_reports';
    }

    /** @return array<int,array<string,mixed>> */
    public static function listForTicket(int $ticketId): array
    {
        global $DB;
        $rows = [];
        $iter = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['tickets_id' => $ticketId],
            'ORDER' => 'version DESC',
        ]);
        foreach ($iter as $r) {
            $rows[] = $r;
        }
        return $rows;
    }

    /**
     * All rows sharing one ticket+version — normally one (mode=full)
     * or two (mode=full and mode=condensed, generated together by
     * OnSolutionAdded when the ticket has a diagnosis on file).
     * Signing one report signs the whole group: see front/sign.submit.php
     * and ajax/sign_submit.php.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function rowsForVersion(int $ticketId, int $version): array
    {
        global $DB;
        $rows = [];
        foreach ($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['tickets_id' => $ticketId, 'version' => $version],
            'ORDER' => 'mode ASC', // deterministic: "condensed" before "full"
        ]) as $r) {
            $rows[] = $r;
        }
        return $rows;
    }

    public static function nextVersion(int $ticketId): int
    {
        global $DB;
        $row = $DB->request([
            'SELECT' => ['MAX' => 'version AS v'],
            'FROM'   => self::getTable(),
            'WHERE'  => ['tickets_id' => $ticketId],
        ])->current();
        return ((int) ($row['v'] ?? 0)) + 1;
    }
}
