<?php
namespace GlpiPlugin\Glpiticketreportsign\Mail;

use GlpiPlugin\Glpiticketreportsign\Config;
use GlpiPlugin\Glpiticketreportsign\Security\SigningToken;

/**
 * Sends a requester a link to front/sign.php for one report — the
 * page itself decides whether to show the signing canvas or a
 * read-only view, based on whether that report is already signed
 * (see front/sign.php). Used for:
 *   - the automatic "please sign" email when the technician signs
 *     (front/sign.submit.php) — for a report born from a ticket
 *     resolution (status Solved/Closed, not an MTTO report), this is
 *     also the ONLY channel the in-person signing OTP goes out on
 *     (##reportsign.otp##, see $includeOtp below and
 *     front/sign.form.php's token gate),
 *   - the post-sign "send me this version" choice
 *     (ajax/choose_report_email.php),
 *   - the automatic resumido email when a ticket closes without a
 *     client signature (src/Hooks/OnTicketClosed.php).
 *
 * Subject/body come from a NotificationTemplate (Setup > Notifications
 * > Notification templates, one of the TEMPLATE_* names below) so an
 * admin can edit the wording without touching code — see
 * Installer::seedNotificationTemplates() for the seeded defaults and
 * TemplateRenderer for how the ##reportsign.*## tags are substituted.
 *
 * Every link here uses SigningToken::TTL_72H, distinct from the
 * manual "Send by email" button's 7-day token (front/email.submit.php),
 * which is unrelated and untouched.
 */
class ReportMailer
{
    // Doubles as the NotificationTemplate's `name` field — shown as-is
    // in Setup > Notifications > Notification templates, so these are
    // written as presentable labels rather than machine slugs.
    public const TEMPLATE_TECH_SIGNED       = 'Informe de ticket: firma del técnico';
    public const TEMPLATE_CHOOSE_VERSION    = 'Informe de ticket: reenvío de versión';
    public const TEMPLATE_CLOSED_UNSIGNED   = 'Informe de ticket: cierre sin firma';

    /**
     * @param string $templateName One of the TEMPLATE_* constants above.
     * @param bool $includeOtp Populates ##reportsign.otp## with the code
     *   minted alongside this link, so the template's own
     *   ##IFreportsign.otp## block can show it. Only meaningful for a
     *   report whose in-person signature is gated by that code (see
     *   front/sign.submit.php) — every other call leaves this false so
     *   that section of the (shared) template stays hidden.
     * @param string $signerName Recipient's display name, stored on the
     *   minted signlink (see SigningToken::mint()) so front/sign.form.php
     *   can pre-fill the "Name" field once the technician enters this
     *   same code.
     */
    public static function sendReportLink(int $reportId, string $recipient, int $ticketId, string $templateName, bool $includeOtp = false, string $signerName = ''): bool
    {
        global $CFG_GLPI;

        $token   = SigningToken::mint($reportId, $recipient, SigningToken::TTL_72H, $signerName);
        $otpCode = $includeOtp ? SigningToken::otpFor($token) : null;
        $signUrl = rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/')
                 . plugin_glpiticketreportsign_web_dir(false)
                 . '/front/sign.php?t=' . urlencode($token);

        // CommonGLPI::getFormURLWithID()'s $full=true only prepends
        // $CFG_GLPI['root_doc'] (the path prefix, e.g. a subdirectory
        // install) — not the scheme+host — so it has to be combined
        // with url_base the same way $signUrl is, to get a URL that
        // resolves outside the browser session that's sending this.
        $ticketUrl = rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/') . \Ticket::getFormURLWithID($ticketId, true);
        $qr        = QrCode::svgDataUri($ticketUrl);

        $cfg  = Config::get();
        $logo = EmailLogo::dataUri((string) ($cfg['email_logo_path'] ?? ''));

        $rendered = TemplateRenderer::render($templateName, [
            '##reportsign.ticket_id##'   => (string) $ticketId,
            '##reportsign.link##'        => htmlspecialchars($signUrl, ENT_QUOTES),
            // The QR points at the ticket's own back-office URL (not
            // the sign link) — for staff, or requesters with a
            // self-service portal account. Empty when bacon-qr-code
            // isn't available (GLPI 10); the template's own
            // ##IFreportsign.qr##...##ENDIFreportsign.qr## block
            // hides the whole section in that case.
            '##reportsign.qr##'          => $qr !== null ? htmlspecialchars($qr, ENT_QUOTES) : '',
            // Same "picker to path field" config as the PDF's logo
            // (Setup > Ticket report styles > Email branding), just a
            // separate value since the email header is dark and
            // usually needs a reversed/white logo variant. Falls back
            // to the company name as plain text when no image is set.
            '##reportsign.logo##'        => $logo !== null ? htmlspecialchars($logo, ENT_QUOTES) : '',
            '##reportsign.company_name##' => htmlspecialchars((string) ($cfg['company_name'] ?? ''), ENT_QUOTES),
            '##reportsign.footer##'      => (string) ($cfg['email_footer_text'] ?? ''),
            '##reportsign.otp##'         => $otpCode !== null ? htmlspecialchars($otpCode, ENT_QUOTES) : '',
        ]);

        return Mailer::sendSigningLink($recipient, (string) $rendered['subject'], (string) $rendered['html']);
    }
}
