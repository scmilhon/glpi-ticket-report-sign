<?php
/**
 * Plugin configuration screen — "edit document styles".
 *
 * Combines form rendering and POST handling in a single file so the
 * form posts back to itself. GLPI 11's global CheckCsrfListener
 * (fires at the Symfony kernel level, before any plugin code runs)
 * rejects cross-URL POSTs more aggressively than same-page POSTs.
 * Following the omada plugin's pattern — same file for GET and POST
 * — sidesteps that. The post body is consumed up front and the page
 * re-renders with a toast on success.
 *
 * Gated by the plugin's own right `plugin_glpiticketreportsign_config`. Admins
 * grant this right through Setup > Profiles > <profile> > Ticket
 * reports tab.
 */

use GlpiPlugin\Glpiticketreportsign\Config;
use GlpiPlugin\Glpiticketreportsign\Mail\ReportMailer;

// GLPI 11 emits a stray E_USER_WARNING from
// Glpi\Agent\Communication\AbstractRequest when a non-XML POST body
// passes through the kernel's request-body sniffing. That warning is
// harmless functionally but the rendered HTML escapes into the
// response BEFORE any header() call, breaking later Html::redirect()
// and feeding the browser a malformed XML page. Buffer + discard
// during bootstrap so the redirect actually works.
ob_start();
include('../../../inc/includes.php');
ob_end_clean();

/** @var array $CFG_GLPI */
global $CFG_GLPI;

Session::checkLoginUser();
if (!Config::hasRight(READ)) {
    Html::displayRightError();
}

// ----- POST handler (same file as the form) -----------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Config::hasRight(UPDATE)) {
        Html::displayRightError();
    }
    // CSRF is best-effort: GLPI 11's global listener already ran
    // before we got here. If it would have failed it would already
    // have thrown — by this point we know the token was accepted.
    if (!empty($_POST['_glpi_csrf_token']) && method_exists(Session::class, 'validateCSRF')) {
        try { Session::validateCSRF($_POST); } catch (\Throwable $e) { /* soft-fail */ }
    }

    $sanitizeHex = static function (string $v, string $fallback): string {
        $v = trim($v);
        return preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtoupper($v) : $fallback;
    };

    $clean = [
        'company_name'      => trim((string) ($_POST['company_name']     ?? '')),
        'company_nit'       => trim((string) ($_POST['company_nit']      ?? '')),
        'company_address'   => trim((string) ($_POST['company_address']  ?? '')),
        'company_website'   => trim((string) ($_POST['company_website']  ?? '')),
        'logo_path'         => trim((string) ($_POST['logo_path']        ?? '')),
        'disclaimer_title'  => trim((string) ($_POST['disclaimer_title'] ?? '')),
        'disclaimer_body'   => (string) ($_POST['disclaimer_body']       ?? ''),
        'footer_text'       => trim((string) ($_POST['footer_text']      ?? '')),
        'section_header_bg' => $sanitizeHex((string) ($_POST['section_header_bg'] ?? ''), '#E6E6E6'),
        'ticket_id_color'   => $sanitizeHex((string) ($_POST['ticket_id_color']   ?? ''), '#DC0000'),
        'draft_ttl_days'    => max(1, min(365, (int) ($_POST['draft_ttl_days'] ?? 15))),
        'email_logo_path'   => trim((string) ($_POST['email_logo_path']   ?? '')),
        'email_footer_text' => (string) ($_POST['email_footer_text'] ?? ''),
    ];

    Config::save($clean);
    Session::addMessageAfterRedirect(__('Document styles saved.', 'glpiticketreportsign'), false, INFO);
    // Redirect to an absolute plugin URL — $_SERVER['PHP_SELF']
    // resolves unpredictably on some IIS rewrite configurations
    // and lands users on the GLPI homepage.
    Html::redirect(plugin_glpiticketreportsign_web_dir() . '/front/config.form.php');
}

