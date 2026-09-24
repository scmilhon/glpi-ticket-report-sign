<?php
namespace GlpiPlugin\Glpiticketreportsign\Mail;

/**
 * Nueva Era Soluciones' branded email shell (CSS + HTML classes)
 * used only to build the DEFAULT content that
 * Installer::seedNotificationTemplates() writes into each
 * NotificationTemplate on first install — an admin can freely
 * rewrite any of it afterwards from Setup > Notifications >
 * Notification templates (the CSS lives on the template itself, the
 * markup on each language's translation), so nothing here is read
 * again once seeded.
 */
class EmailBranding
{
    public const CSS = <<<'EMAILCSS'
/* ========================================
   Nueva Era Soluciones - GLPI Email Template
   CSS Modular y Separado
   ======================================== */

/* ======= RESET Y BASES ======= */
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;
    background-color: #f0f2f5;
    padding: 20px 0;
    line-height: 1.6;
    color: #444;
}

/* ======= CONTENEDORES PRINCIPALES ======= */
.email-wrapper {
    width: 100%;
    background-color: #f0f2f5;
}

.email-container {
    max-width: 600px;
    margin: 0 auto;
    background-color: #ffffff;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
}

/* ======= BARRA GRADIENTE (VISIBLE) ======= */
.header-gradient {
    height: 8px;
    background: linear-gradient(90deg, #10c9c3 0%, #6a4c93 50%, #bf0050 100%);
    display: block;
}

/* ======= HEADER ======= */
.email-header {
    background-color: #1a1a1a;
    padding: 35px 20px;
    text-align: center;
    border-bottom: 1px solid #333;
}

.email-header img {
    max-width: 200px;
    height: auto;
    display: block;
    margin: 0 auto;
}

/* ======= CONTENIDO PRINCIPAL ======= */
.email-content {
    padding: 30px 20px;
    color: #444;
    line-height: 1.7;
}

/* ======= ALERTAS Y ESTADOS ======= */
.status-alert {
    background-color: #e7f9f8;
    border-left: 5px solid #10c9c3;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 25px;
}

.status-alert-title {
    color: #10c9c3;
    font-weight: bold;
    font-size: 16px;
    margin-bottom: 12px;
}

.status-url {
    margin: 10px 0;
    font-size: 13px;
    word-break: break-word;
    text-align: center;
}

.status-url a {
    color: #10c9c3;
    text-decoration: none;
    font-weight: 500;
}

.status-url a:hover {
    text-decoration: underline;
}

.qr-container {
    text-align: center;
    margin: 20px 0;
    padding: 15px;
    background: #f8f9fa;
    border-radius: 8px;
}

.qr-code {
    display: inline-block;
    padding: 10px;
    background: white;
    border: 2px solid #e0e0e0;
    border-radius: 6px;
}

.qr-label {
    font-size: 11px;
    color: #999;
    margin-top: 8px;
    font-style: italic;
}

.alert-warning {
    color: #666;
    font-size: 12px;
    margin: 10px 0;
}

/* ======= BOTONES ======= */
.btn-approve {
    display: inline-block;
    background-color: #bf0050;
    color: #ffffff !important;
    padding: 14px 30px;
    text-decoration: none;
    border-radius: 6px;
    font-weight: bold;
    margin: 15px 0;
    font-size: 14px;
    border: 2px solid #bf0050;
    transition: all 0.3s ease;
}

.btn-approve:hover {
    background-color: #9a003e;
    border-color: #9a003e;
}

/* ======= TABLAS DE INFORMACIÓN ======= */
.ticket-info {
    width: 100%;
    border-collapse: collapse;
    margin: 20px 0;
}

.ticket-info td {
    padding: 10px 0;
    border-bottom: 1px solid #f0f0f0;
    font-size: 14px;
}

.ticket-label {
    font-weight: bold;
    color: #777;
    width: 140px;
    vertical-align: top;
    padding-right: 15px;
}

.ticket-value {
    color: #333;
    word-break: break-word;
}

.status-value-highlight {
    color: #bf0050;
    font-weight: bold;
}

/* ======= CAJAS DE ÍTEMS ======= */
.items-box {
    background-color: #f8f9fa;
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    padding: 15px;
    margin: 20px 0;
}

.items-title {
    font-weight: bold;
    color: #10c9c3;
    margin-bottom: 12px;
    font-size: 14px;
}

.item-row {
    font-size: 13px;
    color: #555;
    padding: 8px 0;
    border-bottom: 1px dashed #ddd;
}

.item-row:last-child {
    border-bottom: none;
}

/* ======= CAJAS DE CONTENIDO ======= */
.content-box {
    background-color: #ffffff;
    border: 1px solid #e0e0e0;
    padding: 20px;
    border-radius: 8px;
    margin: 20px 0;
    font-size: 14px;
    line-height: 1.7;
}

.solution-box {
    background-color: #f0f0f0;
    border: 1px solid #ddd;
    border-radius: 8px;
    padding: 15px;
    margin: 20px 0;
    font-size: 13px;
}

.solution-title {
    font-weight: bold;
    color: #10c9c3;
    margin-bottom: 10px;
}

/* ======= TÍTULOS Y SECCIONES ======= */
h2.section-title {
    color: #1a1a1a;
    font-size: 18px;
    font-weight: bold;
    margin: 25px 0 15px 0;
    border-bottom: 2px solid #e0e0e0;
    padding-bottom: 10px;
}

h3.section-title {
    color: #1a1a1a;
    font-size: 16px;
    font-weight: bold;
    margin: 25px 0 15px 0;
}

/* ======= TIMELINE / SEGUIMIENTO ======= */
.timeline-container {
    margin: 20px 0;
}

.timeline-item {
    margin-bottom: 20px;
    padding-left: 20px;
    border-left: 2px solid #10c9c3;
    font-size: 13px;
    line-height: 1.7;
    position: relative;
}

.timeline-item::before {
    content: '';
    position: absolute;
    left: -7px;
    top: 0;
    width: 12px;
    height: 12px;
    background-color: #bf0050;
    border-radius: 50%;
    border: 2px solid #ffffff;
}

.timeline-date {
    color: #bf0050;
    font-weight: bold;
}

.timeline-author {
    font-weight: bold;
    margin-top: 5px;
}

.timeline-desc {
    color: #666;
    margin-top: 4px;
}

.timeline-meta {
    color: #999;
    font-style: italic;
    font-size: 11px;
    margin-top: 5px;
}

/* ======= RESUMEN Y CIERRE ======= */
.summary-line {
    margin-top: 25px;
    padding-top: 15px;
    border-top: 1px solid #f0f0f0;
    font-size: 12px;
    color: #777;
}

.closing {
    margin-top: 40px;
    line-height: 1.8;
    font-size: 14px;
}

.closing-signature {
    color: #10c9c3;
    font-weight: bold;
}

/* ======= TICKET URL Y QR ======= */
.ticket-url-section {
    text-align: center;
    margin: 25px 0;
    padding: 20px;
    background-color: #f8f9fa;
    border-radius: 8px;
}

.ticket-url-link {
    font-size: 12px;
    margin-bottom: 15px;
}

.ticket-url-link a {
    color: #10c9c3;
    text-decoration: none;
    word-break: break-all;
}

.ticket-qr-container {
    text-align: center;
    margin-top: 15px;
}

.ticket-qr-container img {
    max-width: 150px;
    height: auto;
    border-radius: 4px;
    border: 1px solid #e0e0e0;
    padding: 8px;
    background-color: #ffffff;
}

.ticket-qr-label {
    font-size: 11px;
    color: #777;
    margin-top: 8px;
    font-style: italic;
}

/* ======= NÚMERO DE CASO CENTRADO ======= */
.case-number-section {
    text-align: center;
    margin: 20px 0;
    padding: 15px;
    background: linear-gradient(90deg, #10c9c3 0%, #6a4c93 50%, #bf0050 100%);
    border-radius: 8px;
}

.case-number-label {
    color: #ffffff;
    font-size: 12px;
    opacity: 0.9;
}

.case-number-value {
    color: #ffffff;
    font-size: 24px;
    font-weight: bold;
    margin-top: 8px;
    letter-spacing: 2px;
}
.email-footer {
    background-color: #1a1a1a;
    color: #aaa;
    padding: 25px 20px;
    text-align: center;
    font-size: 12px;
    line-height: 1.8;
    border-top: 1px solid #333;
}

.email-footer a {
    color: #10c9c3;
    text-decoration: none;
    font-weight: 500;
}

.email-footer a:hover {
    text-decoration: underline;
}

.email-footer strong {
    color: #ffffff;
    display: block;
    font-size: 13px;
    margin-bottom: 8px;
}

/* ======= DIVISORES ======= */
.divider {
    border: none;
    border-top: 1px solid #c8e6e5;
    margin: 15px 0;
    display: block;
    height: 0;
}

/* ======= MEDIA QUERIES PARA MÓVIL ======= */
@media only screen and (max-width: 600px) {
    .email-container {
        width: 100% !important;
    }

    .email-content {
        padding: 20px 15px !important;
    }

    .email-header {
        padding: 20px 15px !important;
    }

    .email-footer {
        padding: 20px 15px !important;
    }

    .email-header img {
        max-width: 160px !important;
    }

    .ticket-label {
        display: block !important;
        width: 100% !important;
        padding-right: 0 !important;
        margin-bottom: 5px !important;
    }

    .ticket-info td {
        display: block !important;
        padding: 8px 0 !important;
    }

    .btn-approve {
        width: 100% !important;
        box-sizing: border-box !important;
        text-align: center !important;
        padding: 12px 20px !important;
    }

    h2.section-title {
        font-size: 16px !important;
    }

    h3.section-title {
        font-size: 14px !important;
    }
}

/* ======= IMPRESIÓN ======= */
@media print {
    body {
        background-color: #ffffff;
    }

    .email-wrapper {
        background-color: #ffffff;
    }

    .email-container {
        box-shadow: none;
        max-width: 100%;
    }

    .btn-approve {
        border: 1px solid #bf0050;
        color: #bf0050 !important;
        background-color: transparent;
    }
}
EMAILCSS;

    /**
     * The shared shell for all 3 of this plugin's templates: brand
     * header, a centered ticket-number band, an alert box for the
     * intro sentence, and the sign/view button. Simpler than the
     * full ticket-timeline layout this CSS was lifted from, since
     * these emails only ever carry one sentence and one link.
     *
     * The logo, company name and footer are NOT baked in here — they
     * come from Config::get()['email_logo_path'] /
     * ['email_footer_text'] (Setup > Ticket report styles > Email
     * branding) via the ##reportsign.logo##, ##reportsign.company_name##
     * and ##reportsign.footer## tags ReportMailer supplies at send
     * time, same "picker to path field" mechanism already used for
     * the PDF's logo. Falls back to plain text if no logo image is
     * configured.
     */
    public static function body(string $ticketLabel, string $title, string $intro, string $buttonLabel, string $expiry, string $qrCaption, string $otpLabel = ''): string
    {
        return <<<HTML
<div class="email-wrapper">
  <div class="email-container">
    <span class="header-gradient"></span>
    <div class="email-header">
      ##IFreportsign.logo##<img src="##reportsign.logo##" alt="##reportsign.company_name##" width="240">##ENDIFreportsign.logo####ELSEreportsign.logo##<div style="color:#ffffff;font-size:20px;font-weight:bold;letter-spacing:1px;">##reportsign.company_name##</div>##ENDELSEreportsign.logo##
    </div>
    <div class="email-content">
      <div class="case-number-section">
        <div class="case-number-label">{$ticketLabel}</div>
        <div class="case-number-value">##reportsign.ticket_id##</div>
      </div>
      ##IFreportsign.qr##
      <div class="qr-container">
        <div class="qr-code"><img src="##reportsign.qr##" width="200" height="200" alt="QR"></div>
        <div class="qr-label">{$qrCaption}</div>
      </div>
      ##ENDIFreportsign.qr##
      <div class="status-alert">
        <div class="status-alert-title">{$title}</div>
        <p>{$intro}</p>
      </div>
      <div style="text-align:center;">
        <a href="##reportsign.link##" class="btn-approve">{$buttonLabel}</a>
      </div>
      ##IFreportsign.otp##
      <div class="status-alert" style="border-left-color:#bf0050;">
        <div class="status-alert-title" style="color:#bf0050;">{$otpLabel}</div>
        <div style="text-align:center;font-size:28px;font-weight:bold;letter-spacing:6px;color:#1a1a1a;">##reportsign.otp##</div>
      </div>
      ##ENDIFreportsign.otp##
      <p class="alert-warning" style="text-align:center;">{$expiry}</p>
    </div>
    <div class="email-footer">
      ##reportsign.footer##
    </div>
  </div>
</div>
HTML;
    }

    public static function text(string $title, string $intro, string $buttonLabel, string $expiry, string $otpLabel = ''): string
    {
        $otpBlock = $otpLabel !== '' ? "\n\n" . $otpLabel . ' ##reportsign.otp##' : '';
        return $title . "\n\n" . $intro . "\n\n" . $buttonLabel . ': ##reportsign.link##'
             . $otpBlock
             . "\n\n" . $expiry
             . "\n\n--\n##reportsign.company_name##";
    }
}
