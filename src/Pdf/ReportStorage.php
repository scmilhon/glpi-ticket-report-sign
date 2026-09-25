<?php
namespace GlpiPlugin\Glpiticketreportsign\Pdf;

use Document;
use Document_Item;
use GlpiPlugin\Glpiticketreportsign\Report;
use Ticket;

/**
 * Saves PDF bytes as a GLPI Document, optionally links it to the
 * ticket via Document_Item, and writes/updates the corresponding
 * glpi_plugin_glpiticketreportsign_reports row.
 *
 * Linking policy:
 *   - A draft report (no technician signature yet) is NOT linked
 *     to the ticket. The Document row exists (so the plugin's tab
 *     can preview / download it) but does not appear in the ticket
 *     timeline or its Documents tab.
 *   - As soon as the technician signature is captured, the
 *     current report's Document is linked to the ticket.
 *   - One PDF link per VERSION of the report is kept. If the same
 *     report version is re-saved (e.g. the client signs after the
 *     technician), only the previous Document for THAT version is
 *     unlinked — links for other versions remain intact. This
 *     gives the ticket timeline an entry per version, each showing
 *     the latest signed state of that version.
 *   - A client-only signature (no tech yet) still does not attach.
 *
 * If $existingReportId is given, the existing report row is
 * updated in place (no new version cut), which is what we want
 * when capturing a signature on an existing draft / partially-
 * signed report.
 */
class ReportStorage
{
    /**
     * @param array<string,mixed> $signatureFields Optional persisted
     *   signature columns: signature_tech, signature_client,
     *   signer_name (tech), signer_client_name, signer_users_id (tech),
     *   signer_client_users_id, signed_at, signed_client_at, signed_ip.
     *   Anything not provided is left untouched (on update) or set to
     *   null (on insert).
     * @param ?string $mode ReportPdf::MODE_FULL / MODE_CONDENSED. Null
     *   leaves the column untouched on an update (re-rendering for a
     *   signature preserves whatever mode the report was generated
     *   with) and defaults to MODE_FULL on a fresh insert.
     * @param ?int $version Explicit version number for a fresh insert
     *   — used when generating the full+condensed pair together so
     *   both share the same version instead of each computing its
     *   own "next" and drifting apart. Ignored on an update (the
     *   existing row's version never changes). Null keeps the normal
     *   auto-increment behaviour.
     */
    public static function save(
        Ticket $ticket,
        string $pdfBytes,
        string $state,
        ?int $existingReportId = null,
        array $signatureFields = [],
        ?string $mode = null,
        ?int $version = null,
    ): int {
        $ticketId = $ticket->getID();
        $tmp      = tempnam(sys_get_temp_dir(), 'tr_pdf_');
        file_put_contents($tmp, $pdfBytes);

        $version = $existingReportId !== null
            ? self::reportVersion($existingReportId)
            : ($version ?? Report::nextVersion($ticketId));

        $filename = self::buildFilename($ticket);

        // Document::add() with the upload-form arrays only works
        // inside a real $_FILES upload — we have raw bytes, so we
        // write the file into GLPI's document tree by hand and
        // insert the Document row directly.
        $docId = self::storeDocumentManually($ticket, $tmp, $filename);
        @unlink($tmp);

        if ($docId <= 0) {
            throw new \RuntimeException('Failed to persist report PDF as a Document.');
        }

        // Decide whether this version should be attached to the
        // ticket and, regardless, drop any older glpiticketreportsign
        // Document_Item links for this ticket so the user never
        // sees more than one report PDF in the timeline / Documents
        // tab.
        $shouldAttach = !empty($signatureFields['signature_tech']);
        self::syncTicketAttachment($ticket, $docId, $shouldAttach, $existingReportId);

        $report = new Report();
        if ($existingReportId !== null) {
            $update = array_merge([
                'id'           => $existingReportId,
                'documents_id' => $docId,
                'state'        => $state,
                'date_mod'     => date('Y-m-d H:i:s'),
            ], $mode !== null ? ['mode' => $mode] : [], $signatureFields);
            $report->update($update);
            return $existingReportId;
        }

        $insert = array_merge([
            'tickets_id'       => $ticketId,
            'documents_id'     => $docId,
            'version'          => $version,
            'state'            => $state,
            'mode'             => $mode ?? \GlpiPlugin\Glpiticketreportsign\Pdf\ReportPdf::MODE_FULL,
            'created_users_id' => (int) ($_SESSION['glpiID'] ?? 0),
            'date_creation'    => date('Y-m-d H:i:s'),
            'date_mod'         => date('Y-m-d H:i:s'),
        ], $signatureFields);
        return (int) $report->add($insert);
    }

    private static function reportVersion(int $reportId): int
    {
        $r = new Report();
        return $r->getFromDB($reportId) ? (int) $r->fields['version'] : 1;
    }

