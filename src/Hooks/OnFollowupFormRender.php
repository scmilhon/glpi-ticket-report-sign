<?php
namespace GlpiPlugin\Glpiticketreportsign\Hooks;

use GlpiPlugin\Glpiticketreportsign\Diagnosis\Diagnosis;
use ITILFollowup;
use Ticket;

/**
 * Renders the "Mark as diagnosis" checkbox inside GLPI's native
 * "Answer" (follow-up) form, right before its submit button — the
 * same post_item_form hook and ticket-resolution approach as
 * OnSolutionFormRender (see that class for why options.parent can't
 * be trusted and $_REQUEST['tickets_id'] is used as a fallback).
 *
 * Checking a box on a NEW follow-up marks it as the ticket's current
 * diagnosis (single-selection — see Diagnosis::markCurrent()).
 * Editing an EXISTING follow-up shows the box pre-checked when it is
 * the current mark, so unchecking it clears the mark
 * (OnFollowupUpdated handles that side).
 *
 * Dispatched from the same post_item_form callable as
 * OnSolutionFormRender — see setup.php.
 */
final class OnFollowupFormRender
{
    /** @param array{item?:mixed,options?:array<string,mixed>} $data */
    public static function handle(array $data): void
    {
        $followup = $data['item'] ?? null;
        if (!$followup instanceof ITILFollowup) {
            return;
        }

        $ticket = $data['options']['parent'] ?? null;
        if (!$ticket instanceof Ticket) {
            $ticketId = (int) ($_REQUEST['tickets_id'] ?? 0);
            if ($ticketId <= 0) {
                return;
            }
            $ticket = new Ticket();
            if (!$ticket->getFromDB($ticketId)) {
                return;
            }
        }
        if ($ticket->isNewItem()) {
            return;
        }

        $followupId = (int) ($followup->fields['id'] ?? 0);
        $markedId   = Diagnosis::currentMarkedFollowupId((int) $ticket->getID());
        $checked    = $followupId > 0 && $followupId === $markedId;

        echo '<div class="col-12 mt-2">';
        echo '<input type="hidden" name="' . Diagnosis::MARK_FIELD_PRESENT . '" value="1">';
        echo '<div class="form-check">';
        echo '<input type="checkbox" class="form-check-input" id="trIsDiagnosis" name="' . Diagnosis::MARK_FIELD . '" value="1"'
           . ($checked ? ' checked' : '') . '>';
        echo '<label class="form-check-label" for="trIsDiagnosis">'
           . __('This is the diagnosis for this case', 'glpiticketreportsign') . '</label>';
        echo '</div>';
        echo '</div>';
    }
}
