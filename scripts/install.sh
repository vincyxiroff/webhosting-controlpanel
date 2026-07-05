#!/usr/bin/env bash
set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE_FILE="$ROOT_DIR/infra/docker-compose.yml"
ENV_FILE="$ROOT_DIR/.env"
PROJECT_NAME="${CONTROLPANEL_PROJECT_NAME:-${PROJECT_NAME:-controlpanel}}"
INSTALL_AGENT="${CONTROLPANEL_INSTALL_AGENT:-false}"
AGENT_BIN="${CONTROLPANEL_AGENT_BIN:-/usr/local/bin/controlpanel-agent}"
AGENT_CONFIG_DIR="${CONTROLPANEL_AGENT_CONFIG_DIR:-/etc/controlpanel}"
AGENT_TEMPLATE_DIR="${CONTROLPANEL_AGENT_TEMPLATE_DIR:-/etc/controlpanel/vhost-templates}"
YES="${CONTROLPANEL_YES:-false}"
SKIP_START="${CONTROLPANEL_SKIP_START:-false}"
GUIDED="${CONTROLPANEL_GUIDED:-false}"
OVERWRITE_ENV="${CONTROLPANEL_OVERWRITE_ENV:-false}"
FREE_STALE_PORTS="${CONTROLPANEL_FREE_STALE_PORTS:-true}"
AUTO_PORTS="${CONTROLPANEL_AUTO_PORTS:-true}"
APP_URL="${CONTROLPANEL_APP_URL:-http://localhost:8080}"
WEB_PORT="${CONTROLPANEL_WEB_PORT:-3000}"
API_PORT="${CONTROLPANEL_API_PORT:-8080}"
POSTGRES_PORT="${CONTROLPANEL_POSTGRES_PORT:-5432}"
REDIS_PORT="${CONTROLPANEL_REDIS_PORT:-6379}"
POWERDNS_DNS_PORT="${CONTROLPANEL_POWERDNS_DNS_PORT:-5300}"
POWERDNS_API_PORT="${CONTROLPANEL_POWERDNS_API_PORT:-8082}"
ENABLE_SSL="${CONTROLPANEL_ENABLE_SSL:-auto}"
SSL_EMAIL="${CONTROLPANEL_SSL_EMAIL:-}"
SSL_DOMAIN="${CONTROLPANEL_SSL_DOMAIN:-}"
SSL_NGINX_SITE="${CONTROLPANEL_SSL_NGINX_SITE:-controlpanel}"

log() {
  printf '[controlpanel:install] %s\n' "$*"
}

fail() {
  printf '[controlpanel:install] ERROR: %s\n' "$*" >&2
  exit 1
}

require_command() {
  command -v "$1" >/dev/null 2>&1 || fail "Missing required command: $1"
}

usage() {
  cat <<USAGE
ControlPanel OS installer

Usage:
  ./scripts/install.sh [options]

Options:
  --project NAME        Docker Compose project name. Default: controlpanel
  --env-file PATH       Path to the environment file. Default: ./.env
  --with-agent          Build and install the node agent systemd service
  --guided              Run the interactive guided installer
  --enable-ssl          Configure host NGINX + Let's Encrypt for https APP_URL
  --ssl-email EMAIL     Let's Encrypt account email
  --ssl-domain DOMAIN   Override domain derived from APP_URL
  --skip-start          Generate files but do not start Docker services
  --no-free-ports       Do not remove stale Docker containers occupying selected ports
  --no-auto-ports       Fail instead of selecting the next free host port
  -y, --yes             Do not prompt before starting installation
  -h, --help            Show this help

Environment variables:
  CONTROLPANEL_PROJECT_NAME
  CONTROLPANEL_INSTALL_AGENT=true
  CONTROLPANEL_GUIDED=true
  CONTROLPANEL_OVERWRITE_ENV=true
  CONTROLPANEL_APP_URL
  CONTROLPANEL_WEB_PORT
  CONTROLPANEL_API_PORT
  CONTROLPANEL_ENABLE_SSL=true|false|auto
  CONTROLPANEL_SSL_EMAIL
  CONTROLPANEL_SSL_DOMAIN
  CONTROLPANEL_AGENT_BIN
  CONTROLPANEL_AGENT_CONFIG_DIR
  CONTROLPANEL_AGENT_TEMPLATE_DIR
  CONTROLPANEL_SKIP_START=true
  CONTROLPANEL_FREE_STALE_PORTS=false
  CONTROLPANEL_AUTO_PORTS=false
  CONTROLPANEL_YES=true
USAGE
}

