<?php
namespace GlpiPlugin\Glpiticketreportsign\Pdf;

use Computer;
use Document;
use Document_Item;
use Entity;
use FPDF;
use GlpiPlugin\Glpiticketreportsign\Config;
use GlpiPlugin\Glpiticketreportsign\Diagnosis\Diagnosis;
use ITILFollowup;
use ITILSolution;
use Ticket;
use User;

/**
 * Renders the ticket report with the layout the customer's existing
 * service-order PDF uses. Every section label below is routed
 * through this plugin's __() translation domain (see locales/*.po)
 * and rendered in the active GLPI locale — the names here are the
 * English source strings, not a fixed on-page language:
 *
 *   - Header on every page: company info (pulled from the ticket's
 *     entity), ticket id in red, date.
 *   - CLIENT DATA (entity + requester contact)
 *   - TICKET DETAILS (ticket title, assignee, dates)
 *   - DESCRIPTION (with attached images)
 *   - FOLLOW-UPS (each follow-up with author, date, body, images)
 *   - SOLUTION (with attached images)
 *   - SIGNATURES — single 190×40mm box split visually in two: the
 *     technician's signature on the left, the client's on the right.
 *   - the admin-configured disclaimer (see Config::get(), not a
 *     plugin string at all — whatever the admin typed).
 *
 * Signatures arrive as base64 PNG data URLs (signature_pad.js
 * output). They're decoded into temp files and inserted with
 * FPDF::Image, which handles PNG natively. The same PDF is
 * regenerated whenever either party signs — passing only the
 * tech sig produces a partially-signed PDF; passing both produces
 * the fully signed version.
 */
class ReportPdf
{
    private const IMG_EXT = ['png', 'jpg', 'jpeg', 'gif'];

    /** Every follow-up and solution the ticket has ever had. */
    public const MODE_FULL = 'full';
    /**
     * Only the ticket description, the follow-up marked as the
     * diagnosis (see Diagnosis::latestForTicket()), and the solution
     * from that same resolution cycle. Falls back to MODE_FULL's
     * follow-ups/solution rendering when the ticket has no diagnosis
     * on file (e.g. reports generated before this feature existed).
     */
    public const MODE_CONDENSED = 'condensed';

    public function __construct(
        private readonly Ticket $ticket,
        private readonly ?string $signatureTech     = null,
        private readonly ?string $signerTechName    = null,
        private readonly ?string $signatureClient   = null,
        private readonly ?string $signerClientName  = null,
        private readonly ?Computer $computer        = null,
        private readonly string $mode               = self::MODE_FULL,
    ) {
    }

    /**
     * A deterministic fingerprint of everything this mode's rendered
     * BODY depends on — ticket description, diagnosis, solution(s),
     * and (full mode) every other follow-up — used to detect "the
     * ticket's content changed since this signature was captured"
     * (see ReportStorage's callers) without hashing the rendered PDF
     * bytes themselves, which are not deterministic across renders of
     * unchanged content (embedded QR image encoding, FPDF's own
     * internal object ids/xref offsets). Deliberately excludes the
     * signature images, the header/QR/logo, and anything cosmetic —
     * only the substantive content a signer is attesting to.
     * Mirrors the same is_private exclusion and mode-scoping as the
     * render*Block() methods above, so it tracks exactly what a
     * signer could have seen.
     */
    public static function contentFingerprint(Ticket $ticket, string $mode): string
    {
        global $DB;
        $ticketId = (int) $ticket->getID();
        $parts    = [
            (string) ($ticket->fields['name']    ?? ''),
            (string) ($ticket->fields['content'] ?? ''),
        ];

        $diagnosis   = Diagnosis::latestForTicket($ticketId);
        $diagnosisFupId = (int) ($diagnosis['itilfollowups_id'] ?? 0);
        if ($diagnosisFupId > 0) {
            $row = $DB->request([
                'FROM'  => ITILFollowup::getTable(),
                'WHERE' => ['id' => $diagnosisFupId, 'is_private' => 0],
                'LIMIT' => 1,
            ])->current();
            $parts[] = is_array($row) ? 'diag:' . $row['id'] . ':' . $row['content'] : '';
        }

        if ($mode === self::MODE_FULL) {
            $where = ['itemtype' => 'Ticket', 'items_id' => $ticketId, 'is_private' => 0];
            if ($diagnosisFupId > 0) {
                $where[] = ['NOT' => ['id' => $diagnosisFupId]];
            }
            foreach ($DB->request(['FROM' => ITILFollowup::getTable(), 'WHERE' => $where, 'ORDER' => 'date ASC']) as $r) {
                $parts[] = 'fup:' . $r['id'] . ':' . $r['content'];
            }
        }

        $solWhere = ['itemtype' => 'Ticket', 'items_id' => $ticketId];
        if ($mode === self::MODE_CONDENSED && $diagnosis !== null) {
            $solWhere['id'] = $diagnosis['itilsolutions_id'];
        }
        foreach ($DB->request(['FROM' => ITILSolution::getTable(), 'WHERE' => $solWhere, 'ORDER' => 'date_creation ASC']) as $r) {
            $parts[] = 'sol:' . $r['id'] . ':' . (string) ($r['content'] ?? '') . ':' . (string) ($r['status'] ?? '');
        }

        return hash('sha256', implode("\x1f", $parts));
    }

    public function render(): string
    {
        $pdf = new TicketReportFpdf('P', 'mm', 'A4');
        $pdf->configure($this->ticket);
        $pdf->AliasNbPages();
        $pdf->AddPage();

        $this->renderClientBlock($pdf);
        $this->renderEquipmentBlock($pdf);
        // Descripción → Diagnóstico → Resolución → Seguimientos (the
        // rest, full mode only) — problem, then what was diagnosed,
        // then what was done about it, with the day-to-day follow-up
        // history last since it's context, not the point of the report.
        $this->renderDescriptionBlock($pdf);
        $this->renderDiagnosisBlock($pdf);
        $this->renderSolutionBlock($pdf);
        if ($this->mode === self::MODE_FULL) {
            $this->renderRemainingFollowupsBlock($pdf);
        }
        if ($this->computer !== null) {
            $this->renderComputerBlock($pdf);
        }
        $this->renderSignaturesBlock($pdf);
        $this->renderDisclaimerBlock($pdf);

        return $pdf->Output('S');
    }

    // ---- sections ------------------------------------------------

