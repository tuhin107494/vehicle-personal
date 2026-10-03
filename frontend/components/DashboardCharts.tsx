"use client";

import { useState } from "react";
import { money } from "@/lib/format";
import { useCategories } from "@/lib/categories";
import { Card } from "@/components/app-ui";

export type Stats = {
  months: { month: string; sales: number; purchases: number; collected: number }[];
  stock_by_type: Record<string, number>;
  vehicles: { in_stock: number; sold: number };
  sale_types: Record<string, number>;
  counts: { customers: number; suppliers: number; investors: number };
  payable: number;
  invested: number;
};

// one validated colour per series, in a fixed order
const SERIES = [
  { key: "sales", label: "Sales", color: "#059669" },
  { key: "purchases", label: "Purchases", color: "#2563eb" },
  { key: "collected", label: "Collected", color: "#d97706" },
] as const;

const short = (n: number) => (n >= 1e7 ? `${Math.round(n / 1e6) / 10}Cr` : n >= 1e5 ? `${Math.round(n / 1e4) / 10}L` : n >= 1e3 ? `${Math.round(n / 100) / 10}k` : String(n));

/** Six months of sales, purchases and collections, grouped by month; hover or tap a bar for the exact amount. */
function MonthlyChart({ months }: { months: Stats["months"] }) {
  const [hover, setHover] = useState<string | null>(null);
  const max = Math.max(1, ...months.flatMap((m) => SERIES.map((s) => m[s.key])));
  const ticks = [0, 0.5, 1].map((t) => Math.round(max * t));

  return (
    <Card title="Last 6 months">
      <div className="mb-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-600">
        {SERIES.map((s) => <span key={s.key} className="inline-flex items-center gap-1.5"><span className="size-2.5 rounded-sm" style={{ background: s.color }} />{s.label}</span>)}
        {hover && <span className="ml-auto font-medium text-slate-800">{hover}</span>}
      </div>
      <div className="relative flex h-44 gap-2 pl-10">
        <div className="pointer-events-none absolute inset-y-0 left-0 flex w-9 flex-col justify-between text-right text-[10px] text-slate-400">
          {[...ticks].reverse().map((t) => <span key={t}>{short(t)}</span>)}
        </div>
        <div className="pointer-events-none absolute inset-y-0 left-10 right-0 flex flex-col justify-between">
          {ticks.map((t) => <div key={t} className="border-t border-slate-200/80" />)}
        </div>
        {months.map((m) => (
          <div key={m.month} className="relative flex min-w-0 flex-1 flex-col">
            <div className="flex flex-1 items-end justify-center gap-[2px]">
              {SERIES.map((s) => (
                <button
                  key={s.key}
                  type="button"
                  aria-label={`${m.month} ${s.label} ${money(m[s.key])}`}
                  onMouseEnter={() => setHover(`${m.month} · ${s.label} ${money(m[s.key])}`)}
                  onMouseLeave={() => setHover(null)}
                  onClick={() => setHover(`${m.month} · ${s.label} ${money(m[s.key])}`)}
                  className="w-full max-w-4 rounded-t-[4px] transition-opacity hover:opacity-80"
                  style={{ height: `${(m[s.key] / max) * 100}%`, minHeight: m[s.key] > 0 ? 3 : 0, background: s.color }}
                />
              ))}
            </div>
            <span className="mt-1 text-center text-[11px] text-slate-500">{m.month}</span>
          </div>
        ))}
      </div>
    </Card>
  );
}

/** Horizontal bars for a few categories: label on the left, value at the end of the bar. */
function Bars({ title, rows, color }: { title: string; rows: { label: string; value: number }[]; color: string }) {
  const max = Math.max(1, ...rows.map((r) => r.value));
  return (
    <Card title={title}>
      <div className="space-y-2">
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
  const { categories } = useCategories();
  const typeRows = Object.entries(stats.stock_by_type).map(([k, v]) => ({ label: categories.find((c) => c.key === k)?.name ?? k, value: v })).sort((a, b) => b.value - a.value);
  const saleRows = (["cash", "due", "installment"] as const).map((k) => ({ label: k[0].toUpperCase() + k.slice(1), value: stats.sale_types[k] ?? 0 }));
  const total = stats.vehicles.in_stock + stats.vehicles.sold;

  return (
    <div className="mb-6 space-y-4">
      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        {[
          ["Customers", stats.counts.customers], ["Suppliers", stats.counts.suppliers], ["Investors", stats.counts.investors], ["Vehicles", total],
        ].map(([k, v]) => (
          <div key={k as string} className="rounded-xl bg-white/90 px-3 py-2 ring-1 ring-emerald-200"><p className="text-xs text-slate-500">{k}</p><p className="text-lg font-bold text-slate-900">{v}</p></div>
        ))}
      </div>
      <MonthlyChart months={stats.months} />
      <div className="grid gap-4 md:grid-cols-3">
        <Bars title="Stock by type" rows={typeRows} color="#2563eb" />
        <Bars title="Sales by payment type" rows={saleRows} color="#059669" />
        <Card title="Money">
          <dl className="space-y-2 text-sm">
            <div className="flex justify-between"><dt className="text-slate-500">We owe suppliers</dt><dd className="font-bold text-amber-700">{money(stats.payable)}</dd></div>
            <div className="flex justify-between"><dt className="text-slate-500">Invested by investors</dt><dd className="font-bold text-slate-900">{money(stats.invested)}</dd></div>
            <div className="flex justify-between"><dt className="text-slate-500">In stock / sold</dt><dd className="font-bold text-slate-900">{stats.vehicles.in_stock} / {stats.vehicles.sold}</dd></div>
          </dl>
        </Card>
      </div>
    </div>
  );
}
