# System Architecture

## Control Plane

The control plane owns global state and policy:

- Laravel API and queue workers.
- PostgreSQL global database.
- Redis queues, cache, rate limits, locks, and pub/sub.
- Reverb-compatible WebSocket service.
- Billing integration with FOSSBilling.
- Scheduler for site and database placement.
- NGINX, SSL, DNS, email, deployment, backup, marketplace, and security orchestration.

The control plane is horizontally scalable. API nodes are stateless; sticky sessions are not required because sessions are stored centrally and JWTs carry only short-lived identity claims.

## Data Plane

Each node runs:

- `controlpanel-agent` over mTLS.
- Docker or containerd for per-site isolation.
- Local NGINX and runtime containers for web nodes.
- Optional database, storage, or edge roles.
- Local log shipper and metrics collector.

Node roles:

- `web` - NGINX, app containers, runtime pools.
- `db` - MySQL/MariaDB, PostgreSQL, Redis.
- `storage` - backups, snapshots, file storage.
- `edge` - reverse proxy, cache, WAF, routing.

## Request Flow

1. Dashboard or API client calls the Laravel API.
2. API authorizes the tenant, role, and plan entitlement.
3. API persists desired state and emits a domain event.
4. Queue worker performs orchestration.
5. Node command is signed and sent over mTLS.
6. Agent applies the change idempotently and streams progress.
7. Worker updates status and broadcasts WebSocket events.
8. Audit log records actor, tenant, resource, delta, result, and request metadata.

## Event Bus

Events are persisted in `domain_events` before dispatch so critical workflows can be replayed.

Core event families:

- `tenant.*`
- `plan.*`
- `node.*`
- `site.*`
- `deployment.*`
- `ssl.*`
- `dns.*`
- `mail.*`
- `backup.*`
- `billing.*`
- `security.*`

## Tenant Isolation

Isolation is enforced through:

- Tenant-scoped database rows.
- Per-tenant API keys.
- Per-site Linux users or container identities.
- Per-site containers and networks.
- Per-plan CPU, RAM, IO, inode, bandwidth, mailbox, database, and feature quotas.
- Immutable audit log.
- Signed node commands.

## Scheduler

The scheduler scores candidate nodes with:

- Node health and heartbeat age.
- Role compatibility.
- Reserved plan capacity.
- Current CPU, RAM, disk, IO, and network pressure.
- Tenant/node affinity and anti-affinity.
- Region and cluster preferences.
- Maintenance drain status.

It rejects placement if hard quotas cannot be honored. It returns a deterministic decision object that is auditable and replayable.

## Failure Handling

Node failure is detected by missed heartbeats. The control plane:

1. Marks the node `offline`.
2. Freezes new placement on the node.
3. Opens migration incidents for affected sites.
4. Restores from the latest consistent backup or snapshot.
5. Repoints DNS or edge routing.
6. Emits customer and billing events.