// ----- GET / form rendering ---------------------------------------
$cfg     = Config::get();
$canEdit = Config::hasRight(UPDATE);
// Removed in GLPI 12 (CSRF moved to Sec-Fetch-Site/Origin header
// validation) — guard so this page still renders there.
$csrf    = method_exists(Session::class, 'getNewCSRFToken')
    ? Session::getNewCSRFToken()
    : '';
// Post to the same URL — built from our GLPI-version-safe web-dir
// helper so it's stable across IIS / Apache / rewrite-flavour
// environments. Some IIS setups return an unusable $_SERVER['PHP_SELF'].
$saveUrl = plugin_glpiticketreportsign_web_dir() . '/front/config.form.php';

// Build a small picker of logos already present in GLPI so the
// admin doesn't have to remember the path.
// plugin_glpiticketreportsign_glpi_pics_dir() finds GLPI's pics/
// directory regardless of version — it moved under public/ from
// GLPI 11 on, while GLPI_ROOT itself still points at the framework
// root. We only scan pics/logos/ (GLPI's own dedicated logo folder):
// the root pics/ dir holds hundreds of unrelated UI/item-type icons
// that aren't meant to be used as a report letterhead, and listing
// those too just buried the handful of actual logos in noise.
$picsDir    = plugin_glpiticketreportsign_glpi_pics_dir();
$candidates = [];
if ($picsDir !== '') {
    $logosAbs = $picsDir . DIRECTORY_SEPARATOR . 'logos';
    if (is_dir($logosAbs)) {
        foreach (scandir($logosAbs) ?: [] as $f) {
            if (preg_match('/\.(png|jpe?g|gif)$/i', $f)) {
                $candidates['pics/logos/' . $f] = 'pics/logos/' . $f;
            }
        }
    }
}
if (defined('GLPI_PICTURE_DIR') && is_dir(GLPI_PICTURE_DIR)) {
    foreach (scandir(GLPI_PICTURE_DIR) ?: [] as $f) {
        if (preg_match('/\.(png|jpe?g|gif)$/i', $f)) {
            $candidates['_pictures/' . $f] = '_pictures/' . $f;
        }
    }
}
ksort($candidates);

// Links for the "edit wording in template" menu — one per seeded
// NotificationTemplate (Setup > Notifications > Notification
// templates), since that's where each email's actual subject/body
// wording lives; this page only controls the shared logo/footer.
// Short labels here are just for this menu — the templates' own
// (longer) presentable names are what actually shows in that list.
$emailTemplateLinks = [];
foreach ([
    ReportMailer::TEMPLATE_TECH_SIGNED       => __('Technician signed', 'glpiticketreportsign'),
    ReportMailer::TEMPLATE_CHOOSE_VERSION    => __('Resend chosen version', 'glpiticketreportsign'),
    ReportMailer::TEMPLATE_CLOSED_UNSIGNED   => __('Closed unsigned', 'glpiticketreportsign'),
] as $templateName => $shortLabel) {
    $tpl = new \NotificationTemplate();
    if ($tpl->getFromDBByCrit(['itemtype' => 'Ticket', 'name' => $templateName])) {
        $emailTemplateLinks[] = ['label' => $shortLabel, 'url' => \NotificationTemplate::getFormURLWithID($tpl->getID())];
    }
}
if ($emailTemplateLinks === []) {
    // Not seeded yet for some reason — fall back to the filtered list.
    $emailTemplateLinks[] = [
        'label' => __('Notification templates', 'glpiticketreportsign'),
        'url'   => \NotificationTemplate::getSearchURL() . '?criteria[0][link]=AND&criteria[0][field]=1&criteria[0][searchtype]=contains&criteria[0][value]=' . urlencode('Informe de ticket:'),
    ];
}

