<?php
namespace GlpiPlugin\Glpiticketreportsign\Install;

/**
 * Minimal PHP-only Gettext .po → .mo compiler.
 *
 * Why this exists: GLPI 11's locale loader expects compiled .mo
 * files, not raw .po sources. Shipping .po only causes the plugin
 * UI to stay in English even when the user's GLPI is set to
 * Spanish. The Installer runs PoToMo::compileAll() on every
 * install / upgrade so the .mo files are always in sync with the
 * .po sources — no msgfmt step required on the host machine.
 *
 * Supports the subset of .po features this plugin uses:
 *  - Single-line and multi-line msgid / msgstr.
 *  - Comments (#) and PO header (empty msgid).
 *  - Plural forms (msgid_plural + msgstr[0]/[1]).
 *  - Standard backslash escapes inside string literals.
 *
 * Does NOT support: msgctxt (no context-scoped strings here), the
 * deprecated #~ obsolete markers, or string fields longer than 2^31
 * bytes (the entire plugin is well under that).
 */
final class PoToMo
{
    /** Compile every <locale>.po next to it as <locale>.mo. */
    public static function compileAll(string $localesDir): void
    {
        if (!is_dir($localesDir)) {
            return;
        }
        foreach (glob($localesDir . DIRECTORY_SEPARATOR . '*.po') ?: [] as $po) {
            $mo = preg_replace('/\.po$/', '.mo', $po);
            try {
                self::compile($po, (string) $mo);
            } catch (\Throwable $e) {
                // Don't abort the install on a malformed translation
                // file — just log and move on.
                @error_log('glpiticketreportsign PoToMo: ' . $po . ' — ' . $e->getMessage());
            }
        }
    }

    public static function compile(string $poPath, string $moPath): void
    {
        $entries = self::parse($poPath);
        $bytes   = self::build($entries);
        if (file_put_contents($moPath, $bytes) === false) {
            throw new \RuntimeException('Failed to write ' . $moPath);
        }
    }

    /**
     * Parse a .po file into a flat msgid → msgstr map. Plural rows
     * use the gettext convention msgid . "\0" . msgid_plural →
     * msgstr[0] . "\0" . msgstr[1] ...
     *
     * @return array<string,string>
     */
    private static function parse(string $path): array
    {
        $src = @file_get_contents($path);
        if ($src === false) {
            throw new \RuntimeException('Cannot read ' . $path);
        }
        // Normalise line endings.
        $src = str_replace("\r\n", "\n", $src);

        $entries = [];
        $cur = self::blankEntry();

        $flush = static function () use (&$entries, &$cur): void {
            if ($cur['msgid'] === null) {
                return;
            }
            if ($cur['msgid_plural'] !== null) {
                $key = $cur['msgid'] . "\0" . $cur['msgid_plural'];
                $val = implode("\0", array_values($cur['msgstr_plural']));
            } else {
                $key = $cur['msgid'];
                $val = $cur['msgstr'] ?? '';
            }
            $entries[$key] = $val;
        };

        $current = null;        // which key the next continuation line appends to
        $currentIdx = null;     // for msgstr[N]

        foreach (preg_split('/\n/', $src) as $line) {
            $line = rtrim($line, "\n");
            $trim = ltrim($line);
            if ($trim === '' || $trim[0] === '#') {
                if ($trim === '') {
                    $flush();
                    $cur = self::blankEntry();
                    $current = null;
                    $currentIdx = null;
                }
                continue;
            }

            if (preg_match('/^msgid_plural\s+(.+)$/', $trim, $m)) {
                $cur['msgid_plural'] = self::unquote($m[1]);
                $current             = 'msgid_plural';
                continue;
            }
            if (preg_match('/^msgid\s+(.+)$/', $trim, $m)) {
                $flush();
                $cur          = self::blankEntry();
                $cur['msgid'] = self::unquote($m[1]);
                $current      = 'msgid';
                continue;
            }
            if (preg_match('/^msgstr\[(\d+)\]\s+(.+)$/', $trim, $m)) {
                $idx = (int) $m[1];
                $cur['msgstr_plural'][$idx] = self::unquote($m[2]);
                $current    = 'msgstr_plural';
                $currentIdx = $idx;
                continue;
            }
            if (preg_match('/^msgstr\s+(.+)$/', $trim, $m)) {
                $cur['msgstr'] = self::unquote($m[1]);
                $current       = 'msgstr';
                continue;
            }
            // Continuation line — a quoted string belongs to the
            // most recent field.
            if ($trim[0] === '"' && $current !== null) {
                $piece = self::unquote($trim);
                if ($current === 'msgid')          $cur['msgid']          .= $piece;
                if ($current === 'msgid_plural')   $cur['msgid_plural']   .= $piece;
                if ($current === 'msgstr')         $cur['msgstr']         .= $piece;
                if ($current === 'msgstr_plural')  $cur['msgstr_plural'][$currentIdx] .= $piece;
            }
        }
        $flush();

        // Drop entries with an empty msgstr — they'd just shadow
        // the source string with itself and waste space.
        $clean = [];
        foreach ($entries as $k => $v) {
            // Keep the PO header (empty msgid) since gettext stores
            // charset / plural info there.
            if ($k === '') {
                $clean[''] = $v;
                continue;
            }
            if ($v === '' || $v === "\0") {
                continue;
            }
            $clean[$k] = $v;
        }

        return $clean;
    }

