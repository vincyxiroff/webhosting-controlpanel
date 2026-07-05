# Operation Journal

The Operation Journal is a lightweight event-sourcing layer for enterprise audit, debugging, rollback planning and state reconstruction.

The platform still keeps current state tables for fast reads, but every meaningful operation is also recorded as an immutable journal entry.

## Example Timeline

```text
ProvisionRequested
NodeAllocated
ProvisionStarted
RuntimeProvisionQueued
ContainerCreateQueued
VolumeAttachQueued
NginxConfigureQueued
ServiceStartQueued
HealthCheckQueued
CommandAcknowledged
CommandRunning
CommandSucceeded
Activated

BillingLimitExceeded
BillingSuspended
CommandCreated
CommandSent
CommandSucceeded

ManualUnsuspendRequested
Activated
```

## Why This Exists

| Capability | Result |
| --- | --- |
| Debugging | Operators can see which decision caused each command and state change. |
| Reconstruction | A site can be rebuilt from journal events into an expected state projection. |
| Rollback | Failed commands keep causation IDs and rollback commands are journaled. |
| Audit | Each operation has source, tenant, entity, actor, command and correlation metadata. |
| Compliance | The journal is append-only by design; current-state tables are derived views. |

## Tables

| Table | Purpose |
| --- | --- |
| `operation_journal` | Immutable operation stream with tenant sequence, source, entity, payload and correlation data. |
| `operation_snapshots` | Periodic reconstructed snapshots for faster reads and rollback planning. |
| `ordered_events` | Monotonic per-tenant event order shared with the stability layer. |

## Journal Fields

| Field | Meaning |
| --- | --- |
| `tenant_id` | Tenant boundary for ordering and audit. |
| `sequence` | Monotonic tenant-local sequence from `EventSequencer`. |
| `operation_name` | Business-readable event name such as `NodeAllocated` or `BillingLimitExceeded`. |
| `category` | `lifecycle`, `scheduler`, `state`, `command`, `billing`, etc. |
| `source` | Component that emitted the event. |
| `entity_type` / `entity_id` | Primary aggregate, usually `site`. |
| `site_id`, `node_id`, `command_id` | Operational links for traceability. |
| `correlation_id` | Groups a full workflow such as one site provisioning. |
| `causation_id` | Points to the event/command that caused this operation. |
| `idempotency_key` | Prevents duplicate journal entries during retries. |
| `payload` | Event details. |
| `metadata` | Non-domain hints such as attempts, severity or command type. |

## Integration Points

| Component | Journal events |
| --- | --- |
| Site provisioning | `ProvisionRequested`, `NodeAllocated`, state transition events. |
| State machine | `ProvisionStarted`, `Activated`, `BillingSuspended`, `ReconciliationStarted`, etc. |
| Lifecycle command builder | `RuntimeProvisionQueued`, `ContainerCreateQueued`, `NginxConfigureQueued`, etc. |
| Agent command service | `CommandCreated`, `CommandSent`, `CommandAcknowledged`, `CommandRunning`, `CommandSucceeded`, `CommandFailed`, `CommandDeadLettered`, `RollbackQueued`. |
| Billing enforcement | `BillingSoftLimitExceeded`, `BillingLimitExceeded`, `SecurityAbuseDetected`. |
| FOSSBilling pipeline | `FossBillingEventReceived`, `FossBillingEventProcessed`. |

## API

| Endpoint | Purpose |
| --- | --- |
| `GET /v1/operation-journal` | Recent tenant journal events. Supports `site_id`, `category`, `limit`. |
| `GET /v1/operation-journal/sites/{site}` | Full site timeline, rebuilt state and latest snapshot. |
| `POST /v1/operation-journal/sites/{site}/snapshot` | Rebuilds and stores a site snapshot from journal events. |

## Rollback Model

The journal does not blindly roll back by reversing SQL rows. It preserves enough causation data to generate compensating actions:

```text
CommandFailed(runtime.provision)
CommandDeadLettered(runtime.provision)
RollbackQueued(site.delete)
CommandSent(site.delete)
CommandSucceeded(site.delete)
```

This matches real infrastructure behavior: containers, NGINX files and volumes need compensating commands, not database-only rewinds.

## Reconstruction

`OperationJournal::rebuildSite()` replays the site timeline and derives:

- latest status;
- allocated node;
- command history;
- last observed operation.

`OperationJournal::snapshotSite()` stores the reconstructed state with checksum in `operation_snapshots`.
