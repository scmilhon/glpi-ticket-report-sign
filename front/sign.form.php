<?php
/**
 * Authenticated signing page — captures the technician and/or
 * client signatures. Each side has its own *Save* button so the
 * two parties can sign at different times.
 *
 * Workflow rule: the client may only sign AFTER the technician has
 * signed. The client canvas is disabled in the UI until a tech
 * signature is on file, and the server rejects a client-only POST
 * that arrives before any tech signature.
 */

use GlpiPlugin\Glpiticketreportsign\Profile;
use GlpiPlugin\Glpiticketreportsign\Report;
use GlpiPlugin\Glpiticketreportsign\Security\Authorizer;
use GlpiPlugin\Glpiticketreportsign\Signature\SavedSignature;

ob_start();
include('../../../inc/includes.php');
ob_end_clean();

/** @var array $CFG_GLPI */
global $CFG_GLPI;

Session::checkLoginUser();

$reportId = (int) ($_GET['id'] ?? 0);
$report   = new Report();
if ($reportId <= 0 || !$report->getFromDB($reportId)) {
    Html::displayErrorAndDie(__('Report not found', 'glpiticketreportsign'));
}

$ticketId      = (int) $report->fields['tickets_id'];
$isRequester   = Authorizer::isTicketRequester($ticketId);
$isTechSession = !$isRequester && Profile::hasRight(UPDATE) && Authorizer::canActOnTicket($ticketId);

$ticket = new Ticket();
if (!$ticket->getFromDB($ticketId)) {
    Html::displayErrorAndDie(__('Ticket not found', 'glpiticketreportsign'));
}
// Same scope as the server-side gate in front/sign.submit.php: only a
// report born from a ticket resolution (status Solved/Closed), not an
// MTTO report, requires the signer's code before a technician can
// draw their signature in person.
$isMtto         = (int) ($report->fields['computers_id'] ?? 0) > 0;
$statusGated    = in_array((int) $ticket->fields['status'], [Ticket::SOLVED, Ticket::CLOSED], true);
$otpFlowApplies = !$isMtto && $statusGated;

// Allow access if the user is either:
//   - a technician with the UPDATE right (full page, both panels)
//   - a requester on the ticket (client-only panel)
if (!$isTechSession && !$isRequester) {
    Html::displayRightError();
}

$techAlreadySigned   = !empty($report->fields['signature_tech']);
$clientAlreadySigned = !empty($report->fields['signature_client']);
// Client self-service flag — disables the tech panel entirely and
// the JS will only initialise the client pad.
$clientOnly          = $isRequester && !$isTechSession;
// Both a technician (in-person visit — the client signs right there
// on the technician's device) and the requester themselves may draw
// the client signature; when it's the technician doing it on a report
// gated by $otpFlowApplies below, the signer's own 6-digit code is the
// anti-forgery measure (front/sign.submit.php enforces it server-side)
// — not who's allowed to draw it here.
$clientInteractive   = $techAlreadySigned;
// The technician must type and verify the signer's 6-digit code
// before the Name field / canvas even appear (see trClientFields
// below); the code is also re-checked server-side on save (front/
// sign.submit.php). The requester signing from their own session is
// never gated.
$needsOtp            = $techAlreadySigned && $isTechSession && $otpFlowApplies && !$clientAlreadySigned;
$techExistingName    = (string) ($report->fields['signer_name']        ?? '');
$clientExistingName  = (string) ($report->fields['signer_client_name'] ?? '');

// A technician who already signed a previous report doesn't have to
// redraw every time: pre-load their saved signature into the canvas
// when this particular report doesn't have its own yet.
$savedSignature = ($isTechSession && !$techAlreadySigned)
    ? SavedSignature::get((int) $_SESSION['glpiID'])
    : null;

$base       = plugin_glpiticketreportsign_web_dir();
$pdfUrl     = $base . '/ajax/pdf_bytes.php?id=' . $reportId;
$downloadUrl = $base . '/front/download.php?id=' . $reportId;
$submitUrl  = $base . '/front/sign.submit.php';
// Removed in GLPI 12 (CSRF moved to Sec-Fetch-Site/Origin header
// validation) — guard so this page still renders there.
$csrf       = method_exists(Session::class, 'getNewCSRFToken')
    ? Session::getNewCSRFToken()
    : '';
