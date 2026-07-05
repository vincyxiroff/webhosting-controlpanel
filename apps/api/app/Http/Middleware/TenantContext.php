<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

final class TenantContext
{
    public function handle(Request $request, Closure $next): mixed
    {
        app()->instance('tenant.id', $request->user()->tenant_id);

        return $next($request);
    }
}

