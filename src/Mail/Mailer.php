<?php
namespace GlpiPlugin\Glpiticketreportsign\Mail;

use GLPIMailer;

/**
 * Thin wrapper around GLPI's notification mailer used to deliver
 * the one-off "sign your report" link. We don't go through the
 * Notification framework here because the recipient may not be a
 * GLPI user (e.g. an external customer email).
 */
class Mailer
{
    public static function sendSigningLink(string $to, string $subject, string $bodyHtml): bool
    {
        $mail = new GLPIMailer();
        // GLPIMailer::AddAddress() forwards to Symfony's
        // Address::__construct(string $address, string $name = ''),
        // but its compatibility shim only fills in an argument it
        // actually received — omitting $name here resolves to null,
        // not the PHPMailer-style default '', and Address rejects
        // null with a TypeError. Pass '' explicitly.
        $mail->AddAddress($to, '');
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body    = $bodyHtml;
        // strip_tags() removes tag markup but not a <style> element's
        // own text content, so a stylesheet in the head would
        // otherwise leak into the plain-text fallback verbatim.
        $withoutStyle  = preg_replace('#<style\b[^>]*>.*?</style>#is', '', $bodyHtml) ?? $bodyHtml;
        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>'], "\n", $withoutStyle));
        return (bool) $mail->Send();
    }
}
