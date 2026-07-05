# VPS Linux Installation Guide

This guide installs ControlPanel OS on a Linux VPS and optionally installs a node agent on the same machine or on separate worker nodes.

## Recommended Layout

Single VPS lab:

```text
VPS
  Docker Compose
    Laravel API
    Next.js dashboard
    PostgreSQL
    Redis
    PowerDNS
  systemd
    controlpanel-agent
  Docker runtime
    tenant site containers
```

Production-style layout:

```text
Control-plane VPS
  API + dashboard + PostgreSQL + Redis + PowerDNS

Node VPS 1..N
  controlpanel-agent
  Docker
  NGINX
  tenant containers
```

## Minimum Requirements

Control-plane VPS:

| Resource | Minimum |
| --- | --- |
| OS | Ubuntu 22.04/24.04 or Debian 12 |
| CPU | 2 vCPU |
| RAM | 4 GB |
| Disk | 40 GB SSD |
| Network | Public IPv4, DNS A record recommended |

Node VPS:

| Resource | Minimum |
| --- | --- |
| OS | Ubuntu 22.04/24.04 or Debian 12 |
| CPU | 2 vCPU |
| RAM | 4 GB |
| Disk | 80 GB SSD |
| Runtime | Docker, NGINX, Go only if building agent on host |

## Ports

Control plane:

| Port | Service |
| ---: | --- |
| `3000/tcp` | Dashboard |
| `8080/tcp` | API |
| `5432/tcp` | PostgreSQL, restrict to private network |
| `6379/tcp` | Redis, restrict to private network |
| `5300/udp` | PowerDNS authoritative DNS in local/dev mapping |
| `8082/tcp` | PowerDNS API, restrict |

Node:

| Port | Service |
| ---: | --- |
| `80/tcp` | HTTP sites |
| `443/tcp` | HTTPS sites |
| outbound `8080/tcp` | Agent to control-plane API |

## 1. Prepare Linux

Run as a sudo user:

```bash
sudo apt update
sudo apt install -y ca-certificates curl gnupg git ufw openssl
```

Install Docker:

```bash
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker "$USER"
```

Log out and back in, then verify:

```bash
docker version
docker compose version
```

If you will build the node agent on the VPS:

```bash
sudo apt install -y golang
```

## 2. Download Project

```bash
git clone <your-repository-url> controlpanel
cd controlpanel
```

If you copied the folder manually, enter that folder instead.

## 3. Guided Control-Plane Install

For an interactive install:

```bash
chmod +x scripts/install.sh scripts/uninstall.sh
./scripts/install.sh --guided
```

Typical answers:

| Prompt | Example |
| --- | --- |
| Docker project name | `controlpanel` |
| Environment file path | `.env` |
| Public API/control-plane URL | `https://panel.example.com` or `http://SERVER_IP:8080` |
| Dashboard port | `3000` |
| API port | `8080` |
| PostgreSQL host port | `5432` |
| Redis host port | `6379` |
| Install node agent service | `no` for control-plane only, `yes` for single-VPS lab |
| Start Docker services | `yes` |

If the public URL starts with `https://`, the guided installer automatically offers SSL setup:

```text
Configure SSL for panel.example.com with Let's Encrypt? [Y/n]
SSL domain [panel.example.com]:
Let's Encrypt email [admin@example.com]:
```

When enabled, the installer:

- installs host NGINX and Certbot if they are missing;
- writes `/etc/nginx/sites-available/controlpanel.conf`;
- proxies `/` to the dashboard container;
- proxies `/v1/` and `/agent/` to the API container;
- requests a Let's Encrypt certificate;
- enables HTTP to HTTPS redirect.

Use a real DNS name pointing to the VPS before enabling SSL. Let's Encrypt will not issue certificates for raw IP addresses such as `http://SERVER_IP:8080`.

Non-interactive install:

```bash
CONTROLPANEL_APP_URL=https://panel.example.com \
CONTROLPANEL_ENABLE_SSL=true \
CONTROLPANEL_SSL_EMAIL=admin@example.com \
CONTROLPANEL_WEB_PORT=3000 \
CONTROLPANEL_API_PORT=8080 \
sudo ./scripts/install.sh --yes
```

Equivalent CLI flags:

```bash
sudo ./scripts/install.sh --yes --enable-ssl --ssl-domain panel.example.com --ssl-email admin@example.com
```

## 4. Start Or Restart Services

```bash
docker compose -p controlpanel -f infra/docker-compose.yml --env-file .env up -d --build
```

If you installed with the wrong URL, do not edit `.env` with plain `sed` if the file was created by `sudo`. Use:

```bash
sudo ./scripts/set-public-url.sh http://92.5.152.65:8080
docker compose -p controlpanel -f infra/docker-compose.yml --env-file .env up -d --build
```

On first login open the dashboard and create the first admin account. If the installer generated a setup token, read it with:

```bash
sudo grep '^CONTROLPANEL_SETUP_TOKEN=' .env
```

For FOSSBilling, use `FOSSBILLING_SERVER_API_KEY` as the server manager API key.

For the dashboard on port `3001`, keep the API URL pointed to the API port:

```bash
sudo ./scripts/set-public-url.sh http://92.5.152.65:8080
```

Then browse:

