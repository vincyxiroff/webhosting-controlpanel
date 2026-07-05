<?php

namespace App\Domain\Commands;

use App\Domain\Events\OperationJournal;
use App\Domain\Networking\PortAllocator;
use Illuminate\Support\Facades\DB;

final class SiteLifecycleCommandBuilder
{
    public function __construct(
        private readonly AgentCommandService $commands,
        private readonly OperationJournal $journal,
        private readonly PortAllocator $ports,
    )
    {
    }

    public function create(string $nodeId, string $siteId, array $site): void
    {
        $site = $this->withAllocatedPorts($nodeId, $siteId, $site);
        $steps = [
            'runtime.provision' => ['runtime' => $site['runtime'], 'version' => $site['runtime_version']],
            'site.create' => $site,
            'volume.attach' => ['site_id' => $siteId],
            'nginx.configure' => $this->nginxPayload($siteId, $site),
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
        $this->ports->releaseSite($siteId);
    }

    public function update(string $nodeId, string $siteId, array $site): void
    {
        $site = $this->withAllocatedPorts($nodeId, $siteId, $site);
        $revision = substr(hash('sha256', json_encode($site, JSON_THROW_ON_ERROR)), 0, 16);
        foreach ([
            'runtime.provision' => ['runtime' => $site['runtime'], 'version' => $site['runtime_version']],
            'nginx.configure' => $this->nginxPayload($siteId, $site),
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
        $site = $this->withAllocatedPorts($nodeId, $siteId, $site);
        foreach ([
            'site.restore' => $site,
            'nginx.configure' => $this->nginxPayload($siteId, $site),
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

    private function nginxPayload(string $siteId, array $site): array
    {
        $site = $this->withRuntimeConfig($site);
        $runtime = $site['runtime'];
        $template = $site['vhost_template'] ?? $site['app_template'] ?? $this->templateForRuntime($runtime);

        return [
            'site_id' => $siteId,
            'domain' => $site['primary_domain'],
            'runtime' => $runtime,
            'upstream_port' => $site['app_port'] ?? $site['upstream_port'] ?? $this->defaultPort($runtime),
            'app_port' => $site['app_port'] ?? $site['upstream_port'] ?? $this->defaultPort($runtime),
            'host_port' => $site['host_port'] ?? null,
            'vhost_template' => $template,
            'document_root' => $site['document_root'] ?? '/app',
            'install_command' => $site['install_command'] ?? null,
            'build_command' => $site['build_command'] ?? null,
            'start_command' => $site['start_command'] ?? null,
        ];
    }

    private function withAllocatedPorts(string $nodeId, string $siteId, array $site): array
    {
        $site = $this->withRuntimeConfig($site);
        $allocation = $this->ports->allocate($nodeId, $siteId, $site['runtime'], $site);
        $site = $site + $allocation;
        $site['app_port'] = $allocation['app_port'];
        $site['host_port'] = $allocation['host_port'];
        $this->persistRuntimeConfig($siteId, $site);

        return $site;
    }

    private function persistRuntimeConfig(string $siteId, array $site): void
    {
        $config = array_filter([
            'vhost_template' => $site['vhost_template'] ?? null,
            'document_root' => $site['document_root'] ?? null,
            'app_port' => $site['app_port'] ?? null,
            'host_port' => $site['host_port'] ?? null,
            'install_command' => $site['install_command'] ?? null,
            'build_command' => $site['build_command'] ?? null,
            'start_command' => $site['start_command'] ?? null,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
        DB::table('sites')->where('id', $siteId)->update([
            'runtime_config' => json_encode($config, JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
    }

    private function withRuntimeConfig(array $site): array
    {
        $config = $site['runtime_config'] ?? [];
        if (is_string($config) && $config !== '') {
            $config = json_decode($config, true, 512, JSON_THROW_ON_ERROR) ?? [];
        }
        if (! is_array($config)) {
            $config = [];
        }

        return $site + $config;
    }

    private function templateForRuntime(string $runtime): string
    {
        return match ($runtime) {
            'php' => 'generic-php',
            'static' => 'static',
            'python' => 'python',
            'node' => 'nodejs',
            'go', 'rust', 'bun', 'deno', 'docker', 'reverse_proxy' => 'reverse-proxy',
            default => 'reverse-proxy',
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
