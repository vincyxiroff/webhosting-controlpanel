import type { DashboardOverview } from "@/lib/api";

type NodeTableProps = {
  nodes: DashboardOverview["nodes"];
};

function metricPercent(metrics: Record<string, unknown>, primary: string, nested: string): string {
  const direct = metrics[primary];
  if (typeof direct === "number") {
    return `${Math.round(direct)}%`;
  }
  const nestedValue = metrics[nested];
  if (nestedValue && typeof nestedValue === "object" && "percent" in nestedValue) {
    const percent = (nestedValue as { percent?: unknown }).percent;
    if (typeof percent === "number") {
      return `${Math.round(percent)}%`;
    }
  }

  return "-";
}

export function NodeTable({ nodes }: NodeTableProps) {
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
              <tr key={node.id} className="border-t border-border">
                <td className="px-4 py-3 font-medium">{node.name}</td>
                <td className="px-4 py-3">{node.region}</td>
                <td className="px-4 py-3">{node.roles.join(", ") || "-"}</td>
                <td className="px-4 py-3">{metricPercent(node.metrics, "cpu_percent", "cpu")}</td>
                <td className="px-4 py-3">{metricPercent(node.metrics, "memory_percent", "memory")}</td>
                <td className="px-4 py-3">
                  <span className="rounded-full bg-slate-100 px-2 py-1 text-xs">{node.status}</span>
                </td>
              </tr>
            ))}
            {nodes.length === 0 && (
              <tr className="border-t border-border">
                <td className="px-4 py-6 text-slate-600" colSpan={6}>No nodes registered yet.</td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </section>
  );
}
