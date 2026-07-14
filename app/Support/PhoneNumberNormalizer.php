<?php

namespace App\Support;

/**
 * Normalizes a locally-entered Namibian phone number into the E.164 format
 * Twilio requires (e.g. "0811234567" -> "+264811234567"). Applied at data
 * entry (online application intake) so every phone number we later SMS --
 * approval notices, portal credentials -- is already Twilio-valid, rather
 * than failing at send time.
 */
class PhoneNumberNormalizer
{
    private const DEFAULT_COUNTRY_CODE = '264';

    public static function toE164(?string $raw, string $countryCode = self::DEFAULT_COUNTRY_CODE): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }

        $hasPlus = str_starts_with($raw, '+');
        $digits = preg_replace('/\D/', '', $raw);
        if ($digits === '') {
            return null;
        }

        // Already international (+264... or 00264...)
        if ($hasPlus) {
            return '+' . $digits;
        }
        if (str_starts_with($digits, '00')) {
            return '+' . substr($digits, 2);
        }

        // Local trunk prefix (0811234567 -> 264811234567)
        if (str_starts_with($digits, '0')) {
            return '+' . $countryCode . substr($digits, 1);
        }

        // Already missing the leading 0 but otherwise a bare local number
        // (e.g. someone typed "811234567") -- prepend the country code.
        if (!str_starts_with($digits, $countryCode)) {
            return '+' . $countryCode . $digits;
        }

        return '+' . $digits;
    }
}