    private function renderClientBlock(FPDF $pdf): void
    {
        $t       = $this->ticket->fields;
        $entity  = $this->loadEntity((int) $t['entities_id']);
        $reqName = $this->actorList((int) $t['id'], \CommonITILActor::REQUESTER, true);
        $reqEml  = $this->actorEmails((int) $t['id'], \CommonITILActor::REQUESTER);

        $this->sectionHeader($pdf, __('CLIENT DATA', 'glpiticketreportsign'));

        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(1);
        $pdf->Cell(23, 5, $this->lat(__('Company:', 'glpiticketreportsign')), 1, 0, 'L');
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(167, 5, $this->lat($entity['name'] ?? '-'), 1, 0, 'L');
        $pdf->Ln();

        $pdf->Cell(1);
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(23, 5, $this->lat(__('Requester:', 'glpiticketreportsign')), 1, 0, 'L');
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(77, 5, $this->lat($reqName ?: '-'), 1, 0, 'L');
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(20, 5, $this->lat(__('Phone:', 'glpiticketreportsign')), 1, 0, 'L');
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(70, 5, $this->lat($entity['phonenumber'] ?? '-'), 1, 0, 'L');
        $pdf->Ln();

        $pdf->Cell(1);
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(23, 5, $this->lat(__('Address:', 'glpiticketreportsign')), 1, 0, 'L');
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(77, 5, $this->lat($entity['address'] ?? '-'), 1, 0, 'L');
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(20, 5, $this->lat(__('Email:', 'glpiticketreportsign')), 1, 0, 'L');
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(70, 5, $this->lat($reqEml ?: ($entity['email'] ?? '-')), 1, 0, 'L');
        $pdf->Ln();
    }

    private function renderEquipmentBlock(FPDF $pdf): void
    {
        $t        = $this->ticket->fields;
        $assignee = $this->actorList((int) $t['id'], \CommonITILActor::ASSIGN, true);
        $opened   = $this->fmtDate((string) ($t['date'] ?? ''));
        // Resolution date order of preference:
        //   1. ticket.closedate  – ticket is fully closed
        //   2. ticket.solvedate  – marked solved but not yet closed
        //   3. latest ITILSolution.date_creation – when neither of
        //      the above is populated yet (e.g. a solution has been
        //      filed but the ticket is still in "Resuelto" pending
        //      approval and GLPI hasn't stamped solvedate).
        $closed   = $this->fmtDate((string) ($t['closedate'] ?? $t['solvedate'] ?? ''));
        if ($closed === '-') {
            $closed = $this->latestSolutionDate();
        }

        $this->sectionHeader($pdf, __('TICKET DETAILS', 'glpiticketreportsign'));

        $pdf->Cell(1);
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(14, 5, $this->lat(__('Title:', 'glpiticketreportsign')), 1, 0, 'L');
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(176, 5, $this->lat((string) ($t['name'] ?? '')), 1, 0, 'L');
        $pdf->Ln();

        $pdf->Cell(1);
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(36, 5, $this->lat(__('Assigned technician:', 'glpiticketreportsign')), 1, 0, 'L');
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(154, 5, $this->lat($assignee ?: '-'), 1, 0, 'L');
        $pdf->Ln();

        $pdf->Cell(1);
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(36, 5, $this->lat(__('Opening date:', 'glpiticketreportsign')), 1, 0, 'L');
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(55, 5, $this->lat($opened), 1, 0, 'L');
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(39, 5, $this->lat(__('Resolution date:', 'glpiticketreportsign')), 1, 0, 'L');
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(60, 5, $this->lat($closed), 1, 0, 'L');
        $pdf->Ln();
    }

    private function statusLabel(int $status): string
    {
        // Deliberately NOT using GLPI's Ticket::getAllStatusArray()
        // here: on some installs that function returns the English
        // defaults regardless of the user's locale. Routed through
        // this plugin's own __() domain instead — see locales/*.po —
        // which is already confirmed working correctly at this same
        // render time (front/generate.php's toast messages use the
        // same mechanism in the same request).
        return match ($status) {
            \Ticket::INCOMING => __('New', 'glpiticketreportsign'),
            \Ticket::ASSIGNED => __('Processing (assigned)', 'glpiticketreportsign'),
            \Ticket::PLANNED  => __('Processing (planned)', 'glpiticketreportsign'),
            \Ticket::WAITING  => __('Pending', 'glpiticketreportsign'),
            \Ticket::SOLVED   => __('Solved', 'glpiticketreportsign'),
            \Ticket::CLOSED   => __('Closed', 'glpiticketreportsign'),
            default           => '?',
        };
    }

    /**
     * Date the most recent ITILSolution was filed against this
     * ticket — used as a fallback "resolution date" when the ticket
     * itself isn't yet closed/solved.
     */
    private function latestSolutionDate(): string
    {
        global $DB;
        $row = $DB->request([
            'SELECT' => 'date_creation',
            'FROM'   => 'glpi_itilsolutions',
            'WHERE'  => ['itemtype' => 'Ticket', 'items_id' => $this->ticket->getID()],
            'ORDER'  => 'date_creation DESC',
            'LIMIT'  => 1,
        ])->current();
        if (!is_array($row)) {
            return '-';
        }
        return $this->fmtDate((string) ($row['date_creation'] ?? ''));
    }

    /**
     * The solution-type name of the most recent ITILSolution on this
     * ticket. Empty string when the ticket has no solution yet or
     * the solution was filed without picking a type.
     */
    private function latestSolutionType(): string
    {
        global $DB;
        $row = $DB->request([
            'SELECT' => 't.name',
            'FROM'   => 'glpi_itilsolutions AS s',
            'LEFT JOIN' => [
                'glpi_solutiontypes AS t' => ['ON' => ['s' => 'solutiontypes_id', 't' => 'id']],
            ],
            'WHERE'  => [
                's.itemtype' => 'Ticket',
                's.items_id' => $this->ticket->getID(),
            ],
            'ORDER'  => 's.date_creation DESC',
            'LIMIT'  => 1,
        ])->current();
        return is_array($row) ? (string) ($row['name'] ?? '') : '';
    }

    private function renderDescriptionBlock(FPDF $pdf): void
    {
        $this->sectionHeader($pdf, __('DESCRIPTION', 'glpiticketreportsign'));
        $pdf->Cell(1);
        $pdf->SetFont('Arial', '', 10);
        $pdf->MultiCell(190, 5, $this->lat($this->htmlToText((string) $this->ticket->fields['content'])), 1, 'J');
        $this->renderItemImages($pdf, Ticket::class, (int) $this->ticket->getID());
    }

