import type { DashboardOverview } from "@/lib/api";

type SiteTableProps = {
  sites: DashboardOverview["sites"];
  nodes: DashboardOverview["nodes"];
};

export function SiteTable({ sites, nodes }: SiteTableProps) {
  const nodeNames = new Map(nodes.map((node) => [node.id, node.name]));

  return (
    <section className="rounded-md border border-border bg-panel">
      <div className="border-b border-border px-4 py-3">
        <h2 className="font-semibold">Recent Sites</h2>
      </div>
      <div className="divide-y divide-border">
        {sites.map((site) => (
          <article key={site.id} className="grid grid-cols-[1fr_auto] gap-3 px-4 py-3 text-sm">
            <div>
              <p className="font-medium">{site.primary_domain}</p>
              <p className="mt-1 text-slate-600">{site.runtime} {site.runtime_version} on {site.node_id ? nodeNames.get(site.node_id) ?? site.node_id : "unassigned"}</p>
            </div>
            <span className="self-start rounded-full bg-slate-100 px-2 py-1 text-xs">{site.status}</span>
          </article>
        ))}
        {sites.length === 0 && (
          <div className="px-4 py-6 text-sm text-slate-600">No sites created yet.</div>
        )}
      </div>
    </section>
  );
}
