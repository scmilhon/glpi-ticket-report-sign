<?php
namespace GlpiPlugin\Glpiticketreportsign\Hooks;

use GlpiPlugin\Glpiticketreportsign\Diagnosis\Diagnosis;
use ITILFollowup;

/**
 * Fires when a follow-up is added. If the "mark as diagnosis"
 * checkbox (see OnFollowupFormRender) was ticked, records this
 * follow-up as the ticket's current diagnosis — replacing whatever
 * was previously marked (single-selection per ticket).
 *
 * Registered via $PLUGIN_HOOKS['item_add']['glpiticketreportsign'] in
 * setup.php, narrowed to the ITILFollowup itemtype.
 */
final class OnFollowupAdded
{
    public static function handle(ITILFollowup $followup): void
    {
        if ((string) ($followup->fields['itemtype'] ?? '') !== 'Ticket') {
            return;
        }
        if (empty($followup->input[Diagnosis::MARK_FIELD])) {
            return;
        }
        $ticketId = (int) ($followup->fields['items_id'] ?? 0);
        if ($ticketId <= 0) {
            return;
        }
        Diagnosis::markCurrent($ticketId, (int) $followup->getID(), (int) ($_SESSION['glpiID'] ?? 0));
    }
}