    /**
     * The single follow-up marked as the diagnosis (Diagnosis::
     * latestForTicket()), rendered in both modes. Skipped entirely
     * when the ticket has no diagnosis on file — an older report, or
     * the technician checked "no diagnosis applies".
     */
    private function renderDiagnosisBlock(FPDF $pdf): void
    {
        $diagnosis = Diagnosis::latestForTicket($this->ticket->getID());
        if ($diagnosis === null) {
            return;
        }

        global $DB;
        $row = $DB->request([
            'FROM'  => ITILFollowup::getTable(),
            // A private follow-up is core-gated behind the `followup`
            // SEEPRIVATE right (ITILFollowup::canViewItem()); this
            // report is later served to the ticket requester and to
            // anonymous public-token bearers, neither of whom this
            // plugin ever checks that right for. Excluding is_private
            // unconditionally — rather than trying to decide per
            // recipient — is the only shape that can't leak it.
            'WHERE' => ['id' => $diagnosis['itilfollowups_id'], 'is_private' => 0],
            'LIMIT' => 1,
        ])->current();
        if (!is_array($row)) {
            return;
        }

        $this->sectionHeader($pdf, __('DIAGNOSIS', 'glpiticketreportsign'));
        $author = self::userLabel((int) ($row['users_id'] ?? 0));
        $when   = $this->fmtDate((string) ($row['date'] ?? ''));
        $this->renderEntryCard(
            $pdf,
            __('Diagnosis', 'glpiticketreportsign'),
            $author . '  —  ' . $when,
            '',
            (string) ($row['content'] ?? ''),
            ITILFollowup::class,
            (int) $row['id']
        );
    }

    /**
     * Every follow-up EXCEPT the diagnosis one — full mode only,
     * rendered last as background context rather than interleaved
     * with the problem/diagnosis/resolution narrative.
     */
    private function renderRemainingFollowupsBlock(FPDF $pdf): void
    {
        global $DB;
        $diagnosis = Diagnosis::latestForTicket($this->ticket->getID());
        $excludeId = $diagnosis['itilfollowups_id'] ?? 0;

        // Excluded unconditionally, not per recipient — see the same
        // note in renderDiagnosisBlock(). This report reaches the
        // ticket requester and anonymous public-token bearers, and
        // core's own `followup` SEEPRIVATE right is never consulted
        // by this plugin.
        $where = ['itemtype' => 'Ticket', 'items_id' => $this->ticket->getID(), 'is_private' => 0];
        if ($excludeId > 0) {
            $where[] = ['NOT' => ['id' => $excludeId]];
        }

        $rows = [];
        foreach ($DB->request([
            'FROM'  => ITILFollowup::getTable(),
            'WHERE' => $where,
            'ORDER' => 'date ASC',
        ]) as $r) {
            $rows[] = $r;
        }
        if ($rows === []) {
            return;
        }

        $this->sectionHeader($pdf, __('FOLLOW-UPS', 'glpiticketreportsign'));

        $index = 0;
        foreach ($rows as $row) {
            $index++;
            $author = self::userLabel((int) ($row['users_id'] ?? 0));
            $when   = $this->fmtDate((string) ($row['date'] ?? ''));

            $this->renderEntryCard(
                $pdf,
                sprintf(__('Follow-up #%d', 'glpiticketreportsign'), $index),
                $author . '  —  ' . $when,
                '',
                (string) ($row['content'] ?? ''),
                ITILFollowup::class,
                (int) $row['id']
            );
        }
    }

    /**
     * Render a single follow-up or solution as a self-contained
     * card with a header row (label + meta + optional state badge)
     * and a bordered body, followed by the entry's attached images.
     * Keeps each entry visually distinct, especially when several
     * follow-ups (or rejected + accepted solutions) are stacked.
     */
    private function renderEntryCard(
        FPDF $pdf,
        string $titleLeft,
        string $titleRight,
        string $stateBadge,
        string $bodyHtml,
        string $itemtype,
        int $itemId,
    ): void {
        // Slight gap above each card.
        $pdf->Ln(2);

        // Header strip with a light grey fill so the entry boundary
        // is unambiguous on the page.
        $pdf->Cell(1);
        $pdf->SetFillColor(245, 245, 245);
        $pdf->SetFont('Arial', 'B', 9);

        // Left side: "Seguimiento #2" or "Solución #1"
        $pdf->Cell(40, 6, $this->lat($titleLeft), 'LTB', 0, 'L', true);
        // Middle: author + date
        $rightW = $stateBadge !== '' ? 110 : 150;
        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell($rightW, 6, $this->lat($titleRight), 'TB', 0, 'L', true);
        // Right side: approval-state pill for solutions (empty for follow-ups).
        if ($stateBadge !== '') {
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->Cell(40, 6, $this->lat($stateBadge), 'TRB', 1, 'C', true);
        } else {
            $pdf->Cell(0, 6, '', 'TRB', 1, 'L', true);
        }

        // Body — bordered, justified.
        $pdf->Cell(1);
        $pdf->SetFont('Arial', '', 10);
        $body = $this->htmlToText($bodyHtml);
        if ($body === '') {
            $body = '—';
        }
        $pdf->MultiCell(190, 5, $this->lat($body), 'LRB', 'J');

        // Attachments live underneath the card.
        $this->renderItemImages($pdf, $itemtype, $itemId);
    }

    private function renderSolutionBlock(FPDF $pdf): void
    {
        global $DB;
        $where = ['itemtype' => 'Ticket', 'items_id' => $this->ticket->getID()];
        if ($this->mode === self::MODE_CONDENSED) {
            $diagnosis = Diagnosis::latestForTicket($this->ticket->getID());
            if ($diagnosis !== null) {
                // Only the solution from the same resolution cycle as
                // the diagnosis — "un problema, un diagnóstico, una
                // solución", not the ticket's whole resolution history.
                $where['id'] = $diagnosis['itilsolutions_id'];
            }
        }

        $rows = [];
        foreach ($DB->request([
            'FROM'  => ITILSolution::getTable(),
            'WHERE' => $where,
            'ORDER' => 'date_creation ASC',
        ]) as $r) {
            $rows[] = $r;
        }

        // The section header itself carries the workflow state (and
        // the solution-type label when a solution is filed) — there's
        // no separate "SOLUCIÓN" bar above it any more. Rendered in
        // uppercase to match the rest of the section bars
        // (DATOS DEL CLIENTE, FIRMAS, …).
        $statusName   = $this->statusLabel((int) $this->ticket->fields['status']);
        $solutionType = $this->latestSolutionType();
        $bar          = $statusName;
        if ($solutionType !== '') {
            $bar .= '  —  ' . $solutionType;
        }
        $this->sectionHeader($pdf, mb_strtoupper($bar, 'UTF-8'));

        if ($rows === []) {
            $pdf->Cell(1);
            $pdf->SetFont('Arial', '', 10);
            $pdf->Cell(190, 25, $this->lat(__('Solution Description:', 'glpiticketreportsign')), 1, 1, 'J');
            return;
        }

        // Each solution rendered as its own card with its approval
        // state on the right — so a rejected + accepted pair is
        // unambiguous in the printed report.
        $index = 0;
        foreach ($rows as $row) {
            $index++;
            $author = self::userLabel((int) ($row['users_id'] ?? 0));
            $when   = $this->fmtDate((string) ($row['date_creation'] ?? ''));
            $state  = self::solutionStateLabel((int) ($row['status'] ?? 0));

            $this->renderEntryCard(
                $pdf,
                sprintf(__('Solution #%d', 'glpiticketreportsign'), $index),
                $author . '  —  ' . $when,
                $state,
                (string) ($row['content'] ?? ''),
                ITILSolution::class,
                (int) $row['id']
            );
        }
    }

