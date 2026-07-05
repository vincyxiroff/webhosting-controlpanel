<?php

namespace App\Domain\Nodes;

use App\Support\DomainEvent;
use App\Support\EventRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class NodeRegistrationService
{
    public function __construct(private readonly EventRecorder $events)
    {
    }

    public function createToken(string $name, array $roles, string $region, string $createdBy): array
    {
        $id = (string) Str::uuid();
        $plainToken = Str::random(64);

        DB::table('node_registration_tokens')->insert([
            'id' => $id,
            'name' => $name,
            'roles' => json_encode($roles, JSON_THROW_ON_ERROR),
            'region' => $region,
            'token_hash' => hash('sha256', $plainToken),
            'created_by' => $createdBy,
            'expires_at' => now()->addMinutes(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->events->record(new DomainEvent('node.registration.created', 'node_registration_token', $id, [
            'name' => $name,
            'roles' => $roles,
            'region' => $region,
        ], null));

        return ['id' => $id, 'token' => $plainToken, 'expires_at' => now()->addMinutes(30)->toISOString()];
    }
}

