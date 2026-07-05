# Agent / Control Plane Contract

## API

All agent endpoints are rooted at `/agent/v1`.

| Method | Path | Caller | Purpose |
| --- | --- | --- | --- |
| POST | `/register` | Agent | Exchange one-time registration token for node identity and agent JWT. |
| POST | `/heartbeat` | Agent | Report metrics, capabilities, running containers, active sites, health. |
| POST | `/command/pull` | Agent | Pull reliable commands for this node. |
| POST | `/command/{id}/result` | Agent | Report `acknowledged`, `running`, `success`, or `failed`. |
| POST | `/site/create` | Agent | Capability endpoint. Real delivery uses command pull. |
| POST | `/site/delete` | Agent | Capability endpoint. Real delivery uses command pull. |
| POST | `/site/suspend` | Agent | Capability endpoint. Real delivery uses command pull. |
| POST | `/site/restore` | Agent | Capability endpoint. Real delivery uses command pull. |
| POST | `/runtime/provision` | Agent | Capability endpoint. Real delivery uses command pull. |
| POST | `/runtime/destroy` | Agent | Capability endpoint. Real delivery uses command pull. |

The reliable execution path is pull-based. Laravel never depends on direct inbound connectivity to a node, which keeps nodes behind NAT/firewalls usable.

## Authentication

The agent authenticates with:

1. mTLS fingerprint binding through `x-client-cert-fingerprint` matched against `nodes.fingerprint`.
2. Fallback bearer JWT issued at registration and stored as a SHA-256 hash in `agent_tokens`.

Every request must include `x-node-id`. JWT claims use `sub = node_id`, `aud = controlpanel-agent`, short expiry, and revocable token hashes.

## Command Lifecycle

Statuses:

```text
CREATED -> SENT -> ACKNOWLEDGED -> RUNNING -> SUCCESS
                                      |
                                      v
                                    FAILED -> retry/backoff -> SENT
                                      |
                                      v
                                dead_letter_commands
```

Each command has:

- `idempotency_key`
- `attempt`
- `max_attempts`
- `available_at`
- `timeout_at`
- `last_error`

Laravel marks commands `sent` when pulled under a row lock. Agents report intermediate and final states.

## Site Create Sequence

```text
User/API -> Laravel: POST /v1/sites
Laravel -> Scheduler: select node
Laravel -> DB: create desired site state
Laravel -> Commands: runtime.provision
Laravel -> Commands: site.create
Laravel -> Commands: volume.attach
Laravel -> Commands: nginx.configure
Laravel -> Commands: service.start
Laravel -> Commands: health.check
Agent -> Laravel: POST /agent/v1/command/pull
Agent -> Docker/FS/NGINX: execute idempotent steps
Agent -> Laravel: POST /agent/v1/command/{id}/result
Laravel -> DB: command state + actual site state from heartbeat
```

## Heartbeat Sequence

```text
Agent -> Docker: inspect containers
Agent -> Host: collect CPU/RAM/disk/network/load
Agent -> Laravel: POST /agent/v1/heartbeat
Laravel -> node_heartbeats: append sample
Laravel -> nodes: update online status and latest metrics
Laravel -> site_actual_states: upsert actual state
Laravel -> Failover: mark stale nodes offline
```

## Reconciliation

Laravel stores desired state in `sites` and actual state in `site_actual_states`.

When drift is detected:

- Missing actual state queues `site.create`.
- Active site with stopped container queues `service.start`.
- Unsynced NGINX queues `nginx.configure`.

## Error Handling

- Pull uses database row locks to prevent duplicate delivery.
- Commands are idempotent by `idempotency_key`.
- Failed commands get exponential backoff through `available_at`.
- Timed out commands are retried until `max_attempts`.
- Exhausted commands are copied to `dead_letter_commands`.
- Agent execution reports ACK, RUNNING, and final status separately.
