<?php

namespace App\Services;

/**
 * Makes an ANONYMOUS copy of a Creditinfo report that may be handed to the AI analyser to be explained in plain words.
 *
 * What the AI needs is the credit record: scores, arrears, balances, enquiries, defaults. It never needs to know who the person is, so
 * everything that identifies them or their accounts is removed here, before anything leaves the system:
 *  - whole sections that describe the person (personal details, address and contact history, identification, report and request
 *    information) are dropped, and only sections about the credit record are kept;
 *  - inside the kept sections, every field whose name points to a name, an ID or passport number, a date of birth, an address, a phone
 *    number, an e-mail address, an employer, a token or a reference number is dropped;
 *  - lender and creditor names are replaced by "Lender A", "Lender B" ... (the same lender always gets the same letter);
 *  - phone numbers, e-mail addresses and long digit runs that turn up inside text values are replaced by "[removed]", and a text value that
 *    is a whole sentence or longer (a dispute narrative, a remark) is removed altogether;
 *  - long lists (36 months of payments per account, say) are cut to the most recent entries so the copy stays small.
 * The result is checked once more for anything that still looks like an identifier, and the method throws rather than return it.
 *
 * The rules go by field NAMES and VALUE PATTERNS, not by one fixed report layout, so they keep working when Creditinfo adds or renames
 * fields: anything not recognised as part of the credit record is left out, and anything that looks personal is removed.
 */
class CreditinfoReportMinimizer
{
    /** Top-level sections kept, by the start of their name (case-insensitive). Anything else is dropped. */
    private const KEEP_SECTION = '/^(dashboard|contract|dispute|inquir|enquir|judg|scor|default|public|payment|calendar|collateral|summary|overview|profile|trend|rating|risk)/i';

    /** Words in a field name that mean "this identifies a person or an account": the whole field is dropped. */
    private const DENY_WORDS = [
        'name', 'names', 'surname', 'forename', 'forenames', 'firstname', 'lastname', 'initial', 'initials', 'maiden',
        'address', 'street', 'postal', 'postcode', 'zip', 'city', 'town', 'village', 'suburb', 'province', 'country',
        'phone', 'telephone', 'tel', 'cell', 'cellular', 'mobile', 'fax', 'email', 'mail',
        'birth', 'birthday', 'dob', 'gender', 'sex',
        'id', 'ids', 'identity', 'identification', 'passport',
        'token', 'guid', 'uuid', 'user', 'username', 'requested',
        'employer', 'occupation', 'income', 'signature', 'reference', 'refno',
        // reference numbers, ways to reach someone, and places
        'ref', 'refs', 'contact', 'region', 'location', 'district', 'url', 'uri', 'link', 'href',
        // free text typed by a person (a dispute, a remark) can say anything, including who they are
        'comment', 'comments', 'remark', 'remarks', 'note', 'notes', 'narrative', 'memo', 'message', 'explanation',
        // the other people in a record: the debtor is the subject, the rest are lawyers and the like
        'debtor', 'defendant', 'respondent', 'consumer', 'customer', 'holder', 'owner', 'applicant', 'person', 'individual', 'attorney', 'lawyer',
    ];

    /** Whole-field-name fragments (words joined together) that identify an account, a court case or a person. */
    private const DENY_COMPOUNDS = ['nationalid', 'idnumber', 'contractcode', 'contractnumber', 'accountnumber', 'accountno', 'accountcode', 'referencenumber', 'referenceno', 'casenumber', 'caseno', 'courtcase'];

    /** Words in a field name that mean "this is a lender": the text value becomes Lender A, Lender B ... */
    private const ALIAS_WORDS = ['subscriber', 'creditor', 'lender', 'bank', 'institution', 'company', 'supplier', 'provider', 'issuer', 'plaintiff', 'claimant', 'originator'];

    /** A text value longer than this is a sentence or a paragraph typed by someone, not a code or a label: it is removed whole. */
    private const MAX_TEXT_CHARS = 120;

    /** @var array<string, string> original lender text (upper case) => alias */
    private array $aliases = [];

    /** @var array<string, string> alias => the lender's name as first written in the report */
    private array $originals = [];

