<?php

namespace App\Http\Controllers;

use App\Domain\Commands\SiteLifecycleCommandBuilder;
use App\Domain\Consistency\DesiredStateProjector;
use App\Domain\Sites\SiteProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class FossBillingServerController
{
    public function test(Request $request): JsonResponse
    {
        $this->authorize($request);

        return response()->json(['success' => true, 'panel' => 'ControlPanel OS']);
    }

    public function create(Request $request, SiteProvisioningService $sites): JsonResponse
    {
        $this->authorize($request);
        $tenantId = $this->tenantId($request);
        $actorId = $this->serviceActor($tenantId);
        $planId = $this->planId($request);

        $data = $request->validate([
            'username' => ['nullable', 'string', 'max:120'],
            'password' => ['nullable', 'string', 'max:255'],
            'domain' => ['required', 'string', 'max:253'],
            'package' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:255'],
            'runtime' => ['nullable', 'in:static,php,node,python,docker'],
            'runtime_version' => ['nullable', 'string', 'max:32'],
            'start_command' => ['nullable', 'string', 'max:500'],
            'build_command' => ['nullable', 'string', 'max:500'],
        ]);

        $runtime = $data['runtime'] ?? $this->runtimeFromPackage($data['package'] ?? '');
        $result = $sites->create([
            'plan_id' => $planId,
            'name' => $data['username'] ?? Str::before($data['domain'], '.'),
            'primary_domain' => $data['domain'],
            'runtime' => $runtime,
            'runtime_version' => $data['runtime_version'] ?? $this->defaultVersion($runtime),
            'vhost_template' => $runtime === 'node' ? 'nodejs' : $this->defaultTemplate($runtime),
            'start_command' => $data['start_command'] ?? ($runtime === 'node' ? 'npm run start' : null),
            'build_command' => $data['build_command'] ?? ($runtime === 'node' ? 'npm install && npm run build' : null),
            'environment' => [
                'FOSSBILLING_USERNAME' => $data['username'] ?? null,
                'FOSSBILLING_EMAIL' => $data['email'] ?? null,
            ],
        ], $tenantId, $actorId);

        return response()->json([
            'success' => true,
            'username' => $data['username'] ?? $result['id'],
            'site_id' => $result['id'],
            'status' => $result['status'],
        ], 202);
    }

    public function suspend(Request $request, SiteLifecycleCommandBuilder $lifecycle, DesiredStateProjector $desiredStates): JsonResponse
    {
        $this->authorize($request);
        $site = $this->siteFromRequest($request);
        DB::table('sites')->where('id', $site->id)->update(['status' => 'suspended_billing', 'updated_at' => now()]);
        $desiredStates->projectSite($site->id);
        $lifecycle->suspend($site->node_id, $site->id);

        return response()->json(['success' => true, 'status' => 'suspended_billing'], 202);
    }

    public function unsuspend(Request $request, SiteLifecycleCommandBuilder $lifecycle, DesiredStateProjector $desiredStates): JsonResponse
    {
        $this->authorize($request);
        $site = (array) $this->siteFromRequest($request);
        DB::table('sites')->where('id', $site['id'])->update(['status' => 'active', 'updated_at' => now()]);
        $site['status'] = 'active';
        $desiredStates->projectSite($site['id']);
        $lifecycle->restore($site['node_id'], $site['id'], $site);

        return response()->json(['success' => true, 'status' => 'active'], 202);
    }

    public function cancel(Request $request, SiteLifecycleCommandBuilder $lifecycle, DesiredStateProjector $desiredStates): JsonResponse
    {
        $this->authorize($request);
        $site = $this->siteFromRequest($request);
        DB::table('sites')->where('id', $site->id)->update(['status' => 'deleting', 'updated_at' => now()]);
        $desiredStates->projectSite($site->id);
        $lifecycle->delete($site->node_id, $site->id);

        return response()->json(['success' => true, 'status' => 'deleting'], 202);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $this->authorize($request);
        $data = $request->validate(['password' => ['required', 'string', 'min:8']]);
        $site = $this->siteFromRequest($request);
        $environment = is_string($site->environment ?? null) ? json_decode($site->environment, true, 512, JSON_THROW_ON_ERROR) : [];
        $environment['FOSSBILLING_PASSWORD_UPDATED_AT'] = now()->toISOString();
        $environment['FOSSBILLING_ACCOUNT_PASSWORD_HASH'] = Hash::make($data['password']);
        DB::table('sites')->where('id', $site->id)->update([
            'environment' => json_encode($environment, JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);

        return response()->json(['success' => true, 'status' => 'password_changed']);
    }

    public function changePackage(Request $request): JsonResponse
    {
        $this->authorize($request);
        $site = $this->siteFromRequest($request);
        $planId = $this->planId($request);
        DB::table('sites')->where('id', $site->id)->update(['plan_id' => $planId, 'updated_at' => now()]);

        return response()->json(['success' => true, 'status' => 'package_changed']);
    }

    public function synchronize(Request $request): JsonResponse
    {
        $this->authorize($request);
        $site = $this->siteFromRequest($request);

        return response()->json([
            'success' => true,
            'status' => $site->status,
            'username' => $request->input('username'),
            'domain' => $site->primary_domain,
            'site_id' => $site->id,
        ]);
    }

    private function authorize(Request $request): void
    {
        $expected = (string) env('FOSSBILLING_SERVER_API_KEY', '');
        abort_if($expected === '', 503, 'FOSSBilling server API key is not configured.');
        $provided = trim(str_replace('Bearer ', '', $request->header('Authorization', '')));
        abort_unless(hash_equals($expected, $provided), 401, 'Invalid FOSSBilling server API key.');
    }

    private function tenantId(Request $request): string
    {
        $tenant = DB::table('tenants')->where('type', 'owner')->orderBy('created_at')->first();
        if ($tenant !== null) {
            return $tenant->id;
        }

        $tenantId = (string) Str::uuid();
        DB::table('tenants')->insert([
            'id' => $tenantId,
            'name' => 'FOSSBilling Tenant',
            'type' => 'owner',
            'status' => 'active',
            'settings' => json_encode(['source' => 'fossbilling'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $tenantId;
    }

    private function serviceActor(string $tenantId): string
    {
        $user = DB::table('users')->where('email', 'fossbilling@controlpanel.local')->first();
        if ($user !== null) {
            return $user->id;
        }

        $id = (string) Str::uuid();
        DB::table('users')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'email' => 'fossbilling@controlpanel.local',
            'password_hash' => '',
            'role' => 'service',
            'totp_enabled' => false,
            'oauth_identities' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function planId(Request $request): string
    {
        $package = $request->input('package');
        $plan = $package ? DB::table('hosting_plans')->where('name', $package)->orWhere('tier', $package)->first() : null;
        $plan ??= DB::table('hosting_plans')->where('status', 'active')->orderBy('created_at')->first();
        if ($plan !== null) {
            return $plan->id;
        }

        $id = (string) Str::uuid();
        DB::table('hosting_plans')->insert([
            'id' => $id,
            'name' => $package ?: 'FOSSBilling Shared',
            'tier' => $package ?: 'fossbilling',
            'cpu_millicores' => 1000,
            'memory_mb' => 1024,
            'disk_mb' => 10240,
            'features' => json_encode(['sites' => 1, 'ssl' => true], JSON_THROW_ON_ERROR),
            'runtime_policy' => json_encode(['allowed' => ['static', 'php', 'node', 'python']], JSON_THROW_ON_ERROR),
            'billing_policy' => json_encode(['source' => 'fossbilling'], JSON_THROW_ON_ERROR),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function siteFromRequest(Request $request): object
    {
        $site = null;
        if ($request->filled('site_id')) {
            $site = DB::table('sites')->where('id', $request->string('site_id')->toString())->first();
        }
        if ($site === null && $request->filled('domain')) {
            $site = DB::table('sites')->where('primary_domain', $request->string('domain')->toString())->first();
        }
        if ($site === null && $request->filled('username')) {
            $site = DB::table('sites')->where('name', $request->string('username')->toString())->first();
        }

        abort_if($site === null, 404, 'Hosting account not found.');
        abort_if(empty($site->node_id), 409, 'Hosting account has no assigned node.');

        return $site;
    }

    private function runtimeFromPackage(string $package): string
    {
        $lower = strtolower($package);
        return match (true) {
            str_contains($lower, 'node') => 'node',
            str_contains($lower, 'python') => 'python',
            str_contains($lower, 'static') => 'static',
            default => 'php',
        };
    }

    private function defaultVersion(string $runtime): string
    {
        return match ($runtime) {
            'node' => '22',
            'python' => '3.12',
            'static' => 'nginx',
            default => '8.4',
        };
    }

    private function defaultTemplate(string $runtime): string
    {
        return match ($runtime) {
            'node' => 'nodejs',
            'python' => 'python',
            'static' => 'static',
            default => 'generic-php',
        };
    }
}
