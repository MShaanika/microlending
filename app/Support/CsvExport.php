<?php

namespace App\Support;

/**
 * Sends a list as a CSV download. Cells that a spreadsheet would run as a formula (they start with = + - @) are prefixed with an apostrophe,
 * so a borrower name or a note cannot execute anything when the file is opened in Excel. A UTF-8 byte-order mark keeps accents readable.
 */
class CsvExport
{
    /** @param string[] $headers @param iterable<int, array<int, mixed>> $rows */
    public static function send(string $filename, array $headers, iterable $rows): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"');
        header('Cache-Control: no-store');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_map([self::class, 'cell'], $headers), ',', '"', '\\');
        foreach ($rows as $row) {
            fputcsv($out, array_map([self::class, 'cell'], $row), ',', '"', '\\');
        }
        fclose($out);
        exit;
    }

    public static function cell(mixed $value): string
    {
        $text = (string) ($value ?? '');
        return $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) && !is_numeric($text) ? "'" . $text : $text;
    }
}
