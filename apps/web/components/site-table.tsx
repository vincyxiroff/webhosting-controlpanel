const sites = [
  { domain: "atlas.example.com", runtime: "Next.js", node: "web-fra-01", status: "healthy" },
  { domain: "billing.example.com", runtime: "Laravel", node: "web-nyc-02", status: "deploying" },
  { domain: "docs.example.com", runtime: "Static", node: "edge-sfo-01", status: "healthy" },
  { domain: "api.example.com", runtime: "FastAPI", node: "web-fra-01", status: "healthy" }
];

export function SiteTable() {
  return (
    <section className="rounded-md border border-border bg-panel">
      <div className="border-b border-border px-4 py-3">
        <h2 className="font-semibold">Recent Sites</h2>
      </div>
      <div className="divide-y divide-border">
        {sites.map((site) => (
          <article key={site.domain} className="grid grid-cols-[1fr_auto] gap-3 px-4 py-3 text-sm">
            <div>
              <p className="font-medium">{site.domain}</p>
              <p className="mt-1 text-slate-600">{site.runtime} on {site.node}</p>
            </div>
            <span className="self-start rounded-full bg-slate-100 px-2 py-1 text-xs">{site.status}</span>
          </article>
        ))}
      </div>
    </section>
  );
}