    /**
     * Translated label for an ITILSolution.status value.
     * Constants from CommonITILValidation: PROPOSED=1 / ACCEPTED=2 / REFUSED=3.
     */
    private static function solutionStateLabel(int $status): string
    {
        return match ($status) {
            1       => __('Proposed', 'glpiticketreportsign'),
            2       => __('Accepted', 'glpiticketreportsign'),
            3       => __('Refused', 'glpiticketreportsign'),
            default => '',
        };
    }

    /**
     * MTTO-only: render the selected Computer's metadata, OS, disk
     * volumes and connected components in their own bordered
     * sub-blocks under a single "EQUIPO INFORMÁTICO" section bar.
     */
    private function renderComputerBlock(FPDF $pdf): void
    {
        global $DB;
        $computer = $this->computer;
        if ($computer === null) {
            return;
        }
        $cid = (int) $computer->getID();

        // Page-break if we're already too far down — keeps the
        // section header next to its first kv row.
        if ($pdf->GetY() > 220) {
            $pdf->AddPage();
        }

        $this->sectionHeader($pdf, __('IT EQUIPMENT', 'glpiticketreportsign'));

        // ---- Computer ----------------------------------------
        $f         = $computer->fields;
        $typeName  = self::lookupName('glpi_computertypes',  (int) ($f['computertypes_id']  ?? 0));
        $modelName = self::lookupName('glpi_computermodels', (int) ($f['computermodels_id'] ?? 0));

        $this->subHeader($pdf, __('Computer', 'glpiticketreportsign'));
        $this->kvRow($pdf, __('Name', 'glpiticketreportsign'),           (string) ($f['name']       ?? '-'));
        $this->kvRow($pdf, __('Type', 'glpiticketreportsign'),           $typeName  ?: '-');
        $this->kvRow($pdf, __('Model', 'glpiticketreportsign'),          $modelName ?: '-');
        $this->kvRow($pdf, __('Serial number', 'glpiticketreportsign'),  (string) ($f['serial']     ?? '-'));
        $this->kvRow($pdf, __('Alternate user', 'glpiticketreportsign'), (string) ($f['contact']    ?? '-'));

        // ---- Operating system --------------------------------
        $osRow = $DB->request([
            'SELECT' => [
                'ios.license_number AS lic',
                'os.name  AS os_name',
                'osv.name AS os_version',
            ],
            'FROM'   => 'glpi_items_operatingsystems AS ios',
            'LEFT JOIN' => [
                'glpi_operatingsystems        AS os'  => ['ON' => ['ios' => 'operatingsystems_id',        'os'  => 'id']],
                'glpi_operatingsystemversions AS osv' => ['ON' => ['ios' => 'operatingsystemversions_id', 'osv' => 'id']],
            ],
            'WHERE'  => ['ios.itemtype' => 'Computer', 'ios.items_id' => $cid],
            'LIMIT'  => 1,
        ])->current();

        $this->subHeader($pdf, __('Operating system', 'glpiticketreportsign'));
        $this->kvRow($pdf, __('Name', 'glpiticketreportsign'),          (string) ($osRow['os_name']    ?? '-'));
        $this->kvRow($pdf, __('Version', 'glpiticketreportsign'),       (string) ($osRow['os_version'] ?? '-'));
        $this->kvRow($pdf, __('Serial number', 'glpiticketreportsign'), (string) ($osRow['lic']        ?? '-'));

        // ---- Volumes ------------------------------------------
        $volumes = [];
        foreach ($DB->request([
            'SELECT' => ['name', 'mountpoint', 'totalsize', 'freesize', 'filesystems_id'],
            'FROM'   => 'glpi_items_disks',
            'WHERE'  => ['itemtype' => 'Computer', 'items_id' => $cid],
            'ORDER'  => 'name ASC',
        ]) as $v) {
            $fsName = self::lookupName('glpi_filesystems', (int) ($v['filesystems_id'] ?? 0));
            $volumes[] = [
                'name'  => (string) ($v['mountpoint'] ?: $v['name'] ?: '-'),
                'fs'    => $fsName ?: '-',
                'total' => self::humanMB((int) ($v['totalsize'] ?? 0)),
                'free'  => self::humanMB((int) ($v['freesize']  ?? 0)),
            ];
        }
        $this->subHeader($pdf, __('Volumes', 'glpiticketreportsign'));
        if ($volumes === []) {
            $this->kvRow($pdf, '', '-');
        } else {
            $pdf->Cell(1);
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->Cell(60, 5, $this->lat(__('Mount point', 'glpiticketreportsign')), 1, 0, 'L');
            $pdf->Cell(40, 5, $this->lat(__('Filesystem', 'glpiticketreportsign')), 1, 0, 'L');
            $pdf->Cell(45, 5, $this->lat(__('Total size', 'glpiticketreportsign')), 1, 0, 'L');
            $pdf->Cell(45, 5, $this->lat(__('Free space', 'glpiticketreportsign')), 1, 1, 'L');
            $pdf->SetFont('Arial', '', 10);
            foreach ($volumes as $v) {
                $pdf->Cell(1);
                $pdf->Cell(60, 5, $this->lat($v['name']),  1, 0, 'L');
                $pdf->Cell(40, 5, $this->lat($v['fs']),    1, 0, 'L');
                $pdf->Cell(45, 5, $this->lat($v['total']), 1, 0, 'L');
                $pdf->Cell(45, 5, $this->lat($v['free']),  1, 1, 'L');
            }
        }

        // ---- Components ---------------------------------------
        $components = self::loadComponents($cid);
        $this->subHeader($pdf, __('Components', 'glpiticketreportsign'));
        if ($components === []) {
            $this->kvRow($pdf, '', '-');
        } else {
            $pdf->Cell(1);
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->Cell(40,  5, $this->lat(__('Type', 'glpiticketreportsign')),        1, 0, 'L');
            $pdf->Cell(110, 5, $this->lat(__('Description', 'glpiticketreportsign')), 1, 0, 'L');
            $pdf->Cell(40,  5, $this->lat(__('Capacity', 'glpiticketreportsign')),    1, 1, 'L');
            $pdf->SetFont('Arial', '', 10);
            foreach ($components as $c) {
                $pdf->Cell(1);
                $pdf->Cell(40,  5, $this->lat($c['label']),       1, 0, 'L');
                $pdf->Cell(110, 5, $this->lat($c['designation']), 1, 0, 'L');
                $pdf->Cell(40,  5, $this->lat($c['capacity'] !== '' ? $c['capacity'] : '-'), 1, 1, 'L');
            }
        }
    }

