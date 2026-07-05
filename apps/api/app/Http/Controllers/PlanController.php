<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PlanController
{
    public function index(): JsonResponse
    {
        return response()->json(DB::table('hosting_plans')->orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'tier' => ['required', 'string', 'max:64'],
            'cpu_millicores' => ['required', 'integer', 'min:100'],
            'memory_mb' => ['required', 'integer', 'min:128'],
            'disk_mb' => ['required', 'integer', 'min:1024'],
            'features' => ['required', 'array'],
            'runtime_policy' => ['required', 'array'],
            'billing_policy' => ['required', 'array'],
        ]);
        $id = (string) str()->uuid();
        DB::table('hosting_plans')->insert([
            'id' => $id,
            'name' => $data['name'],
            'tier' => $data['tier'],
            'cpu_millicores' => $data['cpu_millicores'],
            'memory_mb' => $data['memory_mb'],
            'disk_mb' => $data['disk_mb'],
            'features' => json_encode($data['features'], JSON_THROW_ON_ERROR),
            'runtime_policy' => json_encode($data['runtime_policy'], JSON_THROW_ON_ERROR),
            'billing_policy' => json_encode($data['billing_policy'], JSON_THROW_ON_ERROR),
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['id' => $id], 201);
    }

    public function show(string $plan): JsonResponse
    {
        return response()->json(DB::table('hosting_plans')->where('id', $plan)->firstOrFail());
    }

    public function update(Request $request, string $plan): JsonResponse
    {
        DB::table('hosting_plans')->where('id', $plan)->update($request->only(['name', 'status']) + ['updated_at' => now()]);

        return response()->json(['status' => 'updated']);
    }
}