    /** @return array<string,mixed> */
    private static function blankEntry(): array
    {
        return [
            'msgid'         => null,
            'msgid_plural'  => null,
            'msgstr'        => null,
            'msgstr_plural' => [],
        ];
    }

    /**
     * "..." → the unescaped UTF-8 contents.
     */
    private static function unquote(string $literal): string
    {
        $literal = trim($literal);
        if ($literal === '' || $literal[0] !== '"' || $literal[-1] !== '"') {
            return $literal;
        }
        $inner = substr($literal, 1, -1);
        // Decode standard escapes.
        return preg_replace_callback(
            '/\\\\(.)/',
            static function (array $m): string {
                return match ($m[1]) {
                    'n'  => "\n",
                    't'  => "\t",
                    'r'  => "\r",
                    '\\' => '\\',
                    '"'  => '"',
                    "'"  => "'",
                    '0'  => "\0",
                    default => $m[1],
                };
            },
            $inner
        );
    }

    /**
     * Build the binary .mo from a flat msgid → msgstr map.
     */
    private static function build(array $entries): string
    {
        // Sort by msgid bytes (gettext spec requires this for
        // binary-search lookups in the consumer).
        ksort($entries, SORT_STRING);

        $count           = count($entries);
        $headerSize      = 7 * 4;                  // 28 bytes
        $origTableOff    = $headerSize;
        $transTableOff   = $origTableOff  + $count * 8;
        $stringsStartOff = $transTableOff + $count * 8;

        $origTable  = '';
        $transTable = '';
        $strings    = '';
        $offset     = $stringsStartOff;

        // Original strings first.
        $origOffsets = [];
        foreach (array_keys($entries) as $msgid) {
            $len   = strlen($msgid);
            $origTable     .= pack('VV', $len, $offset);
            $origOffsets[]  = $offset;
            $strings       .= $msgid . "\0";
            $offset        += $len + 1;
        }
        // Then translations.
        foreach (array_values($entries) as $msgstr) {
            $len = strlen($msgstr);
            $transTable .= pack('VV', $len, $offset);
            $strings    .= $msgstr . "\0";
            $offset     += $len + 1;
        }

        $header = pack(
            'VVVVVVV',
            0x950412de,         // magic (little-endian)
            0,                  // revision
            $count,             // number of strings
            $origTableOff,      // offset to original table
            $transTableOff,     // offset to translation table
            0,                  // hash table size (no hash)
            0                   // hash table offset
        );

        return $header . $origTable . $transTable . $strings;
    }
}
