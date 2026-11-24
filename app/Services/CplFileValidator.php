<?php

namespace App\Services;

use App\Support\AsciiText;

/**
 * The last check of a CPL file BEFORE an irreversible upload (the automatic submission cannot be taken back once Creditinfo has the file). Pure.
 *
 * It looks at the finished bytes, not at the data they were made from: every line must have the exact record width (718 for a daily file, 700 for a
 * monthly one), contain nothing but printable ASCII, be separated by CRLF only; a monthly file must open with the header for the right month end and end with
 * a trailer whose count is right; the number of lines must equal the number of records the batch says it has; and the file name must be exactly the
 * live (L702) name for this kind and date. Messages carry line numbers only - never any of the data.
 */
final class CplFileValidator
{
    public const DAILY_WIDTH = 718;
    public const MONTHLY_WIDTH = 700;

    /**
     * @param string $kind 'daily' or 'monthly'
     * @param int $expectedRecords data records (a monthly file has two more lines: the header and the trailer)
     * @param string $date Y-m-d: the transaction date of a daily file, the month end of a monthly one
     * @return string[] what is wrong (empty = the file may go)
     */
    public static function validate(string $kind, string $content, int $expectedRecords, string $expectedFileName, string $actualFileName, string $date): array
    {
        $problems = [];
        if ($actualFileName !== $expectedFileName) {
            $problems[] = 'the file name is not the expected live file name';
        }
        if (!CplSftpService::isSafeLiveFilename($actualFileName)) {
            $problems[] = 'the file name is not a valid live L702 name';
        }
        if ($content === '') {
            return array_merge($problems, ['the file is empty']);
        }
        if (!in_array($kind, ['daily', 'monthly'], true)) {
            return array_merge($problems, ['unknown file kind']);
        }

        $width = $kind === 'daily' ? self::DAILY_WIDTH : self::MONTHLY_WIDTH;
        $lines = explode("\r\n", $content);
        $expectedLines = $kind === 'daily' ? $expectedRecords : $expectedRecords + 2;
        if (count($lines) !== $expectedLines) {
            $problems[] = 'the file has ' . count($lines) . ' line(s) but ' . $expectedLines . ' were expected';
        }

        $badWidth = [];
        $badChars = [];
        foreach ($lines as $i => $line) {
            if (strlen($line) !== $width) {
                $badWidth[] = $i + 1;
            }
            if (!AsciiText::isPrintableAscii($line)) {
                $badChars[] = $i + 1;
            }
        }
        if ($badWidth !== []) {
            $problems[] = count($badWidth) . ' line(s) are not exactly ' . $width . ' characters wide (first: line ' . $badWidth[0] . ')';
        }
        if ($badChars !== []) {
            $problems[] = count($badChars) . ' line(s) contain characters that are not plain printable ASCII (first: line ' . $badChars[0] . ')';
        }

        $compactDate = str_replace('-', '', $date);
        if ($kind === 'daily') {
            foreach ($lines as $i => $line) {
                if (!in_array(substr($line, 0, 1), ['R', 'C'], true)) {
                    $problems[] = 'line ' . ($i + 1) . ' is not a registration (R) or closure (C) record';
                    break;
                }
                if (substr($line, 710, 8) !== $compactDate) {
                    $problems[] = 'line ' . ($i + 1) . ' does not carry the transaction date of the file';
                    break;
                }
            }
        } else {
            $first = $lines[0];
            $last = $lines[count($lines) - 1];
            if (substr($first, 0, 1) !== 'H' || substr($first, 11, 8) !== $compactDate) {
                $problems[] = 'the header line is missing or is not for this month end';
            }
            if (count($lines) < 2 || substr($last, 0, 1) !== 'T') {
                $problems[] = 'the trailer line is missing';
            } elseif ((int) substr($last, 1, 9) !== count($lines) || !preg_match('/^T\d{9} *$/D', $last)) {
                $problems[] = 'the trailer count does not match the number of lines';
            }
            for ($i = 1; $i < count($lines) - 1; $i++) {
                if (substr($lines[$i], 0, 1) !== 'D') {
                    $problems[] = 'line ' . ($i + 1) . ' is not a data (D) record';
                    break;
                }
            }
        }
        return $problems;
    }
}