parse_args() {
  while [[ $# -gt 0 ]]; do
    case "$1" in
      --project)
        PROJECT_NAME="${2:-}"
        [[ -n "$PROJECT_NAME" ]] || fail "--project requires a value"
        shift 2
        ;;
      --env-file)
        ENV_FILE="${2:-}"
        [[ -n "$ENV_FILE" ]] || fail "--env-file requires a value"
        shift 2
        ;;
      --with-agent)
        INSTALL_AGENT="true"
        shift
        ;;
      --guided)
        GUIDED="true"
        shift
        ;;
      --enable-ssl)
        ENABLE_SSL="true"
        shift
        ;;
      --ssl-email)
        SSL_EMAIL="${2:-}"
        [[ -n "$SSL_EMAIL" ]] || fail "--ssl-email requires a value"
        shift 2
        ;;
      --ssl-domain)
        SSL_DOMAIN="${2:-}"
        [[ -n "$SSL_DOMAIN" ]] || fail "--ssl-domain requires a value"
        shift 2
        ;;
      --skip-start)
        SKIP_START="true"
        shift
        ;;
      --no-free-ports)
        FREE_STALE_PORTS="false"
        shift
        ;;
      --no-auto-ports)
        AUTO_PORTS="false"
        shift
        ;;
      -y|--yes)
        YES="true"
        shift
        ;;
      -h|--help)
        usage
        exit 0
        ;;
      *)
        fail "Unknown option: $1"
        ;;
    esac
  done
}

prompt_default() {
  local label="$1"
  local default="$2"
  local answer

  printf '%s [%s]: ' "$label" "$default" >&2

  if ! IFS= read -r answer; then
    printf '%s' "$default"
    return
  fi

  printf '%s' "${answer:-$default}"
}

prompt_default_into() {
  local __var_name="$1"
  local label="$2"
  local default="$3"
  local answer

  printf '%s [%s]: ' "$label" "$default"

  if ! IFS= read -r answer; then
    answer="$default"
  fi

  printf -v "$__var_name" '%s' "${answer:-$default}"
}

prompt_yes_no() {
  local label="$1"
  local default="$2"
  local answer
  local hint="[y/N]"
  [[ "$default" == "true" ]] && hint="[Y/n]"
  printf '%s %s ' "$label" "$hint" >&2

  if ! IFS= read -r answer; then
    [[ "$default" == "true" ]]
    return
  fi

  answer="${answer:-$([[ "$default" == "true" ]] && printf y || printf n)}"

  [[ "$answer" =~ ^[Yy]$ ]]
}

url_scheme() {
  printf '%s' "$1" | sed -nE 's#^([a-zA-Z][a-zA-Z0-9+.-]*)://.*#\1#p'
}

url_host() {
  printf '%s' "$1" | sed -E 's#^[a-zA-Z][a-zA-Z0-9+.-]*://##; s#/.*$##; s#:[0-9]+$##'
}

is_public_dns_name() {
  local host="$1"
  [[ -n "$host" ]] || return 1
  [[ "$host" != "localhost" ]] || return 1
  [[ "$host" != "127.0.0.1" ]] || return 1
  [[ "$host" != "::1" ]] || return 1
  [[ "$host" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]] && return 1
  [[ "$host" == *.* ]]
}

resolve_ssl_defaults() {
  if [[ -z "$SSL_DOMAIN" ]]; then
    SSL_DOMAIN="$(url_host "$APP_URL")"
  fi

  if [[ "$ENABLE_SSL" == "auto" ]]; then
    if [[ "$(url_scheme "$APP_URL")" == "https" && -n "$SSL_DOMAIN" ]]; then
      ENABLE_SSL="true"
    else
      ENABLE_SSL="false"
    fi
  fi
}

