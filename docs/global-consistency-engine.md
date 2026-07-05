# Global Consistency Engine

## Control Loop Architecture

```text
                   +----------------------+
                   | Laravel Desired DB   |
                   | sites, domains, SSL  |
                   +----------+-----------+
                              |
                              v
                   +----------------------+
                   | DesiredStateProjector|
                   | hash + generation    |
                   +----------+-----------+
                              |
Agent heartbeat               v
+----------------+  +----------------------+  +----------------+
| Go node agent  +->| ActualStateIngestor  +->| actual snapshots|
| Docker/NGINX   |  +----------+-----------+  +----------------+
+----------------+             |
                               v
                   +----------------------+
                   | StateDiffEngine      |
                   | desired vs actual    |
                   +----------+-----------+
                              |
                              v
                   +----------------------+
                   | DriftResolver        |
                   | drift logs + jobs    |
                   +----------+-----------+
                              |
                              v
                   +----------------------+
                   | AgentCommandService  |
                   | idempotent commands  |
                   +----------+-----------+
                              |
                              v
                   +----------------------+
                   | Go agent executes    |
                   | Docker / NGINX       |
                   +----------------------+
```

The database desired state is the source of truth. Agent state is never written back as desired state.

## State Model

- `desired_states`: source-of-truth projection per site with generation and deterministic hashes.
- `actual_state_snapshots`: append-only snapshots from agent heartbeats.
- `drift_logs`: durable record of every detected mismatch.
- `reconciliation_jobs`: idempotent repair jobs created from drift logs.

## Diff Engine

`StateDiffEngine` compares:

- container existence
- container status
- runtime type/version
- container config hash
- NGINX config hash
- domain bindings
- SSL state
- volume presence

Output is a list of deterministic drift records:

```text
missing_container -> runtime.provision, site.create, nginx.configure, service.start, health.check
container_not_running -> service.start
wrong_runtime_config -> runtime.provision, site.create, service.start
container_config_hash_mismatch -> site.create, service.start
nginx_mismatch -> nginx.configure
domain_mismatch -> nginx.configure
ssl_missing -> ssl.order
volume_missing -> volume.attach
```

## Idempotency

Reconciliation job idempotency key:

```text
sha256(site_id + node_id + drift_type + desired_container_hash + desired_nginx_hash)
```

Corrective commands are generated with stable `reconcile:{job}:{command}` keys. Repeated loops therefore converge without duplicate containers or duplicate NGINX configs.

## Failure Recovery

- Failed commands retry with exponential backoff.
- Timed-out commands are marked failed and retried.
- Exhausted commands are copied to `dead_letter_commands`.
- Lifecycle failures enqueue rollback commands.
- Reconciliation jobs stay durable until a later loop observes convergence.
- Stale nodes are marked offline by failover detection; future scheduler/recovery can move sites.

## Worker

Run one pass:

```bash
php artisan controlpanel:reconcile --limit=500
```

In production this command should run every 30-60 seconds through Laravel Scheduler, systemd timer, or Supervisor.

## Safety Rules

- Agent state is used only as observation, never as truth.
- Desired state is projected from authoritative DB tables.
- Destructive actions are only generated for explicit desired terminal states or rollback.
- Extra containers are logged as drift; deletion should require a policy gate before automatic removal.
