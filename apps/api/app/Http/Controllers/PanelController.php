<?php

namespace App\Http\Controllers;

use App\Domain\Auth\BearerTokenAuthenticator;
use App\Domain\Billing\Enforcement\BillingEnforcementEngine;
use App\Domain\Billing\Pipeline\FossBillingEventPipeline;
use App\Domain\Commands\SiteLifecycleCommandBuilder;
use App\Domain\Consistency\DesiredStateProjector;
use App\Domain\Consistency\GlobalConsistencyEngine;
use App\Domain\Sites\SiteProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

final class PanelController
{
    public function __construct(private readonly BearerTokenAuthenticator $tokens)
    {
    }

    public function bootstrap(Request $request): JsonResponse
    {
        $user = $this->authorizePanel($request);

        return response()->json($this->ensureBootstrap($user));
    }

    public function status(Request $request): JsonResponse
    {
        $this->authorizePanel($request);

        return response()->json([
            'commands' => DB::table('node_commands')->orderByDesc('created_at')->limit(100)->get(),
            'drifts' => DB::table('drift_logs')->orderByDesc('created_at')->limit(100)->get(),
            'reconciliation_jobs' => DB::table('reconciliation_jobs')->orderByDesc('created_at')->limit(50)->get(),
            'billing_decisions' => DB::table('billing_enforcement_decisions')->orderByDesc('created_at')->limit(100)->get(),
            'deployments' => DB::table('deployments')->orderByDesc('created_at')->limit(100)->get(),
            'ssl_orders' => DB::table('ssl_orders')->orderByDesc('created_at')->limit(100)->get(),
            'journal' => DB::table('operation_journal')->orderByDesc('occurred_at')->limit(150)->get(),
        ]);
    }