run_guided_wizard() {
  [[ "$GUIDED" == "true" ]] || return 0

  printf '\nControlPanel OS guided installer\n'
  printf '%s\n' '--------------------------------'
  prompt_default_into PROJECT_NAME 'Docker project name' "$PROJECT_NAME"
  prompt_default_into ENV_FILE 'Environment file path' "$ENV_FILE"
  if [[ -f "$ENV_FILE" ]] && prompt_yes_no 'Env file exists. Regenerate it?' false; then
    OVERWRITE_ENV="true"
  fi
  prompt_default_into APP_URL 'Public API/control-plane URL' "$APP_URL"
  prompt_default_into WEB_PORT 'Dashboard port' "$WEB_PORT"
  prompt_default_into API_PORT 'API port' "$API_PORT"
  prompt_default_into POSTGRES_PORT 'PostgreSQL host port' "$POSTGRES_PORT"
  prompt_default_into REDIS_PORT 'Redis host port' "$REDIS_PORT"
  prompt_default_into POWERDNS_DNS_PORT 'PowerDNS DNS host port' "$POWERDNS_DNS_PORT"
  prompt_default_into POWERDNS_API_PORT 'PowerDNS API host port' "$POWERDNS_API_PORT"
  resolve_ssl_defaults

  if [[ "$(url_scheme "$APP_URL")" == "https" ]]; then
    if prompt_yes_no "Configure SSL for $(url_host "$APP_URL") with Let's Encrypt?" "$ENABLE_SSL"; then
      ENABLE_SSL="true"
      prompt_default_into SSL_DOMAIN 'SSL domain' "${SSL_DOMAIN:-$(url_host "$APP_URL")}"
      prompt_default_into SSL_EMAIL 'Let'\''s Encrypt email' "${SSL_EMAIL:-admin@${SSL_DOMAIN#www.}}"
    else
      ENABLE_SSL="false"
    fi
  fi

  if prompt_yes_no 'Install node agent service?' "$INSTALL_AGENT"; then
    INSTALL_AGENT="true"
  else
    INSTALL_AGENT="false"
  fi

  if prompt_yes_no 'Start Docker services after setup?' "$([[ "$SKIP_START" == "true" ]] && printf false || printf true)"; then
    SKIP_START="false"
  else
    SKIP_START="true"
  fi

  auto_assign_ports

  printf '\nSummary\n'
  printf '  Project: %s\n' "$PROJECT_NAME"
  printf '  Env file: %s\n' "$ENV_FILE"
  printf '  Dashboard: http://localhost:%s\n' "$WEB_PORT"
  printf '  API: http://localhost:%s/v1\n' "$API_PORT"
  printf '  Public URL: %s\n' "$APP_URL"
  printf '  SSL: %s\n' "$ENABLE_SSL"
  if [[ "$ENABLE_SSL" == "true" ]]; then
    printf '  SSL domain: %s\n' "$SSL_DOMAIN"
  fi
  printf '  Agent: %s\n' "$INSTALL_AGENT"
  printf '  Start services: %s\n\n' "$([[ "$SKIP_START" == "true" ]] && printf false || printf true)"
  if prompt_yes_no 'Continue with installation?' true; then
    YES="true"
  else
    fail "Installation cancelled."
  fi
}

docker_compose() {
  if docker compose version >/dev/null 2>&1; then
    docker compose -p "$PROJECT_NAME" -f "$COMPOSE_FILE" "$@"
  elif command -v docker-compose >/dev/null 2>&1; then
    docker-compose -p "$PROJECT_NAME" -f "$COMPOSE_FILE" "$@"
  else
    fail "Docker Compose is required."
  fi
}

container_ids_for_port() {
  local port="$1"
  docker ps -a --format '{{.ID}} {{.Ports}}' \
    | awk -v port="$port" '$0 ~ "(0\\.0\\.0\\.0|127\\.0\\.0\\.1|::):" port "->" {print $1}'
}

is_controlpanel_container() {
  local id="$1"
  local labels name image
  labels="$(docker inspect -f '{{json .Config.Labels}}' "$id" 2>/dev/null || true)"
  name="$(docker inspect -f '{{.Name}}' "$id" 2>/dev/null | sed 's#^/##' || true)"
  image="$(docker inspect -f '{{.Config.Image}}' "$id" 2>/dev/null || true)"

  [[ "$labels" == *'com.docker.compose.project'* ]] || return 1
  [[ "$labels$name$image" == *controlpanel* || "$labels$name$image" == *panel* ]]
}

