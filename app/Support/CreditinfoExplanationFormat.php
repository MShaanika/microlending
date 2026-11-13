<?php

namespace App\Support;

/**
 * Small formatting helpers for the plain-language credit report page: money, how long ago, month names, and the colour and icon that go with
 * each kind of finding. Kept out of the view so they can be tested and so the page stays readable.
 */
final class CreditinfoExplanationFormat
{
    private const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    /** Namibian dollars: whole amounts without decimals, others with two. */
    public static function money(?float $amount): string
    {
        if ($amount === null) {
            return '—';
        }
        $whole = abs($amount - round($amount)) < 0.005;
        return 'N$' . number_format($amount, $whole ? 0 : 2);
    }

    /** A number of days as a person would say it: "5 days", "about 3 months", "about 4 years and 3 months". */
    public static function humanDays(int $days): string
    {
        if ($days < 30) {
            return $days === 1 ? '1 day' : max(0, $days) . ' days';
        }
        $months = (int) round($days / 30.44);
        if ($months < 12) {
            return 'about ' . max(1, $months) . ' ' . ($months <= 1 ? 'month' : 'months');
        }

        $years = (int) floor($days / 365.25);
        $rest = (int) round(($days - $years * 365.25) / 30.44);
        if ($rest >= 12) {
            $years++;
            $rest = 0;
        }
        $years = max(1, $years);
        $text = 'about ' . $years . ' ' . ($years === 1 ? 'year' : 'years');
        if ($rest > 0) {
            $text .= ' and ' . $rest . ' ' . ($rest === 1 ? 'month' : 'months');
        }
        return $text;
    }

    /** "1,461 days (about 4 years)", "12 days", "1 day": how late something is, with the everyday wording once it is a month or more. */
    public static function lateness(int $days): string
    {
        $days = max(0, $days);
        return number_format($days) . ($days === 1 ? ' day' : ' days') . ($days >= 30 ? ' (' . self::humanDays($days) . ')' : '');
    }

    /**
     * Puts the lenders' real names back into a text that was written with "Lender A", "Lender B" ... (the AI never saw the names). A label with
     * no known name stays as it is.
     *
     * @param array<string, string> $names alias => real name
     */
    public static function withLenderNames(string $text, array $names): string
    {
        if ($text === '' || $names === []) {
            return $text;
        }
        return (string) preg_replace_callback(
            '/\bLender ([A-Z]|\d{1,3})\b/',
            static function (array $m) use ($names): string {
                $name = $names[$m[0]] ?? null;
                return is_string($name) && $name !== '' ? $name : $m[0];
            },
            $text
        );
    }

    /**
     * What the applicant owes right now: counts and sums over the accounts the explanation lists, and Creditinfo's own totals when the report
     * states them. The figures were already checked against the report; this is only arithmetic on them. A total stated by the report wins
     * over a sum of the listed accounts, because the list may be incomplete.
     *
     * @param array<int, mixed> $accounts the normalised accounts of an explanation
     * @param array<string, mixed> $totals ['owed' => ?float, 'overdue' => ?float] as stated by the report
     * @return array{count:int, behind:int, on_time:int, unclear:int, owed:?float, owed_source:?string, overdue:?float, overdue_source:?string, differs:bool, worst:?array}
     */
    public static function loanPosition(array $accounts, array $totals = []): array
    {
        $count = $behind = $onTime = $unclear = 0;
        $owedListed = $overdueListed = null;
        $worst = null;
        $worstOverdue = 0.0;
        $worstDays = 0;

        foreach ($accounts as $account) {
            if (!is_array($account)) {
                continue;
            }
            $count++;
            $status = (string) ($account['status'] ?? 'unknown');
            if ($status === 'behind') {
                $behind++;
            } elseif ($status === 'on_time') {
                $onTime++;
            } else {
                $unclear++;
            }

            $owes = isset($account['owes']) && is_numeric($account['owes']) ? (float) $account['owes'] : null;
            $overdue = isset($account['overdue']) && is_numeric($account['overdue']) ? (float) $account['overdue'] : null;
            $days = isset($account['days_late']) && is_numeric($account['days_late']) ? (int) $account['days_late'] : 0;
            if ($owes !== null) {
                $owedListed = round(($owedListed ?? 0.0) + $owes, 2);
            }
            if ($overdue !== null) {
                $overdueListed = round(($overdueListed ?? 0.0) + $overdue, 2);
            }
            if (($overdue ?? 0.0) > 0 || $days > 0) {
                if ($worst === null || ($overdue ?? 0.0) > $worstOverdue || (($overdue ?? 0.0) === $worstOverdue && $days > $worstDays)) {
                    $worst = $account;
                    $worstOverdue = $overdue ?? 0.0;
                    $worstDays = $days;
                }
            }
        }

        $reportOwed = isset($totals['owed']) && is_numeric($totals['owed']) ? (float) $totals['owed'] : null;
        $reportOverdue = isset($totals['overdue']) && is_numeric($totals['overdue']) ? (float) $totals['overdue'] : null;

        return [
            'count' => $count,
            'behind' => $behind,
            'on_time' => $onTime,
            'unclear' => $unclear,
            'owed' => $reportOwed ?? $owedListed,
            'owed_source' => $reportOwed !== null ? 'report' : ($owedListed !== null ? 'listed' : null),
            'overdue' => $reportOverdue ?? $overdueListed,
            'overdue_source' => $reportOverdue !== null ? 'report' : ($overdueListed !== null ? 'listed' : null),
            // The report's own total and the accounts listed disagree: some account is probably missing from the list.
            'differs' => ($reportOwed !== null && $owedListed !== null && abs($reportOwed - $owedListed) > 1.0)
                || ($reportOverdue !== null && $overdueListed !== null && abs($reportOverdue - $overdueListed) > 1.0),
            'worst' => $worst,
        ];
    }

