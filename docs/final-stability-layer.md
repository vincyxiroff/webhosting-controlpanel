# Final Stability Layer

This layer prevents the control plane from issuing conflicting infrastructure actions. Laravel remains the source of truth, but every state-changing decision is serialized through a state machine, Redis distributed locks, ordered tenant events, and priority-based conflict resolution.

## Control Loop

```text
API / Worker / Billing / Scheduler
        |
        v
Acquire distributed lock
        |
        v
Validate transition + priority
        |
        v
Update canonical site state in DB
        |
        v
Append per-tenant ordered event
        |
        v
Emit agent command / reconcile action
        |
        v
Release distributed lock
```

## Canonical Site States

| State | Meaning | Main owner |
| --- | --- | --- |
| `PENDING_PROVISION` | Site row exists but execution is not started. | Scheduler |
| `PROVISIONING` | Containers, volumes, runtime and NGINX are being created. | Scheduler |
| `ACTIVE` | Site is expected to serve traffic. | Manual / consistency |
| `UPDATING` | Runtime, environment or config is being changed. | Manual |
| `SUSPENDED_BILLING` | Billing or plan enforcement blocks runtime traffic. | Billing |
| `SUSPENDED_MANUAL` | Operator intentionally suspended the site. | Manual |
| `DEGRADED` | Security or health has isolated/reduced the workload. | Security |
| `RECONCILING` | Consistency engine is repairing drift. | Consistency |
| `DELETING` | Cleanup is in progress. | Manual |
| `DELETED` | Terminal state after cleanup. | Manual / agent result |

Allowed transitions are enforced by `TransitionValidator`. Same-state transitions are idempotent and allowed, so repeated workers do not create duplicate effects.

## Priority Rules

When two sources compete, the higher numeric priority wins:

| Source | Priority | Use case |
| --- | ---: | --- |
| `billing` | 500 | Suspension for unpaid, over-limit or invalid plans. |
| `security` | 400 | Abuse isolation, attack containment, runtime degradation. |
| `manual` | 300 | Operator/API updates, restore, suspend, delete. |
| `consistency` | 200 | Drift repair and convergence. |
| `scheduler` | 100 | Initial placement and provisioning. |

Lower-priority transitions are deferred, logged in `state_transition_logs`, and recorded in `conflict_logs`. This prevents the reconciler from re-enabling a billing-suspended site or the scheduler from racing an operator delete.

## Redis Lock Schema

Redis keys use:

```text
controlpanel:lock:{scope}:{id}
```

Scopes:

| Scope | Example | Purpose |
| --- | --- | --- |
| `site` | `controlpanel:lock:site:{site_id}` | Serialize all state changes for one site. |
| `tenant` | `controlpanel:lock:tenant:{tenant_id}` | Reserve ordered tenant operations. |
| `node` | `controlpanel:lock:node:{node_id}` | Protect node-level drains or placement. |
| `global` | `controlpanel:lock:global:{resource}` | Protect global maintenance actions. |

Each lock stores an owner identity:

```text
{hostname}:{process_id}:{source}
```

Locks use TTL, are reentrant for the same owner, and are released with a compare-and-delete Lua script so a stale worker cannot release another worker's lock. Every acquire/release is written to `distributed_lock_audits`.

## Data Model

| Table | Purpose |
| --- | --- |
| `site_state_machines` | Current canonical state, version, owner source and last idempotency key. |
| `state_transition_logs` | Immutable audit trail of applied/deferred transitions. |
| `tenant_event_sequences` | Monotonic per-tenant sequence counter. |
| `ordered_events` | Ordered, idempotent event stream for tenant-local workflows. |
| `conflict_logs` | Explicit winner/loser records for priority conflicts. |
| `distributed_lock_audits` | Lock ownership and release audit trail. |

## Event Ordering

`EventSequencer` appends every state transition with a tenant-local monotonic sequence. The sequence is allocated inside a database transaction with row locking on `tenant_event_sequences`, and `ordered_events.idempotency_key` is unique. Replaying the same transition with the same idempotency key returns the original sequence.

This gives every tenant a deterministic event order without requiring global ordering across the whole platform.

## Integration Points

| Engine | Stability behavior |
| --- | --- |
| Site provisioning | Creates `PENDING_PROVISION`, then `PROVISIONING`, before desired state projection and agent commands. |
| Manual API | Update, suspend, restore and delete use the state machine before queuing lifecycle commands. |
| Billing enforcement | Billing suspension moves sites to `SUSPENDED_BILLING`; security isolation moves sites to `DEGRADED`; deferred transitions do not emit agent commands. |
| Consistency engine | Only reconciles operational states and first moves them to `RECONCILING`; protected states are skipped. |

## Failure Scenarios

| Scenario | Behavior |
| --- | --- |
| Duplicate request | Same idempotency key returns the existing state/version and does not emit a duplicate ordered event. |
| Worker crash with lock held | Redis TTL releases the lock; audit shows the abandoned owner. |
| Stale worker releases lock | Lua compare-and-delete refuses release unless the owner matches. |
| Billing vs consistency | Billing wins; consistency transition is deferred and no repair command is emitted. |
| Security vs manual restore | Security wins while isolation is active; manual restore must wait for a higher-priority clear path. |
| Delete vs background repair | Delete state is not reconciled; cleanup lifecycle owns the site until terminal `DELETED`. |

## Operator APIs

Authenticated tenant APIs:

| Endpoint | Purpose |
| --- | --- |
| `GET /v1/stability/sites/{site}` | Current state, recent transitions and ordered events. |
| `GET /v1/stability/transitions` | State transition table and priorities. |
| `GET /v1/stability/conflicts` | Recent conflict-resolution records. |
| `GET /v1/stability/locks` | Recent lock audit entries. |
