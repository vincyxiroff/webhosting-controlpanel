<?php

namespace App\Http\Controllers;

use App\Domain\Auth\AuthService;
use App\Domain\Auth\BearerTokenAuthenticator;
use App\Support\Cors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

final class AuthController
{
    public function login(Request $request, AuthService $auth): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:12'],
            'totp' => ['nullable', 'string'],
        ]);

        try {
            return Cors::apply(response()->json($auth->login($data['email'], $data['password'], $data['totp'] ?? null, $request->ip())), $request);
        } catch (RuntimeException $exception) {
            return Cors::apply(response()->json(['message' => $exception->getMessage()], 401), $request);
        }
    }

    public function setupStatus(Request $request): JsonResponse
    {
        return Cors::apply(response()->json([
            'setup_required' => DB::table('users')->whereIn('role', ['owner', 'admin'])->doesntExist(),
            'setup_token_required' => env('CONTROLPANEL_SETUP_TOKEN') !== null && env('CONTROLPANEL_SETUP_TOKEN') !== '',
        ]), $request);
    }

    public function setup(Request $request, AuthService $auth): JsonResponse
    {
        abort_unless(DB::table('users')->whereIn('role', ['owner', 'admin'])->doesntExist(), 409, 'Initial admin already exists.');

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:12'],
            'setup_token' => ['nullable', 'string'],
        ]);

        $expectedToken = (string) env('CONTROLPANEL_SETUP_TOKEN', '');
        if ($expectedToken !== '') {
            abort_unless(hash_equals($expectedToken, (string) ($data['setup_token'] ?? '')), 401, 'Invalid setup token.');
        }

        $tenantId = (string) Str::uuid();
        $userId = (string) Str::uuid();
        DB::table('tenants')->insert([
            'id' => $tenantId,
            'name' => 'Default Owner',
            'type' => 'owner',
            'status' => 'active',
            'settings' => json_encode(['created_by' => 'initial_setup'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('users')->insert([
            'id' => $userId,
            'tenant_id' => $tenantId,
            'email' => mb_strtolower($data['email']),
            'password_hash' => Hash::make($data['password']),
            'role' => 'owner',
            'totp_enabled' => false,
            'oauth_identities' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Cors::apply(response()->json($auth->login($data['email'], $data['password'], null, $request->ip()), 201), $request);
    }

    public function me(Request $request, BearerTokenAuthenticator $tokens): JsonResponse
    {
        try {
            $user = $tokens->authenticate($request);
        } catch (RuntimeException $exception) {
            return Cors::apply(response()->json(['message' => $exception->getMessage()], 401), $request);
        }

        return Cors::apply(response()->json([
            'id' => $user->id,
            'tenant_id' => $user->tenant_id,
            'email' => $user->email,
            'role' => $user->role,
        ]), $request);
    }
}
