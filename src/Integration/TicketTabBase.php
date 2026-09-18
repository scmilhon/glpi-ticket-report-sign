<?php
namespace GlpiPlugin\Glpiticketreportsign\Integration;

use CommonGLPI;
use GlpiPlugin\Glpiticketreportsign\Profile;
use GlpiPlugin\Glpiticketreportsign\Report;
use GlpiPlugin\Glpiticketreportsign\Security\Authorizer;
use GlpiPlugin\Glpiticketreportsign\Vendor\Assets;
use Plugin;
use Ticket;

/**
 * Renders the "Report" tab on the Ticket form. Every action is a
 * plain HTML <form> POST or <a href> link — no JS is needed for the
 * primary actions. The latest report is rendered inline using
 * pdf.js (loaded from a CDN by default, or local public/vendor/
 * if present), so the user can read the report directly in the tab
 * without downloading.
 *
 * This class deliberately does NOT declare $rightname. GLPI 12 typed
 * CommonGLPI's static $rightname as `string` (untyped before), and a
 * child class redeclaring it must match the parent's type exactly —
 * see https://github.com/glpi-project/glpi/issues/25399. The actual
 * `TicketTab` class registered with Plugin::registerClass() is one of
 * the two leaf files next to this one (TicketTab.php for GLPI <12,
 * TicketTab.glpi12.php for GLPI 12+), each adding only the $rightname
 * declaration with the type its running GLPI expects; setup.php's
 * autoloader picks the matching one at runtime via reflection.
 * Everything else lives here so the two leaves never drift apart.
 */
