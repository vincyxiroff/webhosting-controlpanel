<?php

namespace App\Domain\Billing\Pipeline;

use App\Domain\Commands\SiteLifecycleCommandBuilder;
use App\Domain\Events\OperationJournal;
use App\Domain\Sites\SiteProvisioningService;
use Illuminate\Support\Facades\DB;

final class FossBillingEventPipeline
{
    public function __construct(private readonly OperationJournal $journal)
    {
    }

    public function ingest(array $payload): string
    {
        $eventId = (string) ($payload['event_id'] ?? hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)));
        $existing = DB::table('billing_events')->where('provider_event_id', $eventId)->first();
        if ($existing !== null) {
            return $existing->id;
        }

        $id = (string) str()->uuid();
        DB::table('billing_events')->insert([
            'id' => $id,
            'tenant_id' => $payload['tenant_id'] ?? null,
            'provider' => 'fossbilling',
            'provider_event_id' => $eventId,
            'event_type' => $payload['event'] ?? $payload['type'] ?? 'unknown',
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'status' => 'received',
            'sequence' => (int) ($payload['sequence'] ?? 0),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->journal->record($payload['tenant_id'] ?? null, 'FossBillingEventReceived', 'billing', 'fossbilling', 'tenant', $payload['tenant_id'] ?? null, [
            'billing_event_id' => $id,
            'event_type' => $payload['event'] ?? $payload['type'] ?? 'unknown',
            'payload' => $payload,
        ], 'journal:fossbilling-received:' . $eventId, [], 'billing-event:' . $eventId);

        return $id;
    }

    public function processDue(int $limit = 100): int
    {
        $events = DB::table('billing_events')
            ->whereIn('status', ['received', 'failed'])
            ->orderBy('tenant_id')
            ->orderBy('sequence')
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        foreach ($events as $event) {
            try {
                $this->process($event);
                DB::table('billing_events')->where('id', $event->id)->update([
                    'status' => 'processed',
                    'processed_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->journal->record($event->tenant_id, 'FossBillingEventProcessed', 'billing', 'fossbilling', 'tenant', $event->tenant_id, [
                    'billing_event_id' => $event->id,
                    'event_type' => $event->event_type,
                ], 'journal:fossbilling-processed:' . $event->id, [], 'billing-event:' . $event->id);
            } catch (\Throwable $exception) {
                DB::table('billing_events')->where('id', $event->id)->update([
                    'status' => 'failed',
                    'last_error' => $exception->getMessage(),
                    'updated_at' => now(),
                ]);
            }
        }

        return $events->count();
    }

    private function process(object $event): void
    {
        $payload = json_decode($event->payload, true, 512, JSON_THROW_ON_ERROR);
        match ($event->event_type) {
            'create_site', 'order_activated' => $this->createSite($payload),
            'suspend_site', 'invoice_unpaid', 'service_suspended' => $this->setTenantBillingStatus($payload, 'suspended'),
            'unsuspend_site', 'invoice_paid', 'service_unsuspended' => $this->setTenantBillingStatus($payload, 'active'),
            'upgrade_plan', 'downgrade_plan' => $this->changePlan($payload),
            'delete_site', 'service_terminated' => $this->deleteSite($payload),
            default => null,
        };
    }

    private function createSite(array $payload): void
    {
        if (! isset($payload['tenant_id'], $payload['plan_id'], $payload['domain'])) {
            return;
        }
        app(SiteProvisioningService::class)->create([
            'plan_id' => $payload['plan_id'],
            'name' => $payload['name'] ?? $payload['domain'],
            'primary_domain' => $payload['domain'],
            'runtime' => $payload['runtime'] ?? 'static',
            'runtime_version' => $payload['runtime_version'] ?? 'latest',
            'repository' => $payload['repository'] ?? null,
            'environment' => $payload['environment'] ?? [],
        ], $payload['tenant_id'], $payload['actor_id'] ?? '00000000-0000-0000-0000-000000000000');
    }

    private function setTenantBillingStatus(array $payload, string $status): void
    {
        if (! isset($payload['tenant_id'])) {
            return;
        }
        DB::table('tenant_billing_profiles')->where('tenant_id', $payload['tenant_id'])->update([
            'billing_status' => $status,
            'updated_at' => now(),
        ]);
    }

    private function changePlan(array $payload): void
    {
        if (! isset($payload['tenant_id'], $payload['plan_id'])) {
            return;
        }
        $plan = DB::table('hosting_plans')->where('id', $payload['plan_id'])->firstOrFail();
        $policy = is_string($plan->billing_policy) ? json_decode($plan->billing_policy, true, 512, JSON_THROW_ON_ERROR) : [];
        $existing = DB::table('tenant_billing_profiles')->where('tenant_id', $payload['tenant_id'])->first();
        DB::table('tenant_billing_profiles')->updateOrInsert(
            ['tenant_id' => $payload['tenant_id']],
            [
                'id' => $existing->id ?? (string) str()->uuid(),
                'plan_id' => $payload['plan_id'],
                'provider' => 'fossbilling',
                'provider_client_id' => $payload['client_id'] ?? null,
                'provider_subscription_id' => $payload['subscription_id'] ?? null,
                'billing_status' => 'active',
                'limits' => json_encode($policy['limits'] ?? [], JSON_THROW_ON_ERROR),
                'soft_thresholds' => json_encode($policy['soft_thresholds'] ?? ['ratio' => 0.8], JSON_THROW_ON_ERROR),
                'hard_thresholds' => json_encode($policy['hard_thresholds'] ?? ['ratio' => 1.0], JSON_THROW_ON_ERROR),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    private function deleteSite(array $payload): void
    {
        if (! isset($payload['site_id'])) {
            return;
        }
        $site = DB::table('sites')->where('id', $payload['site_id'])->first();
        if ($site === null) {
            return;
        }
        app(SiteLifecycleCommandBuilder::class)->delete($site->node_id, $site->id);
        DB::table('sites')->where('id', $site->id)->update(['status' => 'terminating', 'updated_at' => now()]);
    }
}