free_stale_port_containers() {
  [[ "$FREE_STALE_PORTS" == "true" ]] || return 0

  local port id
  for port in "$WEB_PORT" "$API_PORT" "$POSTGRES_PORT" "$REDIS_PORT" "$POWERDNS_API_PORT"; do
    [[ -n "$port" ]] || continue
    while IFS= read -r id; do
      [[ -n "$id" ]] || continue
      if is_controlpanel_container "$id"; then
        log "Removing stale ControlPanel Docker container $id on port $port"
        docker rm -f "$id" >/dev/null 2>&1 || true
      fi
    done < <(container_ids_for_port "$port")
  done
}

host_port_in_use() {
  local port="$1"
  if command -v ss >/dev/null 2>&1; then
    ss -ltn "( sport = :$port )" | tail -n +2 | grep -q .
    return
  fi
  if command -v lsof >/dev/null 2>&1; then
    lsof -iTCP:"$port" -sTCP:LISTEN -Pn >/dev/null 2>&1
    return
  fi
  return 1
}

port_in_use() {
  local port="$1"
  [[ -n "$(container_ids_for_port "$port" | head -n 1)" ]] && return 0
  host_port_in_use "$port"
}

next_free_port() {
  local port="$1"
  while port_in_use "$port"; do
    port=$((port + 1))
  done
  printf '%s' "$port"
}

auto_assign_port_var() {
  local var_name="$1"
  local label="$2"
  local current
  local next
  current="${!var_name}"
  [[ -n "$current" ]] || return 0
  if ! port_in_use "$current"; then
    return 0
  fi
  if [[ "$AUTO_PORTS" != "true" ]]; then
    fail "$label port $current is already in use. Choose another port or enable automatic port assignment."
  fi
  next="$(next_free_port "$((current + 1))")"
  log "$label port $current is busy; using $next"
  printf -v "$var_name" '%s' "$next"
}

auto_assign_ports() {
  free_stale_port_containers
  auto_assign_port_var WEB_PORT "Dashboard"
  auto_assign_port_var API_PORT "API"
  auto_assign_port_var POSTGRES_PORT "PostgreSQL"
  auto_assign_port_var REDIS_PORT "Redis"
  auto_assign_port_var POWERDNS_API_PORT "PowerDNS API"
}

assert_ports_available() {
  local port ids
  for port in "$WEB_PORT" "$API_PORT" "$POSTGRES_PORT" "$REDIS_PORT" "$POWERDNS_API_PORT"; do
    [[ -n "$port" ]] || continue
    ids="$(container_ids_for_port "$port" | tr '\n' ' ')"
    if [[ -n "$ids" ]]; then
      fail "Port $port is still used by Docker container(s): $ids. Run scripts/uninstall.sh --project $PROJECT_NAME --yes or rerun install with stale port cleanup enabled."
    fi
    if host_port_in_use "$port"; then
      fail "Port $port is already in use by a host process. Stop that process or choose another port."
    fi
  done
}

confirm_install() {
  [[ "$YES" == "true" ]] && return 0
  printf 'Install ControlPanel OS project "%s" using %s? [y/N] ' "$PROJECT_NAME" "$COMPOSE_FILE"
  read -r answer
  [[ "$answer" == "y" || "$answer" == "Y" ]] || fail "Installation cancelled."
}

generate_secret() {
  if command -v openssl >/dev/null 2>&1; then
    openssl rand -base64 32
  else
    date +%s%N | sha256sum | awk '{print $1}'
  fi
}