    /** Render a sub-section heading (smaller bar inside a section). */
    private function subHeader(FPDF $pdf, string $label): void
    {
        $pdf->Ln(1);
        $pdf->Cell(1);
        $pdf->SetFillColor(245, 245, 245);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(190, 5, $this->lat($label), 1, 1, 'L', true);
    }

    /** Render a label/value pair as a single bordered row. */
    private function kvRow(FPDF $pdf, string $label, string $value): void
    {
        $pdf->Cell(1);
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(50, 5, $this->lat($label), 1, 0, 'L');
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(140, 5, $this->lat($value !== '' ? $value : '-'), 1, 1, 'L');
    }

    private static function lookupName(string $table, int $id): string
    {
        if ($id <= 0) {
            return '';
        }
        global $DB;
        $row = $DB->request(['SELECT' => 'name', 'FROM' => $table, 'WHERE' => ['id' => $id], 'LIMIT' => 1])->current();
        return is_array($row) ? (string) ($row['name'] ?? '') : '';
    }

    /**
     * Format a size given in megabytes as the friendliest unit.
     * GLPI's glpi_items_disks stores totalsize / freesize in MB.
     */
    private static function humanMB(int $mb): string
    {
        if ($mb <= 0) {
            return '-';
        }
        if ($mb >= 1024 * 1024) {
            return number_format($mb / 1048576, 1, ',', '.') . ' TB';
        }
        if ($mb >= 1024) {
            return number_format($mb / 1024, 1, ',', '.') . ' GB';
        }
        return $mb . ' MB';
    }

    /**
     * Walk the per-component-type link tables for a computer and
     * collect every component with its capacity (memory size, disk
     * capacity, processor frequency, GPU memory, …). The capacity
     * field lives on the LINK table (`glpi_items_device*`), so we
     * join and pull the relevant column per device type.
     *
     * @return array<int,array{label:string,designation:string,capacity:string}>
     */
    private static function loadComponents(int $computerId): array
    {
        global $DB;

        // For each device family, declare:
        //   link  – link table holding the per-item assignment
        //   dev   – device catalog table holding the model designation
        //   label – translated label rendered in the "Type" column
        //           (routed through __() here, at call time, so it
        //           reflects the active locale like the rest of the
        //           PDF — see locales/*.po)
        //   cap   – column on the LINK table that holds the capacity
        //           (null when the family has no meaningful capacity)
        //   unit  – formatter to apply to the cap value
        $types = [
            ['link' => 'glpi_items_deviceprocessors',    'dev' => 'glpi_deviceprocessors',    'label' => __('Processor', 'glpiticketreportsign'),     'cap' => 'frequency', 'unit' => 'MHz'],
            ['link' => 'glpi_items_devicememories',      'dev' => 'glpi_devicememories',      'label' => __('Memory', 'glpiticketreportsign'),        'cap' => 'size',      'unit' => 'MB'],
            ['link' => 'glpi_items_devicehdds',          'dev' => 'glpi_devicehdds',          'label' => __('Disk', 'glpiticketreportsign'),          'cap' => 'capacity',  'unit' => 'MB'],
            ['link' => 'glpi_items_devicegraphiccards',  'dev' => 'glpi_devicegraphiccards',  'label' => __('Graphics card', 'glpiticketreportsign'), 'cap' => 'memory',    'unit' => 'MB'],
            ['link' => 'glpi_items_devicenetworkcards',  'dev' => 'glpi_devicenetworkcards',  'label' => __('Network card', 'glpiticketreportsign'),  'cap' => null,        'unit' => null],
            ['link' => 'glpi_items_devicesoundcards',    'dev' => 'glpi_devicesoundcards',    'label' => __('Sound card', 'glpiticketreportsign'),    'cap' => null,        'unit' => null],
            ['link' => 'glpi_items_devicemotherboards',  'dev' => 'glpi_devicemotherboards',  'label' => __('Motherboard', 'glpiticketreportsign'),   'cap' => null,        'unit' => null],
            ['link' => 'glpi_items_devicepowersupplies', 'dev' => 'glpi_devicepowersupplies', 'label' => __('Power supply', 'glpiticketreportsign'),  'cap' => null,        'unit' => null],
            ['link' => 'glpi_items_devicebatteries',     'dev' => 'glpi_devicebatteries',     'label' => __('Battery', 'glpiticketreportsign'),       'cap' => 'capacity',  'unit' => 'mWh'],
            ['link' => 'glpi_items_devicedrives',        'dev' => 'glpi_devicedrives',        'label' => __('Optical drive', 'glpiticketreportsign'), 'cap' => null,        'unit' => null],
            ['link' => 'glpi_items_devicegenerics',      'dev' => 'glpi_devicegenerics',      'label' => __('Generic device', 'glpiticketreportsign'), 'cap' => null,       'unit' => null],
            ['link' => 'glpi_items_devicepcis',          'dev' => 'glpi_devicepcis',          'label' => __('PCI device', 'glpiticketreportsign'),    'cap' => null,        'unit' => null],
            ['link' => 'glpi_items_devicecontrols',      'dev' => 'glpi_devicecontrols',      'label' => __('Controller', 'glpiticketreportsign'),    'cap' => null,        'unit' => null],
            ['link' => 'glpi_items_devicesimplecards',   'dev' => 'glpi_devicesimplecards',   'label' => __('Simple card', 'glpiticketreportsign'),   'cap' => null,        'unit' => null],
            ['link' => 'glpi_items_devicesensors',       'dev' => 'glpi_devicesensors',       'label' => __('Sensor', 'glpiticketreportsign'),        'cap' => null,        'unit' => null],
            ['link' => 'glpi_items_devicefirmwares',     'dev' => 'glpi_devicefirmwares',     'label' => __('Firmware', 'glpiticketreportsign'),      'cap' => null,        'unit' => null],
            ['link' => 'glpi_items_devicecases',         'dev' => 'glpi_devicecases',         'label' => __('Case', 'glpiticketreportsign'),          'cap' => null,        'unit' => null],
        ];

        $out = [];
        foreach ($types as $t) {
            if (!$DB->tableExists($t['link']) || !$DB->tableExists($t['dev'])) {
                continue;
            }
            $fk     = substr($t['dev'], strlen('glpi_')) . '_id';
            $select = ['d.designation'];
            if ($t['cap'] !== null) {
                $select[] = 'l.' . $t['cap'] . ' AS cap_value';
            }

            try {
                $rows = $DB->request([
                    'SELECT' => $select,
                    'FROM'   => $t['link'] . ' AS l',
                    'INNER JOIN' => [
                        $t['dev'] . ' AS d' => ['ON' => ['l' => $fk, 'd' => 'id']],
                    ],
                    'WHERE'  => ['l.itemtype' => 'Computer', 'l.items_id' => $computerId],
                    'ORDER'  => 'd.designation ASC',
                ]);
                foreach ($rows as $r) {
                    $capStr = '';
                    if ($t['cap'] !== null) {
                        $capStr = self::formatCapacity((int) ($r['cap_value'] ?? 0), (string) $t['unit']);
                    }
                    $out[] = [
                        'label'       => $t['label'],
                        'designation' => (string) ($r['designation'] ?? ''),
                        'capacity'    => $capStr,
                    ];
                }
            } catch (\Throwable $_e) {
                // Skip a device table this GLPI build doesn't expose.
                continue;
            }
        }
        return $out;
    }

