<?php

namespace App\Domain\Commands;

use App\Domain\Events\OperationJournal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AgentCommandService
{
    public function __construct(private readonly OperationJournal $journal)
    {
    }

    public function create(string $nodeId, string $command, array $payload, string $idempotencyKey, ?string $siteId = null, string $type = 'site'): string
    {
        $existing = DB::table('node_commands')->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return $existing->id;
        }

        $id = (string) Str::uuid();
        DB::table('node_commands')->insert([
            'id' => $id,
            'node_id' => $nodeId,
            'site_id' => $siteId,
            'type' => $type,
            'command' => $command,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'status' => 'CREATED',
            'idempotency_key' => $idempotencyKey,
            'attempt' => 0,
            'max_attempts' => 5,
            'available_at' => now(),
            'timeout_at' => now()->addMinutes(10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->recordCommandEvent($id, $nodeId, $siteId, $command, 'CommandCreated', $payload, $idempotencyKey, $type);

        return $id;
    }

    public function pull(string $nodeId, int $limit = 10): array
    {
        return DB::transaction(function () use ($nodeId, $limit): array {
            $commands = DB::table('node_commands')
                ->where('node_id', $nodeId)
                ->whereIn('status', ['CREATED', 'FAILED'])
                ->where('attempt', '<', DB::raw('max_attempts'))
                ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))
                ->orderBy('created_at')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            $now = now();
            foreach ($commands as $command) {
                DB::table('node_commands')->where('id', $command->id)->update([
                    'status' => 'SENT',
                    'attempt' => ((int) $command->attempt) + 1,
                    'sent_at' => $now,
                    'timeout_at' => $now->copy()->addMinutes(10),
                    'updated_at' => $now,
                ]);
                $this->recordCommandEvent($command->id, $command->node_id, $command->site_id, $command->command, 'CommandSent', json_decode($command->payload, true, 512, JSON_THROW_ON_ERROR), 'journal:command-sent:' . $command->id . ':' . (((int) $command->attempt) + 1), $command->type, [
                    'attempt' => ((int) $command->attempt) + 1,
                ]);
            }

            return $commands->map(fn (object $command): array => [
                'id' => $command->id,
                'command' => $command->command,
                'payload' => json_decode($command->payload, true, 512, JSON_THROW_ON_ERROR),
                'idempotency_key' => $command->idempotency_key,
                'attempt' => ((int) $command->attempt) + 1,
                'timeout_seconds' => 600,
            ])->all();
        });
    }

    public function report(string $nodeId, string $commandId, array $result): void
    {
        DB::transaction(function () use ($nodeId, $commandId, $result): void {
            $command = DB::table('node_commands')->where('node_id', $nodeId)->where('id', $commandId)->lockForUpdate()->first();
            abort_if($command === null, 404, 'Command not found.');

            $status = $result['status'];
            $mapped = match ($status) {
                'acknowledged' => 'ACKNOWLEDGED',
                'running' => 'RUNNING',
                'success' => 'SUCCESS',
                'failed' => 'FAILED',
                default => abort(422, 'Invalid command status.'),
            };

            $updates = [
                'status' => $mapped,
                'result' => json_encode($result, JSON_THROW_ON_ERROR),
                'last_error' => $result['error'] ?? null,
                'updated_at' => now(),
            ];
            if ($mapped === 'ACKNOWLEDGED') {
                $updates['acknowledged_at'] = now();
            }
            if ($mapped === 'RUNNING') {
                $updates['running_at'] = now();
            }
            if (in_array($mapped, ['SUCCESS', 'FAILED'], true)) {
                $updates['finished_at'] = now();
            }
            if ($mapped === 'FAILED' && ((int) $command->attempt) < ((int) $command->max_attempts)) {
                $updates['available_at'] = now()->addSeconds(2 ** min(8, (int) $command->attempt));
            }
            if ($mapped === 'FAILED' && ((int) $command->attempt) >= ((int) $command->max_attempts)) {
                $this->deadLetter($command, $result['error'] ?? 'Command failed.');
                $this->queueRollback($command);
            }
            if ($mapped === 'SUCCESS') {
                $this->projectRuntimeObject($command, $result);
            }

            DB::table('node_commands')->where('id', $commandId)->update($updates);
            $this->recordCommandEvent($command->id, $command->node_id, $command->site_id, $command->command, $this->operationForStatus($mapped), json_decode($command->payload, true, 512, JSON_THROW_ON_ERROR) + ['result' => $result], 'journal:command-result:' . $commandId . ':' . $mapped . ':' . hash('sha256', json_encode($result, JSON_THROW_ON_ERROR)), $command->type, [
                'status' => $mapped,
                'attempt' => (int) $command->attempt,
            ]);
        });
    }

    public function markTimeouts(): int
    {
        $timedOut = DB::table('node_commands')
            ->whereIn('status', ['SENT', 'ACKNOWLEDGED', 'RUNNING'])
            ->where('timeout_at', '<', now())
            ->get();

        foreach ($timedOut as $command) {
            if ((int) $command->attempt >= (int) $command->max_attempts) {
                $this->deadLetter($command, 'Command timed out.');
                DB::table('node_commands')->where('id', $command->id)->update([
                    'status' => 'FAILED',
                    'last_error' => 'Command timed out.',
                    'finished_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->recordCommandEvent($command->id, $command->node_id, $command->site_id, $command->command, 'CommandTimedOut', json_decode($command->payload, true, 512, JSON_THROW_ON_ERROR), 'journal:command-timeout-final:' . $command->id, $command->type);
                continue;
            }

            DB::table('node_commands')->where('id', $command->id)->update([
                'status' => 'FAILED',
                'last_error' => 'Command timed out.',
                'available_at' => now()->addSeconds(2 ** min(8, (int) $command->attempt)),
                'updated_at' => now(),
            ]);
            $this->recordCommandEvent($command->id, $command->node_id, $command->site_id, $command->command, 'CommandRetryScheduled', json_decode($command->payload, true, 512, JSON_THROW_ON_ERROR), 'journal:command-timeout-retry:' . $command->id . ':' . $command->attempt, $command->type);
        }

        return $timedOut->count();
    }

    private function deadLetter(object $command, string $error): void
    {
        DB::table('dead_letter_commands')->insert([
            'id' => (string) Str::uuid(),
            'command_id' => $command->id,
            'node_id' => $command->node_id,
            'site_id' => $command->site_id,
            'command' => $command->command,
            'payload' => $command->payload,
            'final_status' => 'FAILED',
            'error' => $error,
            'attempts' => $command->attempt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->recordCommandEvent($command->id, $command->node_id, $command->site_id, $command->command, 'CommandDeadLettered', json_decode($command->payload, true, 512, JSON_THROW_ON_ERROR) + ['error' => $error], 'journal:command-dead-letter:' . $command->id, $command->type);
    }

    private function queueRollback(object $command): void
    {
        $payload = json_decode($command->payload, true, 512, JSON_THROW_ON_ERROR);
        $siteId = $command->site_id ?? ($payload['site_id'] ?? null);
        if ($siteId === null) {
            return;
        }

        $rollbackCommand = match ($command->command) {
            'site.create', 'volume.attach', 'nginx.configure', 'service.start', 'health.check' => 'site.delete',
            'site.suspend' => 'service.start',
            default => null,
        };
        if ($rollbackCommand === null) {
            return;
        }

        $rollbackId = $this->create(
            nodeId: $command->node_id,
            command: $rollbackCommand,
            payload: ['site_id' => $siteId, 'rollback_for' => $command->id],
            idempotencyKey: 'rollback:' . $command->id,
            siteId: $siteId,
            type: 'rollback',
        );
        $this->recordCommandEvent($rollbackId, $command->node_id, $siteId, $rollbackCommand, 'RollbackQueued', ['site_id' => $siteId, 'rollback_for' => $command->id], 'journal:rollback-queued:' . $command->id, 'rollback', [
            'causation_command_id' => $command->id,
        ]);
    }

    private function projectRuntimeObject(object $command, array $result): void
    {
        $meta = $result['meta'] ?? [];
        $payload = json_decode($command->payload, true, 512, JSON_THROW_ON_ERROR);
        $siteId = $command->site_id ?? ($payload['site_id'] ?? null);
        if ($siteId === null) {
            return;
        }

        $existing = DB::table('site_runtime_objects')->where('site_id', $siteId)->first();
        $data = [
            'id' => $existing->id ?? (string) Str::uuid(),
            'site_id' => $siteId,
            'node_id' => $command->node_id,
            'updated_at' => now(),
            'created_at' => $existing->created_at ?? now(),
        ];

        foreach ([
            'container_id',
            'container_name',
            'network_id',
            'network_name',
            'volume_id',
            'volume_name',
            'runtime_type',
            'runtime_version',
            'nginx_config_path',
            'nginx_config_version',
        ] as $key) {
            if (array_key_exists($key, $meta)) {
                $data[$key] = $meta[$key];
            }
        }
        if (array_key_exists('resource_limits', $meta)) {
            $data['resource_limits'] = json_encode($meta['resource_limits'], JSON_THROW_ON_ERROR);
        }
        if (array_key_exists('health', $meta)) {
            $data['health'] = json_encode($meta['health'], JSON_THROW_ON_ERROR);
        }

        DB::table('site_runtime_objects')->updateOrInsert(['site_id' => $siteId], $data);
    }

    private function recordCommandEvent(string $commandId, string $nodeId, ?string $siteId, string $command, string $operation, array $payload, string $idempotencyKey, string $type, array $metadata = []): void
    {
        $site = $siteId !== null ? DB::table('sites')->where('id', $siteId)->first() : null;
        $tenantId = $site?->tenant_id;
        $this->journal->record($tenantId, $operation, 'command', 'agent-command', $siteId === null ? 'node' : 'site', $siteId ?? $nodeId, [
            'command_id' => $commandId,
            'command' => $command,
            'node_id' => $nodeId,
            'site_id' => $siteId,
            'payload' => $payload,
        ], $idempotencyKey, $metadata + ['type' => $type], 'command:' . $commandId, $metadata['causation_command_id'] ?? null, null, $siteId, $nodeId, $commandId);
    }

    private function operationForStatus(string $status): string
    {
        return match ($status) {
            'ACKNOWLEDGED' => 'CommandAcknowledged',
            'RUNNING' => 'CommandRunning',
            'SUCCESS' => 'CommandSucceeded',
            'FAILED' => 'CommandFailed',
            default => 'CommandUpdated',
        };
    }
}
