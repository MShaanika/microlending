<?php

namespace App\Services;

/**
 * Turns the rows staff filled in on the "missing details" sheet into corrected identities for the legacy CPL file, and says what is wrong with every row that
 * cannot be used. Nothing is written to the old loan system: the corrections only overlay the file that is built.
 *
 * A Namibian ID is 11 digits whose first six are a real date of birth. Anything else is a non-Namibian ID: digits only (the bureau field is numeric, 13 wide)
 * and the date of birth must be given. A row with no ID filled in is simply "not answered yet" and is neither applied nor an error.
 */
final class LegacyIdCorrections
{
    /**
     * @param array<int, array<string,mixed>> $rows keys: borrower_id, id_number, date_of_birth (optional)
     * @return array{applied: array<int, array{na_id:string, non_na_id:string, date_of_birth:?string}>, rejected: array<int, array{row:int, borrower_id:string, why:string}>, unanswered: int}
     */
    public function parse(array $rows, string $today): array
    {
        $applied = [];
        $rejected = [];
        $unanswered = 0;

        foreach (array_values($rows) as $i => $row) {
            $line = $i + 2; // the sheet's own row number (row 1 holds the headings)
            $rawId = $this->tidy((string) ($row['id_number'] ?? ''));
            $rawBorrower = $this->tidy((string) ($row['borrower_id'] ?? ''));
            if ($rawBorrower === '' && $rawId === '') {
                continue;
            }
            if ($rawId === '') {
                $unanswered++;
                continue;
            }
            if (!preg_match('/^\d+$/', $rawBorrower) || (int) $rawBorrower < 1) {
                $rejected[] = ['row' => $line, 'borrower_id' => $rawBorrower, 'why' => 'the customer number (first column) was changed or removed'];
                continue;
            }
            $borrower = (int) $rawBorrower;
            if (isset($applied[$borrower])) {
                $rejected[] = ['row' => $line, 'borrower_id' => $rawBorrower, 'why' => 'this customer appears twice on the sheet'];
                continue;
            }

            $id = preg_replace('/[\s\-.]/', '', $rawId) ?? '';
            if (!empty($row['numeric_cell']) && strlen($id) === 10) {
                $rejected[] = ['row' => $line, 'borrower_id' => $rawBorrower, 'why' => 'the ID was typed as a number, so a leading zero may have been lost (10 digits); retype it as text with the leading zero'];
                continue;
            }
            if (!preg_match('/^\d+$/', $id)) {
                $rejected[] = ['row' => $line, 'borrower_id' => $rawBorrower, 'why' => 'the ID number may only contain digits (passport letters cannot be reported in this field; ask Creditinfo what to do)'];
                continue;
            }

            if (strlen($id) === 11) {
                $dob = $this->dobFromNaId($id, $today);
                if ($dob === null) {
                    $rejected[] = ['row' => $line, 'borrower_id' => $rawBorrower, 'why' => 'the first six digits of this 11-digit ID are not a believable date of birth (a real date, age 16 to 110), so it is not a valid Namibian ID'];
                    continue;
                }
                $applied[$borrower] = ['na_id' => $id, 'non_na_id' => '', 'date_of_birth' => $dob];
                continue;
            }
            if (strlen($id) < 5 || strlen($id) > 13) {
                $rejected[] = ['row' => $line, 'borrower_id' => $rawBorrower, 'why' => 'the ID number has ' . strlen($id) . ' digits; a Namibian ID has 11 and another ID must have 5 to 13'];
                continue;
            }
            $dob = $this->parseDate((string) ($row['date_of_birth'] ?? ''), $today);
            if ($dob === null) {
                $rejected[] = ['row' => $line, 'borrower_id' => $rawBorrower, 'why' => 'this is not an 11-digit Namibian ID, so a real date of birth (like 1985-05-15) is required'];
                continue;
            }
            $applied[$borrower] = ['na_id' => '', 'non_na_id' => $id, 'date_of_birth' => $dob];
        }

        // The same ID given to two different customers is never right.
        $byId = [];
        foreach ($applied as $b => $a) {
            $byId[$a['na_id'] . $a['non_na_id']][] = $b;
        }
        foreach ($byId as $id => $borrowers) {
            if (count($borrowers) > 1) {
                foreach ($borrowers as $b) {
                    unset($applied[$b]);
                    $rejected[] = ['row' => 0, 'borrower_id' => (string) $b, 'why' => 'the ID number ' . $id . ' was given to more than one customer'];
                }
            }
        }

        return ['applied' => $applied, 'rejected' => $rejected, 'unanswered' => $unanswered];
    }

