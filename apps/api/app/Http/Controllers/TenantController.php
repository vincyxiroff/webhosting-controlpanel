<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class TenantController
{
    public function index(): JsonResponse
    {
        return response()->json(DB::table('tenants')->orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'type' => ['required', 'in:provider,reseller,agency,saas,customer'],
            'parent_id' => ['nullable', 'uuid'],
        ]);
        $id = (string) str()->uuid();
        DB::table('tenants')->insert($data + ['id' => $id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['id' => $id], 201);
    }

    public function show(string $tenant): JsonResponse
    {
        return response()->json(DB::table('tenants')->where('id', $tenant)->firstOrFail());
    }

    public function update(Request $request, string $tenant): JsonResponse
    {
        DB::table('tenants')->where('id', $tenant)->update($request->only(['name', 'status']) + ['updated_at' => now()]);

        return response()->json(['status' => 'updated']);
    }
}

