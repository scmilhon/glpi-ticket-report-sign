<?php
namespace GlpiPlugin\Glpiticketreportsign\Hooks;

use GlpiPlugin\Glpiticketreportsign\Diagnosis\Diagnosis;
use ITILFollowup;
use ITILSolution;
use Ticket;

/**
 * Renders, inside GLPI's native "Add a solution" form, either:
 *   - a read-only confirmation of the follow-up already checked as
 *     the diagnosis (see OnFollowupFormRender for that checkbox), or
 *   - if none is marked, a "no diagnosis applies" checkbox so the
 *     technician can explicitly opt out instead of getting stuck.
 *
 * Confirmed identical in GLPI 10, 11 and 12 —
 * templates/components/itilobject/timeline/form_solution.html.twig
 * calls the post_item_form hook at exactly that spot in all three
 * (verified against the running containers, not guessed).
 *
 * The hook is skipped by GLPI when the form renders with noform=true
 * (the multi-ticket "massive action" resolve flow), so this widget
 * never appears there — see OnSolutionPreAdd for how that case is
 * handled on the enforcement side.
 *
 * This class only draws the widget. The actual requirement is
 * enforced server-side in OnSolutionPreAdd, independent of whether
 * this markup rendered or was tampered with.
 *
 * Registered via $PLUGIN_HOOKS[Hooks::POST_ITEM_FORM]['glpiticketreportsign']
 * in setup.php as a plain callable (NOT itemtype-keyed) — post_item_form
 * always fires with a plain ['item' => ..., 'options' => ...] array,
 * never a bare object, so GLPI's per-itemtype dispatch branch in
 * Plugin::doHook() never applies to it. The same dispatcher also
 * routes to OnFollowupFormRender for ITILFollowup — see setup.php.
 */
final class OnSolutionFormRender
{
    /** @param array{item?:mixed,options?:array<string,mixed>} $data */
    public static function handle(array $data): void
    {
        if (!($data['item'] ?? null) instanceof ITILSolution) {
            return;
        }

        // showForm() (massive actions, our own CLI checks) passes the
        // parent ticket via options.parent. GLPI's real timeline
        // lazy-loads this form through ajax/timeline.php, which
        // renders the Twig template directly WITHOUT populating the
        // `params` variable at all — so options.parent is never set
        // in that path (verified against the running containers'
        // ajax/timeline.php, not guessed). That request always
        // carries the ticket id as tickets_id, the same field the
        // hidden <input> earlier in this same form already uses, so
        // we fall back to reading it directly.
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

        if (!Diagnosis::hasAnyFollowup((int) $ticket->getID())) {
            return; // nothing to mark — matches the exemption in OnSolutionPreAdd
        }

        $markedId = Diagnosis::currentMarkedFollowupId((int) $ticket->getID());

        echo '<div class="col-12 mt-2">';
        if ($markedId !== null) {
            $fup = new ITILFollowup();
            $preview = $fup->getFromDB($markedId) ? self::preview((string) $fup->fields['content']) : ('#' . $markedId);
            echo '<div class="alert alert-light border py-2 small mb-0">'
               . '<i class="ti ti-stethoscope me-1"></i>'
               . sprintf(__('Diagnosis follow-up: %s', 'glpiticketreportsign'), htmlspecialchars($preview, ENT_QUOTES))
               . '</div>';
        } else {
            echo '<div class="form-check">';
            echo '<input type="checkbox" class="form-check-input" id="trNoDiagnosis" name="' . Diagnosis::NO_DIAGNOSIS_FIELD . '" value="1">';
            echo '<label class="form-check-label" for="trNoDiagnosis">'
               . __('No diagnosis applies to this case', 'glpiticketreportsign') . '</label>';
            echo '</div>';
        }
        echo '</div>';
    }

    private static function preview(string $html): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)) ?? '');
        return mb_strlen($text) > 80 ? mb_substr($text, 0, 80) . '…' : $text;
    }
}
