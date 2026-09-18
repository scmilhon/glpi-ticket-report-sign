<?php
/**
 * Ticket Report & Sign plugin (key: glpiticketreportsign) for GLPI 10 through 12.
 *
 * Adds a "Report" tab to every Ticket. The tab generates a PDF
 * containing the ticket header, all follow-ups and solutions
 * (with their attached images inlined), and lets the assigned
 * technician (or their supervisor) draw a signature on any device
 * — desktop or phone — to finalise the report. The signed PDF is
 * stored as a GLPI Document linked to the ticket.
 *
 * Copyright (C) 2026 Nueva Era Soluciones
 * Licensed under the GNU General Public License v3.0 — see LICENSE.
 */

use Glpi\Plugin\Hooks;
use GlpiPlugin\Glpiticketreportsign\Config;
use GlpiPlugin\Glpiticketreportsign\Integration\TicketTab;
use GlpiPlugin\Glpiticketreportsign\Profile;

define('PLUGIN_GLPITICKETREPORTSIGN_VERSION', '0.0.8');
define('PLUGIN_GLPITICKETREPORTSIGN_MIN_GLPI', '10.0.0');
define('PLUGIN_GLPITICKETREPORTSIGN_MAX_GLPI', '12.0.10');

/**
 * Suppress one specific stray PHP user-warning that GLPI 11 emits
 * from Glpi\Agent\Communication\AbstractRequest::__construct() when
 * an inbound POST body fails its XML sniff. The warning is benign
 * but display_errors=on in the host install leaks the warning HTML
 * into the response BEFORE our redirect headers, breaking form
 * submission with a "Start tag expected" XML error page.
 *
 * Installed at setup.php load time (very early in GLPI's bootstrap)
 * and chained to whatever handler GLPI installed previously, so we
 * never silence anything else.
 */
$plugin_glpiticketreportsign_prev_error_handler = set_error_handler(
    function (int $errno, string $errstr, string $errfile = '', int $errline = 0) use (&$plugin_glpiticketreportsign_prev_error_handler) {
        $isAgentXmlSniff = $errno === E_USER_WARNING
            && (
                str_contains($errfile, 'AbstractRequest')
                || str_contains($errstr, 'Start tag expected')
                || str_contains($errstr, "'<' was not found")
            );
        if ($isAgentXmlSniff) {
            return true; // swallow
        }
        if (is_callable($plugin_glpiticketreportsign_prev_error_handler)) {
            return ($plugin_glpiticketreportsign_prev_error_handler)($errno, $errstr, $errfile, $errline);
        }
        return false; // let PHP's default handling run
    }
);

/**
 * Detects whether the running GLPI core declares $rightname (on the
 * given parent class) with an explicit type. GLPI 12 tightened
 * CommonGLPI/CommonDBTM's static $rightname to `string` — untyped in
 * GLPI 10/11 — and PHP requires a child class that redeclares a typed
 * property to match the parent's type exactly. `Report` and
 * `TicketTab` both need their own $rightname (used by CommonDBTM's
 * native add()/update() right checks and, for consistency, mirrored
 * on the tab class), so each ships two leaf files — a default one and
 * a `.glpi12` one — and this check, not a GLPI_VERSION string
 * comparison, picks the one that will actually load.
 * See https://github.com/glpi-project/glpi/issues/25399
 */
function plugin_glpiticketreportsign_rightname_is_typed(string $parentClass): bool
{
    static $cache = [];
    if (array_key_exists($parentClass, $cache)) {
        return $cache[$parentClass];
    }
    $typed = false;
    if (class_exists($parentClass)) {
        try {
            $typed = (new \ReflectionProperty($parentClass, 'rightname'))->hasType();
        } catch (\ReflectionException $e) {
            $typed = false;
        }
    }
    return $cache[$parentClass] = $typed;
}

