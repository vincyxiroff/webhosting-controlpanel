# ControlPanel OS

ControlPanel OS is an API-first, multi-tenant hosting control plane for VPS and cloud providers. It combines a Laravel control API, a Next.js dashboard, a lightweight Go node agent, PostgreSQL, Redis, NGINX, PowerDNS, mail services, Docker-based site isolation, billing automation, and real-time operations.

This repository is a production-oriented foundation, not a toy mockup. It defines the system boundaries, database model, API contract, orchestration services, node-agent protocol, deployment topology, and initial operator workflows needed to build the full platform safely.

## Workspace

- `apps/api` - Laravel 12-ready PHP 8.4 backend modules.
- `apps/web` - Next.js App Router dashboard using TypeScript, Tailwind, TanStack Query, Zustand, and WebSockets.
- `apps/agent` - Go node agent for metrics, lifecycle operations, log streaming, and mTLS control-plane communication.
- `packages/contracts` - OpenAPI contract and shared event names.
- `packages/cli` - CLI command map for operators and automation.
- `infra` - Docker Compose, NGINX, PostgreSQL, PowerDNS, mail, supervisor, and systemd deployment assets.
- `docs` - Architecture, security, deployment, and module runbooks.
- `docs/enterprise-finish.md` - Scheduler, HA, storage, metering, edge, and security finish layers.
- `docs/agent-control-plane-contract.md` - Agent authentication, heartbeat, command lifecycle, reconciliation, and failure handling contract.
- `docs/execution-engine.md` - Docker runtime, NGINX automation, lifecycle execution, monitoring, recovery, and performance design.
- `docs/global-consistency-engine.md` - Kubernetes-style desired/actual state reconciliation loop and drift recovery design.
- `docs/billing-enforcement-engine.md` - Real-time metering, FOSSBilling event pipeline, enforcement and anti-abuse design.
- `docs/final-stability-layer.md` - Canonical state machine, Redis locks, ordered tenant events, priorities, and conflict handling.
- `docs/operation-journal.md` - Lightweight event sourcing, operation timelines, snapshots, rollback causation, and audit model.
- `docs/vps-linux-installation.md` - Complete VPS Linux install, node-agent setup, verification, upgrade, and uninstall guide.
- `docs/nodejs-hosting.md` - Node.js hosting model, app port, install/build/start commands, and NGINX routing.
- `infra/nginx/vhost-templates` - Local NGINX vhost presets adapted from common CloudPanel-style hosting templates.

## Local Start

The Docker runtime targets PHP 8.4 and PostgreSQL 16. Your host PHP may be older; use the containers for parity.

```bash
docker compose -f infra/docker-compose.yml up --build
```

Services:

- Dashboard: `http://localhost:3000`
- API: `http://localhost:8080`
- Reverb/WebSocket: `ws://localhost:8081`
- PostgreSQL: `localhost:5432`
- Redis: `localhost:6379`

## VPS Linux Install

For a guided install on a Linux VPS:

```bash
chmod +x scripts/install.sh scripts/uninstall.sh
sudo ./scripts/install.sh --guided
docker compose -p controlpanel -f infra/docker-compose.yml exec api php artisan migrate --force
```

If the guided URL is `https://panel.example.com`, the installer can configure host NGINX and Let's Encrypt automatically. Use a DNS name already pointing to the VPS; raw IP addresses cannot receive Let's Encrypt certificates.

If the public IP/URL was entered wrong:

```bash
sudo ./scripts/set-public-url.sh http://SERVER_IP:8080
docker compose -p controlpanel -f infra/docker-compose.yml --env-file .env up -d --build
```

For a node VPS:

```bash
sudo ./scripts/install.sh --with-agent --skip-start --yes
sudo nano /etc/controlpanel/agent.yaml
sudo systemctl enable --now controlpanel-agent
```

Read the full guide: `docs/vps-linux-installation.md`.

## FOSSBilling Server Manager

Copy the manager into your FOSSBilling installation:

```bash
sudo cp integrations/fossbilling/Server/Manager/ControlPanelOS.php /path/to/fossbilling/src/library/Server/Manager/ControlPanelOS.php
```

Then create a FOSSBilling server with:

- Manager: `ControlPanelOS`
- Host: your panel host or VPS IP
- Port: `8080` unless the API is behind HTTPS on `443`
- Secure: enabled only when using HTTPS
- API key: value of `FOSSBILLING_SERVER_API_KEY` from `.env`

The manager implements the current `Server_Manager` contract: create, suspend, unsuspend, cancel, synchronize, change password, and change package.

## Installer

Linux hosts can use the included installer:

```bash
chmod +x scripts/install.sh scripts/uninstall.sh
./scripts/install.sh --yes
```

Guided Linux install:

```bash
./scripts/install.sh --guided
```

Optional node-agent install:

```bash
sudo ./scripts/install.sh --with-agent --yes
```

Uninstall while keeping database/cache volumes:

```bash
./scripts/uninstall.sh --yes
```

Guided Linux uninstall:

```bash
./scripts/uninstall.sh --guided
```

Uninstall and delete Docker volumes:

```bash
./scripts/uninstall.sh --destroy-data --confirm-destroy --yes
```

Windows or Docker Desktop development:

```powershell
powershell -ExecutionPolicy Bypass -File scripts\install.ps1 -Yes
powershell -ExecutionPolicy Bypass -File scripts\uninstall.ps1 -Yes
```

Guided Windows install/uninstall:

```powershell
powershell -ExecutionPolicy Bypass -File scripts\install.ps1 -Guided
powershell -ExecutionPolicy Bypass -File scripts\uninstall.ps1 -Guided
```

## Production Principles

- Every mutating action is audited.
- Tenant boundaries are explicit in API, database, queue, filesystem, and runtime layers.
- Nodes never trust dashboard clients directly; all node operations are signed control-plane commands over mTLS.
- Site placement, migration, deployment, SSL, DNS, email, and billing changes are modeled as jobs with idempotency keys.
- Site state changes are serialized through Redis locks and a canonical priority-based state machine.
- Every meaningful operation is recorded in an append-only Operation Journal for audit, debugging, reconstruction and rollback planning.
- The marketplace is declarative and lifecycle-driven, not arbitrary shell execution.
