<?php

namespace App\Domain\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class BearerTokenAuthenticator
{
    public function authenticate(Request $request): object
    {
        $header = $request->header('Authorization', '');
        if (! str_starts_with($header, 'Bearer ')) {
            throw new RuntimeException('Missing bearer token.');
        }

        $token = trim(substr($header, 7));
        try {
            $claims = JWT::decode($token, new Key((string) config('app.key'), 'HS256'));
        } catch (Throwable) {
            throw new RuntimeException('Invalid bearer token.');
        }

        $session = DB::table('sessions')
            ->where('id', $claims->sid ?? '')
            ->where('user_id', $claims->sub ?? '')
            ->where('expires_at', '>', now())
            ->first();
        if ($session === null) {
            throw new RuntimeException('Session expired.');
        }

        $user = DB::table('users')->where('id', $claims->sub)->first();
        if ($user === null) {
            throw new RuntimeException('User not found.');
        }

        return $user;
    }
}
