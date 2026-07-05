<?php

namespace App\Http\Controllers;

use App\Domain\Agent\AgentAuthenticator;
use App\Domain\Agent\AgentRegistrationService;
use App\Domain\Agent\HeartbeatService;
use App\Domain\Commands\AgentCommandService;
use App\Domain\Reconciliation\StateReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AgentController
{
    public function register(Request $request, AgentRegistrationService $registration): JsonResponse
    {
        $data = $request->validate([
            'registration_token' => ['required', 'string'],
            'fingerprint' => ['required', 'string', 'max:160'],
            'agent_version' => ['required', 'string', 'max:40'],
            'capabilities' => ['required', 'array'],
            'runtime_support' => ['required', 'array'],
            'labels' => ['nullable', 'array'],
        ]);

        return response()->json($registration->register($data), 201);
    }

    public function heartbeat(Request $request, AgentAuthenticator $authenticator, HeartbeatService $heartbeats): JsonResponse
    {
        $node = $authenticator->authenticate($request);
        $data = $request->validate([
            'reported_at' => ['nullable', 'date'],
            'metrics' => ['required', 'array'],
            'containers' => ['nullable', 'array'],
            'active_sites' => ['nullable', 'array'],
            'health' => ['required', 'array'],
            'capabilities' => ['nullable', 'array'],
            'runtime_support' => ['nullable', 'array'],
        ]);
        $heartbeats->record($node->id, $data);

        return response()->json(['status' => 'ok']);
    }

    public function pull(Request $request, AgentAuthenticator $authenticator, AgentCommandService $commands): JsonResponse
    {
        $node = $authenticator->authenticate($request);
        $limit = min(50, max(1, (int) $request->input('limit', 10)));

        return response()->json(['commands' => $commands->pull($node->id, $limit)]);
    }

    public function result(Request $request, string $command, AgentAuthenticator $authenticator, AgentCommandService $commands): JsonResponse
    {
        $node = $authenticator->authenticate($request);
        $data = $request->validate([
            'status' => ['required', 'in:acknowledged,running,success,failed'],
            'message' => ['nullable', 'string'],
            'error' => ['nullable', 'string'],
            'meta' => ['nullable', 'array'],
        ]);
        $commands->report($node->id, $command, $data);

        return response()->json(['status' => 'accepted']);
    }

    public function reconcile(Request $request, AgentAuthenticator $authenticator, StateReconciliationService $reconciliation): JsonResponse
    {
        $authenticator->authenticate($request);
        $data = $request->validate(['site_id' => ['required', 'uuid']]);

        return response()->json($reconciliation->reconcileSite($data['site_id']), 202);
    }

    public function capabilityEndpoint(Request $request, AgentAuthenticator $authenticator, string $operation): JsonResponse
    {
        $node = $authenticator->authenticate($request);

        return response()->json([
            'status' => 'supported',
            'node_id' => $node->id,
            'operation' => $operation,
            'mode' => 'pull-command',
            'message' => 'This operation is executed through /agent/v1/command/pull for reliable delivery.',
        ]);
    }

    public function siteCreate(Request $request, AgentAuthenticator $authenticator): JsonResponse
    {
        return $this->capabilityEndpoint($request, $authenticator, 'site.create');
    }

    public function siteDelete(Request $request, AgentAuthenticator $authenticator): JsonResponse
    {
        return $this->capabilityEndpoint($request, $authenticator, 'site.delete');
    }

    public function siteSuspend(Request $request, AgentAuthenticator $authenticator): JsonResponse
    {
        return $this->capabilityEndpoint($request, $authenticator, 'site.suspend');
    }

    public function siteRestore(Request $request, AgentAuthenticator $authenticator): JsonResponse
    {
        return $this->capabilityEndpoint($request, $authenticator, 'site.restore');
    }

    public function runtimeProvision(Request $request, AgentAuthenticator $authenticator): JsonResponse
    {
        return $this->capabilityEndpoint($request, $authenticator, 'runtime.provision');
    }

    public function runtimeDestroy(Request $request, AgentAuthenticator $authenticator): JsonResponse
    {
        return $this->capabilityEndpoint($request, $authenticator, 'runtime.destroy');
    }
}
