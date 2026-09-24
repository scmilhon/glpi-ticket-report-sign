<?php
namespace GlpiPlugin\Glpiticketreportsign\Diagnosis;

/**
 * Tracks which follow-up is "the diagnosis" for a ticket.
 *
 * One row per ticket at any given time (upserted, not a history
 * table): a technician checks a box on a follow-up to mark it as the
 * diagnosis (Diagnosis::markCurrent()) — checking a different one
 * replaces the mark (single-selection, like a radio button). While
 * the ticket is still unresolved, that row has itilsolutions_id = 0
 * ("pending"). The moment a solution is filed, OnSolutionAdded calls
 * attachPendingToSolution() to snapshot the mark against that
 * specific solution, so a later reopen + re-mark starts a fresh
 * pending row without losing the historical pairing (latestForTicket()
 * only ever looks at the confirmed/attached one).
 *
 * The table is intentionally separate from glpi_itilsolutions /
 * glpi_itilfollowups: the link is plugin-owned data, not a native
 * GLPI column.
 */
class Diagnosis
{
    public const TABLE = 'glpi_plugin_glpiticketreportsign_diagnosis';

    /**
     * Checkbox field name rendered on each follow-up's own form
     * (post_item_form hook on ITILFollowup). Read by OnFollowupAdded.
     */
    public const MARK_FIELD = '_glpiticketreportsign_is_diagnosis';

    /**
     * Hidden input rendered alongside MARK_FIELD, always submitted
     * (unlike a checkbox, which sends nothing when unchecked). Lets
     * OnFollowupUpdated tell "the checkbox was on this form and left
     * unchecked" apart from "this update didn't go through a form
     * that renders the checkbox at all" — an edit made through some
     * other path must never be treated as an implicit uncheck.
     */
    public const MARK_FIELD_PRESENT = '_glpiticketreportsign_diagnosis_field_present';

    /**
     * Checkbox field name rendered on the solution form
     * (post_item_form hook on ITILSolution) — an explicit override
     * for "this ticket doesn't need a diagnosis". Read by
     * OnSolutionPreAdd.
     */
    public const NO_DIAGNOSIS_FIELD = '_glpiticketreportsign_no_diagnosis_applies';

    /** True when the ticket has at least one follow-up to pick from. */
    public static function hasAnyFollowup(int $ticketId): bool
    {
        global $DB;
        $row = $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => 'glpi_itilfollowups',
            'WHERE' => ['itemtype' => 'Ticket', 'items_id' => $ticketId],
        ])->current();
        return is_array($row) && (int) $row['cpt'] > 0;
    }

    /**
     * Marks a follow-up as the ticket's current diagnosis, replacing
     * any previous still-pending mark for the same ticket. A mark
     * already attached to a past solution is left alone — a ticket
     * reopened and re-marked gets a fresh pending row.
     */
    public static function markCurrent(int $ticketId, int $followupId, int $userId): void
    {
        global $DB;
        $pending = self::pendingRow($ticketId);
        if ($pending !== null) {
            $DB->update(self::TABLE, [
                'itilfollowups_id' => $followupId,
                'users_id'         => $userId,
            ], ['id' => (int) $pending['id']]);
            return;
        }
        $DB->insert(self::TABLE, [
            'tickets_id'       => $ticketId,
            'itilsolutions_id' => 0,
            'itilfollowups_id' => $followupId,
            'users_id'         => $userId,
            'date_creation'    => date('Y-m-d H:i:s'),
        ]);
    }

    /** Removes the ticket's current pending mark (unchecking it). */
    public static function clearCurrent(int $ticketId): void
    {
        global $DB;
        $DB->delete(self::TABLE, ['tickets_id' => $ticketId, 'itilsolutions_id' => 0]);
    }

    /**
     * The follow-up currently marked as the diagnosis for a ticket —
     * whether still pending or already attached to a past solution.
     * Used to pre-check the box on existing follow-ups and to
     * validate the mandatory-diagnosis rule at resolve time.
     */
    public static function currentMarkedFollowupId(int $ticketId): ?int
    {
        global $DB;
        if (!$DB->tableExists(self::TABLE)) {
            return null;
        }
        $row = $DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => ['tickets_id' => $ticketId],
            'ORDER' => 'date_creation DESC',
            'LIMIT' => 1,
        ])->current();
        return is_array($row) ? (int) $row['itilfollowups_id'] : null;
    }

    /**
     * Snapshots the ticket's pending mark (if any) against the
     * solution that just resolved it. No-op if nothing was marked
     * (technician used the "no diagnosis applies" override).
     */
    public static function attachPendingToSolution(int $ticketId, int $solutionId): void
    {
        global $DB;
        $pending = self::pendingRow($ticketId);
        if ($pending === null) {
            return;
        }
        $DB->update(self::TABLE, ['itilsolutions_id' => $solutionId], ['id' => (int) $pending['id']]);
    }

    /**
     * The diagnosis confirmed for a ticket's most recent resolution
     * (i.e. already attached to a solution) — used to build the
     * "condensed" report. Deliberately excludes a still-pending mark:
     * that belongs to a resolution that hasn't happened (again) yet.
     *
     * @return array{itilsolutions_id:int,itilfollowups_id:int}|null
     */
    public static function latestForTicket(int $ticketId): ?array
    {
        global $DB;
        if (!$DB->tableExists(self::TABLE)) {
            return null;
        }
        $row = $DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => ['tickets_id' => $ticketId, ['itilsolutions_id' => ['>', 0]]],
            'ORDER' => 'date_creation DESC',
            'LIMIT' => 1,
        ])->current();
        if (!is_array($row)) {
            return null;
        }
        return [
            'itilsolutions_id' => (int) $row['itilsolutions_id'],
            'itilfollowups_id' => (int) $row['itilfollowups_id'],
        ];
    }

    /** @return array<string,mixed>|null */
    private static function pendingRow(int $ticketId): ?array
    {
        global $DB;
        $row = $DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => ['tickets_id' => $ticketId, 'itilsolutions_id' => 0],
            'ORDER' => 'date_creation DESC',
            'LIMIT' => 1,
        ])->current();
        return is_array($row) ? $row : null;
    }
}