    /**
     * @param array<string, mixed> $report the report object as stored (the "report" part of the Creditinfo response)
     * @return array<string, mixed> the anonymous copy, empty when no section about the credit record was found
     * @throws \RuntimeException when something that still looks like an identifier could not be removed
     */
    public function minimize(array $report, int $maxChars = 60000): array
    {
        $this->aliases = [];
        $this->originals = [];

        $kept = [];
        foreach ($report as $section => $value) {
            if (is_array($value) && $value !== [] && preg_match(self::KEEP_SECTION, (string) $section)) {
                $kept[(string) $section] = $value;
            }
        }
        if ($kept === []) {
            return [];
        }

        $clean = $this->clean($kept);

        // Smaller and smaller copies until it fits: the most recent entries of each long list are the ones that matter.
        $result = $clean;
        foreach ([14, 8, 4] as $maxItems) {
            $result = $this->limitLists($clean, $maxItems);
            if (strlen((string) json_encode($result)) <= $maxChars) {
                break;
            }
        }
        // Still too big: drop the largest sections, never the dashboard.
        while (strlen((string) json_encode($result)) > $maxChars && count($result) > 1) {
            $sizes = [];
            foreach ($result as $section => $value) {
                $sizes[$section] = strlen((string) json_encode($value));
            }
            arsort($sizes);
            $dropped = false;
            foreach (array_keys($sizes) as $section) {
                if (strcasecmp((string) $section, 'dashboard') !== 0) {
                    unset($result[$section]);
                    $dropped = true;
                    break;
                }
            }
            if (!$dropped) {
                break;
            }
        }

        $this->assertNothingIdentifiable((string) json_encode($result, JSON_UNESCAPED_UNICODE));
        return $result;
    }

    /**
     * The real name behind each alias in the LAST report that was minimised ("Lender A" => "Old Mutual Finance (Pty) Ltd", spelled as the report
     * spells it the first time). The names never leave this system: the AI only ever sees Lender A, Lender B ... and the page puts the names
     * back into the explanation afterwards, so staff can tell the applicant which lender a debt is with.
     *
     * @return array<string, string>
     */
    public function lenders(): array
    {
        return $this->originals;
    }

    // ---- cleaning ----------------------------------------------------------------------------------------------------

    private function clean(mixed $node): mixed
    {
        if (is_array($node)) {
            if ($this->isList($node)) {
                return array_map(fn ($item) => $this->clean($item), $node);
            }
            $out = [];
            foreach ($node as $key => $value) {
                $key = (string) $key;
                $words = $this->words($key);
                $denyHits = array_intersect($words, self::DENY_WORDS);
                $compoundHit = $this->hasDenyCompound($words);

                $isLender = array_intersect($words, self::ALIAS_WORDS) !== [];
                if ($isLender && is_string($value) && !$compoundHit && array_diff($denyHits, ['name', 'names']) === []) {
                    $out[$key] = $this->aliasFor($value);
                    continue;
                }
                if ($denyHits !== [] || $compoundHit) {
                    continue;
                }
                $out[$key] = $this->clean($value);
            }
            return $out;
        }

        if (is_string($node)) {
            $text = $this->scrubString($node);
            return mb_strlen($text) > self::MAX_TEXT_CHARS ? '[text removed]' : $text;
        }
        if (is_int($node) && strlen((string) abs($node)) >= 9) {
            return '[removed]';
        }
        if (is_float($node) && floor($node) === $node && strlen((string) abs((int) $node)) >= 9) {
            return '[removed]';
        }
        return $node;
    }