    /**
     * "23690 Resuelto - Solución Aplicada - nes.pdf"
     *
     * Built from: ticket id, the translated status label, the latest
     * ITILSolution's solution-type name (skipped when the ticket
     * has no solution yet or the technician didn't pick a type),
     * and the "nes" tag the customer asked for. Sanitised against
     * the characters that commonly break filenames on Windows / IIS.
     */
    private static function buildFilename(Ticket $ticket): string
    {
        global $DB;

        $ticketId    = (int) $ticket->getID();
        $statusName  = self::statusLabel((int) ($ticket->fields['status'] ?? 0));
        $solutionType = '';

        $row = $DB->request([
            'SELECT' => 't.name',
            'FROM'   => 'glpi_itilsolutions AS s',
            'LEFT JOIN' => [
                'glpi_solutiontypes AS t' => ['ON' => ['s' => 'solutiontypes_id', 't' => 'id']],
            ],
            'WHERE'  => [
                's.itemtype' => 'Ticket',
                's.items_id' => $ticketId,
            ],
            'ORDER'  => 's.date_creation DESC',
            'LIMIT'  => 1,
        ])->current();
        if (is_array($row)) {
            $solutionType = (string) ($row['name'] ?? '');
        }

        $parts = [(string) $ticketId];
        if ($statusName !== '')   $parts[] = $statusName;
        if ($solutionType !== '') $parts[] = $solutionType;
        $parts[] = 'nes';

        $name = implode(' - ', $parts);
        $name = self::sanitizeFilename($name);
        return $name . '.pdf';
    }

    /**
     * Translated status word used in the downloaded filename — same
     * __() domain as ReportPdf's own statusLabel(), so the filename
     * matches the active locale the same way the PDF body does. Kept
     * as its own (shorter, filename-safe) set of labels rather than
     * reusing ReportPdf::statusLabel(), since "Processing (assigned)"
     * would need extra sanitising for the parentheses.
     */
    private static function statusLabel(int $status): string
    {
        return match ($status) {
            \Ticket::INCOMING => __('New', 'glpiticketreportsign'),
            \Ticket::ASSIGNED => __('In progress', 'glpiticketreportsign'),
            \Ticket::PLANNED  => __('Planned', 'glpiticketreportsign'),
            \Ticket::WAITING  => __('Pending', 'glpiticketreportsign'),
            \Ticket::SOLVED   => __('Solved', 'glpiticketreportsign'),
            \Ticket::CLOSED   => __('Closed', 'glpiticketreportsign'),
            default           => '',
        };
    }

    private static function sanitizeFilename(string $name): string
    {
        // Strip characters that Windows / Linux / IIS reject in
        // filenames, collapse repeating spaces, and trim.
        $name = preg_replace('#[\\\\/:*?"<>|]+#', '-', $name) ?? $name;
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;
        return trim($name, " -");
    }

    /**
     * Keep the attachment list in sync for THIS report version only.
     * Other versions' links are left untouched, so each version
     * remains visible in the ticket timeline / Documents tab.
     *
     *  $shouldAttach = true  → newDocId becomes this version's link.
     *  $shouldAttach = false → this version has no link.
     *
     * If $currentReportId is set, the previous Document referenced
     * by that report row is unlinked (so re-signing the same version
     * replaces rather than accumulates). When no existing report id
     * is provided, this call is for a brand new draft generation
     * (which we don't attach anyway).
     */
    private static function syncTicketAttachment(
        Ticket $ticket,
        int $newDocId,
        bool $shouldAttach,
        ?int $currentReportId,
    ): void {
        global $DB;
        $ticketId = (int) $ticket->getID();

        // Unlink the previous Document for THIS version, if any.
        // We deliberately don't touch links belonging to other
        // report versions — the customer wants each version's
        // signed PDF to remain visible in the ticket.
        $previousDocId = 0;
        if ($currentReportId !== null) {
            $row = $DB->request([
                'SELECT' => 'documents_id',
                'FROM'   => 'glpi_plugin_glpiticketreportsign_reports',
                'WHERE'  => ['id' => $currentReportId],
                'LIMIT'  => 1,
            ])->current();
            if (is_array($row)) {
                $previousDocId = (int) $row['documents_id'];
            }
        }
        // Also drop any link for the newly-inserted Document (a
        // defensive cleanup — should not exist yet, but in case a
        // partial earlier run left one behind).
        $toUnlink = array_filter(array_unique([$previousDocId, $newDocId]));
        if ($toUnlink !== []) {
            $DB->delete('glpi_documents_items', [
                'documents_id' => array_values($toUnlink),
                'itemtype'     => Ticket::class,
                'items_id'     => $ticketId,
            ]);
        }

        // The superseded Document itself (row + file on disk) — not
        // just its link — is now unreferenced by anything: every save
        // (draft, re-sign, …) mints a brand new Document, so nothing
        // else in GLPI points at $previousDocId once this row stops
        // doing so. Left alone, every signature save/resend leaves
        // one orphaned PDF behind permanently, readable by anyone
        // with the core `document` READ right in the entity for as
        // long as the file exists on disk. Document::delete() (not a
        // raw unlink) so its own dedup-safe file removal runs.
        if ($previousDocId > 0 && $previousDocId !== $newDocId) {
            self::purgeIfUnreferenced($previousDocId);
        }

        if ($shouldAttach) {
            $DB->insert('glpi_documents_items', [
                'documents_id'      => $newDocId,
                'itemtype'          => Ticket::class,
                'items_id'          => $ticketId,
                'entities_id'       => (int) $ticket->fields['entities_id'],
                'is_recursive'      => 0,
                'date_creation'     => date('Y-m-d H:i:s'),
                'date_mod'          => date('Y-m-d H:i:s'),
                'users_id'          => (int) ($_SESSION['glpiID'] ?? 0),
                // Pin the attachment to the technician side of the
                // ticket timeline. Document_Item.timeline_position
                // uses CommonITILObject constants:
                //   1 = LEFT (requester), 4 = RIGHT (technician).
                // Default (0 / NOTSET) renders on the left.
                'timeline_position' => \CommonITILObject::TIMELINE_RIGHT,
            ]);
        }
    }

