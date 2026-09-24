<?php
namespace GlpiPlugin\Glpiticketreportsign\Mail;

use RobThree\Auth\Providers\Qr\BaconQrCodeProvider;

/**
 * Renders a QR code as an inline data: URI, reusing the
 * bacon-qr-code + robthree/twofactorauth libraries GLPI core already
 * bundles for its own TOTP setup QR (Glpi\Security\TOTPManager) — this
 * plugin adds no dependency of its own for it.
 *
 * SVG is the only format used because the alternative (PNG/GIF/JPEG)
 * goes through BaconQrCodeProvider's Imagick backend, and none of
 * this plugin's supported GLPI installs are guaranteed to have the
 * `imagick` PHP extension (only `ext-gd`, which this class doesn't
 * need at all).
 */
class QrCode
{
    /**
     * @return string|null A "data:image/svg+xml;base64,..." URI, or
     *   null when bacon-qr-code isn't available (GLPI 10, which
     *   doesn't bundle it) — callers must omit the QR block entirely
     *   in that case rather than pass an empty src.
     */
    public static function svgDataUri(string $text, int $size = 140): ?string
    {
        if (!class_exists(BaconQrCodeProvider::class)) {
            return null;
        }
        $provider = new BaconQrCodeProvider(2, '#ffffff', '#1a1a1a', 'svg');
        $svg      = $provider->getQRCodeImage($text, $size);
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
