<?php

namespace App\Domain\Consistency;

final class StateDiffEngine
{
    public function diff(array $desired, ?array $actual): array
    {
        if ($actual === null) {
            return [[
                'type' => 'missing_container',
                'severity' => 'critical',
                'expected' => ['site_id' => $desired['site_id']],
                'actual' => null,
                'actions' => ['runtime.provision', 'site.create', 'nginx.configure', 'service.start', 'health.check'],
            ]];
        }

        $drifts = [];
        if (! ($actual['container_exists'] ?? false)) {
            $drifts[] = $this->drift('missing_container', 'critical', true, false, ['site.create', 'service.start']);
        }
        if (($actual['container_status'] ?? null) !== 'running' && in_array($desired['status'], ['active', 'provisioning', 'updating', 'restoring'], true)) {
            $drifts[] = $this->drift('container_not_running', 'high', 'running', $actual['container_status'] ?? null, ['service.start']);
        }
        if (($actual['runtime_type'] ?? null) !== $desired['runtime_type'] || ($actual['runtime_version'] ?? null) !== $desired['runtime_version']) {
            $drifts[] = $this->drift('wrong_runtime_config', 'high', [
                'runtime_type' => $desired['runtime_type'],
                'runtime_version' => $desired['runtime_version'],
            ], [
                'runtime_type' => $actual['runtime_type'] ?? null,
                'runtime_version' => $actual['runtime_version'] ?? null,
            ], ['runtime.provision', 'site.create', 'service.start']);
        }
        if (($actual['container_config_hash'] ?? null) !== $desired['container_config_hash']) {
            $drifts[] = $this->drift('container_config_hash_mismatch', 'medium', $desired['container_config_hash'], $actual['container_config_hash'] ?? null, ['site.create', 'service.start']);
        }
        if (($actual['nginx_config_hash'] ?? null) !== $desired['nginx_config_hash']) {
            $drifts[] = $this->drift('nginx_mismatch', 'high', $desired['nginx_config_hash'], $actual['nginx_config_hash'] ?? null, ['nginx.configure']);
        }
        if (array_diff($desired['domains'], $actual['domains'] ?? []) !== []) {
            $drifts[] = $this->drift('domain_mismatch', 'high', $desired['domains'], $actual['domains'] ?? [], ['nginx.configure']);
        }
        if ($desired['ssl_state'] !== 'issued' && $desired['status'] === 'active') {
            $drifts[] = $this->drift('ssl_missing', 'medium', 'issued', $desired['ssl_state'], ['ssl.order']);
        }
        if (($actual['volume_name'] ?? null) === null) {
            $drifts[] = $this->drift('volume_missing', 'critical', 'present', null, ['volume.attach']);
        }

        return $drifts;
    }

    private function drift(string $type, string $severity, mixed $expected, mixed $actual, array $actions): array
    {
        return compact('type', 'severity', 'expected', 'actual', 'actions');
    }
}