    /**
     * Deletes $docId (row + file) via GLPI's own Document::delete(),
     * but only once nothing in this plugin's own reports table still
     * points at it. Document ids this plugin creates are never
     * shared across rows (storeDocumentManually() mints a fresh one
     * on every save), so this should always find zero references —
     * checked anyway rather than assumed, since deleting the wrong
     * document would take a live report's PDF down with it.
     */
    private static function purgeIfUnreferenced(int $docId): void
    {
        global $DB;
        $stillUsed = $DB->request([
            'COUNT'  => 'cpt',
            'FROM'   => 'glpi_plugin_glpiticketreportsign_reports',
            'WHERE'  => ['documents_id' => $docId],
        ])->current();
        if (is_array($stillUsed) && (int) ($stillUsed['cpt'] ?? 0) > 0) {
            return;
        }
        $doc = new Document();
        if ($doc->getFromDB($docId)) {
            $doc->delete(['id' => $docId], true);
        }
    }

    /**
     * Write the PDF bytes into GLPI's document directory and create
     * a Document row pointing at it. Uses the SHA1-bucketed layout
     * core GLPI uses for managed uploads, so the file fits standard
     * download routes.
     *
     * Inserts directly via $DB->insert() rather than Document::add().
     * Document::add() runs prepareInputForAdd, which expects the
     * upload-form metadata ($_FILES, _filename arrays, _tag_filename,
     * etc.) and silently fails or produces a row with broken fields
     * when called outside an HTTP upload — which is what we hit on
     * GLPI 11 (Document row got created but couldn't be re-fetched
     * by getFromDB on download). The direct insert is not pretty
     * but it is fully under our control.
     */
    private static function storeDocumentManually(Ticket $ticket, string $sourcePath, string $filename): int
    {
        global $DB;

        $base = defined('GLPI_DOC_DIR') ? GLPI_DOC_DIR : null;
        if ($base === null) {
            throw new \RuntimeException('GLPI_DOC_DIR is not defined.');
        }
        $sha1 = sha1_file($sourcePath);
        if (!is_string($sha1)) {
            throw new \RuntimeException('Could not hash report PDF.');
        }

        $sub  = substr($sha1, 0, 2);
        $dir  = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . '_uploads' . DIRECTORY_SEPARATOR . $sub;
        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create document storage directory: ' . $dir);
        }
        $relPath = '_uploads/' . $sub . '/' . $sha1 . '.PDF';
        $abs     = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
        if (!is_file($abs) && !@copy($sourcePath, $abs)) {
            throw new \RuntimeException('Could not copy report PDF to: ' . $abs);
        }

        $now = date('Y-m-d H:i:s');
        $ok  = $DB->insert('glpi_documents', [
            'entities_id'      => (int) $ticket->fields['entities_id'],
            'is_recursive'     => 0,
            'name'             => $filename,
            'filename'         => $filename,
            'filepath'         => $relPath,
            'mime'             => 'application/pdf',
            'sha1sum'          => $sha1,
            'is_deleted'       => 0,
            'users_id'         => (int) ($_SESSION['glpiID'] ?? 0),
            'date_creation'    => $now,
            'date_mod'         => $now,
        ]);
        if ($ok === false) {
            throw new \RuntimeException('Failed to insert glpi_documents row: ' . $DB->error());
        }
        return (int) $DB->insertId();
    }
}
