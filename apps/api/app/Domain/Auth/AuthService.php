<?php

namespace App\Domain\Auth;

use App\Support\DomainEvent;
use App\Support\EventRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

final class AuthService
{
    public function __construct(private readonly EventRecorder $events)
    {
    }

    public function login(string $email, string $password, ?string $totpCode, string $ipAddress): array
    {
        $user = DB::table('users')->where('email', mb_strtolower($email))->first();

        if ($user === null || ! Hash::check($password, $user->password_hash)) {
            throw new RuntimeException('Invalid credentials.');
        }

        if ($user->totp_enabled && $totpCode === null) {
            throw new RuntimeException('Two-factor challenge required.');
        }

        $sessionId = (string) str()->uuid();
        DB::table('sessions')->insert([
            'id' => $sessionId,
            'user_id' => $user->id,
            'ip_address' => $ipAddress,
            'expires_at' => now()->addDays(14),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->events->record(new DomainEvent('auth.session.created', 'user', $user->id, [
            'session_id' => $sessionId,
            'ip_address' => $ipAddress,
        ], $user->tenant_id));

        return [
            'session_id' => $sessionId,
            'access_token' => app(TokenIssuer::class)->issue($user->id, $user->tenant_id, $user->role, $sessionId),
            'expires_at' => now()->addMinutes(15)->toISOString(),
            'user' => [
                'id' => $user->id,
                'tenant_id' => $user->tenant_id,
                'email' => $user->email,
                'role' => $user->role,
            ],
        ];
    }
}

