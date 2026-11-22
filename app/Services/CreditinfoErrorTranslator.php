<?php

namespace App\Services;

/**
 * Turns a Creditinfo failure into a sentence staff can act on. The vendor
 * never documented its error body (see CreditinfoApiException), so the
 * parser is deliberately tolerant: it collects message-like strings from the
 * usual places (message, errors[] / errors{field:[..]}, modelState, detail,
 * title, error_description) and maps known field names to plain words.
 * Only the translated words are returned -- never the raw body, which may
 * echo the applicant's personal data.
 */
final class CreditinfoErrorTranslator
{
    private const FIELDS = [
        'dateofbirth' => ['Date of birth', 'Correct the date of birth on the application, then run the check again.'],
        'firstname' => ['First name', 'Correct the first name on the application, then run the check again.'],
        'presentsurname' => ['Surname', 'Correct the surname on the application, then run the check again.'],
        'surname' => ['Surname', 'Correct the surname on the application, then run the check again.'],
        'gender' => ['Gender', 'Set the gender to Male or Female on the application, then run the check again.'],
        'mobilephone' => ['Mobile phone', 'Correct the mobile number on the application, then run the check again.'],
        'idnumber' => ['National ID', 'Check the 11-digit National ID on the application, then run the check again.'],
        'idnumberslist' => ['National ID', 'Check the 11-digit National ID on the application, then run the check again.'],
        'inquiryreason' => ['Inquiry reason', 'The inquiry reason code is rejected. Check Settings > Integrations > Creditinfo.'],
    ];

    public static function translate(CreditinfoApiException $e): string
    {
        $status = $e->httpStatus();
        $body = $e->rawBody();

        if ($e instanceof CreditinfoAuthException) {
            return 'Creditinfo refused our login' . ($status ? ' (HTTP ' . $status . ')' : '') . '. This is not an applicant data problem: the administrator must check the Creditinfo credentials under Settings > Integrations > Creditinfo. Do not retry repeatedly.';
        }
        if ($e instanceof CreditinfoTimeoutException) {
            return 'Creditinfo did not answer in time. The search may or may not have reached the bureau, so wait a few minutes and check the application before running it again.';
        }

        if ($status === null || $status === 0) {
            // No HTTP answer at all (network/timeout/config/disabled): the message is app-authored and safe.
            return $e->getMessage() . ' No request was made to the bureau. If this keeps happening, tell the administrator.';
        }
        if ($status === 400 || $status === 422) {
            $found = self::fieldsFromBody($body);
            if ($found) {
                $lines = [];
                foreach ($found as [$label, $fix]) {
                    $lines[] = $label . ' was rejected by Creditinfo. ' . $fix;
                }
                return implode(' ', array_unique($lines));
            }
            return 'Creditinfo rejected the search details (HTTP ' . $status . ') without saying which field. Check the National ID, names, gender, date of birth and mobile number on the application against the ID card, correct any mistake and run the check again.';
        }
        if ($status === 401 || $status === 403) {
            return 'Creditinfo refused our login (HTTP ' . $status . '). This is not an applicant data problem: the administrator must check the Creditinfo credentials under Settings > Integrations > Creditinfo. Do not retry repeatedly.';
        }
        if ($status === 404) {
            return 'Creditinfo could not find what was asked for (HTTP 404). Run the check again; if it repeats, tell the administrator.';
        }
        if ($status === 429) {
            return 'Creditinfo says there have been too many requests (HTTP 429). Wait a few minutes before running the check again.';
        }
        if ($status >= 500) {
            return 'Creditinfo is having a problem on its side (HTTP ' . $status . '). Nothing is wrong with the application; try again in a few minutes.';
        }
        return 'Creditinfo returned an unexpected answer (HTTP ' . $status . '). Try again; if it repeats, tell the administrator.';
    }

    /** @return array<int, array{0:string,1:string}> unique [label, fix] pairs of known fields named in the body */
    public static function fieldsFromBody(?string $body): array
    {
        $data = json_decode((string) $body, true);
        if (!is_array($data)) {
            return [];
        }
        $texts = [];
        $walk = function ($node) use (&$walk, &$texts): void {
            foreach ($node as $k => $v) {
                if (is_string($k)) {
                    $texts[] = $k; // field names used as keys (errors: {DateOfBirth: [...]})
                }
                if (is_string($v)) {
                    $texts[] = $v;
                } elseif (is_array($v)) {
                    $walk($v);
                }
            }
        };
        $walk($data);
        $out = [];
        foreach ($texts as $t) {
            $norm = strtolower(preg_replace('/[^A-Za-z]/', '', $t));
            foreach (self::FIELDS as $needle => $pair) {
                if ($norm !== '' && strpos($norm, $needle) !== false) {
                    $out[$pair[0]] = $pair;
                }
            }
        }
        return array_values($out);
    }
}
