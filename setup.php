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
 * Licensed under GPLv3.
 */

use Glpi\Http\Firewall;
use Glpi\Http\SessionManager;
use Glpi\Plugin\Hooks;
use GlpiPlugin\Glpiticketreportsign\Config;
use GlpiPlugin\Glpiticketreportsign\Integration\TicketTab;
use GlpiPlugin\Glpiticketreportsign\Profile;

define('PLUGIN_GLPITICKETREPORTSIGN_VERSION', '0.0.9');
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
 * setup.php is loaded on EVERY GLPI request while this plugin is
 * active — installing the handler unconditionally at load time (as
 * this used to) would silence that same warning instance-wide,
 * including on the real inventory-agent submissions it's meant to be
 * diagnostic for. Scoped instead to requests that are actually for
 * one of this plugin's own front/ or ajax/ controllers — the only
 * place the leaked-HTML-before-redirect failure mode can happen —
 * via the request path, which is already known by the time setup.php
 * runs (GLPI's own bootstrap loads every active plugin's setup.php
 * as part of handling the current request, before that request's own
 * controller code resumes). Chained to whatever handler GLPI already
 * installed, so on the requests it does apply to it still never
 * silences anything else.
 */
$plugin_glpiticketreportsign_request_path = (string) ($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '');
if (preg_match('#/plugins/glpiticketreportsign/(front|ajax)/#', $plugin_glpiticketreportsign_request_path)) {
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
}

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
        $path = \Plugin::getWebDir('glpiticketreportsign', $full);
        // Core's own contract for $full=false is inconsistent with
        // itself across call paths: it returns "plugins/<key>" with
        // NO leading slash there, while every caller in this plugin
        // (front/email.submit.php, ajax/send_sign_email.php,
        // ReportMailer::sendReportLink()) concatenates it straight
        // after rtrim($url_base, '/'), expecting one — producing
        // broken links like "http://hostplugins/..." otherwise.
        // Normalizing here (once) beats patching every call site.
        if (!$full && $path !== '' && $path[0] !== '/') {
            $path = '/' . $path;
        }
        return $path;
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

    // GLPI 11+'s Firewall defaults every unregistered legacy plugin
    // script to STRATEGY_AUTHENTICATED — without this, the public
    // token-signed pages (reached by an anonymous client from the
    // emailed link) get redirected to the login page before their
    // own code ever runs, regardless of what that code checks.
    // Precise per-file patterns only — NOT sign.form.php/sign.submit.php,
    // which are the authenticated (technician/logged-in-client) pages
    // and must keep requiring a real GLPI session. Every one of these
    // 4 scripts already enforces its own authorization independently
    // (SigningToken::verify()'s HMAC+expiry+single-use for the token
    // path, or an explicit Session::checkLoginUser() call for the
    // authenticated fallback path) — this only removes the routing-
    // level gate, not any actual check. Pattern/guard follow the same
    // convention already used by glpi-plugins/m365sso/setup.php.
    // (ajax/mint_walkin_token.php is NOT in this list — it's reached
    // only from the authenticated technician session in
    // front/sign.form.php, so it goes through the normal CSRF/session
    // checks like any other authenticated ajax endpoint.)
    //
    // Registering ONLY the firewall strategy is not enough: these are
    // POST endpoints, and declaring csrf_compliant=true above makes
    // GLPI auto-enforce Session::checkCSRF() on every POST this
    // plugin receives — which an anonymous, session-less visitor can
    // never satisfy (confirmed via glpi_logs/access-errors.log:
    // "CSRF check failed ... at /plugins/glpiticketreportsign/ajax/
    // sign_submit.php"). SessionManager::registerPluginStatelessPath()
    // marks the same paths as stateless, which both CheckCsrfListener
    // and FirewallStrategyListener skip entirely for — the correct,
    // official way to carve out token-authenticated endpoints from a
    // plugin that is otherwise CSRF-compliant for its normal forms.
    $publicResourcePatterns = [
        '#^/front/sign\.php#',
        '#^/ajax/sign_submit\.php#',
        '#^/ajax/pdf_bytes\.php#',
        '#^/ajax/choose_report_email\.php#',
    ];
    if (
        class_exists(Firewall::class)
        && method_exists(Firewall::class, 'addPluginStrategyForLegacyScripts')
    ) {
        foreach ($publicResourcePatterns as $pattern) {
            Firewall::addPluginStrategyForLegacyScripts(
                'glpiticketreportsign',
                $pattern,
                Firewall::STRATEGY_NO_CHECK
            );
        }
    }
    if (
        class_exists(SessionManager::class)
        && method_exists(SessionManager::class, 'registerPluginStatelessPath')
    ) {
        foreach ($publicResourcePatterns as $pattern) {
            SessionManager::registerPluginStatelessPath('glpiticketreportsign', $pattern);
        }
    }

    Plugin::registerClass(Profile::class, ['addtabon' => 'Profile']);

    // Inject the "Report" tab into the standard Ticket form for
    // every ticket — no new menu, no separate module.
    Plugin::registerClass(TicketTab::class, ['addtabon' => 'Ticket']);

    // Physical asset filename intentionally left as ticketreport.css —
    // renaming the CSS/JS asset files isn't required by GLPI's plugin
    // key/folder matching rules, only the array key below is.
    $PLUGIN_HOOKS[Hooks::ADD_CSS]['glpiticketreportsign'] = 'public/css/ticketreport.css';

    // Auto-opens the Report tab for a requester with a signature
    // pending — see public/js/pending-signature-redirect.js and
    // ajax/pending_signature.php. Loads on every page, no-ops
    // everywhere except ticket.form.php.
    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['glpiticketreportsign'] = 'public/js/pending-signature-redirect.js';

    // Renders the diagnosis widgets inside GLPI's native forms: the
    // "mark as diagnosis" checkbox on a follow-up (see
    // src/Hooks/OnFollowupFormRender.php) and the confirmation /
    // "no diagnosis applies" checkbox on a solution (see
    // src/Hooks/OnSolutionFormRender.php). Registered as a plain
    // callable, not itemtype-keyed — post_item_form always fires
    // with a plain array, never an object.
    $PLUGIN_HOOKS[Hooks::POST_ITEM_FORM]['glpiticketreportsign'] = 'plugin_glpiticketreportsign_render_diagnosis_widgets';

    // Adds a "View in Report" button next to a report PDF's native
    // timeline entry — see src/Hooks/OnTimelineDocumentShow.php.
    $PLUGIN_HOOKS[Hooks::POST_SHOW_ITEM]['glpiticketreportsign'] = 'plugin_glpiticketreportsign_render_timeline_document_button';

    // Surface the document-style configuration page in Setup >
    // General > Plugins. Visibility is gated by the plugin's own
    // right `plugin_glpiticketreportsign_config` (READ), which admins grant
    // per profile via Setup > Profiles > Ticket reports tab.
    if (Config::hasRight(READ)) {
        $PLUGIN_HOOKS['config_page']['glpiticketreportsign'] = 'front/config.form.php';
    }

    // Gives front/config.form.php's own breadcrumb a real 3rd segment
    // ("Inicio / Configuración / Ticket Report & Sign") instead of
    // just "Inicio / Configuración" — the breadcrumb template reads
    // menu['config']['content'][$item], so we add ONE new key here
    // (never touching any existing entry) and point Html::header()'s
    // $item at that same key. See Html::generateMenuSession() and
    // templates/layout/parts/breadcrumbs.html.twig in GLPI core.
    $PLUGIN_HOOKS[Hooks::REDEFINE_MENUS]['glpiticketreportsign'] = 'plugin_glpiticketreportsign_redefine_menus';

    // Auto-generate a draft report — and jump the user to the
    // Report tab — the moment a solution is filed on a ticket.
    // We register a top-level function callback (rather than a
    // class-method array) because some GLPI 11 minor releases only
    // dispatch hooks declared as plain function names.
    $PLUGIN_HOOKS['item_add']['glpiticketreportsign'] = [
        'ITILSolution'  => 'plugin_glpiticketreportsign_on_solution_added',
        'ITILFollowup'  => 'plugin_glpiticketreportsign_on_followup_added',
    ];

    // A follow-up edited through the form with the diagnosis checkbox
    // (unchecking it clears the mark) — see
    // src/Hooks/OnFollowupUpdated.php. A ticket closing without a
    // client signature triggers the resumido courtesy email — see
    // src/Hooks/OnTicketClosed.php.
    $PLUGIN_HOOKS['item_update']['glpiticketreportsign'] = [
        'ITILFollowup' => 'plugin_glpiticketreportsign_on_followup_updated',
        'Ticket'       => 'plugin_glpiticketreportsign_on_ticket_updated',
    ];

    // Blocks resolving a ticket unless a follow-up is marked as the
    // diagnosis (or "no diagnosis applies" was checked) — see
    // src/Hooks/OnSolutionPreAdd.php for the actual rule.
    $PLUGIN_HOOKS['pre_item_add']['glpiticketreportsign'] = [
        'ITILSolution' => 'plugin_glpiticketreportsign_on_solution_preadd',
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

/**
 * Hook dispatcher for ITILSolution pre_item_add. Kept as a top-level
 * function for the same reason as the item_add dispatcher above.
 */
function plugin_glpiticketreportsign_on_solution_preadd($item): void
{
    if (!$item instanceof \ITILSolution) {
        return;
    }
    \GlpiPlugin\Glpiticketreportsign\Hooks\OnSolutionPreAdd::handle($item);
}

/**
 * Hook dispatcher for ITILFollowup adds. See OnFollowupAdded.
 */
function plugin_glpiticketreportsign_on_followup_added($item): void
{
    if (!$item instanceof \ITILFollowup) {
        return;
    }
    \GlpiPlugin\Glpiticketreportsign\Hooks\OnFollowupAdded::handle($item);
}

/**
 * Hook dispatcher for ITILFollowup updates. See OnFollowupUpdated.
 */
function plugin_glpiticketreportsign_on_followup_updated($item): void
{
    if (!$item instanceof \ITILFollowup) {
        return;
    }
    \GlpiPlugin\Glpiticketreportsign\Hooks\OnFollowupUpdated::handle($item);
}

/**
 * Hook dispatcher for post_item_form. Routes to whichever renderer
 * matches the subitem type — this hook is not itemtype-keyed
 * (Plugin::doHook() only branches on itemtype when the hook is fired
 * with a bare object; post_item_form is always fired with a plain
 * array), so the itemtype check happens inside each handler instead.
 */
function plugin_glpiticketreportsign_render_diagnosis_widgets($data): void
{
    if (!is_array($data)) {
        return;
    }
    \GlpiPlugin\Glpiticketreportsign\Hooks\OnSolutionFormRender::handle($data);
    \GlpiPlugin\Glpiticketreportsign\Hooks\OnFollowupFormRender::handle($data);
}

/**
 * Hook dispatcher for Ticket updates. See OnTicketClosed.
 */
function plugin_glpiticketreportsign_on_ticket_updated($item): void
{
    if (!$item instanceof \Ticket) {
        return;
    }
    \GlpiPlugin\Glpiticketreportsign\Hooks\OnTicketClosed::handle($item);
}

/**
 * Hook dispatcher for post_show_item. See
 * src/Hooks/OnTimelineDocumentShow.php — same array-payload reasoning
 * as the post_item_form dispatcher above.
 */
function plugin_glpiticketreportsign_render_timeline_document_button($data): void
{
    if (!is_array($data)) {
        return;
    }
    \GlpiPlugin\Glpiticketreportsign\Hooks\OnTimelineDocumentShow::handle($data);
}

/**
 * Adds exactly one new key under menu['config']['content'] so
 * front/config.form.php's breadcrumb can show our plugin's name as
 * its 3rd segment (see the Hooks::REDEFINE_MENUS registration
 * above). Never modifies any existing entry — if the 'config'
 * sector isn't present for some reason (e.g. a non-central
 * interface), this is a no-op.
 */
function plugin_glpiticketreportsign_redefine_menus(array $menu): array
{
    if (!isset($menu['config']) || !is_array($menu['config'])) {
        return $menu;
    }
    if (!isset($menu['config']['content']) || !is_array($menu['config']['content'])) {
        $menu['config']['content'] = [];
    }

    // GLPI 10's breadcrumb wraps to a second line instead of
    // truncating once "Home / Setup / <this>" no longer fits the row
    // (it does on a narrow phone width, e.g. 320px, with the full
    // "Ticket Report & Sign") — GLPI 11/12's breadcrumb doesn't have
    // that problem, so only GLPI 10 gets the shortened label. Same
    // Kernel-class check already used elsewhere in this plugin to
    // tell the two apart.
    $menuTitle = class_exists(\Glpi\Kernel\Kernel::class)
        ? 'Ticket Report & Sign'
        : 'Report & Sign';

    $menu['config']['content']['glpiticketreportsign'] = [
        'title' => $menuTitle,
        'page'  => plugin_glpiticketreportsign_web_dir(false) . '/front/config.form.php',
        'icon'  => 'ti ti-signature',
    ];

    return $menu;
}
