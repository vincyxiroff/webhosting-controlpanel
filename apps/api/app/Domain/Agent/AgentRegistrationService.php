<?php

namespace App\Domain\Agent;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AgentRegistrationService
{
    public function __construct(private readonly AgentAuthenticator $authenticator)
    {
    }

    public function register(array $payload): array
    {
        $tokenHash = hash('sha256', $payload['registration_token']);
        $registration = DB::table('node_registration_tokens')
            ->where('token_hash', $tokenHash)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();

        abort_if($registration === null, 401, 'Invalid registration token.');

        $nodeId = (string) Str::uuid();
        DB::transaction(function () use ($payload, $registration, $nodeId): void {
            DB::table('nodes')->insert([
                'id' => $nodeId,
                'name' => $registration->name,
                'roles' => $registration->roles,
                'region' => $registration->region,
                'status' => 'online',
                'draining' => false,
                'labels' => json_encode($payload['labels'] ?? [], JSON_THROW_ON_ERROR),
                'capabilities' => json_encode($payload['capabilities'], JSON_THROW_ON_ERROR),
                'runtime_support' => json_encode($payload['runtime_support'], JSON_THROW_ON_ERROR),
                'fingerprint' => $payload['fingerprint'],
                'agent_version' => $payload['agent_version'],
                'health_status' => 'healthy',
                'last_heartbeat_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('node_registration_tokens')->where('id', $registration->id)->update([
                'used_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return ['node_id' => $nodeId] + $this->authenticator->issueToken($nodeId);
    }
}

