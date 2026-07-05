# Execution Engine

The execution engine turns desired site state into infrastructure objects on a data-plane node.

## Runtime Object Model

```text
Site
  -> Docker network: cp-net-{site_id}
  -> Docker volume: cp-vol-{site_id}
  -> Docker container: cp-site-{site_id}
  -> NGINX config: {site_id}.conf
  -> Runtime object row: site_runtime_objects
```

No site shares a container, network, environment file, or Docker volume with another site.

## Docker Runtime System

The Go agent executes lifecycle commands pulled from the control plane:

- `runtime.provision`: validates Docker and pulls the runtime image.
- `site.create`: creates a per-site Docker volume, network, env file, and container.
- `volume.attach`: ensures the isolated volume exists.
- `nginx.configure`: writes a versioned NGINX config, validates it, reloads safely, rolls back on failure.
- `service.start`: starts the container.
- `health.check`: inspects container and Docker health status.
- `site.suspend`: stops the container.
- `site.restore`: restarts or recreates the container.
- `site.delete`: removes container, NGINX config, volume, and network.
- `logs.tail`: collects recent Docker logs.

Resource controls are passed to Docker with:

- `--cpus`
- `--memory`
- `--pids-limit`
- per-site volume
- per-site network
- per-site `--env-file`
- restart policy `unless-stopped`
- Docker health checks

## Runtime Provisioning

Supported runtime image selection:

- `php`: `php:{version}-fpm-alpine`
- `node`: `node:{version}-alpine`
- `python`: `python:{version}-alpine`
- `go`: `golang:{version}-alpine`
- `static`: `nginx:alpine`
- `docker`: user-provided `image`, fallback `nginx:alpine`

NGINX generation is runtime-aware:

- PHP uses `fastcgi_pass` to the per-site PHP-FPM container.
- Static/Node/Python/Go/Docker use HTTP reverse proxy to the per-site container.

## NGINX Automation

Each config write is versioned in the agent revision directory. Apply flow:

```text
render config
write version file
backup active config
write active config
nginx -t
nginx -s reload
rollback active config if validation or reload fails
```

The command result reports:

- `nginx_config_path`
- `nginx_config_version`

## Site Lifecycle

Create:

```text
Laravel schedules node
Laravel creates desired site row
Laravel queues runtime.provision
Laravel queues site.create
Laravel queues volume.attach
Laravel queues nginx.configure
Laravel queues service.start
Laravel queues health.check
Agent pulls and executes commands idempotently
Agent reports runtime object IDs
Laravel projects IDs into site_runtime_objects
```

Update:

```text
Laravel updates desired config
Laravel queues runtime.provision, nginx.configure, service.start, health.check
Agent validates and applies updated runtime / NGINX state
```

Delete:

```text
Laravel marks terminating
Laravel queues site.suspend, runtime.destroy, site.delete
Agent stops/removes runtime objects and config
```

Suspend:

```text
Laravel marks suspending
Laravel queues site.suspend and maintenance NGINX update
Agent stops workload and blocks traffic path
```

Restore:

```text
Laravel marks restoring
Laravel queues site.restore, nginx.configure, service.start, health.check
Agent restarts/recreates runtime and re-enables route
```

## Monitoring

Heartbeat includes:

- host CPU/RAM/disk/network/load
- Docker container list
- Docker stats rows
- active site list
- runtime support
- node capabilities

Laravel stores heartbeats and upserts actual state for reconciliation.

## Failure Recovery

Command failures:

- Retry with exponential backoff.
- Timeout detection through `timeout_at`.
- Exhausted commands go to `dead_letter_commands`.
- Lifecycle failures queue compensating rollback commands.
- NGINX validation/reload failures restore the previous config.

## Performance Considerations

- Pull-based commands avoid inbound node connectivity requirements.
- Per-node command pulls use row locks to avoid duplicate delivery.
- Docker image pull happens in `runtime.provision` before container creation.
- NGINX reload is done only after config validation.
- Heartbeats should stay at 30s in production; container stats are collected with `docker stats --no-stream`.
- Large clusters should shard command polling and heartbeat ingestion by node group.