```text
http://92.5.152.65:3001
```

Check status:

```bash
docker compose -p controlpanel -f infra/docker-compose.yml ps
```

View logs:

```bash
docker compose -p controlpanel -f infra/docker-compose.yml logs -f api
docker compose -p controlpanel -f infra/docker-compose.yml logs -f worker
```

## 5. Run Database Migrations

The installer starts containers. Run migrations inside the API container:

```bash
docker compose -p controlpanel -f infra/docker-compose.yml exec api php artisan migrate --force
```

Optional workspace validation:

```bash
powershell -ExecutionPolicy Bypass -File scripts/validate-workspace.ps1
```

On Linux without PowerShell, manually verify:

```bash
test -f infra/docker-compose.yml
test -f apps/api/routes/api.php
test -f apps/agent/cmd/agent/main.go
```

## 6. Configure Firewall

Single VPS lab:

```bash
sudo ufw allow OpenSSH
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
```

If SSL/reverse proxy is enabled, ports `3000` and `8080` should remain private or firewall-restricted. Production control plane should expose only ports `80` and `443` publicly and keep PostgreSQL, Redis and PowerDNS API private.

## 7. Install Node Agent On A Node VPS

On each node VPS:

```bash
sudo apt update
sudo apt install -y ca-certificates curl gnupg git nginx openssl golang
curl -fsSL https://get.docker.com | sudo sh
```

Copy or clone the project:

```bash
git clone <your-repository-url> controlpanel
cd controlpanel
```

Install the agent service:

```bash
sudo ./scripts/install.sh --with-agent --skip-start --yes
```

This builds:

```text
/usr/local/bin/controlpanel-agent
/etc/systemd/system/controlpanel-agent.service
/etc/controlpanel/agent.yaml
/etc/controlpanel/vhost-templates/*.conf
```

Edit agent config:

```bash
sudo nano /etc/controlpanel/agent.yaml
```

Set:

```yaml
node_id: paste-node-id-from-control-plane
control_plane_url: https://panel.example.com
agent_token: paste-issued-agent-token
fingerprint: paste-client-cert-fingerprint
ca_cert_path: /etc/controlpanel/ca.pem
client_cert_path: /etc/controlpanel/client.pem
client_key_path: /etc/controlpanel/client-key.pem
nginx_config_dir: /etc/nginx/conf.d/controlpanel
nginx_template_dir: /etc/controlpanel/vhost-templates
site_data_dir: /var/lib/controlpanel/sites
```

Create directories:

```bash
sudo mkdir -p /etc/nginx/conf.d/controlpanel /var/lib/controlpanel/sites
sudo chmod 750 /var/lib/controlpanel/sites
```

Start agent:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now controlpanel-agent
sudo systemctl status controlpanel-agent
```

Follow logs:

```bash
sudo journalctl -u controlpanel-agent -f
```

## 8. Register Nodes

Create/register a node through the API/dashboard, then place the issued `node_id`, token and certificate material into `/etc/controlpanel/agent.yaml`.

The agent sends heartbeats and pulls commands from:

```text
POST /agent/v1/heartbeat
POST /agent/v1/command/pull
POST /agent/v1/command/{id}/result
```

## 9. Verify Platform Behavior

Control-plane health:

```bash
curl http://SERVER_IP:8080/v1
```

Docker services:

```bash
docker ps
```

Agent:

```bash
sudo systemctl status controlpanel-agent
sudo journalctl -u controlpanel-agent --since "10 minutes ago"
```

Operation Journal:

```text
GET /v1/operation-journal
GET /v1/operation-journal/sites/{site_id}
POST /v1/operation-journal/sites/{site_id}/snapshot
```

Stability APIs:

```text
GET /v1/stability/sites/{site_id}
GET /v1/stability/conflicts
GET /v1/stability/locks
```

## 10. Upgrade

```bash
cd controlpanel
git pull
docker compose -p controlpanel -f infra/docker-compose.yml --env-file .env up -d --build
docker compose -p controlpanel -f infra/docker-compose.yml exec api php artisan migrate --force
```

Restart node agent after agent changes:

```bash
cd controlpanel
sudo ./scripts/install.sh --with-agent --skip-start --yes
sudo systemctl restart controlpanel-agent
```

## 11. Uninstall

Keep database/cache Docker volumes:

```bash
./scripts/uninstall.sh --guided
```

Non-interactive:

```bash
./scripts/uninstall.sh --yes
```

Destroy Docker volumes too:

```bash
./scripts/uninstall.sh --destroy-data --confirm-destroy --yes
```

Remove agent service:

```bash
sudo systemctl disable --now controlpanel-agent
sudo rm -f /etc/systemd/system/controlpanel-agent.service
sudo rm -f /usr/local/bin/controlpanel-agent
sudo systemctl daemon-reload
```

## Production Notes

- Put API and dashboard behind TLS reverse proxy.
- Restrict PostgreSQL, Redis and PowerDNS API to private network/firewall only.
- Use real mTLS certificate issuance for agents.
- Back up PostgreSQL and Redis append-only data.
- Keep node storage on dedicated disks if hosting tenant workloads.
- Monitor `operation_journal`, `dead_letter_commands`, `conflict_logs` and `distributed_lock_audits`.