    /** @return string[] the lower-case words of a field name: camelCase, PascalCase, snake_case and ACRONYMWord all split */
    private function words(string $key): array
    {
        $spaced = (string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', ' ', $key);
        $parts = preg_split('/[^A-Za-z0-9]+/', $spaced, -1, PREG_SPLIT_NO_EMPTY);
        return array_map('strtolower', $parts !== false ? $parts : []);
    }

    /** @param string[] $words */
    private function hasDenyCompound(array $words): bool
    {
        $joined = implode('', $words);
        foreach (self::DENY_COMPOUNDS as $compound) {
            if (str_contains($joined, $compound)) {
                return true;
            }
        }
        return false;
    }

    private function aliasFor(string $value): string
    {
        $normalised = mb_strtoupper(trim($value));
        if (!isset($this->aliases[$normalised])) {
            $index = count($this->aliases);
            $this->aliases[$normalised] = 'Lender ' . ($index < 26 ? chr(65 + $index) : (string) ($index + 1));
            $this->originals[$this->aliases[$normalised]] = trim($value);
        }
        return $this->aliases[$normalised];
    }

    /** E-mail addresses and phone- or ID-like digit runs inside a text value become [removed]; dates and amounts are left alone. */
    private function scrubString(string $text): string
    {
        // An ISO date or timestamp is never personal, and its many digits must not be mistaken for a number.
        if (preg_match('/^\d{4}-\d{2}-\d{2}(?:[T ][\d:.]+Z?)?$/', $text)) {
            return $text;
        }
        $text = (string) preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[removed]', $text);
        return (string) preg_replace_callback('/\+?\d[\d \-().]{7,}\d/', static function (array $m): string {
            $digits = preg_replace('/\D/', '', $m[0]);
            // An amount such as 1136984.00 has a decimal point followed by one or two digits at its end; a phone or ID number does not.
            $looksLikeAmount = (bool) preg_match('/^\d+\.\d{1,2}$/', trim($m[0]));
            return strlen((string) $digits) >= 9 && !$looksLikeAmount ? '[removed]' : $m[0];
        }, $text);
    }

    // ---- shrinking ---------------------------------------------------------------------------------------------------

    private function limitLists(mixed $node, int $maxItems): mixed
    {
        if (!is_array($node)) {
            return $node;
        }
        if ($this->isList($node)) {
            if (count($node) > $maxItems) {
                $node = array_slice($this->newestFirst($node), 0, $maxItems);
            }
            return array_map(fn ($item) => $this->limitLists($item, $maxItems), $node);
        }
        $out = [];
        foreach ($node as $key => $value) {
            $out[$key] = $this->limitLists($value, $maxItems);
        }
        return $out;
    }

    /**
     * Orders a long list newest first when its entries carry a period or a date (monthly payment rows do); otherwise leaves it as it is,
     * on the assumption that it already starts with the most recent entry.
     *
     * @param array<int, mixed> $list
     * @return array<int, mixed>
     */
    private function newestFirst(array $list): array
    {
        $first = $list[0] ?? null;
        if (!is_array($first)) {
            return $list;
        }
        $dateKey = null;
        foreach (array_keys($first) as $key) {
            if (preg_match('/(period|date|month)/i', (string) $key) && $this->timeOf($first[$key]) !== null) {
                $dateKey = $key;
                break;
            }
        }
        if ($dateKey === null) {
            return $list;
        }
        usort($list, fn ($a, $b) => ($this->timeOf(is_array($b) ? ($b[$dateKey] ?? null) : null) ?? 0) <=> ($this->timeOf(is_array($a) ? ($a[$dateKey] ?? null) : null) ?? 0));
        return $list;
    }

    private function timeOf(mixed $value): ?int
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $time = strtotime(preg_match('/^\d{4}\s+[A-Za-z]{3}/', $value) ? preg_replace('/^(\d{4})\s+([A-Za-z]{3}).*/', '$2 $1', $value) : $value);
        return $time === false ? null : $time;
    }

    /** @param array<mixed> $value */
    private function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }

    // ---- last line of defence ----------------------------------------------------------------------------------------

    /**
     * @throws \RuntimeException if the copy still contains an e-mail address, a phone-like number or a national-ID-like number
     */
    private function assertNothingIdentifiable(string $json): void
    {
        $withoutDates = (string) preg_replace('/\d{4}-\d{2}-\d{2}(?:T[\d:.]+Z?)?/', '', $json);
        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $withoutDates)
            || preg_match('/(?<![\d.])(?:\+?264|0)8\d{8}(?!\d)/', $withoutDates)
            || preg_match('/(?<![\d.,])\d{11}(?![\d.,])|(?<![\d.,])\d{13}(?![\d.,])/', $withoutDates)
        ) {
            throw new \RuntimeException('The anonymised copy of the report still contains something that looks like an identifier, so it was not sent.');
        }
    }
}
