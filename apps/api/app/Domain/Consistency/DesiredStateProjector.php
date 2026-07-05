<?php

namespace App\Domain\Consistency;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DesiredStateProjector
{
    public function projectSite(string $siteId): array
    {
        $site = DB::table('sites')->where('id', $siteId)->firstOrFail();
        $domains = DB::table('domains')->where('site_id', $siteId)->pluck('name')->all();
        if ($domains === []) {
            $domains = [$site->primary_domain];
        }

        $environment = $this->decode($site->environment ?? []);
        $quotas = $this->decode($site->quotas ?? []);
        $desired = [
            'site_id' => $siteId,
            'tenant_id' => $site->tenant_id,
            'node_id' => $site->node_id,
            'runtime_type' => $site->runtime,
            'runtime_version' => $site->runtime_version,
            'primary_domain' => $site->primary_domain,
            'domains' => $domains,
            'environment_hashes' => $this->hashEnvironment($environment),
            'resource_limits' => $quotas,
            'container_config_hash' => $this->containerHash($site, $quotas, $environment),
            'nginx_config_hash' => $this->nginxHash($site, $domains),
            'ssl_state' => DB::table('ssl_orders')->where('site_id', $siteId)->latest('created_at')->value('status') ?? 'pending',
            'status' => $site->status,
        ];

        $existing = DB::table('desired_states')->where('site_id', $siteId)->first();
        DB::table('desired_states')->updateOrInsert(
            ['site_id' => $siteId],
            [
                'id' => $existing->id ?? (string) Str::uuid(),
                'tenant_id' => $desired['tenant_id'],
                'node_id' => $desired['node_id'],
                'runtime_type' => $desired['runtime_type'],
                'runtime_version' => $desired['runtime_version'],
                'primary_domain' => $desired['primary_domain'],
                'domains' => json_encode($desired['domains'], JSON_THROW_ON_ERROR),
                'environment_hashes' => json_encode($desired['environment_hashes'], JSON_THROW_ON_ERROR),
                'resource_limits' => json_encode($desired['resource_limits'], JSON_THROW_ON_ERROR),
                'container_config_hash' => $desired['container_config_hash'],
                'nginx_config_hash' => $desired['nginx_config_hash'],
                'ssl_state' => $desired['ssl_state'],
                'status' => $desired['status'],
                'generation' => ($existing->generation ?? 0) + 1,
                'updated_at' => now(),
                'created_at' => $existing->created_at ?? now(),
            ],
        );

        return $desired;
    }

    private function decode(mixed $value): array
    {
        if (is_string($value)) {
            return json_decode($value, true, 512, JSON_THROW_ON_ERROR) ?? [];
        }

        return is_array($value) ? $value : [];
    }

    private function hashEnvironment(array $environment): array
    {
        ksort($environment);

        return collect($environment)
            ->mapWithKeys(fn (mixed $value, string $key): array => [$key => hash('sha256', (string) $value)])
            ->all();
    }

    private function containerHash(object $site, array $quotas, array $environment): string
    {
        ksort($quotas);
        ksort($environment);

        return hash('sha256', json_encode([
            'runtime' => $site->runtime,
            'runtime_version' => $site->runtime_version,
            'quotas' => $quotas,
            'environment_hashes' => $this->hashEnvironment($environment),
        ], JSON_THROW_ON_ERROR));
    }

    private function nginxHash(object $site, array $domains): string
    {
        sort($domains);

        return hash('sha256', json_encode([
            'primary_domain' => $site->primary_domain,
            'domains' => $domains,
            'runtime' => $site->runtime,
            'runtime_version' => $site->runtime_version,
        ], JSON_THROW_ON_ERROR));
    }
}

