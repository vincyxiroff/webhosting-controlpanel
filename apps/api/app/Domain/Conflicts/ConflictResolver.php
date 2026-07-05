<?php

namespace App\Domain\Conflicts;

use App\Domain\StateMachine\PriorityRules;
use App\Domain\StateMachine\StateSource;
use Illuminate\Support\Facades\DB;

final class ConflictResolver
{
    public function __construct(private readonly PriorityRules $priorities)
    {
    }

    public function decide(string $tenantId, ?string $siteId, StateSource $incoming, string $incomingAction, StateSource $current, string $currentAction): string
    {
        $winner = $this->priorities->priority($incoming) >= $this->priorities->priority($current) ? $incoming : $current;
        $loser = $winner === $incoming ? $current : $incoming;
        DB::table('conflict_logs')->insert([
            'id' => (string) str()->uuid(),
            'tenant_id' => $tenantId,
            'site_id' => $siteId,
            'winner_source' => $winner->value,
            'loser_source' => $loser->value,
            'winner_action' => $winner === $incoming ? $incomingAction : $currentAction,
            'loser_action' => $winner === $incoming ? $currentAction : $incomingAction,
            'resolution' => json_encode(['rule' => 'higher_priority_wins'], JSON_THROW_ON_ERROR),
            'status' => 'resolved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $winner->value;
    }
}

