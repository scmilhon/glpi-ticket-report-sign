<?php
namespace GlpiPlugin\Glpiticketreportsign\Security;

/**
 * Stateless-ish signing tokens for "Send by email" links.
 *
 * Format: base64url("{reportId}.{exp}.{nonce}.{hmac}")
 *
 * The HMAC is keyed with GLPIKEY (or a derivative); the token's
 * SHA-256 is also persisted in glpi_plugin_glpiticketreportsign_signlinks
 * so we can mark it consumed (single-use) and so revocation works
 * even if the secret is rotated.
 */
class SigningToken
{
    private const TTL_SECONDS = 7 * 24 * 3600;

    public static function mint(int $reportId, string $recipient): string
    {
        $exp   = time() + self::TTL_SECONDS;
        $nonce = bin2hex(random_bytes(8));
        $body  = $reportId . '.' . $exp . '.' . $nonce;
        $hmac  = hash_hmac('sha256', $body, self::secret());
        $token = self::b64u($body . '.' . $hmac);

        global $DB;
        $DB->insert('glpi_plugin_glpiticketreportsign_signlinks', [
            'reports_id'    => $reportId,
            'token_hash'    => hash('sha256', $token),
            'recipient'     => $recipient,
            'expires_at'    => date('Y-m-d H:i:s', $exp),
            'created_users_id' => (int) ($_SESSION['glpiID'] ?? 0),
            'date_creation' => date('Y-m-d H:i:s'),
        ]);

        return $token;
    }

    /** @return array{reports_id:int}|null */
    public static function verify(string $token): ?array
    {
        $raw = self::b64udec($token);
        if ($raw === null) {
            return null;
        }
        $parts = explode('.', $raw);
        if (count($parts) !== 4) {
            return null;
        }
        [$reportId, $exp, $nonce, $hmac] = $parts;
        $body = $reportId . '.' . $exp . '.' . $nonce;

        if (!hash_equals(hash_hmac('sha256', $body, self::secret()), $hmac)) {
            return null;
        }
        if ((int) $exp < time()) {
            return null;
        }

        global $DB;
        $row = $DB->request([
            'FROM'  => 'glpi_plugin_glpiticketreportsign_signlinks',
            'WHERE' => ['token_hash' => hash('sha256', $token)],
            'LIMIT' => 1,
        ])->current();
        if (!is_array($row) || $row['consumed_at'] !== null) {
            return null;
        }

        return ['reports_id' => (int) $reportId];
    }

    public static function consume(string $token): void
    {
        global $DB;
        $DB->update(
            'glpi_plugin_glpiticketreportsign_signlinks',
            ['consumed_at' => date('Y-m-d H:i:s')],
            ['token_hash'  => hash('sha256', $token)]
        );
    }

    private static function secret(): string
    {
        // GLPIKEY is the framework's installation secret; if it isn't
        // exposed in this context we fall back to a configured key
        // file under GLPI_CONFIG_DIR. Both are tied to the install,
        // not to source code, so tokens don't leak via git history.
        if (defined('GLPIKEY') && is_string(GLPIKEY) && GLPIKEY !== '') {
            return 'tr.' . GLPIKEY;
        }
        $keyFile = (defined('GLPI_CONFIG_DIR') ? GLPI_CONFIG_DIR : __DIR__) . '/glpicrypt.key';
        if (is_file($keyFile)) {
            return 'tr.' . (string) file_get_contents($keyFile);
        }
        // Last-ditch: derive from DB credentials hash. NEVER ideal, but
        // never empty either, so signature always validates locally.
        return 'tr.' . hash('sha256', (string) ($GLOBALS['DB']->dbhost ?? '') . '|' . (string) ($GLOBALS['DB']->dbdefault ?? ''));
    }

    private static function b64u(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private static function b64udec(string $s): ?string
    {
        $pad = strlen($s) % 4;
        if ($pad) {
            $s .= str_repeat('=', 4 - $pad);
        }
        $r = base64_decode(strtr($s, '-_', '+/'), true);
        return $r === false ? null : $r;
    }
}
