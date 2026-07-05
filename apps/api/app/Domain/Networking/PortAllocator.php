<?php

namespace App\Domain\Networking;

use App\Domain\Locking\LockManager;
use Illuminate\Support\Facades\DB;

final class PortAllocator
{
    private const DEFAULT_START = 31000;
    private const DEFAULT_END = 39999;

    public function __construct(private readonly LockManager $locks)
    {
    }

    public function allocate(string $nodeId, string $siteId, string $runtime, array $runtimeConfig = []): array
    {
        $owner = $this->locks->owner('port-allocator');

        return $this->locks->withLock(LockManager::node($nodeId), $owner, function () use ($nodeId, $siteId, $runtime, $runtimeConfig): array {
            return DB::transaction(function () use ($nodeId, $siteId, $runtime, $runtimeConfig): array {
                $internalPort = $this->normalizePort($runtimeConfig['app_port'] ?? null, $this->defaultInternalPort($runtime));
                $existing = DB::table('site_port_allocations')
                    ->where('site_id', $siteId)
                    ->where('internal_port', $internalPort)
                    ->where('protocol', 'tcp')
                    ->where('status', 'allocated')
                    ->lockForUpdate()
                    ->first();
                if ($existing !== null) {
                    return [
                        'app_port' => (int) $existing->internal_port,
                        'host_port' => (int) $existing->host_port,
                    ];
                }

                $requestedHostPort = $runtimeConfig['host_port'] ?? null;
                $hostPort = $requestedHostPort !== null
                    ? $this->normalizePort($requestedHostPort, 0)
                    : $this->nextAvailableHostPort($nodeId);
                $collision = DB::table('site_port_allocations')
                    ->where('node_id', $nodeId)
                    ->where('host_port', $hostPort)
                    ->where('protocol', 'tcp')
                    ->where('status', 'allocated')
                    ->lockForUpdate()
                    ->first();
                if ($collision !== null) {
                    throw new \DomainException("Host port {$hostPort} is already allocated on node {$nodeId}.");
                }

                DB::table('site_port_allocations')->insert([
                    'id' => (string) str()->uuid(),
                    'node_id' => $nodeId,
                    'site_id' => $siteId,
                    'internal_port' => $internalPort,
                    'host_port' => $hostPort,
                    'protocol' => 'tcp',
                    'status' => 'allocated',
                    'metadata' => json_encode(['runtime' => $runtime], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return ['app_port' => $internalPort, 'host_port' => $hostPort];
            });
        });
    }

    public function releaseSite(string $siteId): void
    {
        DB::table('site_port_allocations')->where('site_id', $siteId)->update([
            'status' => 'released',
            'updated_at' => now(),
        ]);
    }

    private function nextAvailableHostPort(string $nodeId): int
    {
        $used = DB::table('site_port_allocations')
            ->where('node_id', $nodeId)
            ->where('protocol', 'tcp')
            ->where('status', 'allocated')
            ->pluck('host_port')
            ->map(fn (mixed $port): int => (int) $port)
            ->flip();

        for ($port = self::DEFAULT_START; $port <= self::DEFAULT_END; $port++) {
            if (! $used->has($port)) {
                return $port;
            }
        }

        throw new \RuntimeException("No free host ports on node {$nodeId}.");
    }

    private function normalizePort(mixed $value, int $fallback): int
    {
        $port = is_numeric($value) ? (int) $value : $fallback;
        if ($port < 1 || $port > 65535) {
            throw new \DomainException("Invalid port {$port}.");
        }

        return $port;
    }

    private function defaultInternalPort(string $runtime): int
    {
        return match ($runtime) {
            'static' => 80,
            'php' => 9000,
            'node' => 3000,
            'python' => 8000,
            default => 8080,
        };
    }
}
