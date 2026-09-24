<?php
namespace GlpiPlugin\Glpiticketreportsign\Hooks;

use GlpiPlugin\Glpiticketreportsign\Diagnosis\Diagnosis;
use GlpiPlugin\Glpiticketreportsign\Pdf\ReportPdf;
use GlpiPlugin\Glpiticketreportsign\Pdf\ReportStorage;
use GlpiPlugin\Glpiticketreportsign\Report;
use GlpiPlugin\Glpiticketreportsign\Security\Authorizer;
use ITILSolution;
use Session;
use Ticket;
use Toolbox;

/**
 * Fires when a solution is added to a ticket. Generates a fresh
 * draft report version automatically; the user lands on whatever
 * page GLPI normally redirects to after a solution save and the
 * toast tells them the draft is ready (they click the Report tab
 * to view / sign).
 *
 * Registered via $PLUGIN_HOOKS['item_add']['glpiticketreportsign'] in
 * setup.php, narrowed to the ITILSolution itemtype.
 */
final class OnSolutionAdded
{
    public static function handle(ITILSolution $solution): void
    {
        if ((string) ($solution->fields['itemtype'] ?? '') !== 'Ticket') {
            return;
        }
        $ticketId = (int) ($solution->fields['items_id'] ?? 0);
        if ($ticketId <= 0) {
            return;
        }

        $ticket = new Ticket();
        if (!$ticket->getFromDB($ticketId)) {
            return;
        }
        if (!Authorizer::canActOnTicket($ticketId)) {
            return;
        }

        Diagnosis::attachPendingToSolution($ticketId, (int) $solution->getID());

        // Maintenance-preventive solutions skip auto-generation:
        // they're meant to flow through the MTTO button so the
        // technician explicitly picks which computer the report
        // is for. Auto-generating here would produce a regular
        // report without the EQUIPO INFORMÁTICO section.
        $solutionTypeId = (int) ($solution->fields['solutiontypes_id'] ?? 0);
        if (Authorizer::isMaintenanceSolutionType($solutionTypeId)) {
            return;
        }

        try {
            $version = Report::nextVersion($ticketId);

            $fullBytes = (new ReportPdf($ticket, mode: ReportPdf::MODE_FULL))->render();
            ReportStorage::save($ticket, $fullBytes, Report::STATE_DRAFT, mode: ReportPdf::MODE_FULL, version: $version);

            // The condensed sibling only makes sense once there's a
            // diagnosis to condense around — matches the exemption
            // used everywhere else (OnSolutionPreAdd, the manual
            // "Resumido" option, ReportPdf::renderDiagnosisBlock()).
            if (Diagnosis::latestForTicket($ticketId) !== null) {
                $condensedBytes = (new ReportPdf($ticket, mode: ReportPdf::MODE_CONDENSED))->render();
                ReportStorage::save($ticket, $condensedBytes, Report::STATE_DRAFT, mode: ReportPdf::MODE_CONDENSED, version: $version);
            }

            Session::addMessageAfterRedirect(
                __('Solution recorded. A draft report was generated automatically.', 'glpiticketreportsign'),
                false,
                INFO
            );
        } catch (\Throwable $e) {
            Toolbox::logInFile('glpiticketreportsign_error', 'auto-generate: ' . $e->getMessage());
        }
    }
}
