export async function api<T>(path: string, init?: RequestInit): Promise<T> {
  const baseUrl = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8080/v1";
  const normalizedPath = path.startsWith("/") ? path : `/${path}`;
  const token = typeof window !== "undefined" ? window.localStorage.getItem("controlpanel_access_token") : null;
  const response = await fetch(`${baseUrl}${normalizedPath}`, {
    ...init,
    cache: "no-store",
    headers: {
      "content-type": "application/json",
      ...(token ? { authorization: `Bearer ${token}` } : {}),
      ...(init?.headers ?? {})
    }
  });

  if (!response.ok) {
    let detail = "";
    try {
      const body = await response.json();
      detail = typeof body?.message === "string" ? `: ${body.message}` : "";
    } catch {
      detail = "";
    }
    throw new Error(`API request failed: ${response.status}${detail}`);
  }

  return response.json() as Promise<T>;
}

export async function apiPost<T>(path: string, body?: unknown): Promise<T> {
  return api<T>(path, {
    method: "POST",
    body: body === undefined ? undefined : JSON.stringify(body)
  });
}

export type DashboardOverview = {
  metrics: {
    online_nodes: number;
    total_nodes: number;
    hosted_sites: number;
    active_sites: number;
    cpu_pressure: number;
    memory_pressure: number;
    open_commands: number;
    failed_commands: number;
    billing_actions: number;
    security_events: number;
  };
  nodes: Array<{
    id: string;
    name: string;
    region: string;
    roles: string[];
    status: string;
    health_status: string;
    last_heartbeat_at: string | null;
    metrics: Record<string, unknown>;
  }>;
  sites: Array<{
    id: string;
    name: string;
    primary_domain: string;
    runtime: string;
    runtime_version: string;
    status: string;
    node_id: string | null;
    runtime_config: Record<string, unknown>;
  }>;
};

export type AuthUser = {
  id: string;
  tenant_id: string;
  email: string;
  role: string;
};

export type AuthResponse = {
  access_token: string;
  expires_at: string;
  user: AuthUser;
};

export type SetupStatus = {
  setup_required: boolean;
  setup_token_required: boolean;
};

export type PanelStatus = {
  commands: Array<Record<string, unknown>>;
  drifts: Array<Record<string, unknown>>;
  reconciliation_jobs: Array<Record<string, unknown>>;
  billing_decisions: Array<Record<string, unknown>>;
  deployments: Array<Record<string, unknown>>;
  ssl_orders: Array<Record<string, unknown>>;
  journal: Array<Record<string, unknown>>;
};
