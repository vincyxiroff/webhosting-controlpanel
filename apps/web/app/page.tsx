"use client";

import {
  Activity,
  AlertCircle,
  CheckCircle2,
  Database,
  Globe2,
  HardDrive,
  KeyRound,
  Lock,
  LogIn,
  Mail,
  Play,
  Plus,
  RefreshCcw,
  RotateCcw,
  Server,
  ShieldCheck,
  Square,
  Trash2,
  Wrench
} from "lucide-react";
import { useEffect, useMemo, useState } from "react";
import { MetricTile } from "@/components/metric-tile";
import { api, apiPost, type AuthResponse, type AuthUser, type DashboardOverview, type PanelStatus, type SetupStatus } from "@/lib/api";

const emptyOverview: DashboardOverview = {
  metrics: {
    online_nodes: 0,
    total_nodes: 0,
    hosted_sites: 0,
    active_sites: 0,
    cpu_pressure: 0,
    memory_pressure: 0,
    open_commands: 0,
    failed_commands: 0,
    billing_actions: 0,
    security_events: 0
  },
  nodes: [],
  sites: []
};

const emptyStatus: PanelStatus = {
  commands: [],
  drifts: [],
  reconciliation_jobs: [],
  billing_decisions: [],
  deployments: [],
  ssl_orders: [],
  journal: []
};

const tabs = ["Overview", "Sites", "Nodes", "Operations", "Billing", "Security"] as const;
type Tab = (typeof tabs)[number];

type SiteForm = {
  name: string;
  primary_domain: string;
  runtime: string;
  runtime_version: string;
  start_command: string;
  build_command: string;
};

const defaultSiteForm: SiteForm = {
  name: "",
  primary_domain: "",
  runtime: "node",
  runtime_version: "22",
  start_command: "npm run start",
  build_command: "npm install && npm run build"
};

