<?php

namespace App\Domain\Monitoring;

use Illuminate\Support\Facades\DB;

final class UsageMeteringService
{
    public function recordSample(string $siteId, string $nodeId, array $sample): void
    {
        DB::transaction(function () use ($siteId, $nodeId, $sample): void {
            DB::table('usage_samples')->insert([
                'id' => (string) str()->uuid(),
                'site_id' => $siteId,
                'node_id' => $nodeId,
                'cpu_millicores' => (int) ($sample['cpu_millicores'] ?? 0),
                'memory_mb' => (int) ($sample['memory_mb'] ?? 0),
                'io_read_bytes' => (int) ($sample['io_read_bytes'] ?? 0),
                'io_write_bytes' => (int) ($sample['io_write_bytes'] ?? 0),
                'network_rx_bytes' => (int) ($sample['network_rx_bytes'] ?? 0),
                'network_tx_bytes' => (int) ($sample['network_tx_bytes'] ?? 0),
                'requests' => (int) ($sample['requests'] ?? 0),
                'sampled_at' => $sample['sampled_at'] ?? now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function aggregate(string $bucket): void
    {
        $interval = match ($bucket) {
            '1m' => "date_trunc('minute', sampled_at)",
            '5m' => "date_trunc('hour', sampled_at) + floor(extract(minute from sampled_at) / 5) * interval '5 minutes'",
            '1h' => "date_trunc('hour', sampled_at)",
            default => throw new \InvalidArgumentException('Unsupported bucket: ' . $bucket),
        };

        DB::statement("
            INSERT INTO usage_aggregates (
                id, site_id, bucket, bucket_started_at, cpu_millicores_avg, memory_mb_max,
                io_read_bytes_sum, io_write_bytes_sum, network_rx_bytes_sum, network_tx_bytes_sum,
                requests_sum, created_at, updated_at
            )
            SELECT gen_random_uuid(), site_id, ?, {$interval},
                avg(cpu_millicores), max(memory_mb), sum(io_read_bytes), sum(io_write_bytes),
                sum(network_rx_bytes), sum(network_tx_bytes), sum(requests), now(), now()
            FROM usage_samples
            WHERE sampled_at >= now() - interval '2 hours'
            GROUP BY site_id, {$interval}
            ON CONFLICT (site_id, bucket, bucket_started_at)
            DO UPDATE SET
                cpu_millicores_avg = excluded.cpu_millicores_avg,
                memory_mb_max = excluded.memory_mb_max,
                io_read_bytes_sum = excluded.io_read_bytes_sum,
                io_write_bytes_sum = excluded.io_write_bytes_sum,
                network_rx_bytes_sum = excluded.network_rx_bytes_sum,
                network_tx_bytes_sum = excluded.network_tx_bytes_sum,
                requests_sum = excluded.requests_sum,
                updated_at = now()
        ", [$bucket]);
    }
}