write_env() {
  if [[ -f "$ENV_FILE" && "$OVERWRITE_ENV" != "true" ]]; then
    log "Keeping existing env file: $ENV_FILE"
    return
  fi

  local app_key
  local db_password
  local pdns_key
  local fossbilling_secret
  local fossbilling_server_key
  local setup_token
  app_key="base64:$(generate_secret)"
  db_password="$(generate_secret | tr -dc 'A-Za-z0-9' | head -c 32)"
  pdns_key="$(generate_secret | tr -dc 'A-Za-z0-9' | head -c 32)"
  fossbilling_secret="$(generate_secret | tr -dc 'A-Za-z0-9' | head -c 48)"
  fossbilling_server_key="$(generate_secret | tr -dc 'A-Za-z0-9' | head -c 48)"
  setup_token="$(generate_secret | tr -dc 'A-Za-z0-9' | head -c 32)"
  local next_api_url
  local next_ws_url
  next_api_url="${APP_URL%/}/v1"
  if [[ "$(url_scheme "$APP_URL")" == "https" ]]; then
    next_ws_url="wss://$(url_host "$APP_URL")/app"
  else
    next_ws_url="ws://$(url_host "$APP_URL")/app"
  fi

  umask 077
  mkdir -p "$(dirname "$ENV_FILE")"
  cat >"$ENV_FILE" <<ENV
APP_ENV=production
APP_KEY=$app_key
APP_URL=$APP_URL

DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=controlpanel
DB_USERNAME=controlpanel
DB_PASSWORD=$db_password

REDIS_HOST=redis
QUEUE_CONNECTION=redis
BROADCAST_CONNECTION=reverb

POSTGRES_DB=controlpanel
POSTGRES_USER=controlpanel
POSTGRES_PASSWORD=$db_password

PDNS_AUTH_API_KEY=$pdns_key
FOSSBILLING_WEBHOOK_SECRET=$fossbilling_secret
FOSSBILLING_SERVER_API_KEY=$fossbilling_server_key
CONTROLPANEL_SETUP_TOKEN=$setup_token

NEXT_PUBLIC_API_URL=$next_api_url
NEXT_PUBLIC_WS_URL=$next_ws_url

WEB_PORT=$WEB_PORT
API_PORT=$API_PORT
POSTGRES_PORT=$POSTGRES_PORT
REDIS_PORT=$REDIS_PORT
POWERDNS_DNS_PORT=$POWERDNS_DNS_PORT
POWERDNS_API_PORT=$POWERDNS_API_PORT
CONTROLPANEL_ENABLE_SSL=$ENABLE_SSL
CONTROLPANEL_SSL_DOMAIN=$SSL_DOMAIN
ENV
  if [[ -n "${SUDO_USER:-}" && "${SUDO_USER:-}" != "root" ]] && command -v chown >/dev/null 2>&1; then
    chown "$SUDO_USER":"$SUDO_USER" "$ENV_FILE" >/dev/null 2>&1 || true
  fi
  chmod 0640 "$ENV_FILE" >/dev/null 2>&1 || true
  log "Created .env with generated secrets"
}

check_platform() {
  [[ -f "$COMPOSE_FILE" ]] || fail "Compose file not found at $COMPOSE_FILE"
  require_command docker

  resolve_ssl_defaults

  if [[ "$INSTALL_AGENT" == "true" && "$(id -u)" -ne 0 ]]; then
    fail "--with-agent must be run as root because it writes $AGENT_BIN and systemd files."
  fi
  if [[ "$ENABLE_SSL" == "true" && "$(id -u)" -ne 0 ]]; then
    fail "SSL setup must be run as root because it installs/configures NGINX and Certbot."
  fi
  if [[ "$ENABLE_SSL" == "true" ]] && ! is_public_dns_name "$SSL_DOMAIN"; then
    fail "Let's Encrypt requires a public DNS name. Current SSL domain: $SSL_DOMAIN"
  fi

  if ! docker info >/dev/null 2>&1; then
    fail "Docker is not running or this user cannot access it."
  fi
}

install_package_if_possible() {
  local package="$1"
  if command -v apt-get >/dev/null 2>&1; then
    DEBIAN_FRONTEND=noninteractive apt-get update
    DEBIAN_FRONTEND=noninteractive apt-get install -y "$package"
  elif command -v dnf >/dev/null 2>&1; then
    dnf install -y "$package"
  elif command -v yum >/dev/null 2>&1; then
    yum install -y "$package"
  else
    fail "Cannot install $package automatically. Install it manually and rerun."
  fi
}

ensure_command_or_install() {
  local command_name="$1"
  local package_name="$2"
  if ! command -v "$command_name" >/dev/null 2>&1; then
    log "Installing $package_name"
    install_package_if_possible "$package_name"
  fi
}

