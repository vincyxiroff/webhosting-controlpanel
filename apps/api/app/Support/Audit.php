<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class Audit
{
    public function write(
        string $action,
        string $actorId,
        ?string $tenantId,
        string $resourceType,
        string $resourceId,
        array $before,
        array $after,
        string $result,
    ): void {
        DB::table('audit_logs')->insert([
            'id' => (string) str()->uuid(),
            'tenant_id' => $tenantId,
            'actor_id' => $actorId,
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'before_state' => json_encode($before, JSON_THROW_ON_ERROR),
            'after_state' => json_encode($after, JSON_THROW_ON_ERROR),
            'result' => $result,
            'created_at' => now(),
        ]);
    }
}