abstract class TicketTabBase extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return __('Report', 'glpiticketreportsign');
    }

    /**
     * Tabler icon shown next to the tab name in the ticket sidebar.
     * GLPI 11 picks this up via Plugin::registerClass when rendering
     * tab entries.
     */
    public static function getIcon(): string
    {
        return 'ti ti-signature';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): array|string
    {
        if (!$item instanceof Ticket || $item->isNewItem()) {
            return '';
        }
        $ticketId = $item->getID();

        // Requesters (clients) on the helpdesk interface always see
        // the tab so they can sign their part. They don't need the
        // plugin's profile right.
        $isRequester = Authorizer::isTicketRequester($ticketId);

        if (!$isRequester) {
            // Technicians need the profile right.
            if (!Profile::hasRight(READ)) {
                return '';
            }
            if (!Authorizer::canActOnTicket($ticketId)) {
                return '';
            }
        }
        $count = count(Report::listForTicket($ticketId));
        return self::createTabEntry(self::getTypeName(), $count);
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if (!$item instanceof Ticket) {
            return false;
        }
        $ticketId    = $item->getID();
        $isRequester = Authorizer::isTicketRequester($ticketId);

        if ($isRequester) {
            self::renderClientTab($item);
            return true;
        }

        if (!Profile::hasRight(READ)) {
            echo '<div class="alert alert-warning m-3">'
               . __('Your profile does not have access to ticket reports.', 'glpiticketreportsign')
               . '</div>';
            return true;
        }
        if (!Authorizer::canActOnTicket($ticketId)) {
            echo '<div class="alert alert-warning m-3">'
               . __('Only the assigned technician or their supervisor can generate the report.', 'glpiticketreportsign')
               . '</div>';
            return true;
        }
        self::renderTab($item);
        return true;
    }

    /**
     * Simplified tab shown to the ticket requester (client) in the
     * helpdesk / self-service interface: latest report's PDF
     * preview and download, plus a single "Sign as client" button
     * when the client hasn't signed yet (and the technician has).
     */
    private static function renderClientTab(Ticket $ticket): void
    {
        $reports = Report::listForTicket($ticket->getID());
        $base    = plugin_glpiticketreportsign_web_dir();
        $latest  = $reports[0] ?? null;
        $vendor  = Assets::urls();

        echo '<div class="glpiticketreportsign-tab p-3">';
        echo '<h4 class="mb-3"><i class="ti ti-file-text me-1"></i>'
           . __('Ticket report', 'glpiticketreportsign') . '</h4>';

        if ($latest === null) {
            echo '<div class="alert alert-secondary">'
               . __('No report generated yet for this ticket.', 'glpiticketreportsign')
               . '</div>';
            echo '</div>';
            return;
        }

        $rid       = (int) $latest['id'];
        $hasTech   = !empty($latest['signature_tech']);
        $hasClient = !empty($latest['signature_client']);
        $needClient = $hasTech && !$hasClient;

        $pdfUrl  = $base . '/ajax/pdf_bytes.php?id=' . $rid;
        $dlUrl   = $base . '/front/download.php?id=' . $rid;
        $signUrl = $base . '/front/sign.form.php?id=' . $rid;

        echo '<div class="card mb-3"><div class="card-body">';
        echo '<div class="d-flex align-items-center mb-2 flex-wrap gap-2">';
        echo '  <strong>' . sprintf(__('Report #%d', 'glpiticketreportsign'), (int) $latest['version']) . '</strong>';
        echo '  ' . self::stateBadge($latest);
        echo '  <a href="' . htmlspecialchars($dlUrl) . '" target="_blank" '
           . 'class="btn btn-sm btn-outline-secondary ms-auto">'
           . '<i class="ti ti-download me-1"></i>' . __('Download PDF', 'glpiticketreportsign') . '</a>';
        if ($needClient) {
            echo '  <a href="' . htmlspecialchars($signUrl) . '" '
               . 'class="btn btn-sm btn-primary">'
               . '<i class="ti ti-signature me-1"></i>' . __('Sign as client', 'glpiticketreportsign') . '</a>';
        }
        echo '</div>';

        echo '<div id="trPreviewWrap" '
           . 'data-pdf-url="' . htmlspecialchars($pdfUrl, ENT_QUOTES) . '" '
           . 'data-pdfjs="'   . htmlspecialchars($vendor['pdfJs'],   ENT_QUOTES) . '" '
           . 'data-pdfworker="' . htmlspecialchars($vendor['pdfWorker'], ENT_QUOTES) . '" '
           . 'style="overflow:auto; max-height:75vh; background:#f8f9fa; padding:8px; border:1px solid #ddd; border-radius:4px">'
           . '<div class="text-muted text-center p-4">' . __('Loading preview…', 'glpiticketreportsign') . '</div>'
           . '</div>';

        echo '</div></div></div>';

        // Same inline preview bootstrap as the tech tab.
        self::emitPreviewScript();
    }

    private static function renderTab(Ticket $ticket): void
    {
        $reports     = Report::listForTicket($ticket->getID());
        $base        = plugin_glpiticketreportsign_web_dir();
        // Session::getNewCSRFToken() was removed in GLPI 12 (CSRF moved
        // to Sec-Fetch-Site/Origin header validation) — guard so the
        // tab still renders there instead of fataling.
        $csrf        = method_exists(\Session::class, 'getNewCSRFToken')
            ? \Session::getNewCSRFToken()
            : '';
        $generateUrl = $base . '/front/generate.php';
        $ticketId    = (int) $ticket->getID();
        $latest      = $reports[0] ?? null;
        $vendor      = Assets::urls();
        $closed      = Authorizer::isTicketClosed($ticketId);
        $isMtto      = Authorizer::isMaintenanceTicket($ticketId);
        // Button state machine:
        //   closed                → both buttons disabled.
        //   !closed && maintenance → MTTO on, Generate off.
        //   !closed && !maintenance → Generate on, MTTO off.
        $genEnabled  = !$closed && !$isMtto;
        $mttoEnabled = !$closed &&  $isMtto;
        $canCreate   = Profile::hasRight(CREATE);
        $canUpdate   = Profile::hasRight(UPDATE);

        echo '<div class="glpiticketreportsign-tab p-3">';

        // Action bar.
        echo '<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">';
        echo '<h4 class="mb-0"><i class="ti ti-file-text me-1"></i>'
           . __('Ticket report', 'glpiticketreportsign') . '</h4>';
        if ($canCreate) {
            echo '<div class="d-flex gap-2 flex-wrap">';

            // --- Generate / refresh --------------------------------
            if ($genEnabled) {
                echo '<form method="post" action="' . htmlspecialchars($generateUrl) . '" style="display:inline">';
                echo '  <input type="hidden" name="tickets_id" value="' . $ticketId . '">';
                echo '  <input type="hidden" name="_glpi_csrf_token" value="' . htmlspecialchars($csrf, ENT_QUOTES) . '">';
                echo '  <button type="submit" class="btn btn-primary">'
                   . '<i class="ti ti-refresh me-1"></i>' . __('Generate / refresh', 'glpiticketreportsign') . '</button>';
                echo '</form>';
            } else {
                $tip = $closed
                    ? __('Closed tickets cannot have new report versions.', 'glpiticketreportsign')
                    : __('Use the MTTO button — this ticket is flagged as Mantenimiento Preventivo.', 'glpiticketreportsign');
                echo '<button type="button" class="btn btn-secondary" disabled '
                   . 'title="' . htmlspecialchars($tip, ENT_QUOTES) . '">'
                   . '<i class="ti ti-lock me-1"></i>' . __('Generate / refresh', 'glpiticketreportsign') . '</button>';
            }

            // --- MTTO's --------------------------------------------
            if ($mttoEnabled) {
                echo '<a href="' . htmlspecialchars($base . '/front/mtto.form.php?id=' . $ticketId) . '" '
                   . 'class="btn btn-outline-primary">'
                   . '<i class="ti ti-tools me-1"></i>' . __('MTTO\'s', 'glpiticketreportsign') . '</a>';
            } else {
                $tip = $closed
                    ? __('Closed tickets cannot have new report versions.', 'glpiticketreportsign')
                    : __('Available once the ticket is resolved with solution type "Mantenimiento Preventivo".', 'glpiticketreportsign');
                echo '<button type="button" class="btn btn-outline-secondary" disabled '
                   . 'title="' . htmlspecialchars($tip, ENT_QUOTES) . '">'
                   . '<i class="ti ti-tools me-1"></i>' . __('MTTO\'s', 'glpiticketreportsign') . '</button>';
            }

            echo '</div>';
        }
        echo '</div>';

        if ($closed) {
            echo '<div class="alert alert-info">'
               . '<i class="ti ti-info-circle me-1"></i>'
               . __('This ticket is closed. Existing reports can still be downloaded and signed, but no new versions can be generated.', 'glpiticketreportsign')
               . '</div>';
        }

        if ($reports === []) {
            echo '<div class="alert alert-secondary">'
               . __('No report generated yet for this ticket.', 'glpiticketreportsign')
               . '</div>';
            echo '</div>';
            return;
        }

        // Two-column responsive layout:
        //   - Desktop (col-lg-5 / col-lg-7): versions list on the left,
        //     preview on the right.
        //   - Mobile / tablet (single col stacked): list on top,
        //     preview below — the list is short and the preview area
        //     consumes the remaining viewport.
        $previewPdfUrl = $base . '/ajax/pdf_bytes.php?id=' . (int) $latest['id'];

        echo '<div class="row g-3">';

        // -------- Left column: versions list ------------------
        echo '<div class="col-lg-5">';
        echo '<div class="card h-100">';
        echo '<div class="card-header py-2"><strong>'
           . sprintf(__('All versions (%d)', 'glpiticketreportsign'), count($reports))
           . '</strong></div>';
        echo '<div class="list-group list-group-flush" id="trVersionList">';

        foreach ($reports as $i => $r) {
            $rid     = (int) $r['id'];
            $needs   = self::needsAnotherSignature($r);
            $badge   = self::stateBadge($r);
            $pdfUrl  = $base . '/ajax/pdf_bytes.php?id=' . $rid;
            $dlUrl   = $base . '/front/download.php?id=' . $rid;
            $signUrl = $base . '/front/sign.form.php?id=' . $rid;
            $mailUrl = $base . '/front/email.form.php?id=' . $rid;
            $active  = $i === 0 ? ' active' : '';

            echo '<button type="button" '
               . 'class="list-group-item list-group-item-action' . $active . '" '
               . 'data-tr-preview="' . htmlspecialchars($pdfUrl, ENT_QUOTES) . '" '
               . 'data-tr-version="' . (int) $r['version'] . '">';
            echo '<div class="d-flex justify-content-between align-items-start mb-1 gap-2">';
            echo '  <strong>' . sprintf(__('Report #%d', 'glpiticketreportsign'), (int) $r['version']) . '</strong>';
            echo '  ' . $badge;
            echo '</div>';
            echo '<div class="text-muted small mb-2">'
               . htmlspecialchars((string) ($r['date_mod'] ?: $r['date_creation']))
               . '</div>';
            echo '<div class="d-flex flex-wrap gap-1">';
            echo '  <a href="' . htmlspecialchars($dlUrl) . '" target="_blank" '
               . 'class="btn btn-sm btn-outline-secondary" onclick="event.stopPropagation()">'
               . '<i class="ti ti-download me-1"></i>' . __('Download', 'glpiticketreportsign') . '</a>';
            if ($needs && $canUpdate) {
                echo '  <a href="' . htmlspecialchars($signUrl) . '" '
                   . 'class="btn btn-sm btn-primary" onclick="event.stopPropagation()">'
                   . '<i class="ti ti-signature me-1"></i>' . __('Sign', 'glpiticketreportsign') . '</a>';
                echo '  <a href="' . htmlspecialchars($mailUrl) . '" '
                   . 'class="btn btn-sm btn-outline-primary" onclick="event.stopPropagation()">'
                   . '<i class="ti ti-mail me-1"></i>' . __('Email', 'glpiticketreportsign') . '</a>';
            }
            echo '</div>';
            echo '</button>';
        }
        echo '</div></div></div>'; // /list / card / col

        // -------- Right column: preview ------------------------
        echo '<div class="col-lg-7">';
        echo '<div class="card h-100"><div class="card-body p-2">';
        echo '<div id="trPreviewWrap" '
           . 'data-pdf-url="' . htmlspecialchars($previewPdfUrl, ENT_QUOTES) . '" '
           . 'data-pdfjs="'   . htmlspecialchars($vendor['pdfJs'],   ENT_QUOTES) . '" '
           . 'data-pdfworker="' . htmlspecialchars($vendor['pdfWorker'], ENT_QUOTES) . '" '
           . 'style="overflow:auto; max-height:75vh; background:#f8f9fa; padding:8px; border:1px solid #ddd; border-radius:4px">'
           . '<div class="text-muted text-center p-4">' . __('Loading preview…', 'glpiticketreportsign') . '</div>'
           . '</div>';
        echo '</div></div></div>'; // /card-body / card / col

        echo '</div>'; // /row

        echo '</div>'; // .glpiticketreportsign-tab

        self::emitPreviewScript();
    }

    private static function emitPreviewScript(): void
    {
        // Inline preview bootstrap. We poll for the wrap element a
        // few times because GLPI's tab AJAX may execute scripts
        // before the DOM is fully painted. Also wires the version-
        // list buttons (when present) so clicking one switches the
        // preview's PDF.
        echo '<script>(function(){'
           . '  function findWrap(){ return document.getElementById("trPreviewWrap"); }'
           . '  function loadScript(src){ return new Promise(function(res,rej){'
           . '    var s=document.createElement("script"); s.src=src; s.async=true;'
           . '    s.onload=res; s.onerror=function(){rej(new Error("load fail "+src));};'
           . '    document.head.appendChild(s);'
           . '  });}'
           . '  async function render(wrap, url){'
           . '    wrap.innerHTML = "<div class=\\"text-muted text-center p-4\\">Cargando…</div>";'
           . '    try {'
           . '      if (!window.pdfjsLib) await loadScript(wrap.dataset.pdfjs);'
           . '      window.pdfjsLib.GlobalWorkerOptions.workerSrc = wrap.dataset.pdfworker;'
           . '      var pdf = await window.pdfjsLib.getDocument({url:url, withCredentials:true}).promise;'
           . '      wrap.innerHTML = "";'
           . '      var ratio = window.devicePixelRatio || 1;'
           . '      var targetW = wrap.clientWidth - 16 || 600;'
           . '      for (var p=1; p<=pdf.numPages; p++) {'
           . '        var page = await pdf.getPage(p);'
           . '        var vp1 = page.getViewport({scale:1});'
           . '        var scale = targetW / vp1.width;'
           . '        var vp = page.getViewport({scale:scale});'
           . '        var c = document.createElement("canvas");'
           . '        c.style.cssText = "display:block;margin:0 auto 8px;border:1px solid #ccc;background:#fff";'
           . '        c.width = Math.floor(vp.width * ratio);'
           . '        c.height = Math.floor(vp.height * ratio);'
           . '        c.style.width = vp.width + "px";'
           . '        c.style.height = vp.height + "px";'
           . '        wrap.appendChild(c);'
           . '        var ctx = c.getContext("2d");'
           . '        ctx.setTransform(ratio,0,0,ratio,0,0);'
           . '        await page.render({canvasContext:ctx, viewport:vp}).promise;'
           . '      }'
           . '    } catch(e) {'
           . '      wrap.innerHTML = "<div class=\\"alert alert-warning\\">Vista previa no disponible: "+(e.message||e)+"</div>";'
           . '    }'
           . '  }'
           . '  function wireList(wrap){'
           . '    var list = document.getElementById("trVersionList");'
           . '    if (!list) return;'
           . '    list.addEventListener("click", function(ev){'
           . '      var btn = ev.target.closest("[data-tr-preview]");'
           . '      if (!btn) return;'
           . '      ev.preventDefault();'
           . '      list.querySelectorAll(".list-group-item").forEach(function(el){ el.classList.remove("active"); });'
           . '      btn.classList.add("active");'
           . '      render(wrap, btn.dataset.trPreview);'
           . '    });'
           . '  }'
           . '  var tries = 0;'
           . '  (function poll(){'
           . '    var w = findWrap();'
           . '    if (w) { wireList(w); render(w, w.dataset.pdfUrl); }'
           . '    else if (tries++ < 50) { setTimeout(poll, 100); }'
           . '  })();'
           . '})();</script>';
    }

    /**
     * @param array<string,mixed> $r
     */
    private static function needsAnotherSignature(array $r): bool
    {
        $hasTech   = !empty($r['signature_tech']);
        $hasClient = !empty($r['signature_client']);
        return !($hasTech && $hasClient);
    }

    /**
     * @param array<string,mixed> $r
     */
    private static function stateBadge(array $r): string
    {
        $hasTech   = !empty($r['signature_tech']);
        $hasClient = !empty($r['signature_client']);

        // Short labels with icons so the pill stays on one line in
        // the narrow versions column. `text-dark` is forced on the
        // light backgrounds (info/warning) — Bootstrap's default
        // white-on-light gave the poor contrast you screenshotted.
        $cls = 'badge fs-7 text-nowrap';

        if ($hasTech && $hasClient) {
            return '<span class="' . $cls . ' bg-success">'
                 . '<i class="ti ti-check me-1"></i>' . __('Signed', 'glpiticketreportsign')
                 . '</span>';
        }
        if ($hasTech) {
            return '<span class="' . $cls . ' bg-info text-dark">'
                 . '<i class="ti ti-clock me-1"></i>' . __('Pending client signature', 'glpiticketreportsign')
                 . '</span>';
        }
        if ($hasClient) {
            return '<span class="' . $cls . ' bg-info text-dark">'
                 . '<i class="ti ti-clock me-1"></i>' . __('Pending technician signature', 'glpiticketreportsign')
                 . '</span>';
        }
        return '<span class="' . $cls . ' bg-warning text-dark">'
             . '<i class="ti ti-pencil me-1"></i>' . __('Draft', 'glpiticketreportsign')
             . '</span>';
    }
}
