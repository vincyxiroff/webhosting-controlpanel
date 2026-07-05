<?php

namespace App\Http\Controllers;

use App\Domain\Events\OperationJournal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class OperationJournalController
{
    public function index(Request $request): JsonResponse
    {
        $query = DB::table('operation_journal')
            ->where('tenant_id', $request->user()->tenant_id)
            ->orderByDesc('occurred_at')
            ->limit(min(500, max(1, (int) $request->integer('limit', 100))));

        if ($request->filled('site_id')) {
            $query->where('site_id', $request->string('site_id')->toString());
        }
        if ($request->filled('category')) {
            $query->where('category', $request->string('category')->toString());
        }

        return response()->json($query->get());
    }

    public function site(Request $request, string $site, OperationJournal $journal): JsonResponse
    {
        return response()->json([
            'events' => $journal->timeline($request->user()->tenant_id, 'site', $site),
            'rebuilt_state' => $journal->rebuildSite($site, $request->user()->tenant_id),
            'snapshot' => DB::table('operation_snapshots')
                ->where('tenant_id', $request->user()->tenant_id)
                ->where('entity_type', 'site')
                ->where('entity_id', $site)
                ->first(),
        ]);
    }

    public function snapshot(Request $request, string $site, OperationJournal $journal): JsonResponse
    {
        return response()->json($journal->snapshotSite($site, $request->user()->tenant_id), 202);
    }
}
