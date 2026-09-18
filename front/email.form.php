<?php
/**
 * Plain form to enter the recipient's email and send a signing link.
 * No JavaScript required.
 */

use GlpiPlugin\Glpiticketreportsign\Profile;
use GlpiPlugin\Glpiticketreportsign\Report;
use GlpiPlugin\Glpiticketreportsign\Security\Authorizer;

include('../../../inc/includes.php');

/** @var array $CFG_GLPI */
global $CFG_GLPI;

Session::checkLoginUser();
if (!Profile::hasRight(UPDATE)) {
    Html::displayRightError();
}

$reportId = (int) ($_GET['id'] ?? 0);
$report   = new Report();
if ($reportId <= 0 || !$report->getFromDB($reportId)) {
    Html::displayErrorAndDie(__('Report not found', 'glpiticketreportsign'));
}
if (!Authorizer::canActOnTicket((int) $report->fields['tickets_id'])) {
    Html::displayRightError();
}

$base       = plugin_glpiticketreportsign_web_dir();
$submitUrl  = $base . '/front/email.submit.php';
// Removed in GLPI 12 (CSRF moved to Sec-Fetch-Site/Origin header
// validation) — guard so this page still renders there.
$csrf       = method_exists(Session::class, 'getNewCSRFToken')
    ? Session::getNewCSRFToken()
    : '';
// root_doc (relative), not url_base — see the note in
// front/generate.php about url_base resolving wrong in some setups.
$ticketUrl  = rtrim((string) ($CFG_GLPI['root_doc'] ?? ''), '/') . '/front/ticket.form.php?id=' . (int) $report->fields['tickets_id'];

Html::header(__('Send signing link', 'glpiticketreportsign'), '', 'helpdesk', 'ticket');
?>
<div class="container py-4" style="max-width: 600px">
  <h2><?= __('Send signing link by email', 'glpiticketreportsign') ?></h2>
  <div class="trs-text text-muted">
    <?= sprintf(__('Ticket #%d — version %d', 'glpiticketreportsign'),
        (int) $report->fields['tickets_id'],
        (int) $report->fields['version']) ?>
  </div>

  <form method="post" action="<?= htmlspecialchars($submitUrl) ?>">
    <input type="hidden" name="reports_id" value="<?= $reportId ?>">
    <input type="hidden" name="_glpi_csrf_token" value="<?= htmlspecialchars($csrf) ?>">

    <div class="mb-3">
      <label class="form-label"><?= __('Recipient email', 'glpiticketreportsign') ?></label>
      <input type="email" name="recipient_email" class="form-control" required>
    </div>

    <div class="d-flex gap-2">
      <a class="btn btn-link" href="<?= htmlspecialchars($ticketUrl) ?>"><?= __('Cancel') ?></a>
      <button type="submit" class="btn btn-primary ms-auto"><?= __('Send link', 'glpiticketreportsign') ?></button>
    </div>
  </form>
</div>
<?php
Html::footer();