Html::header(__('Ticket report styles', 'glpiticketreportsign'), $saveUrl, 'config', 'glpiticketreportsign');
$pluginInfo = plugin_version_glpiticketreportsign();
$pluginName = (string) ($pluginInfo['name'] ?? 'Ticket Report & Sign');
?>
<div class="container-fluid pb-3" style="margin-top: .125rem;">
  <h2 class="mb-3">
    <i class="ti ti-signature me-2"></i>
    <?= __('Configuration') ?> <?= htmlspecialchars($pluginName, ENT_QUOTES) ?>
  </h2>
  <form method="post" action="<?= htmlspecialchars($saveUrl) ?>" id="trsConfigForm">
    <input type="hidden" name="_glpi_csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">

    <!-- Same markup/classes GLPI's own item tabs use (see e.g. a
         ticket's left tab list) — vertical nav-tabs on desktop,
         collapsing into the <select> below on narrow viewports —
         so this inherits the real theme styling instead of looking
         like a generic, flat Bootstrap nav-pills list. -->
    <div class="d-flex card-tabs flex-column flex-md-row vertical">
      <ul class="nav nav-tabs flex-row flex-md-column d-none d-md-block" id="trsTabsList" role="tablist">
        <li class="nav-item ms-0">
          <a class="nav-link justify-content-between px-3 py-2 active" id="trsNavDocument" data-bs-toggle="tab" data-bs-target="#trsTabDocument" href="#" role="tab">
            <span class="d-flex align-items-center"><i class="ti ti-file-text me-2"></i><?= __('Document', 'glpiticketreportsign') ?></span>
          </a>
        </li>
        <li class="nav-item ms-0">
          <a class="nav-link justify-content-between px-3 py-2" id="trsNavEmail" data-bs-toggle="tab" data-bs-target="#trsTabEmail" href="#" role="tab">
            <span class="d-flex align-items-center"><i class="ti ti-mail me-2"></i><?= __('Email', 'glpiticketreportsign') ?></span>
          </a>
        </li>
      </ul>
      <select class="form-select border-2 rounded-0 rounded-top d-md-none mb-2" id="trsTabsSelect">
        <option value="trsTabDocument" selected><?= __('Document', 'glpiticketreportsign') ?></option>
        <option value="trsTabEmail"><?= __('Email', 'glpiticketreportsign') ?></option>
      </select>
      <div class="tab-content p-2 pt-3 flex-grow-1 card border-start-0">
      <div class="tab-pane fade show active" id="trsTabDocument" role="tabpanel">
      <div class="trs-text text-muted mb-3">
        <?= __('Customize the header, footer, colours, disclaimer and draft retention used when generating ticket report PDFs.', 'glpiticketreportsign') ?>
      </div>
      <div class="row g-3">
      <div class="col-lg-7">
    <fieldset class="border rounded p-3 mb-4">
      <legend class="float-none w-auto px-2 h6"><?= __('Header — company info', 'glpiticketreportsign') ?></legend>
      <div class="trs-text text-muted small">
        <?= __('These values appear on every report. None of them are read from the GLPI database — edit them here.', 'glpiticketreportsign') ?>
      </div>
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label"><?= __('Company name', 'glpiticketreportsign') ?></label>
          <input type="text" class="form-control" name="company_name"
                 value="<?= htmlspecialchars((string) $cfg['company_name'], ENT_QUOTES) ?>"
                 <?= $canEdit ? '' : 'disabled' ?>>
        </div>
        <div class="col-md-6">
          <label class="form-label"><?= __('Tax ID (NIT)', 'glpiticketreportsign') ?></label>
          <input type="text" class="form-control" name="company_nit"
                 value="<?= htmlspecialchars((string) $cfg['company_nit'], ENT_QUOTES) ?>"
                 <?= $canEdit ? '' : 'disabled' ?>>
        </div>
        <div class="col-md-8">
          <label class="form-label"><?= __('Address', 'glpiticketreportsign') ?></label>
          <input type="text" class="form-control" name="company_address"
                 value="<?= htmlspecialchars((string) $cfg['company_address'], ENT_QUOTES) ?>"
                 <?= $canEdit ? '' : 'disabled' ?>>
        </div>
        <div class="col-md-4">
          <label class="form-label"><?= __('Website', 'glpiticketreportsign') ?></label>
          <input type="text" class="form-control" name="company_website"
                 value="<?= htmlspecialchars((string) $cfg['company_website'], ENT_QUOTES) ?>"
                 <?= $canEdit ? '' : 'disabled' ?>>
        </div>
      </div>
    </fieldset>

    <fieldset class="border rounded p-3 mb-4">
      <legend class="float-none w-auto px-2 h6"><?= __('Logo', 'glpiticketreportsign') ?></legend>
      <div class="trs-text text-muted small">
        <?= __('Path to the logo image, relative to the GLPI installation root. Pick one of the logos already present in your GLPI install, or type a custom path.', 'glpiticketreportsign') ?>
      </div>
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label"><?= __('Pick from existing GLPI logos', 'glpiticketreportsign') ?></label>
          <select class="form-select" id="logoPicker" <?= $canEdit ? '' : 'disabled' ?>>
            <option value=""><?= __('— choose to copy into the path field —', 'glpiticketreportsign') ?></option>
            <?php foreach ($candidates as $path => $label): ?>
              <option value="<?= htmlspecialchars($path, ENT_QUOTES) ?>"><?= htmlspecialchars($label, ENT_QUOTES) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label"><?= __('Logo path', 'glpiticketreportsign') ?></label>
          <input type="text" class="form-control" name="logo_path" id="logoPath"
                 value="<?= htmlspecialchars((string) $cfg['logo_path'], ENT_QUOTES) ?>"
                 <?= $canEdit ? '' : 'disabled' ?>>
        </div>
      </div>
    </fieldset>

    <fieldset class="border rounded p-3 mb-4">
      <legend class="float-none w-auto px-2 h6"><?= __('Colours', 'glpiticketreportsign') ?></legend>
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label"><?= __('Section header background', 'glpiticketreportsign') ?></label>
          <input type="color" class="form-control form-control-color" name="section_header_bg"
                 value="<?= htmlspecialchars((string) $cfg['section_header_bg'], ENT_QUOTES) ?>"
                 <?= $canEdit ? '' : 'disabled' ?>>
        </div>
        <div class="col-md-6">
          <label class="form-label"><?= __('Ticket ID colour (header)', 'glpiticketreportsign') ?></label>
          <input type="color" class="form-control form-control-color" name="ticket_id_color"
                 value="<?= htmlspecialchars((string) $cfg['ticket_id_color'], ENT_QUOTES) ?>"
                 <?= $canEdit ? '' : 'disabled' ?>>
        </div>
      </div>
    </fieldset>

    <fieldset class="border rounded p-3 mb-4">
      <legend class="float-none w-auto px-2 h6"><?= __('Disclaimer block', 'glpiticketreportsign') ?></legend>
      <div class="trs-text text-muted small mb-3">
        <?= __('Printed on its own page near the end of every report, right after the signature block. Leave the body empty to omit this section entirely.', 'glpiticketreportsign') ?>
      </div>
      <div class="mb-3">
        <label class="form-label"><?= __('Title', 'glpiticketreportsign') ?></label>
        <div class="trs-text text-muted small mb-1">
          <?= __('Short heading printed in the grey section bar above the body — e.g. your policy\'s name ("CUSTOMER-INDUCED DAMAGE", "LIMITED WARRANTY TERMS"...).', 'glpiticketreportsign') ?>
        </div>
        <input type="text" class="form-control" name="disclaimer_title"
               value="<?= htmlspecialchars((string) $cfg['disclaimer_title'], ENT_QUOTES) ?>"
               <?= $canEdit ? '' : 'disabled' ?>>
      </div>
      <div>
        <label class="form-label"><?= __('Body', 'glpiticketreportsign') ?></label>
        <div class="trs-text text-muted small mb-1">
          <?= __('Full legal text of your warranty/liability disclaimer, printed as-is (line breaks are kept). This plugin ships with no default wording — write (or paste) your own; it is only ever saved here, in your database, never in the plugin\'s code.', 'glpiticketreportsign') ?>
        </div>
        <textarea class="form-control" name="disclaimer_body" rows="12"
                  <?= $canEdit ? '' : 'disabled' ?>><?= htmlspecialchars((string) $cfg['disclaimer_body']) ?></textarea>
      </div>
    </fieldset>

    <fieldset class="border rounded p-3 mb-4">
      <legend class="float-none w-auto px-2 h6"><?= __('Footer & retention', 'glpiticketreportsign') ?></legend>
      <div class="row g-3">
        <div class="col-md-8">
          <label class="form-label"><?= __('Footer text', 'glpiticketreportsign') ?></label>
          <input type="text" class="form-control" name="footer_text"
                 value="<?= htmlspecialchars((string) $cfg['footer_text'], ENT_QUOTES) ?>"
                 <?= $canEdit ? '' : 'disabled' ?>>
          <div class="form-text">
            <?= __('Placeholders: {page} = current page, {pages} = total pages.', 'glpiticketreportsign') ?>
          </div>
        </div>
        <div class="col-md-4">
          <label class="form-label"><?= __('Draft retention (days)', 'glpiticketreportsign') ?></label>
          <input type="number" class="form-control" name="draft_ttl_days" min="1" max="365"
                 value="<?= (int) $cfg['draft_ttl_days'] ?>"
                 <?= $canEdit ? '' : 'disabled' ?>>
          <div class="form-text">
            <?= __('Unsigned drafts older than this many days are deleted by the daily cron.', 'glpiticketreportsign') ?>
          </div>
        </div>
      </div>
    </fieldset>
      </div>
      <!-- -------- Live preview, sticky — Document tab only --------- -->
      <div class="col-lg-5">
    <div class="card" id="trsPreviewCard" style="position: sticky; top: 10px;">
      <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <strong><i class="ti ti-eye me-1"></i><?= __('Live preview (approximate)', 'glpiticketreportsign') ?></strong>
        <span class="badge bg-secondary"><?= __('Not saved — for reference only', 'glpiticketreportsign') ?></span>
      </div>
      <div class="card-body">
        <div class="trs-text text-muted small mb-3">
          <?= __('Rough mockup of the PDF header, section bars, disclaimer and footer, updated live as you edit the fields on the left. It is an HTML approximation, not a pixel-perfect match — the real PDF is drawn by FPDF, with different fonts and spacing.', 'glpiticketreportsign') ?>
        </div>

        <div id="trsPvHeader" style="border:1px solid #ccc; padding:10px; display:flex; align-items:flex-start; gap:14px; background:#fff;">
          <div style="width:90px; flex-shrink:0;">
            <img id="trsPvLogo" src="" alt="" style="max-width:100%; max-height:60px; display:none;">
            <div id="trsPvLogoNote" class="text-muted" style="font-size:10px;"></div>
          </div>
          <div style="flex:1; text-align:center; min-width:0;">
            <div id="trsPvCompanyName" style="font-weight:bold; font-size:13px;">&nbsp;</div>
            <div id="trsPvCompanyNit" style="font-size:11px;"></div>
            <div id="trsPvCompanyAddress" style="font-size:10px;"></div>
            <div id="trsPvCompanyWebsite" style="font-size:10px;"></div>
          </div>
          <div style="width:80px; flex-shrink:0; text-align:center;">
            <div style="font-weight:bold; font-size:11px;"><?= __('Nº TICKET', 'glpiticketreportsign') ?></div>
            <div id="trsPvTicketId" style="font-weight:bold; font-size:18px;">1234</div>
            <div style="font-size:9px;"><?= htmlspecialchars(date('d/m/Y')) ?></div>
          </div>
        </div>

        <div id="trsPvSectionBar" style="margin-top:10px; padding:6px 10px; font-weight:bold; font-size:12px; text-align:center; border:1px solid #999;">
          <?= __('Follow-ups', 'glpiticketreportsign') ?>
        </div>

        <div id="trsPvDisclaimerWrap" style="margin-top:10px;">
          <div id="trsPvDisclaimerTitle" style="padding:6px 10px; font-weight:bold; font-size:12px; text-align:center; border:1px solid #999;"></div>
          <div id="trsPvDisclaimerBody" style="border:1px solid #999; border-top:0; padding:8px; font-size:11px; white-space:pre-wrap; max-height:220px; overflow:auto;"></div>
        </div>

        <div id="trsPvFooter" class="text-center text-muted small mt-3" style="font-style:italic;"></div>
      </div>
    </div>
      </div>
      </div>
      </div>
      <!-- -------- Email tab ---------------------------------------- -->
      <div class="tab-pane fade" id="trsTabEmail" role="tabpanel">
      <div class="trs-text text-muted mb-3">
        <?= __('Used in the emails this plugin sends (sign requests, resends, closed-without-signature copies) — separate from the PDF logo/footer above, since the email header is dark and usually needs a reversed/white logo.', 'glpiticketreportsign') ?>
      </div>
      <div class="row g-3">
      <div class="col-lg-7">
      <fieldset class="border rounded p-3 mb-4">
        <legend class="float-none w-auto px-2 h6"><?= __('Logo', 'glpiticketreportsign') ?></legend>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label"><?= __('Pick from existing GLPI logos', 'glpiticketreportsign') ?></label>
            <select class="form-select" id="emailLogoPicker" <?= $canEdit ? '' : 'disabled' ?>>
              <option value=""><?= __('— choose to copy into the path field —', 'glpiticketreportsign') ?></option>
              <?php foreach ($candidates as $path => $label): ?>
                <option value="<?= htmlspecialchars($path, ENT_QUOTES) ?>"><?= htmlspecialchars($label, ENT_QUOTES) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label"><?= __('Logo path', 'glpiticketreportsign') ?></label>
            <input type="text" class="form-control" name="email_logo_path" id="emailLogoPath"
                   value="<?= htmlspecialchars((string) $cfg['email_logo_path'], ENT_QUOTES) ?>"
                   <?= $canEdit ? '' : 'disabled' ?>>
            <div class="form-text">
              <?= __('Empty shows the company name as text instead of an image.', 'glpiticketreportsign') ?>
            </div>
          </div>
        </div>
      </fieldset>
      <fieldset class="border rounded p-3 mb-4">
        <legend class="float-none w-auto px-2 h6"><?= __('Email footer text', 'glpiticketreportsign') ?></legend>
        <div class="trs-text text-muted small mb-1">
          <?= __('Raw HTML, printed at the bottom of every email (company name, address, website…).', 'glpiticketreportsign') ?>
        </div>
        <textarea class="form-control" name="email_footer_text" rows="4"
                  <?= $canEdit ? '' : 'disabled' ?>><?= htmlspecialchars((string) $cfg['email_footer_text']) ?></textarea>
      </fieldset>
      </div>
      <!-- -------- Live preview, sticky — Email tab only ------------- -->
      <div class="col-lg-5">
        <div class="card" id="trsEmailPreviewCard" style="position: sticky; top: 10px;">
          <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <strong><i class="ti ti-eye me-1"></i><?= __('Live preview (approximate)', 'glpiticketreportsign') ?></strong>
            <span class="badge bg-secondary"><?= __('Not saved — for reference only', 'glpiticketreportsign') ?></span>
          </div>
          <div class="card-body">
            <div class="trs-text text-muted small mb-3">
              <?= __('Rough mockup of the email header and footer, updated live as you edit the fields on the left. The subject and body wording come from the notification template — see the menu below.', 'glpiticketreportsign') ?>
            </div>
            <div style="border:1px solid #ccc; border-radius:6px; overflow:hidden;">
              <div id="trsEmailPvHeader" style="background:#1a1a1a; padding:16px; text-align:center;">
                <img id="trsEmailPvLogo" src="" alt="" style="max-height:50px; max-width:100%; display:none;">
                <div id="trsEmailPvCompanyName" style="color:#fff; font-weight:bold; font-size:16px; display:none;"></div>
              </div>
              <div style="padding:20px; font-size:12px; color:#999; text-align:center; font-style:italic; background:#fafafa;">
                <?= __('(email body — set in the notification template)', 'glpiticketreportsign') ?>
              </div>
              <div id="trsEmailPvFooter" style="background:#1a1a1a; color:#aaa; padding:14px; text-align:center; font-size:11px;"></div>
            </div>
          </div>
        </div>
      </div>
      </div>
      </div>
      <?php if ($canEdit): ?>
        <div class="card-footer mx-n2 d-flex">
          <div class="d-flex align-items-center gap-2 ms-auto">
            <div class="dropdown" id="trsEmailTemplatesMenu" style="display:none;">
              <button class="btn btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="ti ti-pencil me-1"></i><?= __('Edit wording in template', 'glpiticketreportsign') ?>
              </button>
              <ul class="dropdown-menu dropdown-menu-end">
                <?php foreach ($emailTemplateLinks as $link): ?>
                  <li><a class="dropdown-item" href="<?= htmlspecialchars($link['url'], ENT_QUOTES) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($link['label'], ENT_QUOTES) ?></a></li>
                <?php endforeach; ?>
              </ul>
            </div>
            <button type="submit" class="btn btn-primary">
              <i class="ti ti-device-floppy"></i>
              <span><?= __('Save') ?></span>
            </button>
          </div>
        </div>
      <?php else: ?>
        <div class="alert alert-warning m-2">
          <?= __('Your profile only has read access to these settings.', 'glpiticketreportsign') ?>
        </div>
      <?php endif; ?>
      </div>
    </div>
  </form>

  <?php
  // GLPI's own Setup > Plugins list doesn't reliably surface the
  // 'homepage' value from plugin_version_glpiticketreportsign() for
  // manually-installed plugins on every GLPI version, so we show it
  // here too — a place we fully control. ($pluginInfo already computed
  // above, for the page title.)
  $homepage = (string) ($pluginInfo['homepage'] ?? '');
  ?>
  <div class="trs-text text-muted small mt-4 pt-3 border-top">
    <?= __('Ticket Report & Sign', 'glpiticketreportsign') ?>
    v<?= htmlspecialchars($pluginInfo['version'] ?? '', ENT_QUOTES) ?>
    <?php if ($homepage !== ''): ?>
      — <a href="<?= htmlspecialchars($homepage, ENT_QUOTES) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($homepage) ?></a>
    <?php endif; ?>
  </div>