    /**
     * Format a raw integer capacity value with the right unit and
     * human-friendly scaling (MB → GB → TB; MHz → GHz).
     */
    private static function formatCapacity(int $value, string $unit): string
    {
        if ($value <= 0) {
            return '';
        }
        switch ($unit) {
            case 'MB':
                return self::humanMB($value);
            case 'MHz':
                return $value >= 1000
                    ? number_format($value / 1000, 2, ',', '.') . ' GHz'
                    : $value . ' MHz';
            case 'mWh':
                return $value >= 1000
                    ? number_format($value / 1000, 1, ',', '.') . ' Wh'
                    : $value . ' mWh';
            default:
                return (string) $value . ($unit !== '' ? ' ' . $unit : '');
        }
    }

    private function renderSignaturesBlock(FPDF $pdf): void
    {
        // The signature box contains everything in one bordered
        // rectangle: signatures on top, separator line, then the
        // signer name and role (Technician / Client). Total block
        // height: 6mm (section bar) + 60mm (box) + 4mm (gap).
        //
        // Reserve that full height BEFORE drawing the section bar.
        // Otherwise FPDF's auto page-break can fire mid-box and
        // leave the FIRMAS bar orphaned at the bottom of the page
        // while the box renders alone on the next one.
        $blockH      = 6 + 60 + 4;
        $bottomLimit = 297 - 20; // A4 height minus FPDF bottom margin
        if ($pdf->GetY() + $blockH > $bottomLimit) {
            $pdf->AddPage();
        }
        $this->sectionHeader($pdf, __('SIGNATURES', 'glpiticketreportsign'));

        $boxX  = $pdf->GetX() + 1;
        $boxY  = $pdf->GetY();
        $boxW  = 190;
        $boxH  = 60;
        $halfW = $boxW / 2;

        // Outer rectangle.
        $pdf->Cell(1);
        $pdf->Cell($boxW, $boxH, '', 1, 0, 'L');
        // Vertical separator between the two halves.
        $pdf->Line($boxX + $halfW, $boxY, $boxX + $halfW, $boxY + $boxH);

        // Signature drawing area: top 40 mm of each half.
        $sigAreaH = 40;
        if ($this->signatureTech) {
            $tmp = $this->writeTempPng($this->signatureTech);
            if ($tmp !== null) {
                $pdf->Image($tmp, $boxX + 4, $boxY + 4, $halfW - 8, $sigAreaH - 8, 'PNG');
                @unlink($tmp);
            }
        }
        if ($this->signatureClient) {
            $tmp = $this->writeTempPng($this->signatureClient);
            if ($tmp !== null) {
                $pdf->Image($tmp, $boxX + $halfW + 4, $boxY + 4, $halfW - 8, $sigAreaH - 8, 'PNG');
                @unlink($tmp);
            }
        }

        // Thin horizontal divider just under the signature area
        // (acts as the "sign-on-the-line" baseline).
        $lineY = $boxY + $sigAreaH;
        $pdf->Line($boxX + 6,           $lineY, $boxX + $halfW - 6, $lineY);
        $pdf->Line($boxX + $halfW + 6,  $lineY, $boxX + $boxW - 6,  $lineY);

        // Signer names just below the divider, centered.
        $assignee = $this->actorList((int) $this->ticket->getID(), \CommonITILActor::ASSIGN, true);
        $reqName  = $this->actorList((int) $this->ticket->getID(), \CommonITILActor::REQUESTER, true);

        $pdf->SetXY($boxX, $lineY + 2);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell($halfW, 6, $this->lat($this->signerTechName   ?: $assignee), 0, 0, 'C');
        $pdf->Cell($halfW, 6, $this->lat($this->signerClientName ?: $reqName),   0, 0, 'C');

        // Roles below the names, smaller and italic for hierarchy.
        $pdf->SetXY($boxX, $lineY + 8);
        $pdf->SetFont('Arial', 'I', 8);
        $pdf->Cell($halfW, 5, $this->lat(__('Technician', 'glpiticketreportsign')), 0, 0, 'C');
        $pdf->Cell($halfW, 5, $this->lat(__('Client', 'glpiticketreportsign')), 0, 0, 'C');

        // Move the cursor below the whole box for the next section.
        $pdf->SetY($boxY + $boxH + 4);
    }

    private function renderDisclaimerBlock(FPDF $pdf): void
    {
        // No hard-coded fallback text here on purpose: the disclaimer
        // is whatever (if anything) the admin saved on the config
        // screen — see Config::defaults(). A live fallback to a
        // built-in string would mean a future wording change in the
        // plugin's source retroactively changes what already-
        // configured instances print, and would make an empty body
        // (below) impossible to reach.
        $cfg   = Config::get();
        $title = trim((string) ($cfg['disclaimer_title'] ?? ''));
        $body  = trim((string) ($cfg['disclaimer_body']  ?? ''));

        if ($body === '') {
            return; // admin wiped the body — skip the section entirely
        }

        // Always print the disclaimer on a fresh page for legibility.
        if ($pdf->GetY() > 220) {
            $pdf->AddPage();
        }
        $this->sectionHeader($pdf, $title);

        $pdf->Cell(1);
        $pdf->SetFont('Arial', '', 9);
        $pdf->MultiCell(190, 4.5, $this->lat($body), 1, 'J');
    }

