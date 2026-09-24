<?php
/**
 * Public, session-less signing page reached via the HMAC-token link
 * from "Send by email". This flow is for the CLIENT — the technician
 * has already signed in person (or will sign separately). The page
 * shows the report PDF and one signature canvas; the captured PNG is
 * posted to ajax/sign_submit.php with the token.
 */

use GlpiPlugin\Glpiticketreportsign\Pdf\ReportPdf;
use GlpiPlugin\Glpiticketreportsign\Report;
use GlpiPlugin\Glpiticketreportsign\Security\SigningToken;

include('../../../inc/includes.php');

$token = (string) ($_GET['t'] ?? '');
if ($token === '') {
    http_response_code(400);
    echo 'Missing token.';
    exit;
}
$verified = SigningToken::verify($token);
if ($verified === null) {
    http_response_code(403);
    echo 'This signing link is invalid or has expired.';
    exit;
}

$report = new Report();
if (!$report->getFromDB($verified['reports_id'])) {
    http_response_code(404);
    echo 'Report not found.';
    exit;
}

// Already has a client signature: the link was either the automatic
// "here is your report" resend (ReportMailer) or the client re-opened
// their own already-signed link — either way, show a read-only view
// instead of a signing form asking them to sign again. (A signature a
// technician captures in person is now gated up front by the signer's
// own 6-digit code — see front/sign.submit.php — instead of asking
// for a post-hoc acknowledgement here.)
$alreadySigned = !empty($report->fields['signature_client']);

$siblings = Report::rowsForVersion((int) $report->fields['tickets_id'], (int) $report->fields['version']);
if ($siblings === []) {
    $siblings = [$report->fields];
}

$pdfBytesUrl = plugin_glpiticketreportsign_web_dir() . '/ajax/pdf_bytes.php?id=' . $verified['reports_id'] . '&t=' . urlencode($token);
$submitUrl   = plugin_glpiticketreportsign_web_dir() . '/ajax/sign_submit.php';
$chooseUrl   = plugin_glpiticketreportsign_web_dir() . '/ajax/choose_report_email.php';
$base        = plugin_glpiticketreportsign_web_dir();
// GLPI 10's inc/includes.php checks POSTs to /ajax/ against the
// X-Glpi-Csrf-Token header specifically (not $_POST), unconditionally
// for every request — including this anonymous, session-less one.
// This works without being logged in: getNewCSRFToken() only writes
// to $_SESSION['glpicsrftokens'], tied to this visitor's own (never
// authenticated) PHP session, which persists across this page load
// and the fetch() calls below via the ordinary session cookie.
// Harmless no-op on GLPI 11/12, where these paths are already
// exempted from CSRF entirely (see setup.php's SessionManager
// registration) and this header is simply ignored.
$csrfToken = method_exists(Session::class, 'getNewCSRFToken') ? Session::getNewCSRFToken() : '';

?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title>Firmar informe</title>
<style>
  body { font-family: system-ui, sans-serif; margin: 0; padding: 16px; background: #f6f6f6; color: #222; }
  .card { background: #fff; max-width: 800px; margin: 0 auto; padding: 16px; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
  canvas#sig { display:block; width:100%; height:220px; border:2px dashed #888; border-radius:4px; touch-action:none; background:#fff; }
  .preview { width: 100%; max-height: 70vh; overflow:auto; border: 1px solid #ccc; padding:8px; background:#fff; margin-bottom:12px; }
  button { padding: 10px 14px; border-radius: 4px; border: 1px solid #555; background: #fff; }
  .primary { background: #2b6cb0; color: #fff; border-color: #2b6cb0; }
  .row { display: flex; gap: 8px; align-items: center; }
  input[type=text] { padding: 10px; border: 1px solid #aaa; border-radius: 4px; width: 100%; }
  label { display: block; margin: 12px 0 4px; font-weight: 600; }
</style>
</head>
<body>
<div class="card">
  <h2><?= $alreadySigned ? 'Su informe' : 'Firmar informe (Cliente)' ?></h2>

  <?php if (count($siblings) > 1): ?>
    <label>Ver versión</label>
    <div class="row" style="gap:8px; margin-bottom:8px">
      <?php foreach ($siblings as $sib):
        $sibLabel = ((string) ($sib['mode'] ?? ReportPdf::MODE_FULL)) === ReportPdf::MODE_CONDENSED ? 'Resumida' : 'Completa';
        $sibUrl   = plugin_glpiticketreportsign_web_dir() . '/ajax/pdf_bytes.php?id=' . (int) $sib['id'] . '&t=' . urlencode($token);
      ?>
        <a href="<?= htmlspecialchars($sibUrl) ?>" target="_blank" class="primary"
           style="display:inline-block;padding:8px 12px;border-radius:4px;background:#2b6cb0;color:#fff;text-decoration:none">
          Ver versión <?= htmlspecialchars($sibLabel) ?>
        </a>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <p style="margin:8px 0">
      <a href="<?= htmlspecialchars($pdfBytesUrl) ?>" target="_blank" class="primary"
         style="display:inline-block;padding:8px 12px;border-radius:4px;background:#2b6cb0;color:#fff;text-decoration:none">
        Ver el informe (PDF)
      </a>
    </p>
  <?php endif; ?>

  <?php if ($alreadySigned): ?>
    <p style="margin-top:12px;color:#080">Este informe ya fue firmado y confirmado. Puede descargarlo con los enlaces de arriba.</p>
  <?php else: ?>
    <label for="signerName">Su nombre</label>
    <input type="text" id="signerName" autocomplete="name">

    <label>Dibuje su firma</label>
    <canvas id="sig"></canvas>

    <div class="row" style="margin-top:12px">
      <label style="margin:0; display:flex; align-items:center; gap:6px; font-weight:normal">
        <input type="checkbox" id="clientConfirms"> Confirmo que esta firma es mía.
      </label>
    </div>

    <div class="row" style="margin-top:8px;justify-content:space-between">
      <button type="button" id="clearSig">Borrar</button>
      <button type="button" id="submitSig" class="primary">Firmar y guardar</button>
    </div>

    <div id="chooseVersion" style="display:none;margin-top:16px;padding-top:16px;border-top:1px solid #eee">
      <label>¿Qué versión desea recibir por correo como copia final?</label>
      <div class="row" style="gap:8px">
        <?php foreach ($siblings as $sib):
            $sibLabel = ((string) ($sib['mode'] ?? ReportPdf::MODE_FULL)) === ReportPdf::MODE_CONDENSED ? 'Resumida' : 'Completa';
        ?>
          <button type="button" class="chooseBtn" data-reports-id="<?= (int) $sib['id'] ?>"><?= htmlspecialchars($sibLabel) ?></button>
        <?php endforeach; ?>
      </div>
    </div>

    <p id="msg" style="margin-top:12px;color:#a00"></p>
  <?php endif; ?>
</div>

<script>
window.TicketReportSign = {
  reportId:    <?= (int) $verified['reports_id'] ?>,
  token:       <?= json_encode($token) ?>,
  submitUrl:   <?= json_encode($submitUrl) ?>,
  chooseUrl:   <?= json_encode($chooseUrl) ?>,
  csrfToken:   <?= json_encode($csrfToken) ?>
};
</script>
<?php
$jsFile  = __DIR__ . '/../public/js/sign-page.js';
$jsMtime = is_file($jsFile) ? (string) filemtime($jsFile) : (string) time();
?>
<script src="<?= htmlspecialchars($base . '/public/js/sign-page.js') ?>?v=<?= htmlspecialchars($jsMtime) ?>"></script>
</body>
</html>
