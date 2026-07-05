<?php

namespace App\Domain\Consistency;

use App\Domain\Commands\AgentCommandService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DriftResolver
{
    public function __construct(private readonly AgentCommandService $commands)
    {
    }

    public function recordAndQueue(array $desired, array $drift): string
    {
        $driftId = (string) Str::uuid();
        $actions = $this->buildActions($desired, $drift['actions']);
        $jobKey = hash('sha256', json_encode([
            'site_id' => $desired['site_id'],
            'node_id' => $desired['node_id'],
            'type' => $drift['type'],
            'desired_hash' => $desired['container_config_hash'] . ':' . $desired['nginx_config_hash'],
        ], JSON_THROW_ON_ERROR));

        DB::table('drift_logs')->insert([
            'id' => $driftId,
            'site_id' => $desired['site_id'],
            'node_id' => $desired['node_id'],
            'drift_type' => $drift['type'],
            'severity' => $drift['severity'],
            'expected' => json_encode($drift['expected'], JSON_THROW_ON_ERROR),
            'actual' => json_encode($drift['actual'], JSON_THROW_ON_ERROR),
            'actions' => json_encode($actions, JSON_THROW_ON_ERROR),
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('reconciliation_jobs')->updateOrInsert(
            ['idempotency_key' => $jobKey],
            [
                'id' => (string) Str::uuid(),
                'site_id' => $desired['site_id'],
                'node_id' => $desired['node_id'],
                'drift_log_id' => $driftId,
                'status' => 'queued',
                'actions' => json_encode($actions, JSON_THROW_ON_ERROR),
                'attempt' => 0,
                'max_attempts' => 5,
                'available_at' => now(),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        return $driftId;
    }

    public function dispatchDueJobs(int $limit = 100): int
    {
        $jobs = DB::table('reconciliation_jobs')
            ->where('status', 'queued')
            ->where('available_at', '<=', now())
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        foreach ($jobs as $job) {
            $actions = json_decode($job->actions, true, 512, JSON_THROW_ON_ERROR);
            foreach ($actions as $action) {
                $this->commands->create(
                    nodeId: $job->node_id,
                    command: $action['command'],
                    payload: $action['payload'],
                    idempotencyKey: 'reconcile:' . $job->id . ':' . $action['command'],
                    siteId: $job->site_id,
                    type: 'reconciliation',
                );
            }

            DB::table('reconciliation_jobs')->where('id', $job->id)->update([
                'status' => 'dispatched',
                'attempt' => ((int) $job->attempt) + 1,
                'updated_at' => now(),
            ]);
        }

        return $jobs->count();
    }

    private function buildActions(array $desired, array $commands): array
    {
        return array_map(fn (string $command): array => [
            'command' => $command,
            'payload' => [
                'site_id' => $desired['site_id'],
                'runtime' => $desired['runtime_type'],
                'runtime_version' => $desired['runtime_version'],
                'version' => $desired['runtime_version'],
                'primary_domain' => $desired['primary_domain'],
                'domain' => $desired['primary_domain'],
                'domains' => $desired['domains'],
                'quotas' => $desired['resource_limits'],
                'desired_container_config_hash' => $desired['container_config_hash'],
                'desired_nginx_config_hash' => $desired['nginx_config_hash'],
            ],
        ], $commands);
    }
}

