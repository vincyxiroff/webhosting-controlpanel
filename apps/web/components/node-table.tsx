const nodes = [
  { name: "web-fra-01", region: "fra", role: "web", cpu: "38%", memory: "44%", status: "online" },
  { name: "web-nyc-02", region: "nyc", role: "web", cpu: "52%", memory: "59%", status: "online" },
  { name: "db-fra-01", region: "fra", role: "db", cpu: "31%", memory: "68%", status: "online" },
  { name: "edge-sfo-01", region: "sfo", role: "edge", cpu: "24%", memory: "36%", status: "draining" }
];

export function NodeTable() {
  return (
    <section className="rounded-md border border-border bg-panel">
      <div className="border-b border-border px-4 py-3">
        <h2 className="font-semibold">Cluster Nodes</h2>
      </div>
      <div className="overflow-x-auto">
        <table className="w-full min-w-[620px] text-left text-sm">
          <thead className="bg-slate-50 text-slate-600">
            <tr>
              <th className="px-4 py-3 font-medium">Node</th>
              <th className="px-4 py-3 font-medium">Region</th>
              <th className="px-4 py-3 font-medium">Role</th>
              <th className="px-4 py-3 font-medium">CPU</th>
              <th className="px-4 py-3 font-medium">Memory</th>
              <th className="px-4 py-3 font-medium">Status</th>
            </tr>
          </thead>
          <tbody>
            {nodes.map((node) => (
              <tr key={node.name} className="border-t border-border">
                <td className="px-4 py-3 font-medium">{node.name}</td>
                <td className="px-4 py-3">{node.region}</td>
                <td className="px-4 py-3">{node.role}</td>
                <td className="px-4 py-3">{node.cpu}</td>
                <td className="px-4 py-3">{node.memory}</td>
                <td className="px-4 py-3">
                  <span className="rounded-full bg-slate-100 px-2 py-1 text-xs">{node.status}</span>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  );
}

