<?php
namespace GlpiPlugin\Glpiticketreportsign\Hooks;

use GlpiPlugin\Glpiticketreportsign\Mail\Recipients;
use GlpiPlugin\Glpiticketreportsign\Mail\ReportMailer;
use GlpiPlugin\Glpiticketreportsign\Pdf\ReportPdf;
use GlpiPlugin\Glpiticketreportsign\Report;
use Ticket;
use Toolbox;

/**
 * Fires on every Ticket update. When THIS update is the one that
 * transitioned status to CLOSED (checked via $ticket->oldvalues,
 * GLPI's own changed-fields tracking — not just "happens to be
 * closed already"), and the client never signed any report, emails
 * them the condensed (resumido) version as a courtesy copy — the
 * ticket closed without going through the sign flow, so this is
 * their only shot at getting a copy at all.
 *
 * No-ops when there's no condensed report to send (e.g. no diagnosis
 * was ever marked) or when the client already signed at some point.
 *
 * Registered via $PLUGIN_HOOKS['item_update']['glpiticketreportsign']
 * in setup.php, narrowed to the Ticket itemtype.
 */
final class OnTicketClosed
{
    public static function handle(Ticket $ticket): void
    {
        if (!isset($ticket->oldvalues['status'])) {
            return; // status didn't change in this update
        }
        if ((int) ($ticket->fields['status'] ?? 0) !== Ticket::CLOSED) {
            return;
        }

        $ticketId = (int) $ticket->getID();
        $reports  = Report::listForTicket($ticketId);

        $condensed = null;
        foreach ($reports as $r) {
            if (!empty($r['signature_client'])) {
                return; // the client signed at some point — nothing to auto-send
            }
            if (!empty($r['signature_tech']) && (string) ($r['mode'] ?? '') === ReportPdf::MODE_CONDENSED) {
                $condensed = $r;
            }
        }
        if ($condensed === null) {
            return;
        }

        foreach (Recipients::requesterEmails($ticketId) as $email) {
            try {
                ReportMailer::sendReportLink(
                    (int) $condensed['id'],
                    $email,
                    $ticketId,
                    ReportMailer::TEMPLATE_CLOSED_UNSIGNED
                );
            } catch (\Throwable $e) {
                Toolbox::logInFile('glpiticketreportsign_error', 'closed-auto-email: ' . $e->getMessage());
            }
        }
    }
}
