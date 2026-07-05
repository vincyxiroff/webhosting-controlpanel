<?php

namespace App\Http\Controllers;

use App\Domain\Billing\Enforcement\BillingEnforcementEngine;
use App\Domain\Billing\Metering\BillingUsageMeter;
use App\Domain\Billing\Pipeline\FossBillingEventPipeline;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class BillingController
{
    public function aggregate(Request $request, BillingUsageMeter $meter): JsonResponse
    {
        $data = $request->validate(['window' => ['required', 'in:1m,5m,1h']]);
        $meter->aggregate($data['window']);

        return response()->json(['status' => 'aggregated']);
    }

    public function enforce(Request $request, BillingEnforcementEngine $engine): JsonResponse
    {
        $data = $request->validate(['window' => ['nullable', 'in:1m,5m,1h']]);

        return response()->json($engine->run($data['window'] ?? '5m'), 202);
    }

    public function events(FossBillingEventPipeline $pipeline): JsonResponse
    {
        return response()->json(['processed' => $pipeline->processDue()], 202);
    }

    public function decisions(): JsonResponse
    {
        return response()->json(DB::table('billing_enforcement_decisions')->orderByDesc('created_at')->limit(200)->get());
    }
}

