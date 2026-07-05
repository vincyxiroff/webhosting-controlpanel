# Real-Time Billing Enforcement Engine

## Billing Architecture

```text
FOSSBilling
   |
   v
Webhook -> billing_webhooks -> billing_events
                                |
                                v
                      FossBillingEventPipeline
                                |
                                v
                    Site lifecycle / plan status

Go Agent heartbeat -> usage_time_series -> tenant_usage_rollups
                                               |
                                               v
                                  BillingEnforcementEngine
                                               |
                                               v
                         billing_enforcement_decisions
                                               |
                                               v
                              AgentCommandService
                                               |
                                               v
                              site.throttle / site.suspend / site.isolate
```

## Usage Metering

`BillingUsageMeter` ingests per-site metrics from heartbeat active sites:

- CPU percent
- memory bytes
- disk read/write bytes
- network RX/TX bytes
- request count
- p95 latency
- error rate

Raw samples are stored in `usage_time_series`.

Rollups are stored in `tenant_usage_rollups` for:

- `1m`
- `5m`
- `1h`

## Enforcement

`BillingEnforcementEngine` compares latest tenant rollups against `tenant_billing_profiles` limits.

Soft threshold:

- `site.throttle`
- reduced CPU/RAM via Docker update

Hard threshold:

- `site.suspend`
- stop container
- block NGINX route with HTTP 451 response
- mark site `suspended`

Abuse signals:

- CPU spike
- request flood
- bandwidth flood
- high disk IO / possible mining

Abuse action:

- `site.isolate`
- disconnect container from its per-site network

## FOSSBilling Pipeline

FOSSBilling webhook payloads are stored idempotently by `provider_event_id`.

Supported event types:

- `create_site`
- `order_activated`
- `suspend_site`
- `invoice_unpaid`
- `service_suspended`
- `unsuspend_site`
- `invoice_paid`
- `service_unsuspended`
- `upgrade_plan`
- `downgrade_plan`
- `delete_site`
- `service_terminated`

Events are processed in tenant/sequence order and are retry-safe.

## Workers

Run metering aggregation and enforcement:

```bash
php artisan controlpanel:billing-enforce --window=5m
```

Process queued billing events:

```bash
php artisan controlpanel:billing-events --limit=100
```

Production deployment should run both every 30-60 seconds.

## Safety

- Infrastructure actions are idempotent through `AgentCommandService`.
- Billing event ingestion is idempotent by provider event ID.
- Enforcement decisions are idempotent by tenant/window/decision.
- Agent state is used for usage observations only, not as billing truth.