/**
 * Plugin::getWebDir() was removed in GLPI 12 — plugin URLs are now
 * always /plugins/<key>/..., with $CFG_GLPI['root_doc'] prepended
 * only when the caller wants an absolute path (the old $full=true
 * default). Every ajax/front/src call site in this plugin goes
 * through this instead of calling Plugin::getWebDir() directly, so
 * it works unchanged on GLPI 10/11 (where the core method still
 * exists) and on GLPI 12 (where it doesn't).
 */
function plugin_glpiticketreportsign_web_dir(bool $full = true): string
{
    if (method_exists(\Plugin::class, 'getWebDir')) {
        return \Plugin::getWebDir('glpiticketreportsign', $full);
    }
    global $CFG_GLPI;
    $path = '/plugins/glpiticketreportsign';
    if ($full && is_array($CFG_GLPI ?? null)) {
        $path = rtrim((string) ($CFG_GLPI['root_doc'] ?? ''), '/') . $path;
    }
    return $path;
}

/**
 * Filesystem path to GLPI's own pics/ directory (stock logos, etc.),
 * version-agnostic. Since GLPI 11, public/ is the mandatory web
 * root, so static assets that must be directly HTTP-reachable
 * (pics/) live under GLPI_ROOT/public/pics — GLPI_ROOT itself still
 * points at the framework root either way (that part didn't move).
 * On GLPI 10, public/ was optional and pics/ sits right under
 * GLPI_ROOT. We don't guess by version — we just check which of the
 * two actually exists on disk. Returns '' if neither does.
 */
function plugin_glpiticketreportsign_glpi_pics_dir(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $root = defined('GLPI_ROOT') ? GLPI_ROOT : '';
    foreach (['public' . DIRECTORY_SEPARATOR . 'pics', 'pics'] as $rel) {
        $abs = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . $rel;
        if (is_dir($abs)) {
            return $cached = $abs;
        }
    }
    return $cached = '';
}

/**
 * PSR-4 autoloader used when the GLPI install does not load this
 * plugin's vendor/autoload.php. Resolves GlpiPlugin\Glpiticketreportsign\…
 * onto src/. We deliberately do not autoload FPDF here — that is
 * loaded via vendor/autoload.php (composer install) when needed by
 * the PDF generator.
 */
function plugin_glpiticketreportsign_autoload(string $class): void
{
    $prefix = 'GlpiPlugin\\Glpiticketreportsign\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $rel = substr($class, strlen($prefix));

    // See plugin_glpiticketreportsign_rightname_is_typed() above.
    $glpi12RightnameVariants = [
        'Report'                 => \CommonDBTM::class,
        'Integration\\TicketTab' => \CommonGLPI::class,
    ];
    if (isset($glpi12RightnameVariants[$rel])
        && plugin_glpiticketreportsign_rightname_is_typed($glpi12RightnameVariants[$rel])
    ) {
        $typedFile = __DIR__ . '/src/' . str_replace('\\', '/', $rel) . '.glpi12.php';
        if (is_file($typedFile)) {
            require_once $typedFile;
            return;
        }
    }

    $file = __DIR__ . '/src/' . str_replace('\\', '/', $rel) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
}
spl_autoload_register('plugin_glpiticketreportsign_autoload');

// FPDF is bundled with the plugin under pdf/ — that directory is
// the upstream FPDF distribution (font/, makefont/, fpdf.php, …).
// We load fpdf.php directly so the plugin works without a composer
// install step. The font/ subdirectory must remain alongside it
// because FPDF resolves built-in font metrics relative to fpdf.php.
$fpdfFile = __DIR__ . '/pdf/fpdf.php';
if (is_file($fpdfFile) && !class_exists(\FPDF::class, false)) {
    require_once $fpdfFile;
}

// chillerlan/php-qrcode ships under qrcode/ as a vendored composer
// install. Loading its autoloader makes the QRCode / QROptions
// classes available without a global composer install for the
// plugin. If the directory is missing the header just renders
// without a QR.
$qrAutoload = __DIR__ . '/qrcode/vendor/autoload.php';
if (is_file($qrAutoload)) {
    require_once $qrAutoload;
}

