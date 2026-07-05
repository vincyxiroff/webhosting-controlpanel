<?php

namespace App\Http\Controllers;

use App\Domain\Auth\BearerTokenAuthenticator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DashboardController
{
    public function overview(Request $request, BearerTokenAuthenticator $tokens): JsonResponse
    {
        try {
            $user = $tokens->authenticate($request);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 401);
        }

        $nodes = DB::table('nodes')->orderByDesc('updated_at')->limit(20)->get();
        $sitesQuery = DB::table('sites')->where('tenant_id', $user->tenant_id);
        $sites = (clone $sitesQuery)->orderByDesc('updated_at')->limit(20)->get();
        $latestMetrics = $nodes
            ->map(fn (object $node): array => is_string($node->latest_metrics ?? null) ? (json_decode($node->latest_metrics, true) ?: []) : [])
            ->filter();

        $cpuSamples = $latestMetrics->map(fn (array $metrics): float => (float) ($metrics['cpu_percent'] ?? $metrics['cpu']['percent'] ?? 0));
        $memorySamples = $latestMetrics->map(fn (array $metrics): float => (float) ($metrics['memory_percent'] ?? $metrics['memory']['percent'] ?? 0));

        return response()->json([
            'metrics' => [
                'online_nodes' => DB::table('nodes')->where('status', 'online')->count(),
                'total_nodes' => DB::table('nodes')->count(),
                'hosted_sites' => (clone $sitesQuery)->count(),
                'active_sites' => (clone $sitesQuery)->whereIn('status', ['active', 'provisioning', 'reconciling'])->count(),
                'cpu_pressure' => round($cpuSamples->avg() ?? 0, 1),
                'memory_pressure' => round($memorySamples->avg() ?? 0, 1),
                'open_commands' => DB::table('node_commands')->whereNotIn('status', ['SUCCESS', 'FAILED'])->count(),
                'failed_commands' => DB::table('node_commands')->where('status', 'FAILED')->count(),
                'billing_actions' => DB::table('billing_enforcement_decisions')->where('status', 'queued')->count(),
                'security_events' => DB::table('conflict_logs')->count(),
            ],
            'nodes' => $nodes->map(fn (object $node): array => [
                'id' => $node->id,
                'name' => $node->name,
                'region' => $node->region,
                'roles' => json_decode($node->roles ?? '[]', true) ?: [],
                'status' => $node->status,
                'health_status' => $node->health_status ?? 'unknown',
                'last_heartbeat_at' => $node->last_heartbeat_at,
                'metrics' => is_string($node->latest_metrics ?? null) ? (json_decode($node->latest_metrics, true) ?: []) : [],
            ])->values(),
            'sites' => $sites->map(fn (object $site): array => [
                'id' => $site->id,
                'name' => $site->name,
                'primary_domain' => $site->primary_domain,
                'runtime' => $site->runtime,
                'runtime_version' => $site->runtime_version,
                'status' => $site->status,
                'node_id' => $site->node_id,
                'runtime_config' => is_string($site->runtime_config ?? null) ? (json_decode($site->runtime_config, true) ?: []) : [],
            ])->values(),
        ]);
    }
}
