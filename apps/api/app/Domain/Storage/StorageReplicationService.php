<?php

namespace App\Domain\Storage;

use Illuminate\Support\Facades\DB;

final class StorageReplicationService
{
    public function createPolicy(string $tenantId, string $name, array $policy): string
    {
        $id = (string) str()->uuid();
        DB::table('storage_policies')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'name' => $name,
            'backend' => $policy['backend'],
            'replication_mode' => $policy['replication_mode'],
            'retention' => json_encode($policy['retention'] ?? [], JSON_THROW_ON_ERROR),
            'targets' => json_encode($policy['targets'] ?? [], JSON_THROW_ON_ERROR),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    public function queueSnapshot(string $siteId, string $policyId): string
    {
        $id = (string) str()->uuid();
        DB::table('storage_replication_jobs')->insert([
            'id' => $id,
            'site_id' => $siteId,
            'policy_id' => $policyId,
            'operation' => 'snapshot_and_replicate',
            'status' => 'queued',
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}

