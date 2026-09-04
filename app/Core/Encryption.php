<?php

namespace App\Core;

/**
 * Reversible at-rest encryption for secrets that must be sent back out
 * verbatim later (e.g. a third-party API credential) -- distinct from
 * password_hash()/password_verify(), which is one-way and only for login
 * passwords. Currently used by App\Models\CollexiaSetting for the Collexia
 * EnDO Authentication Credential and Digital Signature secret.
 *
 * AES-256-GCM: a random IV per call (never reused) plus an authentication
 * tag, so tampering with the stored value is detected on decrypt rather
 * than silently producing garbage. The key itself is never stored in the
 * database -- only in config/security.php, which (like config/database.php)
 * holds a placeholder in git and a real value set locally, uncommitted, on
 * each environment.
 */
class Encryption
{
    private const CIPHER = 'aes-256-gcm';
    private const TAG_LENGTH = 16;

    public static function encrypt(string $plaintext): string
    {
        $key = self::key();
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $iv = random_bytes($ivLength);
        $tag = '';

        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed.');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    /** Returns null (rather than throwing) on a corrupt/tampered value, so a caller can treat it as "not set". */
    public static function decrypt(string $encoded): ?string
    {
        $key = self::key();
        $raw = base64_decode($encoded, true);
        if ($raw === false) {
            return null;
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        if (strlen($raw) < $ivLength + self::TAG_LENGTH) {
            return null;
        }

        $iv = substr($raw, 0, $ivLength);
        $tag = substr($raw, $ivLength, self::TAG_LENGTH);
        $ciphertext = substr($raw, $ivLength + self::TAG_LENGTH);

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        return $plaintext === false ? null : $plaintext;
    }

    /** Derives a 32-byte AES-256 key from the configured secret, whatever its raw length. */
    private static function key(): string
    {
        $config = require ROOT_PATH . '/config/security.php';
        $raw = (string) ($config['encryption_key'] ?? '');

        if ($raw === '' || $raw === 'CHANGE_ME_GENERATE_A_RANDOM_SECRET') {
            throw new \RuntimeException(
                'config/security.php: encryption_key is not set. Generate one (e.g. bin2hex(random_bytes(32))) '
                . 'and set it locally on this environment -- never commit the real value.'
            );
        }

        return hash('sha256', $raw, true);
    }
}
