"use client";

import { useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { Check, Eye, History, Search, Undo2 } from "lucide-react";
import { api, ApiError } from "@/lib/api";
import { fmtDate, money } from "@/lib/format";
import { useFetch } from "@/lib/hooks";
import type { CallRow } from "@/lib/types";
import { Badge, Button, Field, inputCls, Modal, PageHeader, Stat } from "@/components/app-ui";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Col, DataTable } from "@/components/DataTable";

type Kind = "" | "installment" | "promise";
type CallEntry = { id: number; called_at: string; for: string; by: string | null; meet_date: string | null; note: string | null };
type Stats = { count: number; amount: number; customers: number };
type Bucket = Stats & { key: string; label: string; kinds: { installment: Stats; promise: Stats } };

const KINDS: { value: Kind; label: string }[] = [
  { value: "", label: "All" },
  { value: "installment", label: "Installments" },
  { value: "promise", label: "Due" },
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
  const [callFilter, setCallFilter] = useState<"" | "done" | "pending">("");
  // the tabs and totals come back with the list (meta.summary), so the page makes one request
  const list = useFetch<CallRow[]>(`/collections/upcoming?bucket=${bucket}${kind ? `&kind=${kind}` : ""}&with_summary=1`);
  const summary = { data: (list.meta as { summary?: { buckets: Bucket[]; current_due: number; total_due: number; recall_days: number } } | null)?.summary };

  const term = q.trim().toLowerCase();
  const rows = (list.data ?? []).filter((r) => callFilter === "" || (callFilter === "done") === !!r.called_until).filter((r) => !term ||[r.customer.name, r.customer.phone, r.customer.alt_phone, r.vehicle.brand, r.vehicle.model, r.vehicle.chassis_no].some((v) => v?.toLowerCase().includes(term)));

  const statsOf = (b: Bucket): Stats => (kind ? b.kinds[kind] : b);
  const current = summary.data?.buckets.find((b) => b.key === bucket);
  const shown = current ? statsOf(current) : undefined;

  // "Call done" opens a small form: the day the customer said they will meet, and a note
  const [calling, setCalling] = useState<CallRow | null>(null);
  const [meetDate, setMeetDate] = useState("");
  const [note, setNote] = useState("");
  const [saving, setSaving] = useState(false);

  // the calls made so far about a row's sale
  const [historyOf, setHistoryOf] = useState<CallRow | null>(null);
  const [history, setHistory] = useState<CallEntry[] | null>(null);
  const showHistory = async (r: CallRow) => {
    setHistoryOf(r);
    setHistory(null);
    try {
      setHistory((await api<CallEntry[]>(`/collections/call-history?type=${r.type}&id=${r.id}`)).data);
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : "Failed to load");
      setHistoryOf(null);
    }
  };

  const startCall = (r: CallRow) => {
    setMeetDate("");
    setNote("");
    setCalling(r);
  };

  const mark = async (r: CallRow, done: boolean) => {
    setSaving(true);
    try {
      await api("/collections/call", { method: done ? "POST" : "DELETE", body: { type: r.type, id: r.id, ...(done && { meet_date: meetDate || null, note: note.trim() || null }) } });
      toast.success(done ? (meetDate ? `Marked done. Call again on ${fmtDate(meetDate)}.` : `Marked done. Call again in ${summary.data?.recall_days ?? 7} days.`) : "Call mark removed");
      setCalling(null);
      list.reload();
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : "Failed");
    } finally {
      setSaving(false);
    }
  };

  const columns: Col<CallRow>[] = [
    { header: "Customer", cell: ({ row }) => <span className="font-medium">{row.original.customer.name}</span> },
    {
      header: "Call", meta: { className: "!px-1" },
      cell: ({ row }) => (
        <div className="flex flex-col items-end gap-1 md:items-start">
          {[row.original.customer.phone, row.original.customer.alt_phone].filter(Boolean).map((n) => (
            <a key={n} href={`tel:${n}`} className="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-800 hover:bg-emerald-200" onClick={(e) => e.stopPropagation()}>
              {n}
            </a>
          ))}
        </div>
      ),
    },
    {
      header: "Guarantor", meta: { className: "!px-1" },
      cell: ({ row }) => row.original.guarantor_phone
        ? <a href={`tel:${row.original.guarantor_phone}`} className="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 hover:bg-amber-200" onClick={(e) => e.stopPropagation()}>{row.original.guarantor_phone}</a>
        : "-",
    },
    {
      header: "Vehicle", meta: { className: "min-w-44" },
      cell: ({ row }) => {
        const v = row.original.vehicle;
        return (
          <span className="block leading-tight">
            <span className="font-medium">{[v.brand, v.model].filter(Boolean).join(" ") || v.chassis_no}</span>
            {(v.brand || v.model) && <span className="block text-xs text-slate-500">Chassis: {v.chassis_no}</span>}
            {v.engine_no && <span className="block text-xs text-slate-500">Engine: {v.engine_no}</span>}
          </span>
        );
      },
    },
    { header: "For", cell: ({ row }) => <Badge tone={row.original.type === "installment" ? "blue" : "gray"}>{row.original.label}</Badge> },
    { header: "Due date", meta: { className: "!px-1" }, cell: ({ row }) => <span className="flex flex-wrap items-center justify-end gap-2 md:justify-start">{fmtDate(row.original.due_date)} <When row={row.original} /></span> },
    { header: "Amount", cell: ({ row }) => <b className={row.original.overdue_by ? "text-red-700" : "text-amber-700"}>{money(row.original.amount)}</b> },
    { header: "Total due", cell: ({ row }) => <b className="text-slate-800">{money(row.original.customer_total_due)}</b> },
    {
      header: "Action",
      id: "actions",
      cell: ({ row }) => (
        <div className="flex flex-col items-end gap-1 md:items-start" onClick={(e) => e.stopPropagation()}>
          <div className="flex flex-wrap items-center justify-end gap-1.5 md:flex-nowrap md:justify-start">
          <Link className="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-emerald-200 px-3 py-1.5 text-sm font-medium text-emerald-700 hover:bg-emerald-50" href={`/sales/${row.original.sale_id}`}><Eye className="size-4" /> Sales details</Link>
          <button type="button" onClick={() => showHistory(row.original)} className="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-sky-300 bg-sky-50 px-3 py-1.5 text-sm font-medium text-sky-700 hover:bg-sky-100"><History className="size-4" /> Call history</button>
          {row.original.called_until ? (
            <button type="button" onClick={() => mark(row.original, false)} className="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-rose-300 bg-rose-50 px-3 py-1.5 text-sm font-medium text-rose-700 hover:bg-rose-100"><Undo2 className="size-4" /> Undo</button>
          ) : (
            <button type="button" onClick={() => startCall(row.original)} className="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-green-300 bg-green-50 px-3 py-1.5 text-sm font-medium text-green-700 hover:bg-green-100"><Check className="size-4" /> Call done</button>
          )}
          </div>
          {row.original.called_until && (
            <span className="text-[11px] font-medium leading-tight text-amber-800">
              Called · call again {fmtDate(row.original.called_until)}
              {row.original.called_meet_date && <span className="block">Will meet: {fmtDate(row.original.called_meet_date)}</span>}
              {row.original.called_note && <span className="block font-normal">{row.original.called_note}</span>}
            </span>
          )}
        </div>
      ),
    },
  ];

  return (
    <>
      <PageHeader title="Due alert" />
      <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-3">
        <Stat compact color="teal-flat" label="Due now" value={money(summary.data?.current_due ?? 0)} />
        <Stat compact color="cyan-flat" label={`${current?.label ?? "List"}: amount`} value={money(shown?.amount ?? 0)} />
        <Stat compact color="amber-flat" label="Total due" value={money(summary.data?.total_due ?? 0)} />
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
              <span className="block font-semibold">{b.label}</span>
              <span className={`block text-xs ${active ? "text-white/85" : style.text}`}>{money(st.amount)}</span>
            </button>
          );
        })}
      </div>

      <div className="mb-3 flex flex-wrap items-center gap-2">
        <div className="relative w-full max-w-md">
          <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
          <input
            type="search"
            value={q}
            onChange={(e) => setQ(e.target.value)}
            placeholder="Search customer, phone, vehicle or chassis"
            className="w-full rounded-lg border border-slate-300 bg-white py-2 pl-9 pr-3 text-sm outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200"
          />
        </div>
        <select
          value={callFilter}
          onChange={(e) => setCallFilter(e.target.value as "" | "done" | "pending")}
          aria-label="Call status"
          className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200"
        >
          <option value="">All calls</option>
          <option value="done">Call done</option>
          <option value="pending">Not called</option>
        </select>
      </div>

      <div className="[&_td]:!text-xs [&_td_a]:!text-xs [&_td_button]:!text-xs [&_th]:!text-[11px]">
        <DataTable columns={columns} data={rows} rowClass={(r) => (r.called_until ? "!bg-amber-100/80 hover:!bg-amber-100" : "")} loading={list.loading} onRowClick={(r) => router.push(`/sales/${r.sale_id}`)} empty={term ? "No match found." : bucket === "overdue" ? "Nothing is overdue." : "Nothing falls due in this period."} />
      </div>
      <Modal open={!!historyOf} title="Call history" onClose={() => setHistoryOf(null)}>
        {historyOf && (
          <div className="space-y-3">
            <p className="text-sm text-slate-600">{historyOf.customer.name} · {historyOf.customer.phone} · Sale #{historyOf.sale_id}</p>
            {history === null ? <p className="text-sm text-slate-500">Loading...</p> : history.length === 0 ? <p className="text-sm text-slate-500">No calls marked done yet.</p> : (
              <ul className="space-y-2">
                {history.map((c) => (
                  <li key={c.id} className="rounded-lg bg-slate-50 p-3 text-sm ring-1 ring-slate-200">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <b className="text-slate-800">{new Date(c.called_at).toLocaleString("en-GB", { day: "2-digit", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" })}</b>
                      <span className="text-xs text-slate-500">{c.for}{c.by ? ` · by ${c.by}` : ""}</span>
                    </div>
                    {c.meet_date && <p className="mt-1 text-slate-700">Will meet: {fmtDate(c.meet_date)}</p>}
                    {c.note && <p className="mt-1 text-slate-700">{c.note}</p>}
                  </li>
                ))}
              </ul>
            )}
            <div className="flex justify-end"><Button type="button" variant="outline" onClick={() => setHistoryOf(null)}>Close</Button></div>
          </div>
        )}
      </Modal>
      <Modal open={!!calling} title="Call done" onClose={() => setCalling(null)}>
        {calling && (
          <form className="space-y-3" onSubmit={(e) => { e.preventDefault(); mark(calling, true); }}>
            <p className="text-sm text-slate-600">{calling.customer.name} · {calling.customer.phone}</p>
            <Field label="Customer will meet me on">
              <Input type="date" className={inputCls} value={meetDate} min={new Date().toLocaleDateString("en-CA")} onChange={(e) => setMeetDate(e.target.value)} />
            </Field>
            <Field label="Note">
              <Textarea className={inputCls} rows={3} maxLength={500} placeholder="What the customer said" value={note} onChange={(e) => setNote(e.target.value)} />
            </Field>
            <p className="text-xs text-slate-500">Leave the date empty to call again after {summary.data?.recall_days ?? 7} days.</p>
            <div className="flex justify-end gap-2">
              <Button type="button" variant="outline" onClick={() => setCalling(null)}>Cancel</Button>
              <Button type="submit" disabled={saving}><Check className="size-4" /> {saving ? "Saving..." : "Call done"}</Button>
            </div>
          </form>
        )}
      </Modal>
    </>
  );
}
