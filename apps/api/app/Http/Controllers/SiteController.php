<?php

namespace App\Http\Controllers;

use App\Domain\Nginx\VhostService;
use App\Domain\Commands\SiteLifecycleCommandBuilder;
use App\Domain\Consistency\DesiredStateProjector;
use App\Domain\Sites\SiteProvisioningService;
use App\Domain\StateMachine\SiteState;
use App\Domain\StateMachine\StateMachineService;
use App\Domain\StateMachine\StateSource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class SiteController
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(DB::table('sites')->where('tenant_id', $request->user()->tenant_id)->orderByDesc('created_at')->get());
    }

    public function store(Request $request, SiteProvisioningService $sites): JsonResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:120'],
            'primary_domain' => ['required', 'string', 'max:253'],
            'runtime' => ['required', 'in:static,php,node,python,go,rust,bun,deno,docker,reverse_proxy'],
            'runtime_version' => ['required', 'string', 'max:32'],
            'vhost_template' => ['nullable', 'in:generic-php,laravel,wordpress,nodejs,static,python,reverse-proxy'],
            'app_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'host_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'install_command' => ['nullable', 'string', 'max:500'],
            'build_command' => ['nullable', 'string', 'max:500'],
            'start_command' => ['nullable', 'string', 'max:500'],
            'document_root' => ['nullable', 'string', 'max:255'],
            'repository' => ['nullable', 'array'],
            'environment' => ['nullable', 'array'],
        ]);

        return response()->json($sites->create($data, $request->user()->tenant_id, $request->user()->id), 202);
    }

    public function show(Request $request, string $site): JsonResponse
    {
        return response()->json(DB::table('sites')->where('tenant_id', $request->user()->tenant_id)->where('id', $site)->firstOrFail());
    }

    public function update(Request $request, string $site, SiteLifecycleCommandBuilder $lifecycle, DesiredStateProjector $desiredStates, StateMachineService $states): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'runtime_version' => ['sometimes', 'string', 'max:32'],
            'vhost_template' => ['sometimes', 'in:generic-php,laravel,wordpress,nodejs,static,python,reverse-proxy'],
            'app_port' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'host_port' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'install_command' => ['sometimes', 'string', 'max:500'],
            'build_command' => ['sometimes', 'string', 'max:500'],
            'start_command' => ['sometimes', 'string', 'max:500'],
            'document_root' => ['sometimes', 'string', 'max:255'],
            'environment' => ['sometimes', 'array'],
        ]);
        $current = DB::table('sites')->where('id', $site)->where('tenant_id', $request->user()->tenant_id)->firstOrFail();
        if (array_key_exists('environment', $data)) {
            $data['environment'] = json_encode($data['environment'], JSON_THROW_ON_ERROR);
        }
        $runtimeConfig = array_intersect_key($data, array_flip(['vhost_template', 'document_root', 'app_port', 'host_port', 'install_command', 'build_command', 'start_command']));
        $dbData = array_intersect_key($data, array_flip(['name', 'runtime_version', 'environment']));
        if ($runtimeConfig !== []) {
            $existingRuntimeConfig = is_string($current->runtime_config ?? null) ? json_decode($current->runtime_config, true, 512, JSON_THROW_ON_ERROR) : [];
            $dbData['runtime_config'] = json_encode(array_filter($runtimeConfig + $existingRuntimeConfig, fn (mixed $value): bool => $value !== null && $value !== ''), JSON_THROW_ON_ERROR);
        }
        if ($dbData !== []) {
            DB::table('sites')->where('id', $site)->where('tenant_id', $request->user()->tenant_id)->update($dbData + ['updated_at' => now()]);
        }
        $states->transition($request->user()->tenant_id, $site, SiteState::Updating, StateSource::Manual, 'manual:' . $site . ':update:' . hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)), [
            'actor_id' => $request->user()->id,
        ]);
        $updated = (array) DB::table('sites')->where('id', $site)->where('tenant_id', $request->user()->tenant_id)->firstOrFail();
        $updated = $updated + $runtimeConfig;
        $desiredStates->projectSite($site);
        $lifecycle->update($current->node_id, $site, $updated);

        return response()->json(['status' => 'updating'], 202);
    }

    public function destroy(Request $request, string $site, SiteLifecycleCommandBuilder $lifecycle, DesiredStateProjector $desiredStates, StateMachineService $states): JsonResponse
    {
        $current = DB::table('sites')->where('id', $site)->where('tenant_id', $request->user()->tenant_id)->firstOrFail();
        $states->transition($request->user()->tenant_id, $site, SiteState::Deleting, StateSource::Manual, 'manual:' . $site . ':delete', [
            'actor_id' => $request->user()->id,
        ]);
        $desiredStates->projectSite($site);
        $lifecycle->delete($current->node_id, $site);

        return response()->json(['status' => 'deleting'], 202);
    }

    public function suspend(Request $request, string $site, SiteLifecycleCommandBuilder $lifecycle, DesiredStateProjector $desiredStates, StateMachineService $states): JsonResponse
    {
        $current = DB::table('sites')->where('id', $site)->where('tenant_id', $request->user()->tenant_id)->firstOrFail();
        $states->transition($request->user()->tenant_id, $site, SiteState::SuspendedManual, StateSource::Manual, 'manual:' . $site . ':suspend', [
            'actor_id' => $request->user()->id,
        ]);
        $desiredStates->projectSite($site);
        $lifecycle->suspend($current->node_id, $site);

        return response()->json(['status' => 'suspended_manual'], 202);
    }

    public function restore(Request $request, string $site, SiteLifecycleCommandBuilder $lifecycle, DesiredStateProjector $desiredStates, StateMachineService $states): JsonResponse
    {
        $current = (array) DB::table('sites')->where('id', $site)->where('tenant_id', $request->user()->tenant_id)->firstOrFail();
        $states->transition($request->user()->tenant_id, $site, SiteState::Active, StateSource::Manual, 'manual:' . $site . ':restore', [
            'actor_id' => $request->user()->id,
        ]);
        $desiredStates->projectSite($site);
        $lifecycle->restore($current['node_id'], $site, $current);

        return response()->json(['status' => 'active'], 202);
    }

    public function deploy(string $site): JsonResponse
    {
        DB::table('deployments')->insert([
            'id' => (string) str()->uuid(),
            'site_id' => $site,
            'status' => 'queued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['status' => 'queued'], 202);
    }

    public function previewVhost(Request $request, string $site, VhostService $vhosts): JsonResponse
    {
        $revision = $vhosts->createRevision($site, $request->user()->tenant_id, $request->input('directives', []), $request->user()->id);

        return response()->json($revision);
    }

    public function orderCertificate(string $site): JsonResponse
    {
        DB::table('ssl_orders')->insert([
            'id' => (string) str()->uuid(),
            'site_id' => $site,
            'provider' => 'letsencrypt',
            'status' => 'queued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['status' => 'queued'], 202);
    }
}