    /**
     * The accounts in the three groups the page shows, each in the order a person wants to read it: the ones that are behind first, the
     * largest overdue amount at the top; the others by how much is still owed.
     *
     * @param array<int, mixed> $accounts
     * @return array{behind: array<int, array>, on_time: array<int, array>, unclear: array<int, array>}
     */
    public static function groupAccounts(array $accounts): array
    {
        $groups = ['behind' => [], 'on_time' => [], 'unclear' => []];
        foreach ($accounts as $account) {
            if (!is_array($account)) {
                continue;
            }
            $status = (string) ($account['status'] ?? 'unknown');
            $groups[$status === 'behind' ? 'behind' : ($status === 'on_time' ? 'on_time' : 'unclear')][] = $account;
        }
        $number = static fn (array $a, string $key): float => isset($a[$key]) && is_numeric($a[$key]) ? (float) $a[$key] : 0.0;
        usort($groups['behind'], static fn (array $a, array $b): int => [$number($b, 'overdue'), $number($b, 'days_late')] <=> [$number($a, 'overdue'), $number($a, 'days_late')]);
        foreach (['on_time', 'unclear'] as $key) {
            usort($groups[$key], static fn (array $a, array $b): int => $number($b, 'owes') <=> $number($a, 'owes'));
        }
        return $groups;
    }

    /**
     * The same facts as the figures above the table, in plain sentences a loan officer can read out to the applicant. Built here from checked
     * figures, never written by the AI, so every number in it is one that was found in the report.
     *
     * @param array<string, mixed> $position the result of loanPosition()
     * @param array<string, string> $names alias => real lender name
     */
    public static function loanSentence(array $position, array $names = []): string
    {
        $n = (int) $position['count'];
        if ($n === 0) {
            return '';
        }

        $parts = ['The applicant has ' . ($n === 1 ? '1 open loan or account' : $n . ' open loans or accounts') . '.'];

        $behind = (int) $position['behind'];
        $overdue = $position['overdue'];
        if ($behind > 0) {
            $who = $behind === $n ? ($n === 1 ? 'It is' : 'All of them are') : ($behind === 1 ? '1 of them is' : $behind . ' of them are');
            $how = $overdue !== null && $overdue > 0
                ? ', with ' . self::money($overdue) . ' overdue' . ($position['overdue_source'] === 'report' ? ' in total' : ' on the accounts shown')
                : '';
            $parts[] = $who . ' behind on payments' . $how . '.';

            $worst = $position['worst'] ?? null;
            if (is_array($worst) && (float) ($worst['overdue'] ?? 0) > 0) {
                $what = array_values(array_filter([trim((string) ($worst['kind'] ?? '')), self::withLenderNames(trim((string) ($worst['lender'] ?? '')), $names)], static fn (string $s): bool => $s !== ''));
                $late = (int) ($worst['days_late'] ?? 0) > 0 ? ', ' . self::lateness((int) $worst['days_late']) . ' late' : '';
                $parts[] = 'The biggest overdue amount is ' . self::money((float) $worst['overdue']) . ($what !== [] ? ' (' . implode(', ', $what) . ')' : '') . $late . '.';
            }
        } elseif ((int) $position['unclear'] === 0) {
            $parts[] = $n === 1 ? 'It is not behind on payments.' : 'None of them is behind on payments.';
        } else {
            $parts[] = 'None is shown as behind on payments.';
        }

        if ($position['owed'] !== null) {
            $parts[] = $position['owed_source'] === 'report'
                ? 'In total ' . self::money((float) $position['owed']) . ' is still owed.'
                : 'The accounts shown add up to ' . self::money((float) $position['owed']) . ' still owed.';
        }

        return implode(' ', $parts);
    }

    /** "2026-09" as "Sep" (or "Sep 2026" with the year). Anything else comes back unchanged. */
    public static function monthLabel(string $yearMonth, bool $withYear = false): string
    {
        if (!preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $yearMonth, $m)) {
            return $yearMonth;
        }
        $name = self::MONTHS[(int) $m[2] - 1];
        return $withYear ? $name . ' ' . $m[1] : $name;
    }

    /** The Bootstrap colour name for a finding's status. */
    public static function statusTone(string $status): string
    {
        return ['bad' => 'danger', 'watch' => 'warning', 'good' => 'success'][$status] ?? 'info';
    }

    /** The Material Design icon class for a finding's status. */
    public static function statusIcon(string $status): string
    {
        return ['bad' => 'mdi-alert-octagon', 'watch' => 'mdi-alert', 'good' => 'mdi-check-circle'][$status] ?? 'mdi-information';
    }

    /** What a status is called on screen, so colour is never the only signal. */
    public static function statusWord(string $status): string
    {
        return ['bad' => 'Serious', 'watch' => 'Watch', 'good' => 'Good sign'][$status] ?? 'Note';
    }

    /** The icon and wording for a month in the payment strip. */
    public static function monthState(string $state): array
    {
        return [
            'ok' => ['icon' => 'mdi-check', 'word' => 'Paid on time'],
            'late' => ['icon' => 'mdi-close', 'word' => 'Late or missed'],
        ][$state] ?? ['icon' => 'mdi-minus', 'word' => 'No information'];
    }

    /** The icon for a risk level on the verdict banner (only icons that exist in the bundled, older icon font). */
    public static function levelIcon(string $level): string
    {
        return [
            'very_low' => 'mdi-check-circle',
            'low' => 'mdi-check-circle-outline',
            'average' => 'mdi-alert-circle-outline',
            'high' => 'mdi-alert',
            'very_high' => 'mdi-alert-octagon',
        ][$level] ?? 'mdi-help-circle-outline';
    }
}