</div>

<script>
(function () {
  // Keep the mobile <select> (shown below the md breakpoint, same as
  // GLPI's own item tabs) in sync with the desktop vertical nav-tabs.
  var list   = document.getElementById('trsTabsList');
  var select = document.getElementById('trsTabsSelect');
  var templatesMenu = document.getElementById('trsEmailTemplatesMenu');
  if (list && select && window.bootstrap) {
    list.querySelectorAll('[data-bs-toggle="tab"]').forEach(function (link) {
      link.addEventListener('shown.bs.tab', function () {
        select.value = link.getAttribute('data-bs-target').slice(1);
        // The "edit wording in template" menu only makes sense next
        // to the Email tab's own fields — hide it otherwise.
        if (templatesMenu) {
          templatesMenu.style.display = (link.id === 'trsNavEmail') ? '' : 'none';
        }
      });
    });
    select.addEventListener('change', function () {
      var link = list.querySelector('[data-bs-target="#' + select.value + '"]');
      if (link) { bootstrap.Tab.getOrCreateInstance(link).show(); }
    });
  }
})();

(function () {
  var picker = document.getElementById('logoPicker');
  var path   = document.getElementById('logoPath');
  if (picker && path) {
    picker.addEventListener('change', function () {
      if (picker.value) path.value = picker.value;
    });
  }

  var emailPicker = document.getElementById('emailLogoPicker');
  var emailPath   = document.getElementById('emailLogoPath');
  if (emailPicker && emailPath) {
    emailPicker.addEventListener('change', function () {
      if (emailPicker.value) emailPath.value = emailPicker.value;
    });
  }
})();

