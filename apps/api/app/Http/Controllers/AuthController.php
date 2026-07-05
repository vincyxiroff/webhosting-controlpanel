<?php

namespace App\Http\Controllers;

use App\Domain\Auth\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AuthController
{
    public function login(Request $request, AuthService $auth): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:12'],
            'totp' => ['nullable', 'string'],
        ]);

        return response()->json($auth->login($data['email'], $data['password'], $data['totp'] ?? null, $request->ip()));
    }
}

