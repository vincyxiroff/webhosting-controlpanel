import { Activity, Database, Globe2, HardDrive, Lock, Mail, Server, ShieldCheck } from "lucide-react";
import { MetricTile } from "@/components/metric-tile";
import { NodeTable } from "@/components/node-table";
import { SiteTable } from "@/components/site-table";

const metrics = [
  { label: "Online nodes", value: "18", trend: "+2", icon: Server },
  { label: "Hosted sites", value: "742", trend: "+31", icon: Globe2 },
  { label: "CPU pressure", value: "41%", trend: "-6%", icon: Activity },
  { label: "Protected domains", value: "1,906", trend: "+88", icon: Lock },
  { label: "Databases", value: "388", trend: "+14", icon: Database },
  { label: "Mailboxes", value: "2,441", trend: "+52", icon: Mail },
  { label: "Backup health", value: "99.7%", trend: "stable", icon: HardDrive },
  { label: "Security events", value: "12", trend: "-4", icon: ShieldCheck }
];

export default function Home() {
  return (
    <main className="min-h-screen">
      <aside className="fixed left-0 top-0 hidden h-screen w-64 border-r border-border bg-panel px-4 py-5 lg:block">
        <div className="text-lg font-semibold">ControlPanel OS</div>
        <nav className="mt-8 grid gap-1 text-sm">
          {["Overview", "Sites", "Nodes", "DNS", "Email", "Deployments", "Backups", "Security", "Billing"].map((item) => (
            <button key={item} className="rounded-md px-3 py-2 text-left text-slate-700 hover:bg-slate-100">
              {item}
            </button>
          ))}
        </nav>
      </aside>
      <section className="lg:pl-64">
        <header className="sticky top-0 z-10 border-b border-border bg-panel/95 px-5 py-4 backdrop-blur">
          <div className="flex items-center justify-between gap-4">
            <div>
              <h1 className="text-xl font-semibold">Infrastructure Overview</h1>
              <p className="text-sm text-slate-600">Live capacity, placements, deployments, and tenant health.</p>
            </div>
            <button className="rounded-md bg-accent px-4 py-2 text-sm font-medium text-white">Add node</button>
          </div>
        </header>
        <div className="grid gap-5 p-5">
          <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            {metrics.map((metric) => (
              <MetricTile key={metric.label} {...metric} />
            ))}
          </section>
          <section className="grid gap-5 xl:grid-cols-[1.15fr_0.85fr]">
            <NodeTable />
            <SiteTable />
          </section>
        </div>
      </section>
    </main>
  );
}

