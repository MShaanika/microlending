<?php

namespace App\Services;

use App\Support\DateOfBirth;
use App\Support\PhoneNumberNormalizer;

/**
 * Pre-flight check run BEFORE any call to Creditinfo. Creditinfo requires
 * firstName, presentSurname, gender, dateOfBirth, mobilePhone and the
 * National ID on the search request, and rejects the whole request with a
 * bare HTTP 400 when any is wrong. Checking here means staff get every
 * problem at once, each naming the field and how to fix it, and a bad
 * request never reaches (or counts against) the bureau.
 *
 * Pure: takes plain arrays/values, touches no database or network, so it is
 * unit-testable. Returns a list of issues:
 *   ['field' => 'dob', 'label' => 'Date of birth', 'severity' => 'error'|'warning',
 *    'problem' => 'what is wrong', 'fix' => 'what to do', 'sms' => 'short phrase for the client']
 * 'error' blocks the check; 'warning' is shown but does not block.
 */
final class CreditCheckReadiness
{
    public static function check(array $application, ?string $dateOfBirth, string $nationalId, ?int $genderCode, ?string $today = null): array
    {
        $issues = [];
        $add = function (string $field, string $label, string $severity, string $problem, string $fix, string $sms) use (&$issues): void {
            $issues[] = ['field' => $field, 'label' => $label, 'severity' => $severity, 'problem' => $problem, 'fix' => $fix, 'sms' => $sms];
        };

        // National ID: Namibian IDs are exactly 11 digits (first six = YYMMDD of birth).
        $id = trim($nationalId);
        $idDigits = preg_replace('/\s+/', '', $id);
        if ($idDigits === '') {
            $add('id_number', 'National ID', 'error', 'No National ID number is on the application.', 'Enter the 11-digit National ID from the applicant\'s ID card.', 'your ID number');
        } elseif (!preg_match('/^[A-Za-z0-9]+$/', $idDigits)) {
            $add('id_number', 'National ID', 'error', 'The National ID contains characters that cannot be part of an ID number.', 'Check the ID card and re-type the number without spaces or symbols.', 'your ID number');
        } elseif (!preg_match('/^\d{11}$/', $idDigits)) {
            // Namibian IDs are 11 digits, but the bureau also accepts other formats (its own test IDs include 13 digits): warn, never block.
            $add('id_number', 'National ID', 'warning', 'The National ID "' . self::mask($idDigits) . '" is not the usual 11 digits (it has ' . strlen($idDigits) . ' characters).', 'Check the ID card; ignore this if it is a foreign or older ID.', 'your ID number');
        } else {
            $yy = (int) substr($idDigits, 0, 2);
            $mm = (int) substr($idDigits, 2, 2);
            $dd = (int) substr($idDigits, 4, 2);
            $plausible = checkdate($mm, $dd, 1900 + $yy) || checkdate($mm, $dd, 2000 + $yy);
            if (!$plausible) {
                $add('id_number', 'National ID', 'warning', 'The first six digits of the National ID do not form a real birth date, so the ID may have a typing mistake.', 'Compare the number with the ID card.', 'your ID number');
            }
        }

        // Names.
        foreach ([['applicant_first_name', 'first_name', 'First name', 'first name'], ['applicant_last_name', 'last_name', 'Surname', 'surname']] as [$key, $field, $label, $sms]) {
            $v = trim((string) ($application[$key] ?? ''));
            if ($v === '') {
                $add($field, $label, 'error', $label . ' is missing.', 'Enter the ' . strtolower($label) . ' exactly as on the ID card.', 'your ' . $sms);
            } elseif (!preg_match('/\p{L}/u', $v)) {
                $add($field, $label, 'error', $label . ' contains no letters.', 'Enter the ' . strtolower($label) . ' exactly as on the ID card.', 'your ' . $sms);
            }
        }

        // Gender (Creditinfo has codes for Male/Female only).
        if ($genderCode === null) {
            $gender = trim((string) ($application['applicant_gender'] ?? ''));
            $add('gender', 'Gender', 'error', $gender === '' ? 'Gender is missing.' : 'Gender must be Male or Female (there is no Creditinfo code for anything else).', 'Set the gender to Male or Female as on the ID card.', 'your gender');
        }

        // Date of birth.
        $dobError = $dateOfBirth === null ? 'Date of birth is missing.' : DateOfBirth::check($dateOfBirth, $today);
        if ($dobError !== null) {
            $add('dob', 'Date of birth', 'error', $dobError, 'Correct the date of birth to match the ID card.', 'your date of birth');
        } elseif (preg_match('/^\d{11}$/', $idDigits)) {
            // Cross-check: the ID's own birth date should match the typed one. Warning only (never derive, never block).
            $dob = DateOfBirth::normalize($dateOfBirth);
            if ($dob !== null && substr($dob, 2, 2) . substr($dob, 5, 2) . substr($dob, 8, 2) !== substr($idDigits, 0, 6)) {
                $add('dob', 'Date of birth', 'warning', 'The date of birth ' . $dob . ' does not match the first six digits of the National ID (' . substr($idDigits, 0, 6) . ' = YYMMDD).', 'Check both the ID number and the date of birth against the ID card.', 'your date of birth or ID number');
            }
        }

        // Mobile phone.
        $phoneRaw = trim((string) ($application['applicant_phone'] ?? ''));
        $e164 = PhoneNumberNormalizer::toE164($phoneRaw);
        if ($phoneRaw === '' || $e164 === null) {
            $add('phone', 'Mobile phone', 'error', 'The mobile phone number is missing.', 'Enter the applicant\'s mobile number, for example 0811234567.', 'your mobile number');
        } elseif (!preg_match('/^\+264(6|8)\d{7,8}$/', $e164)) {
            $add('phone', 'Mobile phone', 'error', 'The phone number "' . self::mask($phoneRaw) . '" is not a valid Namibian mobile number.', 'Re-type it as 081 xxx xxxx (or +264 81 xxx xxxx).', 'your mobile number');
        }

        return $issues;
    }

    /** Only the blocking issues. */
    public static function errors(array $issues): array
    {
        return array_values(array_filter($issues, fn ($i) => $i['severity'] === 'error'));
    }

    /** One flash message listing every blocking problem and its fix. */
    public static function flashMessage(array $issues): string
    {
        $errors = self::errors($issues);
        $lines = [];
        foreach ($errors as $i) {
            $lines[] = $i['label'] . ': ' . $i['problem'] . ' ' . $i['fix'];
        }
        return 'The credit check was not sent to Creditinfo because the application data needs correcting. ' . implode(' | ', $lines);
    }

    /** Short client-facing list: "your date of birth and your mobile number". */
    public static function clientPhrase(array $issues): string
    {
        $parts = [];
        foreach ($issues as $i) {
            if ($i['severity'] === 'error' || $i['severity'] === 'warning') {
                $parts[$i['sms']] = true;
            }
        }
        $list = array_keys($parts);
        if (!$list) {
            return '';
        }
        $last = array_pop($list);
        return $list ? implode(', ', $list) . ' and ' . $last : $last;
    }

    private static function mask(string $v): string
    {
        $v = preg_replace('/[^\p{L}\p{N}+ ]/u', '', $v) ?? '';
        $len = strlen($v);
        return $len <= 4 ? $v : substr($v, 0, 2) . str_repeat('*', max(0, $len - 4)) . substr($v, -2);
    }
}