    // ---- shared helpers -----------------------------------------

    private function sectionHeader(FPDF $pdf, string $label): void
    {
        $cfg = Config::get();
        $rgb = self::hexToRgb((string) ($cfg['section_header_bg'] ?? ''), [230, 230, 230]);
        $pdf->SetFillColor($rgb[0], $rgb[1], $rgb[2]);
        $pdf->Cell(1);
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(190, 6, $this->lat($label), 1, 1, 'C', true);
    }

    /**
     * @return array<int,int>
     */
    private static function hexToRgb(string $hex, array $fallback): array
    {
        if (!preg_match('/^#?([0-9a-fA-F]{6})$/', trim($hex), $m)) {
            return $fallback;
        }
        $int = hexdec($m[1]);
        return [($int >> 16) & 0xFF, ($int >> 8) & 0xFF, $int & 0xFF];
    }

    private function renderItemImages(FPDF $pdf, string $itemtype, int $itemsId): void
    {
        global $DB;

        // Earlier versions tried to filter images by uploader
        // (assigned-tech only, then requester-excluded). Both
        // approaches dropped legitimate technician attachments
        // on installs where users carry multiple roles, supervisors
        // upload on behalf of techs, or the inventory agent owns
        // the document. We now include every image linked to the
        // item; the customer can re-introduce a filter later once
        // we know which user-attribute reliably identifies the
        // "client" in their environment.
        $iter = $DB->request([
            'SELECT' => ['d.id', 'd.filepath', 'd.filename'],
            'FROM'   => Document_Item::getTable() . ' AS di',
            'INNER JOIN' => [
                Document::getTable() . ' AS d' => ['ON' => ['di' => 'documents_id', 'd' => 'id']],
            ],
            'WHERE'  => ['di.itemtype' => $itemtype, 'di.items_id' => $itemsId],
            'ORDER'  => 'd.id ASC',
        ]);
        $names = [];
        foreach ($iter as $row) {
            $abs = self::absPath((string) $row['filepath']);
            if ($abs === null || !is_file($abs)) {
                continue;
            }
            $ext = strtolower((string) pathinfo((string) $row['filename'], PATHINFO_EXTENSION));
            if (!in_array($ext, self::IMG_EXT, true)) {
                $names[] = (string) $row['filename'];
                continue;
            }
            $this->insertImage($pdf, $abs, $ext);
        }
        if ($names !== []) {
            $pdf->Cell(1);
            $pdf->SetFont('Arial', 'I', 9);
            $pdf->MultiCell(190, 4, $this->lat(sprintf(__('Attachments: %s', 'glpiticketreportsign'), implode(', ', $names))));
        }
    }

    private function insertImage(FPDF $pdf, string $absPath, string $ext): void
    {
        // FPDF can't read 16-bit PNGs. Phones and modern cameras
        // often save 16-bit depth, so detect and downsample to 8-bit
        // via GD into a temp file before embedding. JPEGs always
        // pass through unchanged.
        $tmpEncoded = null;
        if ($ext === 'png') {
            $info = @getimagesize($absPath);
            if (is_array($info) && (int) ($info['bits'] ?? 8) > 8) {
                $tmpEncoded = self::downsamplePng($absPath);
                if ($tmpEncoded !== null) {
                    $absPath = $tmpEncoded;
                }
            }
        }

        // Comfortable medium-sized thumbnail: at most 130×95 mm,
        // aspect ratio preserved. Centered horizontally between
        // the 15 mm page margins, with a small inner padding and a
        // soft grey frame so the image sits cleanly on the page
        // instead of looking like a screenshot pasted at the edge.
        $maxW = 130.0;
        $maxH = 95.0;
        $info = @getimagesize($absPath);
        $w = $maxW;
        $h = $maxH;
        if (is_array($info) && $info[0] > 0 && $info[1] > 0) {
            $aspect = $info[0] / $info[1];
            if ($aspect >= $maxW / $maxH) {
                $w = $maxW;
                $h = $maxW / $aspect;
            } else {
                $h = $maxH;
                $w = $maxH * $aspect;
            }
        }

        $padding = 2.0;
        $boxW = $w + 2 * $padding;
        $boxH = $h + 2 * $padding;

        if ($pdf->GetY() + $boxH > 270) {
            $pdf->AddPage();
        }

        // Center horizontally — A4 is 210 mm wide, margins 15 mm
        // each side ⇒ usable area 180 mm centred on x=105.
        $x = 15 + (180 - $boxW) / 2;
        $y = $pdf->GetY() + 2;

        $pdf->SetDrawColor(170, 170, 170);
        $pdf->SetLineWidth(0.3);
        $pdf->Rect($x, $y, $boxW, $boxH);
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetLineWidth(0.2);

        $type = $ext === 'jpg' ? 'JPEG' : strtoupper($ext);
        try {
            $pdf->Image($absPath, $x + $padding, $y + $padding, $w, $h, $type);
        } catch (\Throwable $e) {
            // FPDF throws on unsupported PNG variants (16-bit depth,
            // interlaced, palette + alpha, etc.). Don't kill the
            // whole PDF over one bad attachment — log and skip.
            \Toolbox::logInFile(
                'glpiticketreportsign_error',
                'image skipped (' . $absPath . '): ' . $e->getMessage()
            );
            // Roll back the frame we already drew so we don't leave
            // an empty box on the page.
            $pdf->SetDrawColor(255, 255, 255);
            $pdf->SetLineWidth(0.4);
            $pdf->Rect($x - 0.1, $y - 0.1, $boxW + 0.2, $boxH + 0.2);
            $pdf->SetDrawColor(0, 0, 0);
            $pdf->SetLineWidth(0.2);
        }

        if ($tmpEncoded !== null) {
            @unlink($tmpEncoded);
        }

        // Move the cursor below the framed image (including the
        // frame's bottom edge) with a small gap, and reset X to
        // the left margin so the next content (text, next image)
        // lays out normally.
        $pdf->SetY($y + $boxH + 4);
        $pdf->SetX(15);
    }

