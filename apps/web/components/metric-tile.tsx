import type { LucideIcon } from "lucide-react";

type MetricTileProps = {
  label: string;
  value: string;
  trend: string;
  icon: LucideIcon;
};

export function MetricTile({ label, value, trend, icon: Icon }: MetricTileProps) {
  return (
    <article className="rounded-md border border-border bg-panel p-4">
      <div className="flex items-start justify-between gap-3">
        <div>
          <p className="text-sm text-slate-600">{label}</p>
          <p className="mt-2 text-2xl font-semibold tracking-normal">{value}</p>
        </div>
        <div className="rounded-md border border-border p-2 text-accent">
          <Icon size={18} />
        </div>
      </div>
      <p className="mt-3 text-sm text-slate-600">{trend}</p>
    </article>
  );
}