configure_ssl_proxy() {
  [[ "$ENABLE_SSL" == "true" ]] || return 0

  ensure_command_or_install nginx nginx
  ensure_command_or_install certbot certbot
  if ! certbot plugins 2>/dev/null | grep -q 'nginx'; then
    log "Installing certbot NGINX plugin"
    install_package_if_possible python3-certbot-nginx
  fi

  mkdir -p /etc/nginx/sites-available /etc/nginx/sites-enabled
  cat >"/etc/nginx/sites-available/$SSL_NGINX_SITE.conf" <<NGINX
server {
    listen 80;
    server_name $SSL_DOMAIN;

    client_max_body_size 128m;

    location /agent/ {
        proxy_pass http://127.0.0.1:$API_PORT;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
    }

    location /v1/ {
        proxy_pass http://127.0.0.1:$API_PORT;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
    }

    location / {
        proxy_pass http://127.0.0.1:$WEB_PORT;
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
    }
}
NGINX

  ln -sf "/etc/nginx/sites-available/$SSL_NGINX_SITE.conf" "/etc/nginx/sites-enabled/$SSL_NGINX_SITE.conf"
  nginx -t
  systemctl enable nginx >/dev/null 2>&1 || true
  systemctl restart nginx

  local certbot_email_args=(--register-unsafely-without-email)
  if [[ -n "$SSL_EMAIL" ]]; then
    certbot_email_args=(-m "$SSL_EMAIL")
  fi

  log "Requesting Let's Encrypt certificate for $SSL_DOMAIN"
  certbot --nginx -d "$SSL_DOMAIN" "${certbot_email_args[@]}" --agree-tos --redirect --non-interactive
  systemctl reload nginx || systemctl restart nginx
  log "SSL enabled for https://$SSL_DOMAIN"
}

install_agent_service() {
  [[ "$INSTALL_AGENT" == "true" ]] || return 0
  require_command go

  log "Building node agent"
  (cd "$ROOT_DIR/apps/agent" && go build -o "$AGENT_BIN" ./cmd/agent)

  if command -v systemctl >/dev/null 2>&1; then
    install -d -m 0750 "$AGENT_CONFIG_DIR"
    install -d -m 0755 "$AGENT_TEMPLATE_DIR"
    cp -f "$ROOT_DIR"/infra/nginx/vhost-templates/*.conf "$AGENT_TEMPLATE_DIR"/
    if [[ ! -f "$AGENT_CONFIG_DIR/agent.yaml" ]]; then
      cat >"$AGENT_CONFIG_DIR/agent.yaml" <<YAML
node_id: replace-with-node-id
control_plane_url: https://api.controlpanel.local
agent_token: paste-issued-agent-token
fingerprint: paste-client-cert-fingerprint
ca_cert_path: /etc/controlpanel/ca.pem
client_cert_path: /etc/controlpanel/client.pem
client_key_path: /etc/controlpanel/client-key.pem
nginx_config_dir: /etc/nginx/conf.d/controlpanel
nginx_template_dir: $AGENT_TEMPLATE_DIR
site_data_dir: /var/lib/controlpanel/sites
YAML
      chmod 0640 "$AGENT_CONFIG_DIR/agent.yaml"
      log "Created $AGENT_CONFIG_DIR/agent.yaml template"
    fi

    install -m 0644 "$ROOT_DIR/infra/systemd/controlpanel-agent.service" /etc/systemd/system/controlpanel-agent.service
    systemctl daemon-reload
    systemctl enable controlpanel-agent.service
    log "Installed controlpanel-agent.service. Fill certificate paths before starting it."
  else
    log "systemd not available; agent binary built at $AGENT_BIN"
  fi
}

main() {
  parse_args "$@"
  run_guided_wizard
  check_platform
  auto_assign_ports
  confirm_install
  write_env

  if [[ "$SKIP_START" == "true" ]]; then
    log "Skipping Docker service start"
  else
    assert_ports_available
    log "Building and starting services"
    docker_compose --env-file "$ENV_FILE" up -d --build
  fi

  configure_ssl_proxy
  install_agent_service

  log "Installation complete"
  if [[ "$ENABLE_SSL" == "true" ]]; then
    log "Dashboard: https://$SSL_DOMAIN"
    log "API: https://$SSL_DOMAIN/v1"
  else
    log "Dashboard: http://localhost:$WEB_PORT"
    log "API: http://localhost:$API_PORT/v1"
  fi
}

main "$@"