// root_doc (relative) instead of url_base (absolute, admin-configured
// and easy to get out of sync with the real deployment) — this link
// is only ever followed from within the same browser session, so a
// relative path is both simpler and more reliable. See the note in
// front/generate.php about url_base resolving wrong in some setups.
$ticketUrl  = rtrim((string) ($CFG_GLPI['root_doc'] ?? ''), '/') . '/front/ticket.form.php?id=' . (int) $report->fields['tickets_id'];

// Pre-fill names.
$techDefault   = '';
$u = new User();
if ($u->getFromDB((int) $_SESSION['glpiID'])) {
    $techDefault = formatUserName(
        (int) $u->fields['id'],
        (string) ($u->fields['name']      ?? ''),
        (string) ($u->fields['realname']  ?? ''),
        (string) ($u->fields['firstname'] ?? '')
    );
}
$clientDefault = '';
global $DB;
$req = $DB->request([
    'SELECT' => ['u.realname', 'u.firstname', 'u.name', 'u.id'],
    'FROM'   => 'glpi_tickets_users AS tu',
    'INNER JOIN' => ['glpi_users AS u' => ['ON' => ['tu' => 'users_id', 'u' => 'id']]],
    'WHERE'  => ['tu.tickets_id' => (int) $report->fields['tickets_id'], 'tu.type' => \CommonITILActor::REQUESTER],
    'LIMIT'  => 1,
])->current();
if (is_array($req)) {
    $clientDefault = formatUserName(
        (int) $req['id'],
        (string) ($req['name']      ?? ''),
        (string) ($req['realname']  ?? ''),
        (string) ($req['firstname'] ?? '')
    );
}

