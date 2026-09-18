<?php
namespace GlpiPlugin\Glpiticketreportsign\Pdf;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use FPDF;
use GlpiPlugin\Glpiticketreportsign\Config;
use Ticket;

/**
 * FPDF subclass that paints the per-page header (logo on the left,
 * centered company name + tax-ID + address + website, ticket id in
 * red on the right, QR code on the far right linking back to the
 * ticket) and the page-number footer.
 *
 * Mirrors the screenshot the customer provided as the canonical
 * layout. Company info is pulled from the ticket's GLPI entity:
 *   - name              → entity.name
 *   - tax id (NIT)      → entity.registration_number
 *   - address + city    → entity.address + entity.town
 *   - website           → entity.website
 *
 * Optional logo lives at `pics/logo.png` inside the plugin folder.
 * The QR code is generated on the fly with chillerlan/php-qrcode
 * (vendored under qrcode/) and written to a temp PNG that's
 * cleaned up in the destructor.
 */
class TicketReportFpdf extends FPDF
{
    private string $companyName    = '';
    private string $companyNit     = '';
    private string $companyAddress = '';
    private string $companyWebsite = '';
    private string $logoPath       = '';
    private int    $ticketId       = 0;
    private string $ticketDate     = '';
    private string $qrPath         = '';
    /** @var array<int,int> RGB tuple for the ticket id colour */
    private array  $ticketIdRgb    = [220, 0, 0];
    private string $footerTpl      = 'Página {page}/{pages}';

    public function configure(Ticket $ticket): void
    {
        $this->ticketId   = (int) $ticket->getID();
        $this->ticketDate = $this->fmtDate((string) ($ticket->fields['date'] ?? ''));

        $cfg = Config::get();

        // All company info comes straight from the admin-edited config.
        // No GLPI entity lookup — by design, the customer asked for this.
        $this->companyName    = (string) ($cfg['company_name']    ?? '');
        $this->companyNit     = (string) ($cfg['company_nit']     ?? '');
        $this->companyAddress = (string) ($cfg['company_address'] ?? '');
        $this->companyWebsite = (string) ($cfg['company_website'] ?? '');

        // The logo path is relative to GLPI_ROOT, so the plugin can
        // reuse logos already present in the GLPI install (pics/, the
        // _pictures/ uploads folder, etc.) without duplicating files.
        $this->logoPath = self::resolveLogoPath((string) ($cfg['logo_path'] ?? ''));

        $this->ticketIdRgb = self::hexToRgb((string) $cfg['ticket_id_color'], [220, 0, 0]);
        $this->footerTpl   = (string) ($cfg['footer_text'] ?? 'Página {page}/{pages}');

        $this->qrPath = $this->renderQr($this->ticketUrl());
    }

    /**
     * Turn a config path into an absolute filesystem path that
     * FPDF::Image can read. Handles four forms:
     *   - Absolute paths (returned as-is when the file exists).
     *   - Paths starting with "_pictures/" — resolved against
     *     GLPI_PICTURE_DIR, where GLPI stores uploaded branding.
     *   - Paths starting with "pics/" — resolved against GLPI's own
     *     pics/ directory (version-agnostic: GLPI_ROOT/pics on GLPI
     *     10, GLPI_ROOT/public/pics from GLPI 11 on — see
     *     plugin_glpiticketreportsign_glpi_pics_dir()), so a value
     *     like "pics/logos/logo-GLPI-100-grey.png" picks up the
     *     stock GLPI logo without extra setup on any version.
     *   - Anything else — resolved against GLPI_ROOT as a last
     *     resort, for a fully custom path outside pics/.
     */
    private static function resolveLogoPath(string $configured): string
    {
        $configured = trim($configured);
        if ($configured === '') {
            return '';
        }
        if (is_file($configured)) {
            return $configured;
        }

        if (strncmp($configured, '_pictures/', 10) === 0 && defined('GLPI_PICTURE_DIR')) {
            $abs = rtrim(GLPI_PICTURE_DIR, '/\\')
                 . DIRECTORY_SEPARATOR
                 . substr($configured, 10);
            return is_file($abs) ? $abs : '';
        }

        if (strncmp($configured, 'pics/', 5) === 0 && function_exists('plugin_glpiticketreportsign_glpi_pics_dir')) {
            $picsDir = plugin_glpiticketreportsign_glpi_pics_dir();
            if ($picsDir !== '') {
                $abs = $picsDir . DIRECTORY_SEPARATOR
                     . str_replace('/', DIRECTORY_SEPARATOR, substr($configured, 5));
                if (is_file($abs)) {
                    return $abs;
                }
            }
        }

        $root = defined('GLPI_ROOT') ? GLPI_ROOT : dirname(__DIR__, 4);
        $abs  = rtrim($root, '/\\')
              . DIRECTORY_SEPARATOR
              . str_replace('/', DIRECTORY_SEPARATOR, ltrim($configured, '/\\'));
        return is_file($abs) ? $abs : '';
    }

