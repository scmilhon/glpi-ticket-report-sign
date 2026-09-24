<?php
namespace GlpiPlugin\Glpiticketreportsign\Hooks;

use GlpiPlugin\Glpiticketreportsign\Diagnosis\Diagnosis;
use ITILFollowup;

/**
 * Fires when an existing follow-up is edited. When the update came
 * through the form that renders the "mark as diagnosis" checkbox
 * (confirmed via Diagnosis::MARK_FIELD_PRESENT — a checkbox submits
 * nothing when unchecked, so its mere absence can't tell "left
 * unchecked" apart from "this edit didn't go through that form at
 * all"), an unchecked box means the technician deliberately unmarked
 * it — but only if THIS follow-up was the current mark; another
 * follow-up's mark is left untouched. Edits that don't carry the
 * presence marker (e.g. a quick private/public toggle) never touch
 * the diagnosis mark.
 *
 * Registered via $PLUGIN_HOOKS['item_update']['glpiticketreportsign']
 * in setup.php, narrowed to the ITILFollowup itemtype.
 */
final class OnFollowupUpdated
{
    public static function handle(ITILFollowup $followup): void
    {
        if ((string) ($followup->fields['itemtype'] ?? '') !== 'Ticket') {
            return;
        }
        if (empty($followup->input[Diagnosis::MARK_FIELD_PRESENT])) {
            return; // this edit didn't go through the form with the checkbox
        }
        $ticketId = (int) ($followup->fields['items_id'] ?? 0);
        if ($ticketId <= 0) {
            return;
        }
        $followupId = (int) $followup->getID();

        if (!empty($followup->input[Diagnosis::MARK_FIELD])) {
            Diagnosis::markCurrent($ticketId, $followupId, (int) ($_SESSION['glpiID'] ?? 0));
            return;
        }

        if (Diagnosis::currentMarkedFollowupId($ticketId) === $followupId) {
            Diagnosis::clearCurrent($ticketId);
        }
    }
}
