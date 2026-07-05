<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class Cors
{
    public static function apply(JsonResponse|Response $response, ?Request $request = null): JsonResponse|Response
    {
        $origin = $request?->headers->get('Origin', '*') ?? '*';

        return $response
            ->header('Access-Control-Allow-Origin', $origin)
            ->header('Vary', 'Origin')
            ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Authorization, Content-Type, Accept, X-Requested-With, X-Node-Id, X-Client-Cert-Fingerprint')
            ->header('Access-Control-Max-Age', '86400');
    }
}
