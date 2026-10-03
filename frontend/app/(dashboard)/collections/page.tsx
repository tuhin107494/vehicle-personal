"use client";

import { useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { Eye, Phone, Search } from "lucide-react";
import { fmtDate, money } from "@/lib/format";
import { useFetch } from "@/lib/hooks";
import type { CallRow } from "@/lib/types";
import { Badge, PageHeader, Stat } from "@/components/app-ui";
import { Col, DataTable } from "@/components/DataTable";

type Kind = "" | "installment" | "promise";
type Stats = { count: number; amount: number; customers: number };
type Bucket = Stats & { key: string; label: string; kinds: { installment: Stats; promise: Stats } };

const KINDS: { value: Kind; label: string }[] = [
  { value: "", label: "All" },
  { value: "installment", label: "Installments" },
  { value: "promise", label: "Normal due" },
];

const STYLE: Record<string, { ring: string; active: string; text: string }> = {
  overdue: { ring: "ring-red-200 hover:bg-red-50", active: "bg-red-600 text-white ring-red-600", text: "text-red-700" },
  d3: { ring: "ring-amber-200 hover:bg-amber-50", active: "bg-amber-500 text-white ring-amber-500", text: "text-amber-700" },
  d7: { ring: "ring-sky-200 hover:bg-sky-50", active: "bg-sky-600 text-white ring-sky-600", text: "text-sky-700" },
  d10: { ring: "ring-emerald-200 hover:bg-emerald-50", active: "bg-emerald-600 text-white ring-emerald-600", text: "text-emerald-700" },
};

const plural = (n: number, word: string) => `${n} ${word}${n === 1 ? "" : "s"}`;

/** "2 months 3 days over", "4 days over" */
const overText = (o: NonNullable<CallRow["overdue_by"]>) => `${[o.months ? plural(o.months, "month") : "", o.days || !o.months ? plural(o.days, "day") : ""].filter(Boolean).join(" ")} over`;

function When({ row }: { row: CallRow }) {
  if (row.overdue_by) return <Badge tone="red">{overText(row.overdue_by)}</Badge>;
  if (row.days_left === 0) return <Badge tone="yellow">Today</Badge>;
  return <Badge tone={row.days_left <= 3 ? "yellow" : "blue"}>in {plural(row.days_left, "day")}</Badge>;
}

export default function CollectionsPage() {
  const router = useRouter();
  const [bucket, setBucket] = useState("overdue");
  const [kind, setKind] = useState<Kind>("");
  const [q, setQ] = useState("");
  const summary = useFetch<{ buckets: Bucket[]; current_due: number }>("/collections/summary");
  const list = useFetch<CallRow[]>(`/collections/upcoming?bucket=${bucket}${kind ? `&kind=${kind}` : ""}`);

  const term = q.trim().toLowerCase();
  const rows = (list.data ?? []).filter((r) => !term || [r.customer.name, r.customer.phone, r.customer.alt_phone, r.vehicle.brand, r.vehicle.model, r.vehicle.chassis_no].some((v) => v?.toLowerCase().includes(term)));

  const statsOf = (b: Bucket): Stats => (kind ? b.kinds[kind] : b);
  const current = summary.data?.buckets.find((b) => b.key === bucket);
  const shown = current ? statsOf(current) : undefined;

  const columns: Col<CallRow>[] = [
    { header: "Customer", cell: ({ row }) => <span className="font-medium">{row.original.customer.name}</span> },
    {
      header: "Call",
      cell: ({ row }) => (
        <div className="flex flex-col items-end gap-1 md:items-start">
          {[row.original.customer.phone, row.original.customer.alt_phone].filter(Boolean).map((n) => (
            <a key={n} href={`tel:${n}`} className="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-2.5 py-1 text-sm font-medium text-emerald-800 hover:bg-emerald-200" onClick={(e) => e.stopPropagation()}>
              <Phone className="size-3.5" /> {n}
            </a>
          ))}
        </div>
      ),
    },
    { header: "Vehicle", cell: ({ row }) => [row.original.vehicle.brand, row.original.vehicle.model].filter(Boolean).join(" ") || row.original.vehicle.chassis_no },
    { header: "For", cell: ({ row }) => <Badge tone={row.original.type === "installment" ? "blue" : "gray"}>{row.original.label}</Badge> },
    { header: "Due date", cell: ({ row }) => <span className="flex flex-wrap items-center justify-end gap-2 md:justify-start">{fmtDate(row.original.due_date)} <When row={row.original} /></span> },
    { header: "Amount", cell: ({ row }) => <b className={row.original.overdue_by ? "text-red-700" : "text-amber-700"}>{money(row.original.amount)}</b> },
    { header: "Customer's total due", cell: ({ row }) => <b className="text-slate-800">{money(row.original.customer_total_due)}</b> },
    { header: "Action", id: "actions", cell: ({ row }) => <Link className="inline-flex items-center gap-1.5 rounded-lg border border-emerald-200 px-3 py-1.5 text-sm font-medium text-emerald-700 hover:bg-emerald-50" href={`/sales/${row.original.sale_id}`}><Eye className="size-4" /> Open sale</Link> },
  ];

  return (
    <>
      <PageHeader title="Due alert" />
      <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-3">
        <Stat color="amber-soft" label="Due now (overdue + today)" value={money(summary.data?.current_due ?? 0)} />
        <Stat color="blue-soft" label={`${current?.label ?? "List"}: amount`} value={money(shown?.amount ?? 0)} />
        <Stat color="green-soft" label={`${current?.label ?? "List"}: customers to call`} value={shown?.customers ?? 0} />
      </div>

      <div className="mb-3 flex flex-wrap gap-2">
        {KINDS.map((k) => (
          <button
            key={k.value || "all"}
            type="button"
            onClick={() => setKind(k.value)}
            className={`rounded-full px-4 py-1.5 text-sm font-medium transition ${kind === k.value ? "bg-gradient-to-r from-emerald-600 to-green-600 text-white shadow" : "bg-white text-emerald-800 ring-1 ring-emerald-200 hover:bg-emerald-50"}`}
          >
            {k.label}
          </button>
        ))}
      </div>

      <div className="mb-4 flex flex-wrap gap-2">
        {(summary.data?.buckets ?? []).map((b) => {
          const active = bucket === b.key;
          const style = STYLE[b.key];
          const st = statsOf(b);
          return (
            <button
              key={b.key}
              type="button"
              onClick={() => setBucket(b.key)}
              className={`rounded-xl px-4 py-2 text-left text-sm ring-1 transition ${active ? style.active : `bg-white ${style.ring}`}`}
            >
              <span className="block font-semibold">{b.label} · {st.count}</span>
              <span className={`block text-xs ${active ? "text-white/85" : style.text}`}>{money(st.amount)}</span>
            </button>
          );
        })}
      </div>

      <div className="relative mb-3 max-w-md">
        <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
        <input
          type="search"
          value={q}
          onChange={(e) => setQ(e.target.value)}
          placeholder="Search customer, phone, vehicle or chassis"
          className="w-full rounded-lg border border-slate-300 bg-white py-2 pl-9 pr-3 text-sm outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200"
        />
      </div>

      <DataTable columns={columns} data={rows} loading={list.loading} onRowClick={(r) => router.push(`/sales/${r.sale_id}`)} empty={term ? "No match found." : bucket === "overdue" ? "Nothing is overdue." : "Nothing falls due in this period."} />
    </>
  );
}