    /**
     * Re-encode a PNG into FPDF-friendly 8-bit RGB(A) via GD.
     * Returns the temp file path on success, null if GD couldn't
     * read the source (corrupt file, unsupported variant, etc.).
     */
    private static function downsamplePng(string $absPath): ?string
    {
        if (!function_exists('imagecreatefrompng')) {
            return null;
        }
        $img = @imagecreatefrompng($absPath);
        if ($img === false) {
            return null;
        }
        imagesavealpha($img, true);
        $tmp = tempnam(sys_get_temp_dir(), 'tr_png_') . '.png';
        $ok  = @imagepng($img, $tmp, 6); // compression 0-9; 6 is default
        imagedestroy($img);
        if ($ok === false) {
            @unlink($tmp);
            return null;
        }
        return $tmp;
    }

    private function writeTempPng(string $base64): ?string
    {
        $bin = base64_decode(preg_replace('/^data:image\/png;base64,/', '', $base64), true);
        if ($bin === false) {
            return null;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'tr_sig_') . '.png';
        if (file_put_contents($tmp, $bin) === false) {
            return null;
        }
        return $tmp;
    }

    /** @return array<string,string> */
    private function loadEntity(int $entityId): array
    {
        $e = new Entity();
        if (!$e->getFromDB($entityId)) {
            return [];
        }
        return [
            // The entity's own name only — the user explicitly asked
            // for it to NOT include the parent path that completename
            // would carry.
            'name'        => (string) ($e->fields['name'] ?? ''),
            'address'     => (string) (($e->fields['address']    ?? '') . ' ' . ($e->fields['town'] ?? '')),
            'phonenumber' => (string) ($e->fields['phonenumber']  ?? ''),
            'email'       => (string) ($e->fields['email']        ?? ''),
        ];
    }

    private function actorList(int $ticketId, int $type, bool $usersOnly = false): string
    {
        global $DB;
        $names = [];
        foreach ($DB->request([
            'SELECT' => 'users_id',
            'FROM'   => 'glpi_tickets_users',
            'WHERE'  => ['tickets_id' => $ticketId, 'type' => $type],
        ]) as $r) {
            $names[] = self::userLabel((int) $r['users_id']);
        }
        if (!$usersOnly) {
            foreach ($DB->request([
                'SELECT' => 'g.completename',
                'FROM'   => 'glpi_groups_tickets AS gt',
                'INNER JOIN' => ['glpi_groups AS g' => ['ON' => ['gt' => 'groups_id', 'g' => 'id']]],
                'WHERE'  => ['gt.tickets_id' => $ticketId, 'gt.type' => $type],
            ]) as $r) {
                $names[] = (string) $r['completename'];
            }
        }
        return implode(', ', $names);
    }

    private function actorEmails(int $ticketId, int $type): string
    {
        global $DB;
        $emails = [];
        foreach ($DB->request([
            'SELECT' => 'u.id',
            'FROM'   => 'glpi_tickets_users AS tu',
            'INNER JOIN' => ['glpi_users AS u' => ['ON' => ['tu' => 'users_id', 'u' => 'id']]],
            'WHERE'  => ['tu.tickets_id' => $ticketId, 'tu.type' => $type],
        ]) as $r) {
            $emails[] = self::primaryEmail((int) $r['id']);
        }
        return implode(', ', array_filter($emails));
    }

    private static function primaryEmail(int $userId): string
    {
        global $DB;
        $r = $DB->request([
            'SELECT' => 'email',
            'FROM'   => 'glpi_useremails',
            'WHERE'  => ['users_id' => $userId, 'is_default' => 1],
            'LIMIT'  => 1,
        ])->current();
        return is_array($r) ? (string) $r['email'] : '';
    }

    private static function userLabel(int $userId): string
    {
        if ($userId <= 0) {
            return __('System', 'glpiticketreportsign');
        }
        $u = new User();
        if (!$u->getFromDB($userId)) {
            return '#' . $userId;
        }
        return \formatUserName(
            (int) $u->fields['id'],
            (string) ($u->fields['name']      ?? ''),
            (string) ($u->fields['realname']  ?? ''),
            (string) ($u->fields['firstname'] ?? '')
        );
    }

    private static function absPath(string $rel): ?string
    {
        if ($rel === '') {
            return null;
        }
        $base = defined('GLPI_DOC_DIR') ? GLPI_DOC_DIR : (defined('GLPI_VAR_DIR') ? GLPI_VAR_DIR : '');
        return $base === '' ? null : rtrim($base, '/\\') . DIRECTORY_SEPARATOR . ltrim($rel, '/\\');
    }

    private function fmtDate(string $s): string
    {
        if ($s === '' || str_starts_with($s, '0000')) {
            return '-';
        }
        $ts = strtotime($s);
        return $ts === false ? $s : date('Y-m-d H:i', $ts);
    }

    /**
     * Convert ticket/follow-up/solution HTML into the plain text
     * that FPDF::MultiCell consumes.
     *
     * GLPI's rich editor wraps every paragraph in <p>…</p>, so a
     * naive </p> → "\n\n" left big gaps when users hit Enter
     * between thoughts (every paragraph was a double-blank). We
     * now normalise:
     *   - <br>  → single newline
     *   - </p>  → single newline
     *   - &nbsp; → space
     *   - lines that contain only whitespace are dropped
     *   - runs of 2+ blank lines collapse to one blank line
     */
    private function htmlToText(string $html): string
    {
        $txt = $html;
        $txt = preg_replace('#<br\s*/?>#i', "\n", $txt) ?? $txt;
        $txt = preg_replace('#</p>#i',      "\n", $txt) ?? $txt;
        $txt = preg_replace('#</div>#i',    "\n", $txt) ?? $txt;
        $txt = strip_tags($txt);
        $txt = html_entity_decode($txt, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $txt = str_replace(["\r\n", "\r"], "\n", $txt);
        $txt = str_replace("\xC2\xA0", ' ', $txt); // utf-8 nbsp → space

        // Trim each line, drop blank-only lines that come in runs.
        $lines = array_map('trim', explode("\n", $txt));
        $out   = [];
        $blank = false;
        foreach ($lines as $line) {
            if ($line === '') {
                if (!$blank && $out !== []) {
                    $out[] = '';
                    $blank = true;
                }
                continue;
            }
            $out[] = $line;
            $blank = false;
        }
        while ($out !== [] && end($out) === '') {
            array_pop($out);
        }
        return implode("\n", $out);
    }

    /**
     * FPDF core fonts are CP1252-only. iconv with TRANSLIT//IGNORE
     * keeps Spanish accents and degrades anything outside Latin-1.
     */
    private function lat(string $s): string
    {
        $out = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s);
        return $out === false ? $s : $out;
    }
}