    public function createSite(Request $request, SiteProvisioningService $sites): JsonResponse
    {
        $user = $this->authorizePanel($request);
        $bootstrap = $this->ensureBootstrap($user);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'primary_domain' => ['required', 'string', 'max:253'],
            'runtime' => ['required', 'in:static,php,node,python,go,rust,bun,deno,docker,reverse_proxy'],
            'runtime_version' => ['nullable', 'string', 'max:32'],
            'vhost_template' => ['nullable', 'in:generic-php,laravel,wordpress,nodejs,static,python,reverse-proxy'],
            'app_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'host_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'install_command' => ['nullable', 'string', 'max:500'],
            'build_command' => ['nullable', 'string', 'max:500'],
            'start_command' => ['nullable', 'string', 'max:500'],
            'document_root' => ['nullable', 'string', 'max:255'],
        ]);

        $data['plan_id'] = $bootstrap['plan_id'];
        $data['runtime_version'] = $data['runtime_version'] ?? $this->defaultRuntimeVersion($data['runtime']);
        $data['vhost_template'] = $data['vhost_template'] ?? $this->defaultTemplate($data['runtime']);

        return response()->json($sites->create($data, $bootstrap['tenant_id'], $bootstrap['actor_id']), 202);
    }

    public function siteAction(
        Request $request,
        string $site,
        string $action,
        SiteLifecycleCommandBuilder $lifecycle,
        DesiredStateProjector $desiredStates,
    ): JsonResponse {
        $this->authorizePanel($request);
        $current = (array) DB::table('sites')->where('id', $site)->firstOrFail();
        abort_if(empty($current['node_id']), 409, 'Site has no assigned node.');

        return match ($action) {
            'suspend' => $this->suspendSite($site, $current, $lifecycle, $desiredStates),
            'restore' => $this->restoreSite($site, $current, $lifecycle, $desiredStates),
            'delete' => $this->deleteSite($site, $current, $lifecycle, $desiredStates),
            'deploy' => $this->deploySite($site),
            'ssl' => $this->orderSsl($site),
            default => abort(404, 'Unsupported panel site action.'),
        };
    }

    public function nodeAction(Request $request, string $node, string $action): JsonResponse
    {
        $this->authorizePanel($request);
        DB::table('nodes')->where('id', $node)->firstOrFail();

        return match ($action) {
            'drain' => $this->drainNode($node),
            'migrate' => $this->migrateNode($node),
            default => abort(404, 'Unsupported panel node action.'),
        };
    }

    public function runConsistency(Request $request, GlobalConsistencyEngine $engine): JsonResponse
    {
        $this->authorizePanel($request);

        return response()->json($engine->run(), 202);
    }

    public function runBilling(Request $request, BillingEnforcementEngine $engine): JsonResponse
    {
        $this->authorizePanel($request);

        return response()->json($engine->run($request->string('window', '5m')->toString()), 202);
    }

    public function processBillingEvents(Request $request, FossBillingEventPipeline $pipeline): JsonResponse
    {
        $this->authorizePanel($request);

        return response()->json(['processed' => $pipeline->processDue()], 202);
    }

    private function authorizePanel(Request $request): object
    {
        try {
            $user = $this->tokens->authenticate($request);
        } catch (RuntimeException $exception) {
            abort(401, $exception->getMessage());
        }

        abort_unless(in_array($user->role, ['owner', 'admin', 'user'], true), 403, 'Panel access denied.');

        return $user;
    }

    private function suspendSite(string $site, array $current, SiteLifecycleCommandBuilder $lifecycle, DesiredStateProjector $desiredStates): JsonResponse
    {
        DB::table('sites')->where('id', $site)->update(['status' => 'suspended_manual', 'updated_at' => now()]);
        $desiredStates->projectSite($site);
        $lifecycle->suspend($current['node_id'], $site);

        return response()->json(['status' => 'suspended_manual'], 202);
    }

    private function restoreSite(string $site, array $current, SiteLifecycleCommandBuilder $lifecycle, DesiredStateProjector $desiredStates): JsonResponse
    {
        DB::table('sites')->where('id', $site)->update(['status' => 'active', 'updated_at' => now()]);
        $current['status'] = 'active';
        $desiredStates->projectSite($site);
        $lifecycle->restore($current['node_id'], $site, $current);

        return response()->json(['status' => 'active'], 202);
    }

    private function deleteSite(string $site, array $current, SiteLifecycleCommandBuilder $lifecycle, DesiredStateProjector $desiredStates): JsonResponse
    {
        DB::table('sites')->where('id', $site)->update(['status' => 'deleting', 'updated_at' => now()]);
        $desiredStates->projectSite($site);
        $lifecycle->delete($current['node_id'], $site);

        return response()->json(['status' => 'deleting'], 202);
    }

    private function deploySite(string $site): JsonResponse
    {
        DB::table('deployments')->insert([
            'id' => (string) Str::uuid(),
            'site_id' => $site,
            'status' => 'queued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['status' => 'queued'], 202);
    }

    private function orderSsl(string $site): JsonResponse
    {
        DB::table('ssl_orders')->insert([
            'id' => (string) Str::uuid(),
            'site_id' => $site,
            'provider' => 'letsencrypt',
            'status' => 'queued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['status' => 'queued'], 202);
    }

    private function drainNode(string $node): JsonResponse
    {
        DB::table('nodes')->where('id', $node)->update(['draining' => true, 'updated_at' => now()]);

        return response()->json(['status' => 'draining'], 202);
    }

    private function migrateNode(string $node): JsonResponse
    {
        DB::table('migration_jobs')->insert([
            'id' => (string) Str::uuid(),
            'source_node_id' => $node,
            'status' => 'queued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['status' => 'queued'], 202);
    }

    private function ensureBootstrap(object $user): array
    {
        $tenantId = $user->tenant_id;
        $actorId = $user->id;

        $plan = DB::table('hosting_plans')->where('status', 'active')->orderBy('created_at')->first();
        if ($plan === null) {
            $planId = (string) Str::uuid();
            DB::table('hosting_plans')->insert([
                'id' => $planId,
                'name' => 'Default VPS Plan',
                'tier' => 'standard',
                'cpu_millicores' => 1000,
                'memory_mb' => 1024,
                'disk_mb' => 10240,
                'features' => json_encode(['sites' => 25, 'nodejs' => true, 'ssl' => true], JSON_THROW_ON_ERROR),
                'runtime_policy' => json_encode(['allowed' => ['static', 'php', 'node', 'python', 'docker']], JSON_THROW_ON_ERROR),
                'billing_policy' => json_encode(['included_bandwidth_gb' => 100], JSON_THROW_ON_ERROR),
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $planId = $plan->id;
        }

        $node = DB::table('nodes')->where('status', 'online')->whereJsonContains('roles', 'web')->orderBy('created_at')->first();
        if ($node === null) {
            $nodeId = (string) Str::uuid();
            DB::table('nodes')->insert([
                'id' => $nodeId,
                'name' => 'local-vps-01',
                'roles' => json_encode(['web', 'edge'], JSON_THROW_ON_ERROR),
                'region' => 'local',
                'status' => 'online',
                'draining' => false,
                'labels' => json_encode(['bootstrap' => true], JSON_THROW_ON_ERROR),
                'capabilities' => json_encode([
                    'cpu_millicores' => 4000,
                    'memory_mb' => 8192,
                    'disk_mb' => 102400,
                    'runtimes' => ['static', 'php', 'node', 'python', 'docker'],
                ], JSON_THROW_ON_ERROR),
                'latest_metrics' => json_encode(['cpu_percent' => 0, 'memory_percent' => 0, 'disk_percent' => 0], JSON_THROW_ON_ERROR),
                'last_heartbeat_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $nodeId = $node->id;
        }

        return [
            'tenant_id' => $tenantId,
            'actor_id' => $actorId,
            'plan_id' => $planId,
            'node_id' => $nodeId,
        ];
    }

    private function defaultRuntimeVersion(string $runtime): string
    {
        return match ($runtime) {
            'php' => '8.4',
            'node' => '22',
            'python' => '3.12',
            'static' => 'nginx',
            default => 'latest',
        };
    }

    private function defaultTemplate(string $runtime): string
    {
        return match ($runtime) {
            'php' => 'generic-php',
            'node' => 'nodejs',
            'python' => 'python',
            'static' => 'static',
            default => 'reverse-proxy',
        };
    }
}