    public function Header(): void
    {
        // All header elements stay inside the body's 15 mm side
        // margins (so they line up visually with the section bars
        // below). Usable width is 180 mm (15 to 195).
        //
        //   Logo:        x=15, w=36  → 15-51
        //   Centered:    x=55, w=90  → 55-145
        //   Right info:  x=148, w=25 → 148-173
        //   QR code:     x=173, w=22 → 173-195
        if ($this->logoPath !== '') {
            $this->Image($this->logoPath, 15, 5, 36);
        }

        // --- Centered company block ------------------------------
        $cx = 55;
        $cw = 90;

        $this->SetY(7);
        $this->SetX($cx);
        $this->SetFont('Arial', 'B', 12);
        $this->Cell($cw, 5, $this->lat($this->companyName), 0, 1, 'C');

        if ($this->companyNit !== '') {
            $this->SetX($cx);
            $this->SetFont('Arial', 'B', 10);
            $this->Cell($cw, 5, $this->lat('NIT: ' . $this->companyNit), 0, 1, 'C');
        }

        if ($this->companyAddress !== '') {
            $this->SetX($cx);
            $this->SetFont('Arial', '', 9);
            $this->Cell($cw, 5, $this->lat($this->companyAddress), 0, 1, 'C');
        }

        if ($this->companyWebsite !== '') {
            $this->SetX($cx);
            $this->SetFont('Arial', '', 9);
            $this->Cell($cw, 4, $this->lat($this->companyWebsite), 0, 1, 'C');
        }

        // --- Right column: Nº TICKET / id (red) / date ------------
        $rx = 148;
        $rw = 25;

        $this->SetY(7);
        $this->SetX($rx);
        $this->SetFont('Arial', 'B', 11);
        $this->Cell($rw, 5, $this->lat('Nº TICKET'), 0, 1, 'C');

        $this->SetX($rx);
        $this->SetTextColor($this->ticketIdRgb[0], $this->ticketIdRgb[1], $this->ticketIdRgb[2]);
        $this->SetFont('Arial', 'B', 16);
        $this->Cell($rw, 7, (string) $this->ticketId, 0, 1, 'C');
        $this->SetTextColor(0, 0, 0);

        $this->SetX($rx);
        $this->SetFont('Arial', '', 9);
        $this->Cell($rw, 4, $this->lat($this->ticketDate), 0, 1, 'C');

        // --- QR code (far right, 22 mm) — within right margin ----
        if ($this->qrPath !== '' && is_file($this->qrPath)) {
            $this->Image($this->qrPath, 173, 5, 22, 22, 'PNG');
        }

        // Drop the cursor below the header so body content starts
        // on a clean line.
        $this->SetY(34);
    }

    public function Footer(): void
    {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);

        // {pages} is rendered via FPDF's {nb} alias (set via
        // AliasNbPages in ReportPdf::render). {page} is the current
        // page number from PageNo().
        $tpl = $this->footerTpl !== '' ? $this->footerTpl : 'Página {page}/{pages}';
        $tpl = str_replace(['{page}', '{pages}'], [(string) $this->PageNo(), '{nb}'], $tpl);
        $this->Cell(0, 10, $this->lat($tpl), 0, 0, 'C');
    }

    /**
     * @return array<int,int>
     */
    private static function hexToRgb(string $hex, array $fallback): array
    {
        if (!preg_match('/^#?([0-9a-fA-F]{6})$/', trim($hex), $m)) {
            return $fallback;
        }
        $int = hexdec($m[1]);
        return [($int >> 16) & 0xFF, ($int >> 8) & 0xFF, $int & 0xFF];
    }

    public function __destruct()
    {
        if ($this->qrPath !== '' && is_file($this->qrPath)) {
            @unlink($this->qrPath);
        }
    }

    private function ticketUrl(): string
    {
        global $CFG_GLPI;
        $base = is_array($CFG_GLPI ?? null) ? (string) ($CFG_GLPI['url_base'] ?? '') : '';
        return rtrim($base, '/') . '/front/ticket.form.php?id=' . $this->ticketId;
    }

    private function renderQr(string $url): string
    {
        if (!class_exists(QRCode::class) || !class_exists(QROptions::class)) {
            return '';
        }
        try {
            $options = new QROptions([
                'version'      => 5,
                'outputType'   => QRCode::OUTPUT_IMAGE_PNG,
                'eccLevel'     => QRCode::ECC_L,
                'imageBase64'  => false,
                'scale'        => 6,
            ]);
            $bytes = (new QRCode($options))->render($url);
            if (!is_string($bytes) || $bytes === '') {
                return '';
            }
            $tmp = tempnam(sys_get_temp_dir(), 'tr_qr_') . '.png';
            if (file_put_contents($tmp, $bytes) === false) {
                return '';
            }
            return $tmp;
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function lat(string $s): string
    {
        $out = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s);
        return $out === false ? $s : $out;
    }

    private function fmtDate(string $s): string
    {
        if ($s === '' || str_starts_with($s, '0000')) {
            return '-';
        }
        $ts = strtotime($s);
        return $ts === false ? $s : date('d/m/Y', $ts);
    }
}
