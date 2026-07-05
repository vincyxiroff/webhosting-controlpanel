<?php

namespace App\Domain\Billing\Enforcement;

final class AntiAbuseDetector
{
    public function detect(object $rollup): array
    {
        $signals = [];
        if ((float) $rollup->cpu_percent_avg > 95) {
            $signals[] = 'cpu_spike';
        }
        if ((int) $rollup->request_count_sum > 1000000) {
            $signals[] = 'request_flood';
        }
        if ((int) $rollup->bandwidth_bytes_sum > 50 * 1024 * 1024 * 1024) {
            $signals[] = 'bandwidth_flood';
        }
        if ((int) $rollup->disk_io_bytes_sum > 20 * 1024 * 1024 * 1024) {
            $signals[] = 'possible_mining_or_io_abuse';
        }

        return $signals;
    }
}