Html::header(__('Sign report', 'glpiticketreportsign'), '', 'helpdesk', 'ticket');
?>
<div class="container py-4" style="max-width: 1000px">
  <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <div>
      <h2 class="mb-1"><i class="ti ti-signature me-1"></i><?= __('Sign report', 'glpiticketreportsign') ?></h2>
      <div class="trs-text text-muted mb-0">
        <?= sprintf(__('Ticket #%d — version %d', 'glpiticketreportsign'),
            (int) $report->fields['tickets_id'],
            (int) $report->fields['version']) ?>
      </div>
    </div>
    <a class="btn btn-outline-secondary" href="<?= htmlspecialchars($ticketUrl) ?>">
      <i class="ti ti-arrow-left me-1"></i><?= __('Back to ticket', 'glpiticketreportsign') ?>
    </a>
  </div>

  <form method="post" action="<?= htmlspecialchars($submitUrl) ?>" id="trSignForm">
    <input type="hidden" name="reports_id" value="<?= $reportId ?>">
    <input type="hidden" name="_glpi_csrf_token" value="<?= htmlspecialchars($csrf) ?>">
    <input type="hidden" name="which" id="trWhich" value="">
    <input type="hidden" name="signature_tech"   id="trSigTechField">
    <input type="hidden" name="signature_client" id="trSigClientField">
    <!-- Server-side guard mirror: 1 when tech is on file, so the
         server can also reject a client-only POST sent in spite of
         the disabled UI. -->
    <input type="hidden" name="tech_already_signed" value="<?= $techAlreadySigned ? '1' : '0' ?>">

    <div class="row g-3">
      <?php if (!$clientOnly): ?>
      <!-- Technician panel — hidden in client-only mode --------- -->
      <div class="col-lg-6">
        <div class="card h-100 <?= $techAlreadySigned ? 'border-success' : 'border-primary' ?>">
          <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
              <i class="ti ti-user-circle me-1"></i>
              <?= __('Technician', 'glpiticketreportsign') ?>
            </h5>
            <?php if ($techAlreadySigned): ?>
              <span class="badge bg-success">
                <i class="ti ti-check me-1"></i><?= __('Already signed', 'glpiticketreportsign') ?>
              </span>
            <?php endif; ?>
          </div>
          <div class="card-body">
            <?php if ($techAlreadySigned): ?>
              <div class="alert alert-light border py-2 small mb-3">
                <?= sprintf(__('Currently signed by: %s. Drawing a new signature will replace it.', 'glpiticketreportsign'),
                    htmlspecialchars($techExistingName ?: __('unknown', 'glpiticketreportsign'))) ?>
              </div>
            <?php endif; ?>
            <?php if ($savedSignature !== null): ?>
              <div class="alert alert-light border py-2 small mb-3" id="trSavedSigHint">
                <i class="ti ti-info-circle me-1"></i>
                <?= __('Loaded your saved signature — just save, or clear it to draw a new one.', 'glpiticketreportsign') ?>
              </div>
            <?php endif; ?>

            <label class="form-label fw-semibold"><?= __('Name', 'glpiticketreportsign') ?></label>
            <input type="text" name="signer_tech_name" class="form-control mb-3"
                   value="<?= htmlspecialchars($techExistingName ?: ($savedSignature['signer_name'] ?? '') ?: $techDefault, ENT_QUOTES) ?>">

            <label class="form-label fw-semibold"><?= __('Draw signature', 'glpiticketreportsign') ?></label>
            <canvas id="trSigTech" class="trSigPad"></canvas>

            <div class="mt-3 d-flex gap-2 flex-wrap">
              <button type="button" class="btn btn-sm btn-outline-secondary" data-clear="trSigTech">
                <i class="ti ti-eraser me-1"></i><?= __('Clear', 'glpiticketreportsign') ?>
              </button>
              <?php if ($savedSignature !== null): ?>
                <button type="button" class="btn btn-sm btn-outline-danger" id="trDeleteSavedSig">
                  <i class="ti ti-trash me-1"></i><?= __('Delete saved signature', 'glpiticketreportsign') ?>
                </button>
              <?php endif; ?>
              <button type="button" class="btn btn-primary ms-auto" id="trSubmitTech">
                <i class="ti ti-device-floppy me-1"></i><?= __('Save technician signature', 'glpiticketreportsign') ?>
              </button>
            </div>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <!-- Client panel ----------------------------------------- -->
      <div class="<?= $clientOnly ? 'col-lg-12' : 'col-lg-6' ?>">
        <div class="card h-100 <?= $clientAlreadySigned ? 'border-success' : ($techAlreadySigned ? 'border-primary' : 'border-secondary opacity-75') ?>"
             id="trClientCard">
          <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
              <i class="ti ti-user me-1"></i>
              <?= __('Client', 'glpiticketreportsign') ?>
            </h5>
            <?php if ($clientAlreadySigned): ?>
              <span class="badge bg-success">
                <i class="ti ti-check me-1"></i><?= __('Already signed', 'glpiticketreportsign') ?>
              </span>
            <?php elseif (!$techAlreadySigned): ?>
              <span class="badge bg-secondary">
                <i class="ti ti-lock me-1"></i><?= __('Locked until technician signs', 'glpiticketreportsign') ?>
              </span>
            <?php endif; ?>
          </div>
          <div class="card-body">
            <?php if (!$techAlreadySigned): ?>
              <div class="alert alert-warning py-2 small mb-3">
                <i class="ti ti-alert-triangle me-1"></i>
                <?= __('The client can only sign after the technician has signed the report.', 'glpiticketreportsign') ?>
              </div>
            <?php elseif ($clientAlreadySigned): ?>
              <div class="alert alert-light border py-2 small mb-3">
                <?= sprintf(__('Currently signed by: %s. Drawing a new signature will replace it.', 'glpiticketreportsign'),
                    htmlspecialchars($clientExistingName ?: __('unknown', 'glpiticketreportsign'))) ?>
              </div>
            <?php elseif (!$clientInteractive): ?>
              <div class="alert alert-light border py-2 small mb-3">
                <i class="ti ti-info-circle me-1"></i>
                <?= __('Only the client can sign this side — from their own session, or the link emailed to them.', 'glpiticketreportsign') ?>
              </div>
            <?php endif; ?>

            <?php if ($needsOtp): ?>
              <div class="alert alert-warning py-2 small mb-3" id="trOtpNotice">
                <i class="ti ti-shield-lock me-1"></i>
                <?= __('Ask the signer for the 6-digit code sent to their email and enter it below.', 'glpiticketreportsign') ?>
              </div>
              <label class="form-label fw-semibold"><?= __('Verification code', 'glpiticketreportsign') ?></label>
              <div class="d-flex gap-2 mb-2">
                <input type="text" name="otp_code" id="trOtpCode" class="form-control"
                       inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="one-time-code" placeholder="000000">
                <button type="button" class="btn btn-outline-primary text-nowrap" id="trOtpVerify">
                  <?= __('Verify', 'glpiticketreportsign') ?>
                </button>
              </div>
              <div id="trOtpVerifyMsg" class="small mb-2"></div>
              <div class="mb-3">
                <button type="button" class="btn btn-link btn-sm p-0" id="trOtpAdhocToggle">
                  <?= __('Signer is not one of the requesters?', 'glpiticketreportsign') ?>
                </button>
                <div id="trOtpAdhocBox" class="mt-2 d-none gap-2 flex-wrap">
                  <input type="text" id="trOtpAdhocName" class="form-control form-control-sm" style="max-width:200px"
                         placeholder="<?= htmlspecialchars(__('Signer name', 'glpiticketreportsign'), ENT_QUOTES) ?>">
                  <input type="email" id="trOtpAdhocEmail" class="form-control form-control-sm" style="max-width:220px"
                         placeholder="<?= htmlspecialchars(__('Signer email', 'glpiticketreportsign'), ENT_QUOTES) ?>">
                  <button type="button" class="btn btn-sm btn-outline-primary text-nowrap" id="trOtpAdhocSend">
                    <?= __('Send code', 'glpiticketreportsign') ?>
                  </button>
                </div>
                <div id="trOtpAdhocMsg" class="small mt-1"></div>
              </div>
            <?php endif; ?>

            <div id="trClientFields" class="<?= $needsOtp ? 'd-none' : '' ?>">
              <label class="form-label fw-semibold"><?= __('Name', 'glpiticketreportsign') ?></label>
              <input type="text" name="signer_client_name" id="trSigClientName" class="form-control mb-3"
                     value="<?= htmlspecialchars($clientExistingName ?: ($needsOtp ? '' : $clientDefault), ENT_QUOTES) ?>"
                     <?= $clientInteractive ? '' : 'disabled' ?>>

              <label class="form-label fw-semibold"><?= __('Draw signature', 'glpiticketreportsign') ?></label>
              <canvas id="trSigClient" class="trSigPad <?= $clientInteractive ? '' : 'trDisabled' ?>"></canvas>

              <div class="mt-3 d-flex gap-2 flex-wrap">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-clear="trSigClient"
                        <?= $clientInteractive ? '' : 'disabled' ?>>
                  <i class="ti ti-eraser me-1"></i><?= __('Clear', 'glpiticketreportsign') ?>
                </button>
                <button type="button" class="btn btn-primary ms-auto" id="trSubmitClient"
                        <?= $clientInteractive ? '' : 'disabled' ?>>
                  <i class="ti ti-device-floppy me-1"></i><?= __('Save client signature', 'glpiticketreportsign') ?>
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div id="trStatus" class="mt-3 small text-muted"><?= __('Loading signature pad…', 'glpiticketreportsign') ?></div>
    <div id="trMsg" class="mt-2 text-danger"></div>
  </form>
