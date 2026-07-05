<?php

namespace App\Domain\Billing\Metering;

use Illuminate\Support\Facades\DB;

final class BillingUsageMeter
{
    public function ingestHeartbeat(string $nodeId, array $payload): void
    {
        foreach ($payload['active_sites'] ?? [] as $site) {
            $siteId = $site['site_id'] ?? null;
            if ($siteId === null) {
                continue;
            }
            $siteRow = DB::table('sites')->where('id', $siteId)->first();
            if ($siteRow === null) {
                continue;
            }

            $runtime = $site['runtime'] ?? [];
            $stats = $runtime['stats'] ?? [];
            [$memoryBytes] = $this->parsePairBytes((string) ($stats['MemUsage'] ?? '0B / 0B'));
            [$networkRx, $networkTx] = $this->parsePairBytes((string) ($stats['NetIO'] ?? '0B / 0B'));
            [$diskRead, $diskWrite] = $this->parsePairBytes((string) ($stats['BlockIO'] ?? '0B / 0B'));

            DB::table('usage_time_series')->insert([
                'id' => (string) str()->uuid(),
                'tenant_id' => $siteRow->tenant_id,
                'site_id' => $siteId,
                'node_id' => $nodeId,
                'container_name' => (string) ($runtime['container'] ?? ''),
                'cpu_percent' => $this->parsePercent((string) ($stats['CPUPerc'] ?? '0%')),
                'memory_bytes' => $memoryBytes,
                'disk_read_bytes' => $diskRead,
                'disk_write_bytes' => $diskWrite,
                'disk_usage_bytes' => (int) ($runtime['disk_usage_bytes'] ?? 0),
                'network_rx_bytes' => $networkRx,
                'network_tx_bytes' => $networkTx,
                'request_count' => (int) ($runtime['request_count'] ?? 0),
                'latency_ms_p95' => (float) ($runtime['latency_ms_p95'] ?? 0),
                'error_rate' => (float) ($runtime['error_rate'] ?? 0),
                'sampled_at' => $payload['reported_at'] ?? now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function aggregate(string $window): void
    {
        $bucket = match ($window) {
            '1m' => "date_trunc('minute', sampled_at)",
            '5m' => "date_trunc('hour', sampled_at) + floor(extract(minute from sampled_at) / 5) * interval '5 minutes'",
            '1h' => "date_trunc('hour', sampled_at)",
            default => throw new \InvalidArgumentException('Unsupported billing window: ' . $window),
        };

        DB::statement("
            INSERT INTO tenant_usage_rollups (
                id, tenant_id, window, window_started_at, cpu_percent_avg, memory_bytes_max,
                disk_io_bytes_sum, disk_usage_bytes_max, bandwidth_bytes_sum, request_count_sum, active_sites, active_containers,
                created_at, updated_at
            )
            SELECT gen_random_uuid(), tenant_id, ?, {$bucket},
                avg(cpu_percent), max(memory_bytes), sum(disk_read_bytes + disk_write_bytes), max(disk_usage_bytes),
                sum(network_rx_bytes + network_tx_bytes), sum(request_count),
                count(distinct site_id), count(distinct container_name), now(), now()
            FROM usage_time_series
            WHERE sampled_at >= now() - interval '2 hours'
            GROUP BY tenant_id, {$bucket}
            ON CONFLICT (tenant_id, window, window_started_at)
            DO UPDATE SET
                cpu_percent_avg = excluded.cpu_percent_avg,
                memory_bytes_max = excluded.memory_bytes_max,
                disk_io_bytes_sum = excluded.disk_io_bytes_sum,
                disk_usage_bytes_max = excluded.disk_usage_bytes_max,
                bandwidth_bytes_sum = excluded.bandwidth_bytes_sum,
                request_count_sum = excluded.request_count_sum,
                active_sites = excluded.active_sites,
                active_containers = excluded.active_containers,
                updated_at = now()
        ", [$window]);
    }

    private function parsePercent(string $value): float
    {
        return (float) str_replace('%', '', trim($value));
    }

    private function parsePairBytes(string $value): array
    {
        $parts = array_map('trim', explode('/', $value));

        return [
            $this->parseBytes($parts[0] ?? '0B'),
            $this->parseBytes($parts[1] ?? '0B'),
        ];
    }

    private function parseBytes(string $value): int
    {
        if (! preg_match('/([0-9.]+)\s*([KMGT]?i?B|B)?/i', trim($value), $matches)) {
            return 0;
        }

        $amount = (float) $matches[1];
        $unit = strtolower($matches[2] ?? 'b');
        $multiplier = match ($unit) {
            'kb' => 1000,
            'mb' => 1000 ** 2,
            'gb' => 1000 ** 3,
            'tb' => 1000 ** 4,
            'kib' => 1024,
            'mib' => 1024 ** 2,
            'gib' => 1024 ** 3,
            'tib' => 1024 ** 4,
            default => 1,
        };

        return (int) round($amount * $multiplier);
    }
}
