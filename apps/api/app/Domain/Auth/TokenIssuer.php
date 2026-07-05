<?php

namespace App\Domain\Auth;

use Firebase\JWT\JWT;

final class TokenIssuer
{
    public function issue(string $userId, string $tenantId, string $role, string $sessionId): string
    {
        $now = time();

        return JWT::encode([
            'iss' => config('app.url'),
            'sub' => $userId,
            'tid' => $tenantId,
            'role' => $role,
            'sid' => $sessionId,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + 900,
        ], config('app.key'), 'HS256');
    }
}

