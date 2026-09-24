<?php
namespace GlpiPlugin\Glpiticketreportsign\Signature;

/**
 * One reusable signature PNG per GLPI user, so a technician who
 * already signed a report once doesn't have to redraw it on every
 * ticket. Saved automatically whenever a technician signature is
 * submitted (front/sign.submit.php); loaded back into the canvas by
 * front/sign.form.php when the current report has no tech signature
 * of its own yet.
 *
 * Deliberately keyed by users_id only (one row per user, upserted) —
 * this is "my current signature", not a history.
 */
class SavedSignature
{
    public const TABLE = 'glpi_plugin_glpiticketreportsign_signatures';

    /** @return array{signature_png:string,signer_name:string}|null */
    public static function get(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }
        global $DB;
        if (!$DB->tableExists(self::TABLE)) {
            return null; // plugin update not yet run on an existing install
        }
        $row = $DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => ['users_id' => $userId],
            'LIMIT' => 1,
        ])->current();
        if (!is_array($row) || empty($row['signature_png'])) {
            return null;
        }
        return [
            'signature_png' => (string) $row['signature_png'],
            'signer_name'   => (string) ($row['signer_name'] ?? ''),
        ];
    }

    public static function save(int $userId, string $signaturePngDataUrl, string $signerName): void
    {
        if ($userId <= 0 || $signaturePngDataUrl === '') {
            return;
        }
        global $DB;
        $values = [
            'signature_png' => $signaturePngDataUrl,
            'signer_name'   => $signerName,
            'date_mod'      => date('Y-m-d H:i:s'),
        ];
        if (self::exists($userId)) {
            $DB->update(self::TABLE, $values, ['users_id' => $userId]);
        } else {
            $values['users_id'] = $userId;
            $DB->insert(self::TABLE, $values);
        }
    }

    public static function clear(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }
        global $DB;
        $DB->delete(self::TABLE, ['users_id' => $userId]);
    }

    private static function exists(int $userId): bool
    {
        global $DB;
        $row = $DB->request([
            'COUNT' => 'cpt',
            'FROM'  => self::TABLE,
            'WHERE' => ['users_id' => $userId],
        ])->current();
        return is_array($row) && (int) $row['cpt'] > 0;
    }
}
