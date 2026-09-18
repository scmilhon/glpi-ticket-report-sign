<?php
namespace GlpiPlugin\Glpiticketreportsign\Cron;

use CommonDBTM;
use CronTask;
use GlpiPlugin\Glpiticketreportsign\Config;
use GlpiPlugin\Glpiticketreportsign\Report;

/**
 * Cron task that purges abandoned draft reports.
 *
 * A "draft" is a row in glpi_plugin_glpiticketreportsign_reports whose
 * `signature_tech` AND `signature_client` columns are both empty
 * (i.e. the user generated a PDF but never captured any signature).
 * After 15 days these are deleted along with the underlying GLPI
 * Document row and the PDF file on disk — they're cluttering up
 * storage and the version list with no audit value.
 *
 * Signed (partial or full) reports are never touched.
 *
 * Registered in hook.php as `CronTask::register(...)` on install,
 * so admins can adjust the schedule via Setup > Automatic actions.
 */
class CleanupDrafts extends CommonDBTM
{
    public const TASK_NAME = 'CleanupDrafts';

    // Fallback used only when the config row hasn't been written yet
    // (e.g. mid-upgrade before seedConfig runs).
    public const DEFAULT_DRAFT_TTL_DAYS = 15;

    public static function getTypeName($nb = 0): string
    {
        return __('Ticket Report & Sign: clean abandoned drafts', 'glpiticketreportsign');
    }

    /**
     * @return array<string,string>
     */
    public static function cronInfo(string $name): array
    {
        return [
            'description' => __(
                'Remove draft reports (no signatures) older than 15 days, including their PDF on disk.',
                'glpiticketreportsign'
            ),
        ];
    }

    /**
     * GLPI cron entry point. Returns 1 on partial work, 0 on no-op,
     * -1 on error — that's the CronTask convention.
     */
    public static function cronCleanupDrafts(CronTask $task): int
    {
        global $DB;

        $ttl    = (int) (Config::get()['draft_ttl_days'] ?? self::DEFAULT_DRAFT_TTL_DAYS);
        $ttl    = $ttl > 0 ? $ttl : self::DEFAULT_DRAFT_TTL_DAYS;
        $cutoff = date('Y-m-d H:i:s', strtotime('-' . $ttl . ' days'));
        $deleted = 0;

        // Select all rows older than the cut-off and filter the
        // "no signatures" condition in PHP — DBmysqlIterator's OR
        // syntax is finicky for null-or-empty checks on the same
        // column, and the result set here is small anyway.
        $rows = $DB->request([
            'FROM'  => Report::getTable(),
            'WHERE' => ['date_creation' => ['<', $cutoff]],
        ]);

        foreach ($rows as $r) {
            if (!empty($r['signature_tech']) || !empty($r['signature_client'])) {
                continue;
            }
            try {
                self::purgeReport((int) $r['id'], (int) $r['documents_id']);
                $deleted++;
            } catch (\Throwable $e) {
                \Toolbox::logInFile(
                    'glpiticketreportsign_error',
                    'cleanup-draft #' . $r['id'] . ': ' . $e->getMessage()
                );
            }
        }

        $task->addVolume($deleted);
        if ($deleted > 0) {
            $task->log(sprintf('Deleted %d abandoned draft report(s).', $deleted));
            return 1;
        }
        return 0;
    }

    /**
     * Hard-delete a single report along with its Document row, the
     * file on disk and any leftover Document_Item link (drafts
     * shouldn't have one but we defensively clean anyway).
     */
    private static function purgeReport(int $reportId, int $documentId): void
    {
        global $DB;

        if ($documentId > 0) {
            // Find the on-disk path before deleting the Document row.
            $doc = $DB->request([
                'SELECT' => 'filepath',
                'FROM'   => 'glpi_documents',
                'WHERE'  => ['id' => $documentId],
                'LIMIT'  => 1,
            ])->current();

            if (is_array($doc) && !empty($doc['filepath'])
                && defined('GLPI_DOC_DIR')) {
                $abs = rtrim(GLPI_DOC_DIR, '/\\') . DIRECTORY_SEPARATOR
                     . str_replace('/', DIRECTORY_SEPARATOR, (string) $doc['filepath']);
                if (is_file($abs)) {
                    @unlink($abs);
                }
            }

            $DB->delete('glpi_documents_items', ['documents_id' => $documentId]);
            $DB->delete('glpi_documents',       ['id' => $documentId]);
        }

        $DB->delete(Report::getTable(), ['id' => $reportId]);
    }
}
