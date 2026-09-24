<?php
namespace GlpiPlugin\Glpiticketreportsign\Hooks;

use GlpiPlugin\Glpiticketreportsign\Diagnosis\Diagnosis;
use ITILSolution;
use Session;

/**
 * Fires before an ITILSolution is inserted. Blocks the save unless
 * the ticket already has a follow-up checked as "the diagnosis"
 * (Diagnosis::markCurrent(), set from a checkbox on the follow-up's
 * own form — see OnFollowupFormRender/OnFollowupAdded), or the
 * technician explicitly checked "no diagnosis applies" on the
 * solution form (Diagnosis::NO_DIAGNOSIS_FIELD). Enforced here,
 * server-side, so the rule holds regardless of what rendered on the
 * client.
 *
 * Tickets with zero follow-ups are exempt — there is nothing to
 * mark, and blocking every solution on a followup-less ticket would
 * be a workflow change nobody asked for.
 *
 * Registered via $PLUGIN_HOOKS['pre_item_add']['glpiticketreportsign']
 * in setup.php, narrowed to the ITILSolution itemtype. Setting
 * $item->input = false is GLPI's documented way for a plugin hook to
 * cancel an add; CommonDBTM::add() checks for it right after firing
 * this hook.
 */
final class OnSolutionPreAdd
{
    public static function handle(ITILSolution $solution): void
    {
        if ($solution->input === false) {
            return; // already cancelled by an earlier hook
        }
        if ((string) ($solution->input['itemtype'] ?? '') !== 'Ticket') {
            return;
        }
        $ticketId = (int) ($solution->input['items_id'] ?? 0);
        if ($ticketId <= 0) {
            return;
        }
        if (!Diagnosis::hasAnyFollowup($ticketId)) {
            return;
        }
        if (!empty($solution->input[Diagnosis::NO_DIAGNOSIS_FIELD])) {
            return; // explicit "no diagnosis applies" — a deliberate choice, not an omission
        }
        if (Diagnosis::currentMarkedFollowupId($ticketId) !== null) {
            return;
        }

        Session::addMessageAfterRedirect(
            __('Mark which follow-up is the diagnosis (or check "no diagnosis applies") before resolving the ticket.', 'glpiticketreportsign'),
            false,
            ERROR
        );
        $solution->input = false;
    }
}
