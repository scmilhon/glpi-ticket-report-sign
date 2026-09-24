<?php
namespace GlpiPlugin\Glpiticketreportsign\Hooks;

use Document;

/**
 * Adds a "View in Report" button next to a report PDF's native
 * timeline entry, so it's not just a bare download link — confirmed
 * against the running containers: the ticket timeline renders an
 * attached Document directly (not through a separate "Documents"
 * tab) via templates/components/itilobject/timeline/timeline.html.twig,
 * which fires the post_show_item hook for every entry with
 * {'item': entry_object, 'options': {'parent': item, 'rand': ...}} —
 * entry_object is the Document instance itself for a Document_Item
 * attached directly to the Ticket (see CommonITILObject::getTimelineItems()).
 *
 * We deliberately don't try to intercept/redirect the native
 * document.send.php link's click — it stays a normal download.
 *
 * Registered via $PLUGIN_HOOKS[Hooks::POST_SHOW_ITEM]['glpiticketreportsign']
 * in setup.php as a plain callable — post_show_item, like
 * post_item_form, always fires with a plain array, never a bare
 * object.
 */
final class OnTimelineDocumentShow
{
    /** @param array{item?:mixed,options?:array<string,mixed>} $data */
    public static function handle(array $data): void
    {
        $document = $data['item'] ?? null;
        if (!$document instanceof Document) {
            return;
        }
        $documentId = (int) $document->getID();
        if ($documentId <= 0) {
            return;
        }

        global $DB;
        $row = $DB->request([
            'SELECT' => 'tickets_id',
            'FROM'   => 'glpi_plugin_glpiticketreportsign_reports',
            'WHERE'  => ['documents_id' => $documentId],
            'LIMIT'  => 1,
        ])->current();
        if (!is_array($row)) {
            return; // not one of our reports — leave the entry alone
        }
        $ticketId = (int) $row['tickets_id'];

        $url = 'ticket.form.php?id=' . $ticketId
             . '&forcetab=' . 'GlpiPlugin\\Glpiticketreportsign\\Integration\\TicketTab$1';

        echo '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" class="btn btn-sm btn-outline-primary mt-1">'
           . '<i class="ti ti-eye me-1"></i>' . __('View in Report', 'glpiticketreportsign')
           . '</a>';
    }
}
