<?php

namespace App\Services;

use RuntimeException;

/**
 * Reads a bank statement PDF into rows ready for accounting_bank_statement (the same row shape as BankStatementCsvParser).
 *
 * Text is pulled out of the PDF with smalot/pdfparser (pure PHP, nothing to install on the server besides the Composer package).
 * Two layouts are understood exactly: First National Bank Namibia and Bank Windhoek. Every result is then CHECKED against the
 * statement's own arithmetic -- each line's balance must equal the previous balance plus money in minus money out, and the last
 * balance must equal the closing balance -- so a mis-read line can never slip through quietly. If the check fails, or the
 * layout is not recognised, the caller can fall back to AiBankStatementRowsReader and show the result for review.
 *
 * Keep the pure parts (parseLines, verify) free of file access: they are unit-tested with plain text fixtures.
 */
class BankStatementPdfParser
{
    public const MAX_BYTES = 10 * 1024 * 1024;
    public const MAX_PAGES = 80;

    private const MONTHS = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];
    private const NUMBER = '-?\d[\d,]*\.\d{2}';

    public static function isAvailable(): bool
    {
        return class_exists(\Smalot\PdfParser\Parser::class);
    }

    /**
     * Whole pipeline for one file: extract text, read it with the exact parser for the bank, verify.
     *
     * @return array{ok: bool, bank: ?string, rows: array, opening: ?float, closing: ?float, errors: string[], check: array, reader: string}
     */
    public static function parseFile(string $path): array
    {
        try {
            $lines = self::extractLines($path);
        } catch (RuntimeException $e) {
            return self::failure([$e->getMessage()]);
        }

        $result = self::parseLines($lines);
        if ($result['rows'] === []) {
            $result['errors'] = $result['errors'] ?: ['No transaction lines could be read from this PDF.'];
            return $result;
        }
        $result['check'] = self::verify($result['rows'], $result['opening'], $result['closing']);
        $result['ok'] = $result['errors'] === [] && $result['check']['balanced'];
        return $result;
    }

    /** @return string[] non-empty text lines of every page, in reading order */
    public static function extractLines(string $path): array
    {
        if (!self::isAvailable()) {
            throw new RuntimeException('PDF reading is not installed on this server yet. Please import a CSV file instead, or ask your administrator to run "composer install".');
        }
        if (!is_file($path) || filesize($path) === 0) {
            throw new RuntimeException('The uploaded PDF is empty.');
        }
        if (filesize($path) > self::MAX_BYTES) {
            throw new RuntimeException('The PDF is too large (maximum 10MB).');
        }

        try {
            $pdf = (new \Smalot\PdfParser\Parser())->parseFile($path);
            $pages = $pdf->getPages();
        } catch (\Throwable $e) {
            throw new RuntimeException('This PDF could not be opened. It may be damaged or password-protected.');
        }
        if (count($pages) > self::MAX_PAGES) {
            throw new RuntimeException('The PDF has too many pages (maximum ' . self::MAX_PAGES . ').');
        }

        $lines = [];
        foreach ($pages as $page) {
            foreach (preg_split('/\R/u', (string) $page->getText()) ?: [] as $line) {
                // PDF text often uses non-breaking spaces; make them ordinary so the patterns below match.
                $line = trim(preg_replace('/[\x{00A0}\x{2007}\x{202F}]/u', ' ', $line) ?? $line);
                if ($line !== '') {
                    $lines[] = $line;
                }
            }
        }
        if ($lines === []) {
            throw new RuntimeException('No text could be read from this PDF. It looks like a scanned picture, not a bank-issued statement.');
        }
        return $lines;
    }

    /**
     * @param string[] $lines
     * @return array{ok: bool, bank: ?string, rows: array, opening: ?float, closing: ?float, errors: string[], check: array, reader: string}
     */
    public static function parseLines(array $lines): array
    {
        $bank = self::detectBank($lines);
        $result = match ($bank) {
            'FNB Namibia' => self::parseFnb($lines),
            'Bank Windhoek' => self::parseBankWindhoek($lines),
            default => null,
        };
        if ($result === null) {
            return self::failure(['This statement layout is not recognised yet (supported: FNB Namibia and Bank Windhoek).']);
        }
        return $result + ['ok' => false, 'bank' => $bank, 'check' => [], 'reader' => 'pdf'];
    }

    public static function detectBank(array $lines): ?string
    {
        $head = strtolower(implode("\n", array_slice($lines, 0, 150)));
        if (str_contains($head, 'fnbnamibia') || str_contains($head, 'firnnanx') || str_contains($head, 'first national bank')) {
            return 'FNB Namibia';
        }
        if (str_contains($head, 'bank windhoek') || str_contains($head, 'bankwindhoek')) {
            return 'Bank Windhoek';
        }
        return null;
    }

    // ------------------------------------------------------------------------------------------------------------------
    // FNB Namibia: "03 MayFnb App Transfer From Emily 600.00Cr 613.37Cr" -- amount with Cr = money in, no suffix = money out;
    // the balance carries Cr (positive) or Dr (overdrawn); an optional "accrued bank charges" number may follow.
    // The year is not printed on each line, so it comes from "Statement Period : 30 April 2025 to 31 May 2025".
    // ------------------------------------------------------------------------------------------------------------------

    private static function parseFnb(array $lines): array
    {
        $errors = [];
        [$periodStart, $periodEnd] = self::fnbPeriod($lines);
        if ($periodStart === null) {
            return ['rows' => [], 'opening' => null, 'closing' => null, 'errors' => ['The statement period could not be found, so the transaction years are unknown.']];
        }

        $opening = $closing = null;
        foreach ($lines as $line) {
            if ($opening === null && preg_match('/^Opening Balance\s+(' . self::NUMBER . ')\s*(Cr|Dr)?/iu', $line, $m)) {
                $opening = self::signed($m[1], $m[2] ?? 'Cr');
            }
            if ($closing === null && preg_match('/^Closing Balance\s+(' . self::NUMBER . ')\s*(Cr|Dr)?/iu', $line, $m)) {
                $closing = self::signed($m[1], $m[2] ?? 'Cr');
            }
        }

        $months = implode('|', array_map('ucfirst', array_keys(self::MONTHS)));
        $rowPattern = '/^(\d{2})\s+(' . $months . ')\s*(.*?)\s+(' . self::NUMBER . ')(Cr)?\s+(' . self::NUMBER . ')\s*(Cr|Dr)(?:\s+' . self::NUMBER . ')?$/u';
        $startPattern = '/^\d{2}\s+(' . $months . ')[A-Za-z#\s]/u';

        $rows = [];
        foreach ($lines as $line) {
            if (preg_match($rowPattern, $line, $m)) {
                $date = self::fnbDate((int) $m[1], $m[2], $periodStart, $periodEnd);
                if ($date === null) {
                    $errors[] = 'Could not work out the date of: ' . self::clip($line);
                    continue;
                }
                $amount = self::toFloat($m[4]);
                $isCredit = $m[5] === 'Cr';
                $rows[] = self::row($date, trim($m[3]), $isCredit ? $amount : 0.0, $isCredit ? 0.0 : $amount, self::signed($m[6], $m[7]));
            } elseif (preg_match($startPattern, $line) && !preg_match('/^Closing Balance/iu', $line)) {
                $errors[] = 'Could not read this line: ' . self::clip($line);
            }
        }

        return ['rows' => $rows, 'opening' => $opening, 'closing' => $closing, 'errors' => $errors];
    }

    /** @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable} */
    private static function fnbPeriod(array $lines): array
    {
        foreach ($lines as $line) {
            if (preg_match('/Statement Period\s*:\s*(\d{1,2})\s+([A-Za-z]+)\s+(\d{4})\s*to\s*(\d{1,2})\s+([A-Za-z]+)\s+(\d{4})/iu', $line, $m)) {
                $start = self::textDate((int) $m[1], $m[2], (int) $m[3]);
                $end = self::textDate((int) $m[4], $m[5], (int) $m[6]);
                if ($start && $end) {
                    return [$start, $end];
                }
            }
        }
        return [null, null];
    }

    private static function fnbDate(int $day, string $month, \DateTimeImmutable $start, \DateTimeImmutable $end): ?string
    {
        $monthNo = self::MONTHS[strtolower(substr($month, 0, 3))] ?? null;
        if ($monthNo === null) {
            return null;
        }
        // A statement covers at most a few months, so one of the years it spans always puts the date inside the period.
        foreach (range((int) $start->format('Y'), (int) $end->format('Y')) as $year) {
            if (!checkdate($monthNo, $day, $year)) {
                continue;
            }
            $candidate = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $monthNo, $day));
            if ($candidate >= $start && $candidate <= $end) {
                return $candidate->format('Y-m-d');
            }
        }
        return null;
    }

    // ------------------------------------------------------------------------------------------------------------------
    // Bank Windhoek: "02/05/2025POS PURCHASE FEE 5.46 -11.89". Only the amount and the new balance are printed, with no
    // debit/credit marker, so the direction comes from whether the balance went up or down. A long narration wraps: the date
    // line carries no numbers and the next line carries the rest of the narration plus the amounts.
    // ------------------------------------------------------------------------------------------------------------------

    private static function parseBankWindhoek(array $lines): array
    {
        $errors = [];
        $opening = $closing = null;
        foreach ($lines as $line) {
            if ($opening === null && preg_match('/^OPENING BALANCE\s+(' . self::NUMBER . ')\s*$/iu', $line, $m)) {
                $opening = self::toFloat($m[1]);
            }
            if ($closing === null && preg_match('/Closing balance on \d{2}\/\d{2}\/\d{4}\s+(' . self::NUMBER . ')/iu', $line, $m)) {
                $closing = self::toFloat($m[1]);
            }
        }
        if ($opening === null) {
            return ['rows' => [], 'opening' => null, 'closing' => $closing, 'errors' => ['The opening balance could not be found, so money in and money out cannot be told apart.']];
        }

        // Header dates (statement date, period) come before the opening balance line; only read what follows it.
        foreach ($lines as $i => $line) {
            if (preg_match('/^OPENING BALANCE\s+' . self::NUMBER . '\s*$/iu', $line)) {
                $lines = array_slice($lines, $i + 1);
                break;
            }
        }

        $rows = [];
        $previous = $opening;
        $pending = null; // a dated line whose amounts are on the next line

        $finish = function (array $p, string $description, array $numbers) use (&$rows, &$previous, &$errors): void {
            $balance = self::toFloat($numbers[count($numbers) - 1]);
            $amount = abs(self::toFloat($numbers[count($numbers) - 2]));
            $delta = round($balance - $previous, 2);
            $flag = null;
            if (abs(abs($delta) - $amount) > 0.01) {
                // e.g. a service-fee column on the same line: trust the balance movement, but tell the reader.
                $flag = 'The amount printed (' . number_format($amount, 2) . ') does not match the balance movement (' . number_format(abs($delta), 2) . ').';
                $amount = abs($delta);
            }
            if ($delta === 0.0 && $amount === 0.0) {
                $previous = $balance;
                return;
            }
            $rows[] = self::row($p['date'], trim(preg_replace('/\s+/', ' ', $description)), $delta > 0 ? $amount : 0.0, $delta > 0 ? 0.0 : $amount, $balance, $flag);
            $previous = $balance;
        };

        foreach ($lines as $line) {
            if (preg_match('#^(\d{2})/(\d{2})/(\d{4})\s*(.*)$#u', $line, $m)) {
                if ($pending !== null) {
                    $errors[] = 'Could not read this line: ' . self::clip($pending['raw']);
                    $pending = null;
                }
                if (!checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
                    continue;
                }
                $p = ['date' => sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]), 'raw' => $line];
                [$text, $numbers] = self::splitTrailingNumbers($m[4]);
                if (count($numbers) >= 2) {
                    $finish($p, $text, $numbers);
                } elseif (count($numbers) === 0) {
                    $pending = $p + ['text' => $text];
                } else {
                    $errors[] = 'Could not read this line: ' . self::clip($line);
                }
                continue;
            }

            if ($pending !== null) {
                [$text, $numbers] = self::splitTrailingNumbers($line);
                if (count($numbers) >= 2) {
                    $finish($pending, $pending['text'] . ' ' . $text, $numbers);
                } else {
                    $errors[] = 'Could not read this line: ' . self::clip($pending['raw']);
                }
                $pending = null;
            }
        }
        if ($pending !== null) {
            $errors[] = 'Could not read this line: ' . self::clip($pending['raw']);
        }

        return ['rows' => $rows, 'opening' => $opening, 'closing' => $closing, 'errors' => $errors];
    }

    // ------------------------------------------------------------------------------------------------------------------
    // Verification
    // ------------------------------------------------------------------------------------------------------------------

    /**
     * Does the statement add up? Each balance must follow from the one before, and the last must equal the closing balance.
     *
     * @param array<int, array{money_in: float, money_out: float, balance: ?float}> $rows
     * @return array{balanced: bool, broken: int[], closing_matches: ?bool, total_in: float, total_out: float, rows: int}
     */
    public static function verify(array $rows, ?float $opening, ?float $closing): array
    {
        $broken = [];
        $previous = $opening;
        $totalIn = $totalOut = 0.0;
        foreach (array_values($rows) as $i => $row) {
            $totalIn += $row['money_in'];
            $totalOut += $row['money_out'];
            if ($previous !== null && $row['balance'] !== null) {
                $expected = round($previous + $row['money_in'] - $row['money_out'], 2);
                if (abs($expected - $row['balance']) > 0.01) {
                    $broken[] = $i;
                }
            }
            if ($row['balance'] !== null) {
                $previous = $row['balance'];
            } elseif ($previous !== null) {
                $previous = round($previous + $row['money_in'] - $row['money_out'], 2);
            }
        }

        $closingMatches = null;
        if ($closing !== null && $rows !== []) {
            $closingMatches = $previous !== null && abs($previous - $closing) <= 0.01;
        }

        $rowsWithFlag = array_filter($rows, fn ($r) => !empty($r['flag']));
        return [
            'balanced' => $broken === [] && $closingMatches !== false && $opening !== null && $rowsWithFlag === [],
            'broken' => $broken,
            'closing_matches' => $closingMatches,
            'total_in' => round($totalIn, 2),
            'total_out' => round($totalOut, 2),
            'rows' => count($rows),
        ];
    }

    // ------------------------------------------------------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------------------------------------------------------

    /** The row shape shared with the CSV importer. reference_no is a fingerprint, so overlapping statements de-duplicate. */
    public static function row(string $date, string $description, float $in, float $out, ?float $balance, ?string $flag = null): array
    {
        $in = round($in, 2);
        $out = round($out, 2);
        $row = [
            'transaction_date' => $date,
            'reference_no' => 'P' . substr(sha1($date . '|' . $description . '|' . $in . '|' . $out . '|' . $balance), 0, 11),
            'description' => mb_substr($description, 0, 255),
            'money_in' => $in,
            'money_out' => $out,
            'balance' => $balance,
        ];
        if ($flag !== null) {
            $row['flag'] = $flag;
        }
        return $row;
    }

    /** @return array{0: string, 1: string[]} the text without its trailing money amounts, and those amounts in order */
    private static function splitTrailingNumbers(string $text): array
    {
        $numbers = [];
        $text = trim($text);
        while (preg_match('/^(.*?)(?:^|\s)(' . self::NUMBER . ')\s*$/u', $text, $m)) {
            array_unshift($numbers, $m[2]);
            $text = trim($m[1]);
        }
        return [$text, $numbers];
    }

    private static function toFloat(string $value): float
    {
        return round((float) str_replace(',', '', $value), 2);
    }

    private static function signed(string $value, string $side): float
    {
        $amount = self::toFloat($value);
        return strcasecmp($side, 'Dr') === 0 ? -$amount : $amount;
    }

    private static function textDate(int $day, string $month, int $year): ?\DateTimeImmutable
    {
        $monthNo = self::MONTHS[strtolower(substr($month, 0, 3))] ?? null;
        return $monthNo !== null && checkdate($monthNo, $day, $year) ? new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $monthNo, $day)) : null;
    }

    private static function clip(string $text): string
    {
        return mb_strlen($text) > 90 ? mb_substr($text, 0, 90) . '...' : $text;
    }

    private static function failure(array $errors): array
    {
        return ['ok' => false, 'bank' => null, 'rows' => [], 'opening' => null, 'closing' => null, 'errors' => $errors, 'check' => [], 'reader' => 'pdf'];
    }
}