</div>

<style>
canvas.trSigPad {
  display: block;
  width: 100%;
  height: 200px;
  background: #fff;
  border: 2px dashed #adb5bd;
  border-radius: 6px;
  touch-action: none;
}
canvas.trSigPad.trDisabled {
  background: #f5f5f5;
  border-style: solid;
  border-color: #dee2e6;
  pointer-events: none;
  opacity: 0.6;
}
.card.opacity-75 { opacity: 0.75; }
</style>
<?php
$jsFile  = __DIR__ . '/../public/js/sign-internal.js';
$jsMtime = is_file($jsFile) ? (string) filemtime($jsFile) : (string) time();
?>
<script>window.TicketReportSignPage = {
  techAlreadySigned: <?= $techAlreadySigned ? 'true' : 'false' ?>,
  clientOnly:        <?= $clientOnly ? 'true' : 'false' ?>,
  clientInteractive: <?= $clientInteractive ? 'true' : 'false' ?>,
  needsOtp:           <?= $needsOtp ? 'true' : 'false' ?>,
  reportId:           <?= $reportId ?>,
  mintWalkinUrl:      <?= json_encode($base . '/ajax/mint_walkin_token.php') ?>,
  verifyOtpUrl:       <?= json_encode($base . '/ajax/verify_client_otp.php') ?>,
  savedTechSignature: <?= $savedSignature !== null ? json_encode($savedSignature['signature_png']) : 'null' ?>,
  clearSavedSigUrl:   <?= json_encode($base . '/front/signature.clear.php') ?>,
  csrf:               <?= json_encode($csrf) ?>,
  // The signature already on file for THIS report — shown as-is so
  // "already signed" isn't just a badge over a blank canvas.
  existingTechSignature:   <?= $techAlreadySigned   ? json_encode((string) $report->fields['signature_tech'])   : 'null' ?>,
  existingClientSignature: <?= $clientAlreadySigned ? json_encode((string) $report->fields['signature_client']) : 'null' ?>
};</script>
<script src="<?= htmlspecialchars($base . '/public/js/sign-internal.js') ?>?v=<?= htmlspecialchars($jsMtime) ?>"></script>
<?php
Html::footer();
