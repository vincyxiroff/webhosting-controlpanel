#!/usr/bin/env bash
set -Eeuo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE_FILE="$ROOT_DIR/infra/docker-compose.yml"
ENV_FILE="$ROOT_DIR/.env"
PROJECT_NAME="${CONTROLPANEL_PROJECT_NAME:-${PROJECT_NAME:-controlpanel}}"
REMOVE_DATA="${CONTROLPANEL_REMOVE_DATA:-false}"
REMOVE_ENV="${CONTROLPANEL_REMOVE_ENV:-false}"
REMOVE_AGENT="${CONTROLPANEL_REMOVE_AGENT:-false}"
AGENT_BIN="${CONTROLPANEL_AGENT_BIN:-/usr/local/bin/controlpanel-agent}"
AGENT_CONFIG_DIR="${CONTROLPANEL_AGENT_CONFIG_DIR:-/etc/controlpanel}"
YES="${CONTROLPANEL_YES:-false}"
GUIDED="${CONTROLPANEL_GUIDED:-false}"

log() {
  printf '[controlpanel:uninstall] %s\n' "$*"
}

fail() {
  printf '[controlpanel:uninstall] ERROR: %s\n' "$*" >&2
  exit 1
}

usage() {
  cat <<USAGE
ControlPanel OS uninstaller

Usage:
  ./scripts/uninstall.sh [options]

Options:
  --project NAME          Docker Compose project name. Default: controlpanel
  --env-file PATH         Path to the environment file. Default: ./.env
  --guided                Run the interactive guided uninstaller
  --destroy-data          Delete Docker volumes. Requires --confirm-destroy
  --confirm-destroy       Confirms destructive volume deletion
  --remove-env            Delete the env file
  --remove-agent          Remove the node agent binary and systemd service
  --remove-agent-config   Also delete /etc/controlpanel or configured agent dir
  -y, --yes               Do not prompt before uninstalling
  -h, --help              Show this help

Environment variables:
  CONTROLPANEL_PROJECT_NAME
  CONTROLPANEL_REMOVE_DATA=true
  CONTROLPANEL_CONFIRM_DESTROY=DESTROY
  CONTROLPANEL_REMOVE_ENV=true
  CONTROLPANEL_REMOVE_AGENT=true
  CONTROLPANEL_REMOVE_AGENT_CONFIG=true
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
      --guided)
        GUIDED="true"
        shift
        ;;
      --destroy-data)
        REMOVE_DATA="true"
        shift
        ;;
      --confirm-destroy)
        CONTROLPANEL_CONFIRM_DESTROY="DESTROY"
        shift
        ;;
      --remove-env)
        REMOVE_ENV="true"
        shift
        ;;
      --remove-agent)
        REMOVE_AGENT="true"
        shift
        ;;
      --remove-agent-config)
        REMOVE_AGENT="true"
        CONTROLPANEL_REMOVE_AGENT_CONFIG="true"
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

run_guided_wizard() {
  [[ "$GUIDED" == "true" ]] || return 0

  printf '\nControlPanel OS guided uninstaller\n'
  printf '%s\n' '----------------------------------'
  prompt_default_into PROJECT_NAME 'Docker project name' "$PROJECT_NAME"
  prompt_default_into ENV_FILE 'Environment file path' "$ENV_FILE"

  if prompt_yes_no 'Delete Docker volumes and hosted data?' "$REMOVE_DATA"; then
    REMOVE_DATA="true"
    CONTROLPANEL_CONFIRM_DESTROY="DESTROY"
  else
    REMOVE_DATA="false"
  fi

  if prompt_yes_no 'Remove env file?' "$REMOVE_ENV"; then
    REMOVE_ENV="true"
  else
    REMOVE_ENV="false"
  fi

  if prompt_yes_no 'Remove node agent?' "$REMOVE_AGENT"; then
    REMOVE_AGENT="true"
  else
    REMOVE_AGENT="false"
  fi

  if [[ "$REMOVE_AGENT" == "true" ]] && prompt_yes_no 'Remove agent config directory too?' "${CONTROLPANEL_REMOVE_AGENT_CONFIG:-false}"; then
    CONTROLPANEL_REMOVE_AGENT_CONFIG="true"
  fi

  printf '\nSummary\n'
  printf '  Project: %s\n' "$PROJECT_NAME"
  printf '  Env file: %s\n' "$ENV_FILE"
  printf '  Delete Docker volumes: %s\n' "$REMOVE_DATA"
  printf '  Remove env file: %s\n' "$REMOVE_ENV"
  printf '  Remove agent: %s\n\n' "$REMOVE_AGENT"
  if prompt_yes_no 'Continue with uninstall?' false; then
    YES="true"
  else
    fail "Uninstall cancelled."
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

confirm_data_removal() {
  [[ "$REMOVE_DATA" == "true" ]] || return 0

  if [[ "${CONTROLPANEL_CONFIRM_DESTROY:-}" != "DESTROY" ]]; then
    fail "Data removal requested. Re-run with CONTROLPANEL_CONFIRM_DESTROY=DESTROY to delete volumes."
  fi
}

confirm_uninstall() {
  [[ "$YES" == "true" ]] && return 0
  if [[ "$REMOVE_DATA" == "true" ]]; then
    printf 'Stop ControlPanel OS project "%s" and DELETE Docker volumes? [y/N] ' "$PROJECT_NAME"
  else
    printf 'Stop ControlPanel OS project "%s" and keep Docker volumes? [y/N] ' "$PROJECT_NAME"
  fi
  read -r answer
  [[ "$answer" == "y" || "$answer" == "Y" ]] || fail "Uninstall cancelled."
}

remove_agent_service() {
  [[ "$REMOVE_AGENT" == "true" ]] || return 0

  if command -v systemctl >/dev/null 2>&1 && [[ -f /etc/systemd/system/controlpanel-agent.service ]]; then
    systemctl stop controlpanel-agent.service >/dev/null 2>&1 || true
    systemctl disable controlpanel-agent.service >/dev/null 2>&1 || true
    rm -f /etc/systemd/system/controlpanel-agent.service
    systemctl daemon-reload
    log "Removed controlpanel-agent.service"
  fi

  rm -f "$AGENT_BIN"
  if [[ "${CONTROLPANEL_REMOVE_AGENT_CONFIG:-false}" == "true" ]]; then
    rm -rf "$AGENT_CONFIG_DIR"
    log "Removed $AGENT_CONFIG_DIR"
  fi
}

main() {
  parse_args "$@"
  run_guided_wizard
  [[ -f "$COMPOSE_FILE" ]] || fail "Compose file not found at $COMPOSE_FILE"
  confirm_data_removal
  confirm_uninstall

  if [[ "$REMOVE_DATA" == "true" ]]; then
    log "Stopping services and deleting Docker volumes"
    docker_compose --env-file "$ENV_FILE" down --remove-orphans --volumes
  else
    log "Stopping services and keeping Docker volumes"
    docker_compose --env-file "$ENV_FILE" down --remove-orphans
  fi

  remove_agent_service

  if [[ "$REMOVE_ENV" == "true" ]]; then
    rm -f "$ENV_FILE"
    log "Removed .env"
  fi

  log "Uninstall complete"
}

main "$@"