    /**
     * Only customers who really are missing details may be corrected: an answer for any other customer number is refused, so a retyped customer number can
     * never overwrite a good ID.
     *
     * @param array{applied: array<int,array>, rejected: array<int,array>, unanswered:int} $report parse() output
     * @param int[] $allowedBorrowers the customers on the missing-details list
     */
    public function restrictTo(array $report, array $allowedBorrowers): array
    {
        $allowed = array_flip($allowedBorrowers);
        foreach (array_keys($report['applied']) as $b) {
            if (!isset($allowed[$b])) {
                unset($report['applied'][$b]);
                $report['rejected'][] = ['row' => 0, 'borrower_id' => (string) $b, 'why' => 'this customer is not on the missing-details list (customer number changed?)'];
            }
        }
        return $report;
    }

    private function tidy(string $v): string
    {
        return trim(preg_replace('/[\p{Z}\x{200B}\x{2010}-\x{2015}\x{2212}]+/u', ' ', $v) ?? $v);
    }

    /**
     * Reads the completed sheet (.xlsx or .csv) whose headings were written by LegacyStaffSheet.
     *
     * @return array<int, array<string,mixed>>
     */
    public function readFile(string $path): array
    {
        $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $sheet = $book->getSheetByName(LegacyStaffSheet::DATA_SHEET) ?? $book->getActiveSheet();
        $grid = $sheet->toArray(null, true, false, false);
        $head = array_shift($grid) ?: [];
        $col = [];
        foreach ($head as $i => $h) {
            $col[strtolower(trim((string) $h))] = $i;
        }
        foreach ([LegacyStaffSheet::HEAD_BORROWER, LegacyStaffSheet::HEAD_ID, LegacyStaffSheet::HEAD_DOB] as $h) {
            if (!isset($col[strtolower($h)])) {
                throw new \RuntimeException('The sheet has no column headed "' . $h . '": use the sheet exactly as it was sent out.');
            }
        }
        $rows = [];
        foreach ($grid as $r) {
            $dob = $r[$col[strtolower(LegacyStaffSheet::HEAD_DOB)]] ?? '';
            if ((is_int($dob) || is_float($dob)) && $dob > 0 && $dob < 100000) {
                $dob = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $dob)->format('Y-m-d'); // a real Excel date cell
            }
            $idCell = $r[$col[strtolower(LegacyStaffSheet::HEAD_ID)]] ?? '';
            $rows[] = [
                'borrower_id' => $r[$col[strtolower(LegacyStaffSheet::HEAD_BORROWER)]] ?? '',
                'numeric_cell' => is_int($idCell) || is_float($idCell),
                'id_number' => is_float($idCell) ? sprintf('%.0f', $idCell) : $idCell,
                'date_of_birth' => $dob,
            ];
        }
        return $rows;
    }

    private function dobFromNaId(string $id, string $today): ?string
    {
        if (!preg_match('/^(\d{2})(\d{2})(\d{2})\d{5}$/', $id, $m)) {
            return null;
        }
        $yy = (int) $m[1];
        $year = 2000 + $yy;
        if (!checkdate((int) $m[2], (int) $m[3], $year) || sprintf('%04d%02d%02d', $year, $m[2], $m[3]) > str_replace('-', '', $today)) {
            $year = 1900 + $yy; // a 20xx birth date in the future can only be 19xx
        }
        if (!checkdate((int) $m[2], (int) $m[3], $year)) {
            return null;
        }
        $iso = sprintf('%04d%02d%02d', $year, $m[2], $m[3]);
        return $this->ageOk($iso, $today) ? $iso : null;
    }

    /** YYYY-MM-DD, YYYYMMDD or DD/MM/YYYY, a real past date, borrower aged 16 to 110. Returns YYYYMMDD. */
    private function parseDate(string $raw, string $today): ?string
    {
        $raw = trim($raw);
        if (preg_match('/^(\d{4})[-\/]?(\d{2})[-\/]?(\d{2})$/', $raw, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})$/', $raw, $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }
        if (!checkdate($mo, $d, $y)) {
            return null;
        }
        $iso = sprintf('%04d%02d%02d', $y, $mo, $d);
        return $this->ageOk($iso, $today) ? $iso : null;
    }

    private function ageOk(string $iso, string $today): bool
    {
        $todayCompact = str_replace('-', '', $today);
        $age = (int) substr($todayCompact, 0, 4) - (int) substr($iso, 0, 4) - (substr($todayCompact, 4) < substr($iso, 4) ? 1 : 0);
        return $age >= 16 && $age <= 110;
    }
}
