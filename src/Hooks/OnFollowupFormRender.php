<?php
namespace GlpiPlugin\Glpiticketreportsign\Hooks;

use CommonDBTM;
use GlpiPlugin\Glpiticketreportsign\Diagnosis\Diagnosis;
use ITILFollowup;
use Ticket;

/**
 * Renders the "Mark as diagnosis" checkbox inside GLPI's native
 * "Answer" (follow-up) form, right before its submit button — the
 * same post_item_form hook and ticket-resolution approach as
 * OnSolutionFormRender (see that class for why options.parent can't
 * be trusted and $_REQUEST['tickets_id'] is used as a further
 * fallback).
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

        $ticket = self::resolveTicket($followup, $data['options'] ?? []);
        if ($ticket === null || $ticket->isNewItem()) {
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

    /**
     * Resolves the ticket a follow-up/solution belongs to. GLPI's own
     * inline "new item" timeline form
     * (templates/components/itilobject/answer.html.twig) only ever
     * passes {item, subitem, kb_id_toload} to this template's
     * include() — it never sets a 'params' key at all when the
     * itemtype has a dedicated template (which ITILFollowup and
     * ITILSolution both do) — so options.parent is NEVER actually
     * populated on that path; it's only set by the older showForm()
     * fallback the same template uses for itemtypes without one.
     * Confirmed against a running GLPI 10.0.18 container: it always
     * evaluates to null on this form. $_REQUEST['tickets_id'] only
     * carries the ticket id on the ajax/timeline.php edit path, not
     * on this inline "new item" form — so both of those had been
     * silently failing here, which is why this checkbox never
     * rendered regardless of GLPI version. The reliable source is the
     * follow-up/solution's own itemtype/items_id columns, which GLPI
     * core populates on the object before the template ever renders
     * (CommonITILObject::showTimelineForm() for a brand-new item,
     * getFromDB() for an existing one) — tried first, with
     * options.parent and $_REQUEST kept as defensive fallbacks for
     * any other caller.
     */
    private static function resolveTicket(CommonDBTM $subitem, array $options): ?Ticket
    {
        $ticket = $options['parent'] ?? null;
        if ($ticket instanceof Ticket) {
            return $ticket;
        }

        $ticketId = 0;
        if (($subitem->fields['itemtype'] ?? '') === Ticket::class) {
            $ticketId = (int) ($subitem->fields['items_id'] ?? 0);
        }
        if ($ticketId <= 0) {
            $ticketId = (int) ($_REQUEST['tickets_id'] ?? 0);
        }
        if ($ticketId <= 0) {
            return null;
        }

        $ticket = new Ticket();
        return $ticket->getFromDB($ticketId) ? $ticket : null;
    }
}
