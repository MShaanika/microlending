<?php

namespace App\Support;

/**
 * Strict command line for bin/cpl_auto_submit.php. This script can upload a file to a credit bureau, so a typo must never be guessed at: only the
 * four documented options exist, each at most once, nothing else (no positional word, no unknown flag, no value on a flag that takes none), exactly one
 * of --daily / --monthly, and --date must be a real calendar date (YYYY-MM-DD). Pure: no framework, no database, so the script can check its arguments
 * BEFORE it loads or touches anything.
 */
final class CplAutoSubmitArgs
{
    public const USAGE = "Usage: php bin/cpl_auto_submit.php (--daily | --monthly) [--dry-run] [--date=YYYY-MM-DD]\n"
        . "  --daily      build/send the daily file (new registrations and closures)\n"
        . "  --monthly    build/send the monthly file(s) that are due\n"
        . "  --dry-run    show what would happen; writes and sends nothing\n"
        . "  --date=...   act as this date (never in the future)\n";

    /**
     * @param string[] $argv the full $argv (the script name first)
     * @return array{kind: string, dry_run: bool, date: ?string}
     * @throws \InvalidArgumentException with a message that says what is wrong
     */
    public static function parse(array $argv): array
    {
        $args = array_slice(array_values($argv), 1);
        $seen = [];
        $date = null;
        foreach ($args as $arg) {
            if (!is_string($arg)) {
                throw new \InvalidArgumentException('Invalid argument.');
            }
            if (str_starts_with($arg, '--date=')) {
                $name = '--date';
                $value = substr($arg, 7);
                if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                    throw new \InvalidArgumentException('--date must be a real date like 2026-11-30.');
                }
                $date = $value;
            } elseif (in_array($arg, ['--daily', '--monthly', '--dry-run'], true)) {
                $name = $arg;
            } else {
                throw new \InvalidArgumentException('Unknown or unexpected argument: ' . self::printable($arg));
            }
            if (isset($seen[$name])) {
                throw new \InvalidArgumentException('The option ' . $name . ' was given more than once.');
            }
            $seen[$name] = true;
        }
        if (isset($seen['--daily']) === isset($seen['--monthly'])) {
            throw new \InvalidArgumentException('Give exactly one of --daily or --monthly.');
        }
        return ['kind' => isset($seen['--daily']) ? 'daily' : 'monthly', 'dry_run' => isset($seen['--dry-run']), 'date' => $date];
    }

    /** An argument made safe to print back (printable ASCII only, short). */
    private static function printable(string $arg): string
    {
        return substr((string) preg_replace('/[^\x20-\x7E]/', '?', $arg), 0, 40);
    }
}
