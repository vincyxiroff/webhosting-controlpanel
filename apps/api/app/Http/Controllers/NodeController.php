<?php

namespace App\Http\Controllers;

use App\Domain\Nodes\NodeRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class NodeController
{
    public function index(): JsonResponse
    {
        return response()->json(DB::table('nodes')->orderBy('name')->get());
    }

    public function store(Request $request, NodeRegistrationService $registrations): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['in:web,db,storage,edge'],
            'region' => ['required', 'string', 'max:64'],
        ]);

        return response()->json($registrations->createToken($data['name'], $data['roles'], $data['region'], $request->user()->id), 201);
    }

    public function show(string $node): JsonResponse
    {
        return response()->json(DB::table('nodes')->where('id', $node)->firstOrFail());
    }

    public function update(Request $request, string $node): JsonResponse
    {
        DB::table('nodes')->where('id', $node)->update($request->only(['name', 'labels']) + ['updated_at' => now()]);

        return response()->json(['status' => 'updated']);
    }

    public function drain(string $node): JsonResponse
    {
        DB::table('nodes')->where('id', $node)->update(['draining' => true, 'updated_at' => now()]);

        return response()->json(['status' => 'draining'], 202);
    }

    public function migrateSites(string $node): JsonResponse
    {
        DB::table('migration_jobs')->insert([
            'id' => (string) str()->uuid(),
            'source_node_id' => $node,
            'status' => 'queued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['status' => 'queued'], 202);
    }
}

