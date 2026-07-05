<?php

namespace App\Http\Controllers;

use App\Domain\Consistency\GlobalConsistencyEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class ConsistencyController
{
    public function run(GlobalConsistencyEngine $engine): JsonResponse
    {
        return response()->json($engine->run(), 202);
    }

    public function drifts(): JsonResponse
    {
        return response()->json(DB::table('drift_logs')->orderByDesc('created_at')->limit(200)->get());
    }

    public function jobs(): JsonResponse
    {
        return response()->json(DB::table('reconciliation_jobs')->orderByDesc('created_at')->limit(200)->get());
    }
}

