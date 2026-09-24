<?php
namespace GlpiPlugin\Glpiticketreportsign\Mail;

use GlpiPlugin\Glpiticketreportsign\Config;

/**
 * Resolves the "email_logo_path" config value (from the same
 * "Pick from existing GLPI logos" picker used for the PDF's logo, on
 * front/config.form.php) into a data: URI — an email needs the image
 * bytes embedded, unlike the PDF which just gives FPDF a file path.
 */
class EmailLogo
{
    public static function dataUri(string $configured): ?string
    {
        $abs = Config::resolveLogoPath($configured);
        if ($abs === '') {
            return null;
        }

        $mime = match (strtolower((string) pathinfo($abs, PATHINFO_EXTENSION))) {
            'png'          => 'image/png',
            'jpg', 'jpeg'  => 'image/jpeg',
            'gif'          => 'image/gif',
            default        => null,
        };
        if ($mime === null) {
            return null;
        }

        $bytes = file_get_contents($abs);
        if ($bytes === false) {
            return null;
        }

        return 'data:' . $mime . ';base64,' . base64_encode($bytes);
    }
}
