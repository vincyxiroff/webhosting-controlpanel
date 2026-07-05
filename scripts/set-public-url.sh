#!/usr/bin/env bash
set -Eeuo pipefail

ENV_FILE="${CONTROLPANEL_ENV_FILE:-.env}"
PUBLIC_URL="${1:-}"

fail() {
  printf '[controlpanel:url] ERROR: %s\n' "$*" >&2
  exit 1
}

[[ -n "$PUBLIC_URL" ]] || fail "Usage: ./scripts/set-public-url.sh http://SERVER_IP:8080"
[[ -f "$ENV_FILE" ]] || fail "Env file not found: $ENV_FILE"

scheme="$(printf '%s' "$PUBLIC_URL" | sed -nE 's#^([a-zA-Z][a-zA-Z0-9+.-]*)://.*#\1#p')"
host="$(printf '%s' "$PUBLIC_URL" | sed -E 's#^[a-zA-Z][a-zA-Z0-9+.-]*://##; s#/.*$##')"
[[ -n "$scheme" && -n "$host" ]] || fail "Public URL must include scheme, for example http://92.5.152.65:8080"

api_url="${PUBLIC_URL%/}/v1"
if [[ "$scheme" == "https" ]]; then
  ws_url="wss://${host}/app"
else
  ws_url="ws://${host}/app"
fi

set_key() {
  local key="$1"
  local value="$2"
  if grep -q "^${key}=" "$ENV_FILE"; then
    sed -i "s#^${key}=.*#${key}=${value}#" "$ENV_FILE"
  else
    printf '%s=%s\n' "$key" "$value" >>"$ENV_FILE"
  fi
}

set_key APP_URL "$PUBLIC_URL"
set_key NEXT_PUBLIC_API_URL "$api_url"
set_key NEXT_PUBLIC_WS_URL "$ws_url"

printf 'Updated %s\n' "$ENV_FILE"
grep -E '^(APP_URL|NEXT_PUBLIC_API_URL|NEXT_PUBLIC_WS_URL)=' "$ENV_FILE"
