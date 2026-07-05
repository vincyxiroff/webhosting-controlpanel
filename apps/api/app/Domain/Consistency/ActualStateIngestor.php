<?php

namespace App\Domain\Consistency;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ActualStateIngestor
{
    public function ingestNodeSnapshot(string $nodeId, array $payload): void
    {
        foreach ($payload['active_sites'] ?? [] as $site) {
            $snapshot = $this->normalizeSiteSnapshot($site);
            DB::table('actual_state_snapshots')->insert([
                'id' => (string) Str::uuid(),
                'node_id' => $nodeId,
                'site_id' => $snapshot['site_id'] ?: null,
                'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                'snapshot_hash' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
                'reported_at' => $payload['reported_at'] ?? now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function normalizeSiteSnapshot(array $site): array
    {
        $runtime = $site['runtime'] ?? [];

        return [
            'site_id' => $site['site_id'] ?? null,
            'container_exists' => ($site['container_status'] ?? 'unknown') !== 'missing',
            'container_status' => $site['container_status'] ?? 'unknown',
            'service_status' => $site['service_status'] ?? 'unknown',
            'nginx_status' => $site['nginx_status'] ?? 'unknown',
            'container_config_hash' => $runtime['container_config_hash'] ?? null,
            'nginx_config_hash' => $runtime['nginx_config_hash'] ?? null,
            'runtime_type' => $runtime['runtime_type'] ?? null,
            'runtime_version' => $runtime['runtime_version'] ?? null,
            'environment_hashes' => $runtime['environment_hashes'] ?? [],
            'volume_name' => $runtime['volume_name'] ?? null,
            'domains' => $runtime['domains'] ?? [],
            'stats' => $runtime['stats'] ?? [],
        ];
    }
}

