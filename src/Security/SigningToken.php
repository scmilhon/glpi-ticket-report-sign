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
    /** Default TTL for the manual "Send by email" (front/email.form.php) link. */
    private const TTL_SECONDS = 7 * 24 * 3600;

    /** TTL for the automatic sign-request / view-link emails (see ReportMailer). */
    public const TTL_72H = 72 * 3600;

    /**
     * @param string $signerName Display name for the recipient — the
     *   linked requester's own name, or whatever a technician typed
     *   for a walk-in signer (see ajax/mint_walkin_token.php). Stored
     *   so front/sign.form.php can pre-fill the "Name" field once the
     *   matching code is verified (see verifyOtpForTicket()), instead
     *   of asking the technician to retype what the signer already
     *   gave them.
     */
    public static function mint(int $reportId, string $recipient, ?int $ttlSeconds = null, string $signerName = ''): string
    {
        $exp   = time() + ($ttlSeconds ?? self::TTL_SECONDS);
        $nonce = bin2hex(random_bytes(8));
        $body  = $reportId . '.' . $exp . '.' . $nonce;
        $hmac  = hash_hmac('sha256', $body, self::secret());
        $token = self::b64u($body . '.' . $hmac);

        global $DB;
        $DB->insert('glpi_plugin_glpiticketreportsign_signlinks', [
            'reports_id'    => $reportId,
            'token_hash'    => hash('sha256', $token),
            // Short, spoken-aloud/typed-by-hand companion to the long
            // link — shares this same row's expiry/consumed_at so
            // revoking one revokes the other. Not every mint() caller
            // needs it (the manual "Send by email" 7-day link has no
            // use for it), but generating it unconditionally keeps
            // this the single place a signlink row is created.
            'otp_code'      => str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT),
            'signer_name'   => $signerName !== '' ? $signerName : null,
            'recipient'     => $recipient,
            'expires_at'    => date('Y-m-d H:i:s', $exp),
            'created_users_id' => (int) ($_SESSION['glpiID'] ?? 0),
            'date_creation' => date('Y-m-d H:i:s'),
        ]);

        return $token;
    }

    /** The 6-digit code minted alongside $token (see mint()), or null. */
    public static function otpFor(string $token): ?string
    {
        global $DB;
        $row = $DB->request([
            'FROM'  => 'glpi_plugin_glpiticketreportsign_signlinks',
            'WHERE' => ['token_hash' => hash('sha256', $token)],
            'LIMIT' => 1,
        ])->current();
        $otp = is_array($row) ? (string) ($row['otp_code'] ?? '') : '';
        return $otp !== '' ? $otp : null;
    }

    /**
     * Looks up a not-yet-consumed, not-yet-expired signlink by its
     * 6-digit code, scoped to reports belonging to $ticketId — used
     * when a technician types a requester's (or walk-in signer's)
     * code in person instead of the requester following their own
     * emailed link (see front/sign.submit.php, front/sign.form.php).
     *
     * @return array{signlink_id:int,reports_id:int,recipient:string,signer_name:string}|null
     */
    public static function verifyOtpForTicket(int $ticketId, string $otp): ?array
    {
        $otp = trim($otp);
        if (!preg_match('/^\d{6}$/', $otp)) {
            return null;
        }

        global $DB;
        $row = $DB->request([
            'SELECT'     => ['sl.id AS signlink_id', 'sl.reports_id', 'sl.recipient', 'sl.signer_name', 'sl.expires_at'],
            'FROM'       => 'glpi_plugin_glpiticketreportsign_signlinks AS sl',
            'INNER JOIN' => [
                'glpi_plugin_glpiticketreportsign_reports AS r' => ['ON' => ['sl' => 'reports_id', 'r' => 'id']],
            ],
            'WHERE'      => [
                'r.tickets_id'   => $ticketId,
                'sl.otp_code'    => $otp,
                'sl.consumed_at' => null,
            ],
            'ORDER'      => 'sl.id DESC',
            'LIMIT'      => 1,
        ])->current();

        if (!is_array($row) || $row['expires_at'] === null || strtotime((string) $row['expires_at']) < time()) {
            return null;
        }

        return [
            'signlink_id' => (int) $row['signlink_id'],
            'reports_id'  => (int) $row['reports_id'],
            'recipient'   => (string) $row['recipient'],
            'signer_name' => (string) ($row['signer_name'] ?? ''),
        ];
    }

    /**
     * Revokes every outstanding signlink (long link + OTP alike, they
     * share a row) tied to any report version of $ticketId — both the
     * "full" and "condensed" siblings of every version — excluding
     * MTTO reports (computers_id > 0), which have their own
     * independent lifecycle. Called once a technician's token-gated,
     * in-person client signature succeeds (see front/sign.submit.php),
     * so a code that already served its purpose can't be reused for a
     * different signer later in the same ticket.
     */
    public static function invalidateAllForTicket(int $ticketId): void
    {
        global $DB;
        $reportIds = [];
        foreach ($DB->request([
            'SELECT' => 'id',
            'FROM'   => 'glpi_plugin_glpiticketreportsign_reports',
            'WHERE'  => ['tickets_id' => $ticketId, 'computers_id' => 0],
        ]) as $row) {
            $reportIds[] = (int) $row['id'];
        }
        if ($reportIds === []) {
            return;
        }
        $DB->update(
            'glpi_plugin_glpiticketreportsign_signlinks',
            ['consumed_at' => date('Y-m-d H:i:s')],
            ['reports_id' => $reportIds, 'consumed_at' => null]
        );
    }

    /** @return array{reports_id:int,recipient:string}|null */
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

        return ['reports_id' => (int) $reportId, 'recipient' => (string) $row['recipient']];
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
