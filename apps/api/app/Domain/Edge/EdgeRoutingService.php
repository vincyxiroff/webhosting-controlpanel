<?php

namespace App\Domain\Edge;

use Illuminate\Support\Facades\DB;

final class EdgeRoutingService
{
    public function publishRoute(string $siteId, array $route): void
    {
        DB::table('edge_routes')->updateOrInsert(
            ['site_id' => $siteId, 'hostname' => $route['hostname']],
            [
                'id' => $route['id'] ?? (string) str()->uuid(),
                'tenant_id' => $route['tenant_id'],
                'origin_node_id' => $route['origin_node_id'],
                'edge_pool' => $route['edge_pool'] ?? 'default',
                'routing_policy' => json_encode($route['routing_policy'] ?? ['mode' => 'latency'], JSON_THROW_ON_ERROR),
                'health_policy' => json_encode($route['health_policy'] ?? ['path' => '/', 'interval_seconds' => 10], JSON_THROW_ON_ERROR),
                'status' => 'publishing',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function reroute(string $siteId, string $targetNodeId): void
    {
        DB::table('edge_routes')->where('site_id', $siteId)->update([
            'origin_node_id' => $targetNodeId,
            'status' => 'publishing',
            'updated_at' => now(),
        ]);
    }
}

