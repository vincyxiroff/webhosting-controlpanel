<?php

namespace App\Domain\Commands;

use App\Domain\Events\OperationJournal;
use Illuminate\Support\Facades\DB;

final class SiteLifecycleCommandBuilder
{
    public function __construct(
        private readonly AgentCommandService $commands,
        private readonly OperationJournal $journal,
    )
    {
    }

    public function create(string $nodeId, string $siteId, array $site): void
    {
        $steps = [
            'runtime.provision' => ['runtime' => $site['runtime'], 'version' => $site['runtime_version']],
            'site.create' => $site,
            'volume.attach' => ['site_id' => $siteId],
            'nginx.configure' => ['site_id' => $siteId, 'domain' => $site['primary_domain'], 'runtime' => $site['runtime'], 'upstream_port' => $site['upstream_port'] ?? $this->defaultPort($site['runtime'])],
            'service.start' => ['site_id' => $siteId],
            'health.check' => ['site_id' => $siteId, 'path' => '/'],
        ];

        foreach ($steps as $command => $payload) {
            $commandId = $this->commands->create(
                nodeId: $nodeId,
                command: $command,
                payload: ['site_id' => $siteId] + $payload,
                idempotencyKey: $command . ':' . $siteId,
                siteId: $siteId,
            );
            $this->recordQueued($siteId, $nodeId, $commandId, $command, ['site_id' => $siteId] + $payload, $command . ':' . $siteId);
        }
    }

    public function delete(string $nodeId, string $siteId): void
    {
        foreach (['site.suspend', 'runtime.destroy', 'site.delete'] as $command) {
            $commandId = $this->commands->create($nodeId, $command, ['site_id' => $siteId], $command . ':' . $siteId, $siteId);
            $this->recordQueued($siteId, $nodeId, $commandId, $command, ['site_id' => $siteId], $command . ':' . $siteId);
        }
    }

    public function update(string $nodeId, string $siteId, array $site): void
    {
        $revision = substr(hash('sha256', json_encode($site, JSON_THROW_ON_ERROR)), 0, 16);
        foreach ([
            'runtime.provision' => ['runtime' => $site['runtime'], 'version' => $site['runtime_version']],
            'nginx.configure' => ['site_id' => $siteId, 'domain' => $site['primary_domain'], 'runtime' => $site['runtime'], 'upstream_port' => $site['upstream_port'] ?? $this->defaultPort($site['runtime'])],
            'service.start' => ['site_id' => $siteId],
            'health.check' => ['site_id' => $siteId, 'path' => '/'],
        ] as $command => $payload) {
            $key = $command . ':update:' . $siteId . ':' . $revision;
            $commandId = $this->commands->create($nodeId, $command, ['site_id' => $siteId] + $payload, $key, $siteId);
            $this->recordQueued($siteId, $nodeId, $commandId, $command, ['site_id' => $siteId] + $payload, $key);
        }
    }

    public function suspend(string $nodeId, string $siteId): void
    {
        foreach (['site.suspend', 'nginx.configure'] as $command) {
            $key = $command . ':suspend:' . $siteId;
            $commandId = $this->commands->create($nodeId, $command, ['site_id' => $siteId, 'maintenance_mode' => true], $key, $siteId);
            $this->recordQueued($siteId, $nodeId, $commandId, $command, ['site_id' => $siteId, 'maintenance_mode' => true], $key);
        }
    }

    public function restore(string $nodeId, string $siteId, array $site): void
    {
        foreach ([
            'site.restore' => $site,
            'nginx.configure' => ['site_id' => $siteId, 'domain' => $site['primary_domain'], 'runtime' => $site['runtime'], 'upstream_port' => $site['upstream_port'] ?? $this->defaultPort($site['runtime'])],
            'service.start' => ['site_id' => $siteId],
            'health.check' => ['site_id' => $siteId, 'path' => '/'],
        ] as $command => $payload) {
            $key = $command . ':restore:' . $siteId;
            $commandId = $this->commands->create($nodeId, $command, ['site_id' => $siteId] + $payload, $key, $siteId);
            $this->recordQueued($siteId, $nodeId, $commandId, $command, ['site_id' => $siteId] + $payload, $key);
        }
    }

    private function recordQueued(string $siteId, string $nodeId, string $commandId, string $command, array $payload, string $idempotencyKey): void
    {
        $site = DB::table('sites')->where('id', $siteId)->first();
        $tenantId = $site?->tenant_id;
        $this->journal->record($tenantId, $this->operationForCommand($command) . 'Queued', 'command', 'control-plane', 'site', $siteId, [
            'site_id' => $siteId,
            'node_id' => $nodeId,
            'command' => $command,
            'payload' => $payload,
        ], 'journal:command-queued:' . $idempotencyKey, [], 'site:' . $siteId, $idempotencyKey, null, $siteId, $nodeId, $commandId);
    }

    private function operationForCommand(string $command): string
    {
        return match ($command) {
            'runtime.provision' => 'RuntimeProvision',
            'site.create' => 'ContainerCreate',
            'volume.attach' => 'VolumeAttach',
            'nginx.configure' => 'NginxConfigure',
            'service.start' => 'ServiceStart',
            'health.check' => 'HealthCheck',
            'runtime.destroy' => 'RuntimeDestroy',
            'site.delete' => 'SiteDelete',
            'site.suspend' => 'SiteSuspend',
            'site.restore' => 'SiteRestore',
            default => str_replace('.', '', ucwords($command, '.')),
        };
    }

    private function defaultPort(string $runtime): int
    {
        return match ($runtime) {
            'static' => 80,
            'php' => 9000,
            'node' => 3000,
            'python' => 8000,
            'go', 'rust', 'docker', 'reverse_proxy' => 8080,
            default => 8080,
        };
    }
}
