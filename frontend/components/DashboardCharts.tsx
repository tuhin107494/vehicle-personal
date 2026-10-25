"use client";

import Link from "next/link";
import { Car } from "lucide-react";
import { Card } from "@/components/app-ui";

export type Stats = {
  months: { month: string; sales: number; purchases: number; collected: number }[];
  stock_types: { key: string; name: string; count: number }[];
  vehicles: { in_stock: number; sold: number };
  sale_types: Record<string, number>;
  counts: { customers: number; suppliers: number; investors: number };
  payable: number;
  invested: number;
};

/** Horizontal bars for a few categories: label on the left, value at the end of the bar. */
function Bars({ title, rows, color, total, href }: { title: string; rows: { label: string; value: number }[]; color: string; total?: number; href?: string }) {
  const max = Math.max(1, ...rows.map((r) => r.value));
  return (
    <Card className="h-full gap-2 py-3" title={title} actions={total !== undefined && (href ? <Link href={href} title="View vehicles in stock" className="inline-flex items-center gap-1.5 rounded-full bg-blue-50 px-3 py-1 text-sm font-bold text-blue-700 hover:bg-blue-100"><Car className="size-4" /> {total}</Link> : <span className="inline-flex items-center gap-1.5 rounded-full bg-blue-50 px-3 py-1 text-sm font-bold text-blue-700"><Car className="size-4" /> {total}</span>)}>
      <div className="space-y-1.5">
        {rows.map((r) => (
          <div key={r.label} className="flex items-center gap-2 text-sm">
            <span className="w-24 shrink-0 truncate text-slate-600">{r.label}</span>
            <div className="h-3 min-w-0 flex-1 rounded-full bg-slate-100"><div className="h-3 rounded-full" style={{ width: `${(r.value / max) * 100}%`, background: color, minWidth: r.value > 0 ? 6 : 0 }} /></div>
            <span className="w-8 shrink-0 text-right font-medium text-slate-800">{r.value}</span>
          </div>
        ))}
        {!rows.length && <p className="text-sm text-slate-500">Nothing to show yet.</p>}
      </div>
    </Card>
  );
}

export function DashboardCharts({ stats }: { stats: Stats }) {
  const typeRows = stats.stock_types.map((t) => ({ label: t.name, value: t.count }));

  return (
    <>
        <Bars title="Stock" rows={typeRows} color="#2563eb" total={stats.vehicles.in_stock} href="/vehicles?status=in_stock" />
    </>
  );
}
