<?php

namespace App\Domain\Billing;

use App\Support\DomainEvent;
use App\Support\EventRecorder;
use App\Domain\Billing\Pipeline\FossBillingEventPipeline;
use Illuminate\Support\Facades\DB;

final class FossBillingService
{
    public function __construct(
        private readonly EventRecorder $events,
        private readonly FossBillingEventPipeline $pipeline,
    )
    {
    }

    public function receiveWebhook(array $payload, string $signature): void
    {
        $secret = config('services.fossbilling.webhook_secret');
        $expected = hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR), $secret);

        abort_unless(hash_equals($expected, $signature), 401, 'Invalid FOSSBilling signature.');

        DB::table('billing_webhooks')->insert([
            'id' => (string) str()->uuid(),
            'provider' => 'fossbilling',
            'event' => $payload['event'] ?? $payload['type'] ?? 'unknown',
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'status' => 'accepted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $eventId = $this->pipeline->ingest($payload);
        $this->pipeline->processDue();

        $this->events->record(new DomainEvent('billing.webhook.received', 'billing', $eventId, $payload, $payload['tenant_id'] ?? null));
    }
}
