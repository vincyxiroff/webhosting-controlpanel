<?php

namespace App\Domain\Agent;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AgentAuthenticator
{
    public function authenticate(Request $request): object
    {
        $nodeId = (string) $request->header('x-node-id');
        if ($nodeId === '') {
            abort(401, 'Missing node identity.');
        }

        $node = DB::table('nodes')->where('id', $nodeId)->first();
        abort_if($node === null, 401, 'Unknown node.');

        $mtlsFingerprint = $request->header('x-client-cert-fingerprint');
        if ($mtlsFingerprint !== null && $node->fingerprint !== null) {
            abort_unless(hash_equals((string) $node->fingerprint, (string) $mtlsFingerprint), 401, 'Node certificate fingerprint mismatch.');

            return $node;
        }

        $authorization = (string) $request->header('authorization');
        abort_unless(str_starts_with($authorization, 'Bearer '), 401, 'Missing agent token.');
        $token = substr($authorization, 7);

        try {
            $claims = (array) JWT::decode($token, new Key(config('app.key'), 'HS256'));
        } catch (\Throwable) {
            abort(401, 'Invalid agent token.');
        }

        abort_unless(($claims['sub'] ?? null) === $nodeId, 401, 'Token subject mismatch.');

        $tokenHash = hash('sha256', $token);
        $tokenRow = DB::table('agent_tokens')
            ->where('node_id', $nodeId)
            ->where('token_hash', $tokenHash)
            ->where('expires_at', '>', now())
            ->first();

        abort_if($tokenRow === null, 401, 'Expired or revoked agent token.');

        return $node;
    }

    public function issueToken(string $nodeId, int $ttlMinutes = 60): array
    {
        $now = time();
        $token = JWT::encode([
            'iss' => config('app.url'),
            'aud' => 'controlpanel-agent',
            'sub' => $nodeId,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + ($ttlMinutes * 60),
            'jti' => (string) Str::uuid(),
        ], config('app.key'), 'HS256');

        DB::table('agent_tokens')->insert([
            'id' => (string) Str::uuid(),
            'node_id' => $nodeId,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes($ttlMinutes),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['token' => $token, 'expires_at' => now()->addMinutes($ttlMinutes)->toISOString()];
    }
}

