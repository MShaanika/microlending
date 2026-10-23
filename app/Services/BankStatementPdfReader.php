<?php

namespace App\Services;

/**
 * Orchestrates reading a bank statement PDF for the Import Bank Statement screen:
 *   1. the exact reader for the bank (BankStatementPdfParser) -- trusted only when the statement's own arithmetic checks out;
 *   2. otherwise, when an OpenAI key is configured, the AI reader (AiBankStatementRowsReader) -- never trusted, always reviewed;
 *   3. otherwise whatever the exact reader found, shown with its problems highlighted.
 *
 * Also keeps the short-lived "review before saving" copy of what was read: a JSON file under storage/, named by an unguessable
 * token, readable only by the person who uploaded it, removed once imported, cancelled or two hours old.
 */
class BankStatementPdfReader
{
    public const MAX_BYTES = BankStatementPdfParser::MAX_BYTES;
    private const PREVIEW_TTL_SECONDS = 7200;

    /**
     * @return array{rows: array, opening: ?float, closing: ?float, bank: ?string, reader: string, trusted: bool, check: array, errors: string[], notes: string[]}
     */
    public static function read(string $pdfPath): array
    {
        $notes = [];
        $exact = BankStatementPdfParser::isAvailable()
            ? BankStatementPdfParser::parseFile($pdfPath)
            : ['ok' => false, 'bank' => null, 'rows' => [], 'opening' => null, 'closing' => null, 'check' => [], 'reader' => 'pdf',
               'errors' => ['PDF reading is not installed on this server yet (the Composer package smalot/pdfparser is missing).']];

        if ($exact['ok']) {
            return self::result($exact, true, $notes);
        }

        if (AiBankStatementRowsReader::isConfigured()) {
            $reason = $exact['errors'] ? implode(' ', array_slice($exact['errors'], 0, 2)) : 'The statement did not add up.';
            $ai = AiBankStatementRowsReader::read($pdfPath);
            if (!empty($ai['success'])) {
                $check = BankStatementPdfParser::verify($ai['rows'], $ai['opening'], $ai['closing']);
                $notes[] = 'The built-in reader could not verify this statement (' . trim($reason) . ') so it was read by AI instead. AI can misread an amount: compare every line with your statement.';
                if (!empty($ai['skipped'])) {
                    $notes[] = $ai['skipped'] . ' line(s) the AI returned were unusable and left out.';
                }
                return self::result([
                    'bank' => $exact['bank'], 'rows' => $ai['rows'], 'opening' => $ai['opening'], 'closing' => $ai['closing'],
                    'check' => $check, 'errors' => [], 'reader' => 'ai',
                ], false, $notes);
            }
            $notes[] = 'AI reading was also tried and failed: ' . ($ai['error'] ?? 'unknown error') . '.';
        }

        if ($exact['rows'] !== []) {
            $notes[] = 'The statement was read but it does not add up. The lines marked below do not follow from the previous balance.';
        }
        return self::result($exact, false, $notes);
    }

    private static function result(array $r, bool $trusted, array $notes): array
    {
        return [
            'rows' => $r['rows'],
            'opening' => $r['opening'],
            'closing' => $r['closing'],
            'bank' => $r['bank'],
            'reader' => $r['reader'],
            'trusted' => $trusted,
            'check' => $r['check'],
            'errors' => $r['errors'],
            'notes' => $notes,
        ];
    }

    // ------------------------------------------------------------------------------------------------------------------
    // review copy
    // ------------------------------------------------------------------------------------------------------------------

    /** @return string the token to put in the review URL */
    public static function savePreview(array $data): string
    {
        self::sweep();
        $dir = self::dir();
        $token = bin2hex(random_bytes(16));
        file_put_contents($dir . '/' . $token . '.json', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR), LOCK_EX);
        return $token;
    }

    /** @return array|null null when the token is malformed, unknown, expired, or belongs to another user */
    public static function loadPreview(string $token, int $userId): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        $file = self::dir() . '/' . $token . '.json';
        if (!is_file($file) || filemtime($file) < time() - self::PREVIEW_TTL_SECONDS) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data) || (int) ($data['user_id'] ?? 0) !== $userId || $userId === 0) {
            return null;
        }
        return $data;
    }

    public static function deletePreview(string $token): void
    {
        if (preg_match('/^[a-f0-9]{32}$/', $token)) {
            @unlink(self::dir() . '/' . $token . '.json');
        }
    }

    private static function dir(): string
    {
        $dir = STORAGE_PATH . '/bank_import_previews';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (!is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n");
        }
        return $dir;
    }

    private static function sweep(): void
    {
        foreach (glob(self::dir() . '/*.json') ?: [] as $file) {
            if (filemtime($file) < time() - self::PREVIEW_TTL_SECONDS) {
                @unlink($file);
            }
        }
    }
}