// composer's vendor/autoload.php is still loaded if present, for
// future composer-managed dependencies.
$autoload = __DIR__ . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

function plugin_init_glpiticketreportsign(): void
{
    /** @var array $PLUGIN_HOOKS */
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['glpiticketreportsign'] = true;

    Plugin::registerClass(Profile::class, ['addtabon' => 'Profile']);

    // Inject the "Report" tab into the standard Ticket form for
    // every ticket — no new menu, no separate module.
    Plugin::registerClass(TicketTab::class, ['addtabon' => 'Ticket']);

    // Physical asset filename intentionally left as ticketreport.css —
    // renaming the CSS/JS asset files isn't required by GLPI's plugin
    // key/folder matching rules, only the array key below is.
    $PLUGIN_HOOKS[Hooks::ADD_CSS]['glpiticketreportsign'] = 'public/css/ticketreport.css';

    // Surface the document-style configuration page in Setup >
    // General > Plugins. Visibility is gated by the plugin's own
    // right `plugin_glpiticketreportsign_config` (READ), which admins grant
    // per profile via Setup > Profiles > Ticket reports tab.
    if (Config::hasRight(READ)) {
        $PLUGIN_HOOKS['config_page']['glpiticketreportsign'] = 'front/config.form.php';
    }

    // Auto-generate a draft report — and jump the user to the
    // Report tab — the moment a solution is filed on a ticket.
    // We register a top-level function callback (rather than a
    // class-method array) because some GLPI 11 minor releases only
    // dispatch hooks declared as plain function names.
    $PLUGIN_HOOKS['item_add']['glpiticketreportsign'] = [
        'ITILSolution' => 'plugin_glpiticketreportsign_on_solution_added',
    ];
}

/**
 * @return array<string,mixed>
 */
function plugin_version_glpiticketreportsign(): array
{
    return [
        'name'           => 'Ticket Report & Sign',
        'version'        => PLUGIN_GLPITICKETREPORTSIGN_VERSION,
        'author'         => 'scmilhon',
        'license'        => 'GPLv3',
        'homepage'       => 'https://github.com/scmilhon/glpi-ticket-report-sign',
        'minGlpiVersion' => PLUGIN_GLPITICKETREPORTSIGN_MIN_GLPI,
        'requirements'   => [
            'glpi' => [
                'min' => PLUGIN_GLPITICKETREPORTSIGN_MIN_GLPI,
                'max' => PLUGIN_GLPITICKETREPORTSIGN_MAX_GLPI,
            ],
            'php'    => ['min' => '8.2'],
            'ext-gd' => true,
        ],
    ];
}

function plugin_glpiticketreportsign_check_prerequisites(): bool
{
    if (!extension_loaded('gd')) {
        echo 'Ticket Report & Sign plugin requires the PHP gd extension (used to render the signature PNG into the PDF).';
        return false;
    }
    if (!class_exists(\FPDF::class)) {
        // FPDF ships inside this plugin under pdf/. If the class
        // still isn't loaded, the directory is missing or the file
        // was deleted on copy.
        echo 'Ticket Report & Sign plugin needs FPDF. Make sure pdf/fpdf.php exists inside the plugin folder.';
        return false;
    }
    return true;
}

function plugin_glpiticketreportsign_check_config(bool $verbose = false): bool
{
    return true;
}

/**
 * Hook dispatcher for ITILSolution adds. Kept as a top-level
 * function (not a static method) so every GLPI 11 minor release's
 * hook dispatcher can resolve it. Delegates to the real handler.
 */
function plugin_glpiticketreportsign_on_solution_added($item): void
{
    if (!$item instanceof \ITILSolution) {
        return;
    }
    \GlpiPlugin\Glpiticketreportsign\Hooks\OnSolutionAdded::handle($item);
}
