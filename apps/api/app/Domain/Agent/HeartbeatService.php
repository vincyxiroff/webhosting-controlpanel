<?php

namespace App\Domain\Agent;

use App\Domain\HighAvailability\FailoverOrchestrator;
use App\Domain\Consistency\ActualStateIngestor;
use App\Domain\Billing\Metering\BillingUsageMeter;
use Illuminate\Support\Facades\DB;

final class HeartbeatService
{
    public function __construct(
        private readonly ActualStateIngestor $actualStates,
        private readonly BillingUsageMeter $billingMeter,
    )
    {
    }

    public function record(string $nodeId, array $payload): void
    {
        DB::transaction(function () use ($nodeId, $payload): void {
            DB::table('node_heartbeats')->insert([
                'id' => (string) str()->uuid(),
                'node_id' => $nodeId,
                'metrics' => json_encode($payload['metrics'], JSON_THROW_ON_ERROR),
                'containers' => json_encode($payload['containers'] ?? [], JSON_THROW_ON_ERROR),
                'active_sites' => json_encode($payload['active_sites'] ?? [], JSON_THROW_ON_ERROR),
                'health' => json_encode($payload['health'], JSON_THROW_ON_ERROR),
                'reported_at' => $payload['reported_at'] ?? now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('nodes')->where('id', $nodeId)->update([
                'status' => 'online',
                'health_status' => $payload['health']['status'] ?? 'unknown',
                'latest_metrics' => json_encode($payload['metrics'], JSON_THROW_ON_ERROR),
                'capabilities' => json_encode($payload['capabilities'] ?? [], JSON_THROW_ON_ERROR),
                'runtime_support' => json_encode($payload['runtime_support'] ?? [], JSON_THROW_ON_ERROR),
                'last_heartbeat_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($payload['active_sites'] ?? [] as $site) {
                DB::table('site_actual_states')->updateOrInsert(
                    ['site_id' => $site['site_id']],
                    [
                        'id' => $site['state_id'] ?? (string) str()->uuid(),
                        'node_id' => $nodeId,
                        'container_status' => $site['container_status'] ?? 'unknown',
                        'service_status' => $site['service_status'] ?? 'unknown',
                        'nginx_status' => $site['nginx_status'] ?? 'unknown',
                        'runtime' => json_encode($site['runtime'] ?? [], JSON_THROW_ON_ERROR),
                        'drift' => json_encode($site['drift'] ?? [], JSON_THROW_ON_ERROR),
                        'reported_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        });

        $this->actualStates->ingestNodeSnapshot($nodeId, $payload);
        $this->billingMeter->ingestHeartbeat($nodeId, $payload);
        app(FailoverOrchestrator::class)->detectAndQueue();
    }
}
