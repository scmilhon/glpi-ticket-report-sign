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
        $mail->AddAddress($to);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body    = $bodyHtml;
        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>'], "\n", $bodyHtml));
        return (bool) $mail->Send();
    }
}
