<?php

namespace App\Http\Controllers;

use App\Domain\Billing\UsageEnforcementService;
use App\Domain\Edge\EdgeRoutingService;
use App\Domain\HighAvailability\FailoverOrchestrator;
use App\Domain\Monitoring\UsageMeteringService;
use App\Domain\Nodes\Scheduler;
use App\Domain\Security\SecurityPolicyEngine;
use App\Domain\Storage\StorageReplicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class EnterpriseController
{
    public function placementDecisions(Request $request): JsonResponse
    {
        $query = DB::table('placement_decisions')->orderByDesc('created_at')->limit(100);
        if ($request->user()->role !== 'super_admin') {
            $query->where('tenant_id', $request->user()->tenant_id);
        }

        return response()->json($query->get());
    }

    public function recordUsage(Request $request, UsageMeteringService $metering): JsonResponse
    {
        $data = $request->validate([
            'site_id' => ['required', 'uuid'],
            'node_id' => ['required', 'uuid'],
            'sample' => ['required', 'array'],
        ]);

        $metering->recordSample($data['site_id'], $data['node_id'], $data['sample']);

        return response()->json(['status' => 'recorded'], 202);
    }

    public function aggregateUsage(Request $request, UsageMeteringService $metering): JsonResponse
    {
        $data = $request->validate([
            'bucket' => ['required', 'in:1m,5m,1h'],
        ]);

        $metering->aggregate($data['bucket']);

        return response()->json(['status' => 'aggregated']);
    }

    public function enforceSite(string $site, UsageEnforcementService $enforcement): JsonResponse
    {
        return response()->json($enforcement->enforceSite($site), 202);
    }

    public function detectFailover(FailoverOrchestrator $failover): JsonResponse
    {
        return response()->json(['offline_nodes' => $failover->detectAndQueue()], 202);
    }

    public function recoverSite(string $site, FailoverOrchestrator $failover, Scheduler $scheduler): JsonResponse
    {
        return response()->json($failover->planSiteRecovery($site, $scheduler), 202);
    }

    public function createStoragePolicy(Request $request, StorageReplicationService $storage): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'backend' => ['required', 'in:s3,minio,zfs,rsync'],
            'replication_mode' => ['required', 'in:sync,async,snapshot'],
            'retention' => ['nullable', 'array'],
            'targets' => ['required', 'array', 'min:1'],
        ]);

        return response()->json([
            'id' => $storage->createPolicy($request->user()->tenant_id, $data['name'], $data),
        ], 201);
    }

    public function queueStorageSnapshot(Request $request, StorageReplicationService $storage): JsonResponse
    {
        $data = $request->validate([
            'site_id' => ['required', 'uuid'],
            'policy_id' => ['required', 'uuid'],
        ]);

        return response()->json([
            'id' => $storage->queueSnapshot($data['site_id'], $data['policy_id']),
            'status' => 'queued',
        ], 202);
    }

    public function publishEdgeRoute(Request $request, EdgeRoutingService $edge): JsonResponse
    {
        $data = $request->validate([
            'site_id' => ['required', 'uuid'],
            'hostname' => ['required', 'string', 'max:253'],
            'origin_node_id' => ['required', 'uuid'],
            'edge_pool' => ['nullable', 'string', 'max:80'],
            'routing_policy' => ['nullable', 'array'],
            'health_policy' => ['nullable', 'array'],
        ]);
        $edge->publishRoute($data['site_id'], $data + ['tenant_id' => $request->user()->tenant_id]);

        return response()->json(['status' => 'publishing'], 202);
    }

    public function rerouteEdge(Request $request, EdgeRoutingService $edge): JsonResponse
    {
        $data = $request->validate([
            'site_id' => ['required', 'uuid'],
            'target_node_id' => ['required', 'uuid'],
        ]);
        $edge->reroute($data['site_id'], $data['target_node_id']);

        return response()->json(['status' => 'publishing'], 202);
    }

    public function scoreSecurity(Request $request, SecurityPolicyEngine $security): JsonResponse
    {
        $data = $request->validate([
            'site_id' => ['required', 'uuid'],
            'request' => ['required', 'array'],
        ]);

        return response()->json($security->analyzeRequest($data['site_id'], $data['request']));
    }
}