export default function Home() {
  const [setupStatus, setSetupStatus] = useState<SetupStatus | null>(null);
  const [authUser, setAuthUser] = useState<AuthUser | null>(null);
  const [authForm, setAuthForm] = useState({ email: "admin@example.com", password: "", setup_token: "" });
  const [activeTab, setActiveTab] = useState<Tab>("Overview");
  const [overview, setOverview] = useState<DashboardOverview>(emptyOverview);
  const [status, setStatus] = useState<PanelStatus>(emptyStatus);
  const [siteForm, setSiteForm] = useState<SiteForm>(defaultSiteForm);
  const [selectedSiteId, setSelectedSiteId] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  function saveSession(response: AuthResponse) {
    window.localStorage.setItem("controlpanel_access_token", response.access_token);
    setAuthUser(response.user);
  }

  async function checkSession() {
    setLoading(true);
    setError(null);
    try {
      const setup = await api<SetupStatus>("/auth/setup-status");
      setSetupStatus(setup);
      const existingToken = window.localStorage.getItem("controlpanel_access_token");
      if (!setup.setup_required && existingToken) {
        setAuthUser(await api<AuthUser>("/auth/me"));
      }
    } catch (exception) {
      setError(exception instanceof Error ? exception.message : "Unable to check login state.");
    } finally {
      setLoading(false);
    }
  }

  async function submitSetup() {
    setBusy("Create admin");
    setError(null);
    try {
      const response = await apiPost<AuthResponse>("/auth/setup", authForm);
      saveSession(response);
      setSetupStatus({ setup_required: false, setup_token_required: false });
      setNotice("Admin created");
      await loadAll();
    } catch (exception) {
      setError(exception instanceof Error ? exception.message : "Setup failed.");
    } finally {
      setBusy(null);
    }
  }

  async function submitLogin() {
    setBusy("Login");
    setError(null);
    try {
      const response = await apiPost<AuthResponse>("/auth/login", {
        email: authForm.email,
        password: authForm.password
      });
      saveSession(response);
      setNotice("Logged in");
      await loadAll();
    } catch (exception) {
      setError(exception instanceof Error ? exception.message : "Login failed.");
    } finally {
      setBusy(null);
    }
  }

  function logout() {
    window.localStorage.removeItem("controlpanel_access_token");
    setAuthUser(null);
    setOverview(emptyOverview);
    setStatus(emptyStatus);
  }

  async function loadAll() {
    if (!window.localStorage.getItem("controlpanel_access_token")) {
      return;
    }
    setLoading(true);
    setError(null);
    try {
      await api("/panel/bootstrap");
      const [nextOverview, nextStatus] = await Promise.all([
        api<DashboardOverview>("/dashboard/overview"),
        api<PanelStatus>("/panel/status")
      ]);
      setOverview(nextOverview);
      setStatus(nextStatus);
      if (!selectedSiteId && nextOverview.sites[0]) {
        setSelectedSiteId(nextOverview.sites[0].id);
      }
    } catch (exception) {
      setError(exception instanceof Error ? exception.message : "Unable to load panel data.");
    } finally {
      setLoading(false);
    }
  }

  async function runAction(label: string, action: () => Promise<unknown>) {
    setBusy(label);
    setNotice(null);
    setError(null);
    try {
      await action();
      setNotice(`${label} queued`);
      await loadAll();
    } catch (exception) {
      setError(exception instanceof Error ? exception.message : `${label} failed.`);
    } finally {
      setBusy(null);
    }
  }

  async function createSite() {
    const payload = {
      ...siteForm,
      vhost_template: siteForm.runtime === "node" ? "nodejs" : undefined,
      document_root: siteForm.runtime === "static" ? "/app/public" : undefined
    };
    await runAction("Create site", async () => apiPost("/panel/sites", payload));
    setSiteForm(defaultSiteForm);
    setActiveTab("Sites");
  }

  useEffect(() => {
    void checkSession();
  }, []);

  useEffect(() => {
    if (authUser) {
      void loadAll();
    }
  }, [authUser?.id]);

  const selectedSite = overview.sites.find((site) => site.id === selectedSiteId) ?? overview.sites[0] ?? null;
  const nodeNames = new Map(overview.nodes.map((node) => [node.id, node.name]));

  const metrics = useMemo(() => [
    { label: "Online nodes", value: `${overview.metrics.online_nodes}/${overview.metrics.total_nodes}`, trend: "agent heartbeat", icon: Server },
    { label: "Hosted sites", value: String(overview.metrics.hosted_sites), trend: `${overview.metrics.active_sites} active`, icon: Globe2 },
    { label: "CPU pressure", value: `${overview.metrics.cpu_pressure}%`, trend: "cluster average", icon: Activity },
    { label: "Memory pressure", value: `${overview.metrics.memory_pressure}%`, trend: "cluster average", icon: HardDrive },
    { label: "Open commands", value: String(overview.metrics.open_commands), trend: `${overview.metrics.failed_commands} failed`, icon: Database },
    { label: "Billing actions", value: String(overview.metrics.billing_actions), trend: "enforcement queue", icon: Mail },
    { label: "Security events", value: String(overview.metrics.security_events), trend: "conflicts logged", icon: ShieldCheck },
    { label: "API source", value: process.env.NEXT_PUBLIC_API_URL?.replace(/^https?:\/\//, "") ?? "localhost", trend: loading ? "loading" : "live", icon: Lock }
  ], [overview, loading]);

  if (!authUser) {
    const setupRequired = setupStatus?.setup_required ?? false;
    return (
      <main className="grid min-h-screen place-items-center p-5">
        <section className="w-full max-w-md rounded-md border border-border bg-panel p-5">
          <div className="mb-5 flex items-center gap-3">
            <div className="rounded-md border border-border p-2 text-accent">
              {setupRequired ? <KeyRound size={20} /> : <LogIn size={20} />}
            </div>
            <div>
              <h1 className="text-xl font-semibold">{setupRequired ? "Create admin" : "Admin login"}</h1>
              <p className="text-sm text-slate-600">ControlPanel OS protected console.</p>
            </div>
          </div>

          {(error || notice) && (
            <div className={`mb-4 rounded-md border p-3 text-sm ${error ? "border-danger/30 bg-red-50 text-danger" : "border-emerald-200 bg-emerald-50 text-emerald-800"}`}>
              {error ?? notice}
            </div>
          )}

          <div className="grid gap-3">
            <TextInput label="Email" value={authForm.email} onChange={(value) => setAuthForm({ ...authForm, email: value })} placeholder="admin@example.com" />
            <TextInput label="Password" value={authForm.password} onChange={(value) => setAuthForm({ ...authForm, password: value })} type="password" placeholder="at least 12 chars" />
            {setupRequired && setupStatus?.setup_token_required && (
              <TextInput label="Setup token" value={authForm.setup_token} onChange={(value) => setAuthForm({ ...authForm, setup_token: value })} type="password" placeholder="from .env CONTROLPANEL_SETUP_TOKEN" />
            )}
            <button
              disabled={busy !== null || !authForm.email || authForm.password.length < 12}
              onClick={() => void (setupRequired ? submitSetup() : submitLogin())}
              className="inline-flex items-center justify-center gap-2 rounded-md bg-accent px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
            >
              <LogIn size={16} />
              {busy ?? (setupRequired ? "Create admin" : "Login")}
            </button>
          </div>
        </section>
      </main>
    );
  }

  return (
    <main className="min-h-screen">
      <aside className="fixed left-0 top-0 hidden h-screen w-64 border-r border-border bg-panel px-4 py-5 lg:block">
        <div className="text-lg font-semibold">ControlPanel OS</div>
        <div className="mt-2 truncate text-xs text-slate-600">{authUser.email}</div>
        <nav className="mt-8 grid gap-1 text-sm">
          {tabs.map((item) => (
            <button
              key={item}
              onClick={() => setActiveTab(item)}
              className={`rounded-md px-3 py-2 text-left ${activeTab === item ? "bg-accent text-white" : "text-slate-700 hover:bg-slate-100"}`}
            >
              {item}
            </button>
          ))}
        </nav>
      </aside>

      <section className="lg:pl-64">
        <header className="sticky top-0 z-10 border-b border-border bg-panel/95 px-5 py-4 backdrop-blur">
          <div className="flex flex-wrap items-center justify-between gap-4">
            <div>
              <h1 className="text-xl font-semibold">{activeTab}</h1>
              <p className="text-sm text-slate-600">Live control plane connected to {process.env.NEXT_PUBLIC_API_URL ?? "API"}.</p>
            </div>
            <div className="flex flex-wrap gap-2">
              <button onClick={logout} className="inline-flex items-center gap-2 rounded-md border border-border px-3 py-2 text-sm font-medium">
                <Lock size={16} />
                Logout
              </button>
              <button onClick={() => void runAction("Consistency", async () => apiPost("/panel/consistency/run"))} className="inline-flex items-center gap-2 rounded-md border border-border px-3 py-2 text-sm font-medium">
                <Wrench size={16} />
                Reconcile
              </button>
              <button onClick={() => void loadAll()} className="inline-flex items-center gap-2 rounded-md bg-accent px-3 py-2 text-sm font-medium text-white">
                <RefreshCcw size={16} />
                Refresh
              </button>
            </div>
          </div>
          <div className="mt-4 flex gap-2 overflow-x-auto lg:hidden">
            {tabs.map((item) => (
              <button key={item} onClick={() => setActiveTab(item)} className={`shrink-0 rounded-md px-3 py-2 text-sm ${activeTab === item ? "bg-accent text-white" : "border border-border bg-white"}`}>
                {item}
              </button>
            ))}
          </div>
        </header>

        <div className="grid gap-5 p-5">
          {(error || notice || busy) && (
            <section className={`rounded-md border p-4 text-sm ${error ? "border-danger/30 bg-red-50 text-danger" : "border-emerald-200 bg-emerald-50 text-emerald-800"}`}>
              <div className="flex items-start gap-3">
                {error ? <AlertCircle className="mt-0.5 shrink-0" size={18} /> : <CheckCircle2 className="mt-0.5 shrink-0" size={18} />}
                <div>
                  <p className="font-medium">{error ? "Action failed" : busy ? `${busy} running` : notice}</p>
                  {error && <p className="mt-1">{error}</p>}
                </div>
              </div>
            </section>
          )}

          {activeTab === "Overview" && (
            <>
              <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                {metrics.map((metric) => <MetricTile key={metric.label} {...metric} />)}
              </section>
              <section className="grid gap-5 xl:grid-cols-[1.2fr_0.8fr]">
                <PanelCard title="Command Queue">
                  <RecordList records={status.commands} primary="command" secondary="status" empty="No commands queued." />
                </PanelCard>
                <PanelCard title="Operation Journal">
                  <RecordList records={status.journal} primary="operation_name" secondary="category" empty="No operations recorded." />
                </PanelCard>
              </section>
            </>
          )}

          {activeTab === "Sites" && (
            <section className="grid gap-5 xl:grid-cols-[0.9fr_1.1fr]">
              <PanelCard title="Create Site">
                <div className="grid gap-3">
                  <TextInput label="Name" value={siteForm.name} onChange={(value) => setSiteForm({ ...siteForm, name: value })} placeholder="my-app" />
                  <TextInput label="Domain" value={siteForm.primary_domain} onChange={(value) => setSiteForm({ ...siteForm, primary_domain: value })} placeholder="app.example.com" />
                  <label className="grid gap-1 text-sm">
                    <span className="text-slate-600">Runtime</span>
                    <select value={siteForm.runtime} onChange={(event) => setSiteForm({ ...siteForm, runtime: event.target.value, runtime_version: runtimeVersion(event.target.value) })} className="rounded-md border border-border bg-white px-3 py-2">
                      {["node", "php", "python", "static", "docker"].map((runtime) => <option key={runtime}>{runtime}</option>)}
                    </select>
                  </label>
                  <TextInput label="Version" value={siteForm.runtime_version} onChange={(value) => setSiteForm({ ...siteForm, runtime_version: value })} />
                  <TextInput label="Build command" value={siteForm.build_command} onChange={(value) => setSiteForm({ ...siteForm, build_command: value })} />
                  <TextInput label="Start command" value={siteForm.start_command} onChange={(value) => setSiteForm({ ...siteForm, start_command: value })} />
                  <button disabled={!siteForm.name || !siteForm.primary_domain || busy !== null} onClick={() => void createSite()} className="inline-flex items-center justify-center gap-2 rounded-md bg-accent px-4 py-2 text-sm font-medium text-white disabled:opacity-50">
                    <Plus size={16} />
                    Create site
                  </button>
                </div>
              </PanelCard>

              <PanelCard title="Sites">
                <div className="grid gap-3">
                  {overview.sites.map((site) => (
                    <button key={site.id} onClick={() => setSelectedSiteId(site.id)} className={`rounded-md border p-3 text-left ${selectedSite?.id === site.id ? "border-accent bg-blue-50" : "border-border bg-white"}`}>
                      <div className="flex items-start justify-between gap-3">
                        <div>
                          <p className="font-medium">{site.primary_domain}</p>
                          <p className="mt-1 text-sm text-slate-600">{site.runtime} {site.runtime_version} on {site.node_id ? nodeNames.get(site.node_id) ?? site.node_id : "unassigned"}</p>
                        </div>
                        <span className="rounded-full bg-slate-100 px-2 py-1 text-xs">{site.status}</span>
                      </div>
                    </button>
                  ))}
                  {overview.sites.length === 0 && <EmptyState text="No sites created yet." />}
                </div>

                {selectedSite && (
                  <div className="mt-4 flex flex-wrap gap-2 border-t border-border pt-4">
                    <ActionButton label="Deploy" icon={Play} onClick={() => runAction("Deploy", async () => apiPost(`/panel/sites/${selectedSite.id}/deploy`))} />
                    <ActionButton label="SSL" icon={Lock} onClick={() => runAction("SSL order", async () => apiPost(`/panel/sites/${selectedSite.id}/ssl`))} />
                    <ActionButton label="Suspend" icon={Square} onClick={() => runAction("Suspend", async () => apiPost(`/panel/sites/${selectedSite.id}/suspend`))} />
                    <ActionButton label="Restore" icon={RotateCcw} onClick={() => runAction("Restore", async () => apiPost(`/panel/sites/${selectedSite.id}/restore`))} />
                    <ActionButton label="Delete" icon={Trash2} danger onClick={() => runAction("Delete", async () => apiPost(`/panel/sites/${selectedSite.id}/delete`))} />
                  </div>
                )}
              </PanelCard>
            </section>
          )}

          {activeTab === "Nodes" && (
            <PanelCard title="Cluster Nodes">
              <div className="overflow-x-auto">
                <table className="w-full min-w-[720px] text-left text-sm">
                  <thead className="bg-slate-50 text-slate-600">
                    <tr>
                      <th className="px-4 py-3 font-medium">Node</th>
                      <th className="px-4 py-3 font-medium">Region</th>
                      <th className="px-4 py-3 font-medium">Roles</th>
                      <th className="px-4 py-3 font-medium">Status</th>
                      <th className="px-4 py-3 font-medium">Heartbeat</th>
                      <th className="px-4 py-3 font-medium">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    {overview.nodes.map((node) => (
                      <tr key={node.id} className="border-t border-border">
                        <td className="px-4 py-3 font-medium">{node.name}</td>
                        <td className="px-4 py-3">{node.region}</td>
                        <td className="px-4 py-3">{node.roles.join(", ")}</td>
                        <td className="px-4 py-3">{node.status}</td>
                        <td className="px-4 py-3">{node.last_heartbeat_at ?? "-"}</td>
                        <td className="px-4 py-3">
                          <div className="flex gap-2">
                            <ActionButton label="Drain" icon={Square} onClick={() => runAction("Drain node", async () => apiPost(`/panel/nodes/${node.id}/drain`))} />
                            <ActionButton label="Migrate" icon={RotateCcw} onClick={() => runAction("Migrate node", async () => apiPost(`/panel/nodes/${node.id}/migrate`))} />
                          </div>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </PanelCard>
          )}

          {activeTab === "Operations" && (
            <section className="grid gap-5 xl:grid-cols-2">
              <PanelCard title="Commands">
                <RecordList records={status.commands} primary="command" secondary="status" empty="No commands queued." />
              </PanelCard>
              <PanelCard title="Deployments">
                <RecordList records={status.deployments} primary="site_id" secondary="status" empty="No deployments queued." />
              </PanelCard>
              <PanelCard title="SSL Orders">
                <RecordList records={status.ssl_orders} primary="site_id" secondary="status" empty="No SSL orders queued." />
              </PanelCard>
              <PanelCard title="Reconciliation">
                <RecordList records={status.reconciliation_jobs} primary="node_id" secondary="status" empty="No reconciliation jobs." />
              </PanelCard>
            </section>
          )}

          {activeTab === "Billing" && (
            <PanelCard title="Billing Enforcement">
              <div className="mb-4 flex flex-wrap gap-2">
                <ActionButton label="Enforce" icon={ShieldCheck} onClick={() => runAction("Billing enforce", async () => apiPost("/panel/billing/enforce", { window: "5m" }))} />
                <ActionButton label="Process events" icon={Play} onClick={() => runAction("Billing events", async () => apiPost("/panel/billing/events/process"))} />
              </div>
              <RecordList records={status.billing_decisions} primary="decision" secondary="severity" empty="No billing decisions." />
            </PanelCard>
          )}

          {activeTab === "Security" && (
            <section className="grid gap-5 xl:grid-cols-2">
              <PanelCard title="Drift Log">
                <RecordList records={status.drifts} primary="drift_type" secondary="status" empty="No drift detected." />
              </PanelCard>
              <PanelCard title="Journal">
                <RecordList records={status.journal} primary="operation_name" secondary="source" empty="No journal events." />
              </PanelCard>
            </section>
          )}
        </div>
      </section>
    </main>
  );
}

function PanelCard({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <section className="rounded-md border border-border bg-panel">
      <div className="border-b border-border px-4 py-3">
        <h2 className="font-semibold">{title}</h2>
      </div>
      <div className="p-4">{children}</div>
    </section>
  );
}

function TextInput({ label, value, onChange, placeholder, type = "text" }: { label: string; value: string; onChange: (value: string) => void; placeholder?: string; type?: string }) {
  return (
    <label className="grid gap-1 text-sm">
      <span className="text-slate-600">{label}</span>
      <input type={type} value={value} onChange={(event) => onChange(event.target.value)} placeholder={placeholder} className="rounded-md border border-border bg-white px-3 py-2" />
    </label>
  );
}

function ActionButton({ label, icon: Icon, onClick, danger = false }: { label: string; icon: typeof Play; onClick: () => Promise<void>; danger?: boolean }) {
  return (
    <button onClick={() => void onClick()} className={`inline-flex items-center gap-2 rounded-md border px-3 py-2 text-sm font-medium ${danger ? "border-red-200 text-danger hover:bg-red-50" : "border-border hover:bg-slate-50"}`}>
      <Icon size={15} />
      {label}
    </button>
  );
}

function RecordList({ records, primary, secondary, empty }: { records: Array<Record<string, unknown>>; primary: string; secondary: string; empty: string }) {
  if (records.length === 0) {
    return <EmptyState text={empty} />;
  }

  return (
    <div className="grid gap-2">
      {records.slice(0, 12).map((record, index) => (
        <article key={String(record.id ?? index)} className="rounded-md border border-border bg-white p-3 text-sm">
          <div className="flex items-start justify-between gap-3">
            <div className="min-w-0">
              <p className="truncate font-medium">{display(record[primary])}</p>
              <p className="mt-1 truncate text-slate-600">{display(record[secondary])}</p>
            </div>
            <span className="shrink-0 rounded-full bg-slate-100 px-2 py-1 text-xs">{display(record.status ?? record.created_at ?? record.occurred_at)}</span>
          </div>
        </article>
      ))}
    </div>
  );
}

function EmptyState({ text }: { text: string }) {
  return <div className="rounded-md border border-dashed border-border bg-slate-50 px-4 py-6 text-sm text-slate-600">{text}</div>;
}

function display(value: unknown): string {
  if (value === null || value === undefined || value === "") {
    return "-";
  }
  if (typeof value === "object") {
    return JSON.stringify(value);
  }
  return String(value);
}

function runtimeVersion(runtime: string): string {
  if (runtime === "php") return "8.4";
  if (runtime === "python") return "3.12";
  if (runtime === "static") return "nginx";
  if (runtime === "node") return "22";
  return "latest";
}