(function () {
  var form = document.getElementById('trsConfigForm');
  if (!form) return;

  var ROOT_DOC = <?= json_encode((string) ($CFG_GLPI['root_doc'] ?? '')) ?>;
  var LOGO_PREVIEW_UNAVAILABLE = <?= json_encode(__('Preview not available for this path — the real PDF will still use it.', 'glpiticketreportsign')) ?>;

  function fieldVal(name) {
    var el = form.querySelector('[name="' + name + '"]');
    return el ? el.value : '';
  }

  function update() {
    document.getElementById('trsPvCompanyName').textContent    = fieldVal('company_name')    || ' ';
    document.getElementById('trsPvCompanyNit').textContent     = fieldVal('company_nit')      ? ('NIT: ' + fieldVal('company_nit')) : '';
    document.getElementById('trsPvCompanyAddress').textContent = fieldVal('company_address');
    document.getElementById('trsPvCompanyWebsite').textContent = fieldVal('company_website');

    var bg = fieldVal('section_header_bg') || '#E6E6E6';
    document.getElementById('trsPvSectionBar').style.background = bg;

    var idColor = fieldVal('ticket_id_color') || '#DC0000';
    document.getElementById('trsPvTicketId').style.color = idColor;

    var dTitle = fieldVal('disclaimer_title');
    var dBody  = fieldVal('disclaimer_body');
    var wrap   = document.getElementById('trsPvDisclaimerWrap');
    if (dBody.trim() === '') {
      wrap.style.display = 'none';
    } else {
      wrap.style.display = '';
      var titleEl = document.getElementById('trsPvDisclaimerTitle');
      titleEl.textContent = dTitle || ' ';
      titleEl.style.background = bg;
      document.getElementById('trsPvDisclaimerBody').textContent = dBody;
    }

    var footerTpl = fieldVal('footer_text') || 'Página {page}/{pages}';
    document.getElementById('trsPvFooter').textContent =
      footerTpl.replace('{page}', '1').replace('{pages}', '3');

    var logoPath = fieldVal('logo_path');
    var img  = document.getElementById('trsPvLogo');
    var note = document.getElementById('trsPvLogoNote');
    img.style.display = 'none';
    img.removeAttribute('src');
    note.textContent = '';
    if (logoPath.indexOf('pics/') === 0) {
      img.src = ROOT_DOC + '/' + logoPath;
      img.style.display = '';
      img.onerror = function () {
        img.style.display = 'none';
        note.textContent = LOGO_PREVIEW_UNAVAILABLE;
      };
    } else if (logoPath !== '') {
      note.textContent = LOGO_PREVIEW_UNAVAILABLE;
    }

    // Email tab's own preview — same field names, different card.
    var emailLogoPath = fieldVal('email_logo_path');
    var emailImg  = document.getElementById('trsEmailPvLogo');
    var emailName = document.getElementById('trsEmailPvCompanyName');
    if (emailLogoPath.indexOf('pics/') === 0) {
      emailImg.src = ROOT_DOC + '/' + emailLogoPath;
      emailImg.style.display = '';
      emailName.style.display = 'none';
      emailImg.onerror = function () {
        emailImg.style.display = 'none';
        emailName.style.display = '';
      };
    } else if (emailLogoPath !== '') {
      // Custom path outside pics/ — can't preview it here either;
      // fall back to the company-name text, same as the real email would.
      emailImg.style.display = 'none';
      emailName.style.display = '';
    } else {
      emailImg.style.display = 'none';
      emailName.style.display = '';
    }
    emailName.textContent = fieldVal('company_name') || ' ';

    document.getElementById('trsEmailPvFooter').innerHTML = fieldVal('email_footer_text');
  }

  form.addEventListener('input', update);
  form.addEventListener('change', update);
  update();
})();
</script>
<?php
Html::footer();
