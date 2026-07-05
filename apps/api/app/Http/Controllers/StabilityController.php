<?php

namespace App\Http\Controllers;

use App\Domain\StateMachine\TransitionValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class StabilityController
{
    public function site(Request $request, string $site): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;

        return response()->json([
            'state' => DB::table('site_state_machines')->where('tenant_id', $tenantId)->where('site_id', $site)->first(),
            'transitions' => DB::table('state_transition_logs')
                ->where('tenant_id', $tenantId)
                ->where('site_id', $site)
                ->orderByDesc('created_at')
                ->limit(50)
                ->get(),
            'events' => DB::table('ordered_events')
                ->where('tenant_id', $tenantId)
                ->where('payload->site_id', $site)
                ->orderByDesc('sequence')
                ->limit(50)
                ->get(),
        ]);
    }

    public function transitions(TransitionValidator $validator): JsonResponse
    {
        return response()->json([
            'states' => $validator->table(),
            'priorities' => [
                'billing' => 500,
                'security' => 400,
                'manual' => 300,
                'consistency' => 200,
                'scheduler' => 100,
            ],
        ]);
    }

    public function conflicts(Request $request): JsonResponse
    {
        return response()->json(DB::table('conflict_logs')
            ->where('tenant_id', $request->user()->tenant_id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get());
    }

    public function locks(): JsonResponse
    {
        return response()->json(DB::table('distributed_lock_audits')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get());
    }
}
