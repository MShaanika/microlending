<?php

namespace App\Services;

/**
 * A reproducible sample of the accounts in a candidate file, for a person to compare with the old loan system's own screens before the file is trusted.
 * Weighted towards the risky cases (accounts the file calls "in arrears", closed and written-off ones), because arrears are an estimate.
 */
final class LegacySpotCheck
{
    private const SHARES = ['active, in arrears' => 0.40, 'active' => 0.27, 'C' => 0.20, 'W' => 0.13];

    /**
     * @param array<int, array<string,mixed>> $details the per-account details of LegacyCplExtract
     * @return array<int, array<string,mixed>> at most $count accounts, the same ones for the same $seed
     */
    public function sample(array $details, int $count = 30, string $seed = 'cpl'): array
    {
        $groups = array_fill_keys(array_keys(self::SHARES), []);
        foreach ($details as $d) {
            $groups[$d['kind']][] = $d;
        }
        foreach ($groups as $kind => $list) {
            usort($list, fn (array $a, array $b): int => crc32($seed . $a['loan_id']) <=> crc32($seed . $b['loan_id']));
            $groups[$kind] = $list;
        }

        $picked = [];
        foreach (self::SHARES as $kind => $share) {
            $picked = array_merge($picked, array_slice($groups[$kind], 0, (int) round($count * $share)));
        }
        // short of the target (a small group): fill up from what is left, in the same fixed order
        foreach ($groups as $list) {
            foreach ($list as $d) {
                if (count($picked) >= $count) {
                    break 2;
                }
                if (!in_array($d, $picked, true)) {
                    $picked[] = $d;
                }
            }
        }
        usort($picked, static fn (array $a, array $b): int => $a['loan_id'] <=> $b['loan_id']);
        return array_slice($picked, 0, $count);
    }
}
