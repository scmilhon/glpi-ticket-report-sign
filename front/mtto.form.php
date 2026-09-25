<?php
/**
 * MTTO report picker. Lets the technician search the GLPI computer
 * inventory by serial number and pick which device the maintenance
 * report should be generated for. Choosing a row posts to
 * mtto.generate.php, which produces the PDF and redirects back to
 * the ticket.
 *
 * Reached from the "MTTO's" button on the ticket Report tab.
 */

use GlpiPlugin\Glpiticketreportsign\Profile;
use GlpiPlugin\Glpiticketreportsign\Security\Authorizer;

ob_start();
include('../../../inc/includes.php');
ob_end_clean();

/** @var array $CFG_GLPI */
global $CFG_GLPI;

Session::checkLoginUser();
if (!Profile::hasRight(CREATE)) {
    Html::displayRightError();
}

$ticketId = (int) ($_GET['id'] ?? 0);
$ticket   = new Ticket();
if ($ticketId <= 0 || !$ticket->getFromDB($ticketId)) {
    Html::displayErrorAndDie(__('Ticket not found', 'glpiticketreportsign'));
}
if (!Authorizer::canActOnTicket($ticketId)) {
    Html::displayRightError();
}
// Closed lock doesn't apply when the ticket is MTTO-eligible —
// otherwise it'd block the very workflow this form is for.
if (Authorizer::isTicketClosed($ticketId) && !Authorizer::isMaintenanceTicket($ticketId)) {
    Session::addMessageAfterRedirect(
        __('Cannot generate a report on a closed ticket.', 'glpiticketreportsign'),
        false,
        ERROR
    );
    Html::back();
}
if (!Authorizer::isMaintenanceTicket($ticketId)) {
    Session::addMessageAfterRedirect(
        __('Available once the ticket is resolved with solution type "Mantenimiento Preventivo".', 'glpiticketreportsign'),
        false,
        ERROR
    );
    Html::back();
}

$base       = plugin_glpiticketreportsign_web_dir();
$generateUrl = $base . '/front/mtto.generate.php';
// root_doc (relative), not url_base — see the note in
// front/generate.php about url_base resolving wrong in some setups.
$ticketUrl   = rtrim((string) ($CFG_GLPI['root_doc'] ?? ''), '/') . '/front/ticket.form.php?id=' . $ticketId;
// Removed in GLPI 12 (CSRF moved to Sec-Fetch-Site/Origin header
// validation) — guard so this page still renders there.
$csrf        = method_exists(Session::class, 'getNewCSRFToken')
    ? Session::getNewCSRFToken()
    : '';
$query       = trim((string) ($_GET['q'] ?? ''));

// Search results — only run the query when the user submitted
// something. LIKE on serial first, fall back to name/otherserial.
$rows = [];
if ($query !== '') {
    global $DB;
    $iter = $DB->request([
        'SELECT' => ['c.id', 'c.name', 'c.serial', 'c.otherserial',
                     't.name AS type_name', 'm.name AS model_name'],
        'FROM'   => 'glpi_computers AS c',
        'LEFT JOIN' => [
            'glpi_computertypes  AS t' => ['ON' => ['c' => 'computertypes_id',  't' => 'id']],
            'glpi_computermodels AS m' => ['ON' => ['c' => 'computermodels_id', 'm' => 'id']],
        ],
        'WHERE'  => [
            'c.is_deleted' => 0,
            'c.is_template' => 0,
            // Without this, any technician authorised on ONE ticket
            // could enumerate every computer in every entity of the
            // instance by serial/name/inventory number — exactly the
            // identifiers an attacker would want to pivot with.
            'OR' => [
                ['c.serial'      => ['LIKE', '%' . $query . '%']],
                ['c.otherserial' => ['LIKE', '%' . $query . '%']],
                ['c.name'        => ['LIKE', '%' . $query . '%']],
            ],
        ] + getEntitiesRestrictCriteria('c', '', '', true),
        'ORDER'  => 'c.name ASC',
        'LIMIT'  => 30,
    ]);
    foreach ($iter as $r) {
        $rows[] = $r;
    }
}

Html::header(__('MTTO report', 'glpiticketreportsign'), '', 'helpdesk', 'ticket');
?>
<div class="container py-4" style="max-width: 1000px">
  <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <div>
      <h2 class="mb-1"><i class="ti ti-tools me-1"></i><?= __('MTTO report', 'glpiticketreportsign') ?></h2>
      <div class="trs-text text-muted mb-0">
        <?= sprintf(__('Pick the computer the maintenance report is for — ticket #%d', 'glpiticketreportsign'),
            $ticketId) ?>
      </div>
    </div>
    <a class="btn btn-outline-secondary" href="<?= htmlspecialchars($ticketUrl) ?>">
      <i class="ti ti-arrow-left me-1"></i><?= __('Back to ticket', 'glpiticketreportsign') ?>
    </a>
  </div>

  <form method="get" action="" class="mb-3">
    <input type="hidden" name="id" value="<?= $ticketId ?>">
    <div class="input-group">
      <span class="input-group-text"><i class="ti ti-search"></i></span>
      <input type="text" name="q" class="form-control" autofocus
             value="<?= htmlspecialchars($query, ENT_QUOTES) ?>"
             placeholder="<?= htmlspecialchars(__('Serial number, name, alternate serial…', 'glpiticketreportsign'), ENT_QUOTES) ?>">
      <button type="submit" class="btn btn-primary"><?= __('Search', 'glpiticketreportsign') ?></button>
    </div>
  </form>

  <?php if ($query === ''): ?>
    <div class="alert alert-secondary">
      <?= __('Type a serial number above to find computers in the GLPI asset inventory.', 'glpiticketreportsign') ?>
    </div>
  <?php elseif ($rows === []): ?>
    <div class="alert alert-warning">
      <?= sprintf(__('No computer matches "%s".', 'glpiticketreportsign'), htmlspecialchars($query)) ?>
    </div>
  <?php else: ?>
    <div class="card">
      <table class="table table-sm table-hover mb-0 align-middle">
        <thead>
          <tr>
            <th><?= __('Serial number', 'glpiticketreportsign') ?></th>
            <th><?= __('Name') ?></th>
            <th><?= __('Type') ?></th>
            <th><?= __('Model') ?></th>
            <th class="text-end"></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><code><?= htmlspecialchars((string) ($r['serial'] ?: '-')) ?></code></td>
            <td><?= htmlspecialchars((string) ($r['name'] ?: '-')) ?></td>
            <td><?= htmlspecialchars((string) ($r['type_name'] ?: '-')) ?></td>
            <td><?= htmlspecialchars((string) ($r['model_name'] ?: '-')) ?></td>
            <td class="text-end">
              <form method="post" action="<?= htmlspecialchars($generateUrl) ?>" class="d-inline">
                <input type="hidden" name="tickets_id"   value="<?= $ticketId ?>">
                <input type="hidden" name="computers_id" value="<?= (int) $r['id'] ?>">
                <input type="hidden" name="_glpi_csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
                <button type="submit" class="btn btn-sm btn-primary">
                  <i class="ti ti-file-text me-1"></i><?= __('Select', 'glpiticketreportsign') ?>
                </button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<?php
Html::footer();
