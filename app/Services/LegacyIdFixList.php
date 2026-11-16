<?php

namespace App\Services;

/**
 * For the accounts the bureau file cannot carry because the customer has no usable ID number in the old loan system: one row per customer for staff to fix,
 * with a SUGGESTED ID taken from the applications received through the new system (they hold the ID number from the credit check). A suggestion is only a
 * lead for a person to confirm against the ID copy; it is never written anywhere automatically.
 *
 * Matching: the phone number (last nine digits) AND the surname = "high"; the phone number alone, or first name + surname without a phone = "medium".
 */
final class LegacyIdFixList
{
    /**
     * @param array<int, array{loan_id:int, borrower_id:int, account_no:string, reasons:string[], notes:string[], name?:string, ref_no?:string, phone?:string}> $exceptions
     * @param array<int, array{id_number?:string, first_name?:string, last_name?:string, phone?:string}> $applications
     * @return array<int, array{borrower_id:int, name:string, phone:string, loans:string, problem:string, suggested_id:string, confidence:string, matched_with:string}>
     */
    public function build(array $exceptions, array $applications): array
    {
        $byPhone = [];
        foreach ($applications as $i => $a) {
            $key = $this->phoneKey((string) ($a['phone'] ?? ''));
            if ($key !== '' && trim((string) ($a['id_number'] ?? '')) !== '') {
                $byPhone[$key][] = $i;
            }
        }

        $rows = [];
        foreach ($exceptions as $e) {
            $id = (int) $e['borrower_id'];
            $rows[$id] ??= ['borrower_id' => $id, 'name' => (string) ($e['name'] ?? ''), 'phone' => (string) ($e['phone'] ?? ''), 'loan_list' => [], 'problems' => []];
            $rows[$id]['loan_list'][] = ($e['ref_no'] ?? '') !== '' ? $e['ref_no'] : $e['account_no'];
            foreach ($e['notes'] as $note) {
                if (str_contains($note, 'ID')) {
                    $rows[$id]['problems'][$note] = true;
                }
            }
        }

        $out = [];
        foreach ($rows as $row) {
            [$suggested, $confidence, $with] = $this->suggest($row['name'], $row['phone'], $applications, $byPhone);
            $out[] = [
                'borrower_id' => $row['borrower_id'],
                'name' => $row['name'],
                'phone' => $row['phone'],
                'loans' => implode(' ', array_unique($row['loan_list'])),
                'problem' => implode('; ', array_keys($row['problems'])) ?: 'no usable ID number',
                'suggested_id' => $suggested,
                'confidence' => $confidence,
                'matched_with' => $with,
            ];
        }
        usort($out, static fn (array $a, array $b): int => [$a['confidence'] === '' ? 1 : 0, $a['name']] <=> [$b['confidence'] === '' ? 1 : 0, $b['name']]);
        return $out;
    }

    /** @return array{0:string,1:string,2:string} suggested ID, confidence, what it was matched on */
    private function suggest(string $name, string $phone, array $applications, array $byPhone): array
    {
        $surname = $this->surname($name);
        $key = $this->phoneKey($phone);
        $first = strtolower((string) strtok(trim($name), ' '));

        $best = ['', '', ''];
        foreach ($byPhone[$key] ?? [] as $i) {
            $a = $applications[$i];
            $sameSurname = $surname !== '' && strtolower(trim((string) ($a['last_name'] ?? ''))) === $surname;
            $candidate = [trim((string) $a['id_number']), $sameSurname ? 'high' : 'medium', $sameSurname ? 'phone number and surname' : 'phone number only'];
            if ($best[1] === '' || ($candidate[1] === 'high' && $best[1] !== 'high')) {
                $best = $candidate;
            }
        }
        if ($best[1] !== '') {
            return $best;
        }
        foreach ($applications as $a) {
            if (trim((string) ($a['id_number'] ?? '')) !== '' && $surname !== '' && strtolower(trim((string) ($a['last_name'] ?? ''))) === $surname
                && $first !== '' && strtolower(trim((string) ($a['first_name'] ?? ''))) === $first) {
                return [trim((string) $a['id_number']), 'medium', 'first name and surname (no phone match)'];
            }
        }
        return ['', '', ''];
    }

    /** The last nine digits of a phone number, so 081 234 5678 and +264 81 234 5678 are the same. */
    private function phoneKey(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        return strlen($digits) >= 9 ? substr($digits, -9) : '';
    }

    private function surname(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        return strtolower((string) end($parts));
    }
}
