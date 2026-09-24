<?php
namespace GlpiPlugin\Glpiticketreportsign\Mail;

use NotificationTemplate;

/**
 * Renders one of this plugin's notification templates (seeded by
 * Installer::seedNotificationTemplates(), editable by an admin under
 * Setup > Notifications > Notification templates) without going
 * through GLPI's full NotificationTarget/recipient pipeline — the
 * caller already knows exactly which single recipient address to
 * send to, so only the subject/body text needs to be admin-editable.
 */
class TemplateRenderer
{
    /**
     * @param array<string,string> $tags Keys are literal ##tag## placeholders.
     * @return array{subject: ?string, html: ?string}
     */
    public static function render(string $templateName, array $tags): array
    {
        $tpl = new NotificationTemplate();
        if (!$tpl->getFromDBByCrit(['itemtype' => 'Ticket', 'name' => $templateName])) {
            return ['subject' => null, 'html' => null];
        }

        $data = $tpl->getByLanguage($_SESSION['glpilanguage'] ?? '');
        if (!$data) {
            return ['subject' => null, 'html' => null];
        }

        $subject = NotificationTemplate::process($data['subject'], $tags);
        $content = NotificationTemplate::process($data['content_html'], $tags, true);
        $css     = (string) ($tpl->fields['css'] ?? '');

        $html = '<!DOCTYPE html><html><head><meta charset="utf-8">'
              . '<meta name="viewport" content="width=device-width, initial-scale=1">'
              . '<title>' . htmlspecialchars($subject) . '</title>'
              . ($css !== '' ? '<style type="text/css">' . $css . '</style>' : '')
              . '</head><body>' . $content . '</body></html>';

        return ['subject' => $subject, 'html' => $html];
    }
}
