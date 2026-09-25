<?php
namespace GlpiPlugin\Glpiticketreportsign\Hooks;

use CommonDBTM;
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
 * The post_item_form hook call itself is confirmed identical in GLPI
 * 10, 11 and 12 — templates/components/itilobject/timeline/
 * form_solution.html.twig calls it at exactly that spot in all three
 * (verified against the running containers). But the widget still
 * failed to render on every version, because of how the ticket was
 * being resolved — see resolveTicket() below for the actual bug and
 * fix (found and confirmed live on GLPI 10.0.18, applies equally to
 * 11/12 since the template structure it depends on is unchanged).
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
        $solution = $data['item'] ?? null;
        if (!$solution instanceof ITILSolution) {
            return;
        }

        $ticket = self::resolveTicket($solution, $data['options'] ?? []);
        if ($ticket === null || $ticket->isNewItem()) {
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
     * silently failing here, which is why this widget never rendered
     * regardless of GLPI version. The reliable source is the
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
