"use client";

import { useState } from "react";
import Link from "next/link";
import { RotateCcw } from "lucide-react";
import { toast } from "sonner";
import { api, ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { money } from "@/lib/format";
import { usePagedList } from "@/lib/hooks";
import { stageLabel, STAGES } from "@/lib/sms";
import type { SmsLog, SmsSummary } from "@/lib/types";
import { Badge, Button, ErrorText, inputCls, PageHeader, Pagination, Stat } from "@/components/app-ui";
import { Col, DataTable } from "@/components/DataTable";
import { SmsSettingsPanel, SmsTextsPanel } from "@/components/SmsSetup";
import { Input } from "@/components/ui/input";
import { NativeSelect } from "@/components/ui/native-select";

const STATUS_TONE: Record<SmsLog["status"], string> = { queued: "yellow", sent: "green", delivered: "green", failed: "red", skipped: "gray" };
const thisMonth = () => new Date().toLocaleDateString("en-CA").slice(0, 7);
const fmtTime = (v: string | null) => (v ? new Date(v).toLocaleString("en-GB", { day: "2-digit", month: "short", hour: "2-digit", minute: "2-digit" }) : "-");

type Tab = "log" | "settings" | "texts";
const TABS: [Tab, string][] = [["log", "SMS log"], ["settings", "Settings"], ["texts", "Message texts"]];

/** SMS due alerts in one place: what was sent, the settings, and the texts. The last two only for users who manage settings. */
export default function SmsPage() {
  const { can } = useAuth();
  const [tab, setTab] = useState<Tab>("log");
  const tabs = TABS.filter(([key]) => key === "log" || can("settings.manage"));

  return (
    <>
      <PageHeader title="SMS" />
      {tabs.length > 1 && (
        <div role="tablist" className="mb-4 inline-flex flex-wrap gap-1 rounded-xl bg-white p-1 ring-1 ring-emerald-300">
          {tabs.map(([key, text]) => (
            <button key={key} type="button" role="tab" aria-selected={tab === key} onClick={() => setTab(key)}
              className={`rounded-lg px-4 py-2 text-sm font-medium transition ${tab === key ? "bg-gradient-to-r from-emerald-600 to-green-600 text-white shadow" : "text-emerald-800 hover:bg-emerald-50"}`}>
              {text}
            </button>
          ))}
        </div>
      )}
      {tab === "log" && <SmsLogPanel />}
      {tab === "settings" && <SmsSettingsPanel />}
      {tab === "texts" && <SmsTextsPanel />}
    </>
  );
}

/** Every SMS due alert of a month: what went to whom (numbers masked), what failed and why, and the month's count and cost. */
function SmsLogPanel() {
  const { can } = useAuth();
  const [month, setMonth] = useState(thisMonth());
  const [status, setStatus] = useState("");
  const [stage, setStage] = useState("");
  const list = usePagedList<SmsLog>("/sms", { month, status, stage });
  const summary = (list.meta as { summary?: SmsSummary } | null)?.summary;
  const [busy, setBusy] = useState<number | null>(null);

  const retry = async (id: number) => {
    setBusy(id);
    try {
      const res = await api(`/sms/${id}/retry`, { method: "POST" });
      toast.success(res.message);
      list.reload();
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : "Failed");
    } finally {
      setBusy(null);
    }
  };

  const columns: Col<SmsLog>[] = [
    { header: "Date", cell: ({ row }) => <span className="whitespace-nowrap">{fmtTime(row.original.sent_at ?? row.original.created_at)}</span> },
    { header: "Customer", cell: ({ row }) => (
      <span className="block leading-tight">
        {row.original.customer ? <Link className="font-medium underline" href={`/customers/${row.original.customer.id}`}>{row.original.customer.name}</Link> : <span className="text-slate-500">Staff test</span>}
        <span className="block text-xs text-slate-500">{row.original.phone}</span>
      </span>
    ) },
    { header: "Stage", cell: ({ row }) => (
      <span className="block leading-tight">
        {stageLabel(row.original.stage)}
        {row.original.sale_id && <Link className="block text-xs text-slate-500 underline" href={`/sales/${row.original.sale_id}`}>Sale #{row.original.sale_id}</Link>}
      </span>
    ) },
    { header: "Message", cell: ({ row }) => <span className="block max-w-sm whitespace-normal text-sm">{row.original.message}</span> },
    { header: "SMS", cell: ({ row }) => <span className="whitespace-nowrap">{row.original.parts} · {money(row.original.cost)}</span> },
    { header: "Status", cell: ({ row }) => (
      <span className="block max-w-xs leading-tight">
        <Badge tone={STATUS_TONE[row.original.status]}>{row.original.status}</Badge>
        {row.original.response && <span className="mt-1 block whitespace-normal text-xs text-slate-500">{row.original.response}</span>}
      </span>
    ) },
    { header: "Action", id: "actions", cell: ({ row }) => row.original.status === "failed" && can("sms.manage")
      ? <Button variant="outline" disabled={busy === row.original.id} onClick={() => retry(row.original.id)}><RotateCcw className="size-4 text-sky-600" /> Retry</Button>
      : null },
  ];

  return (
    <>
      {summary && !summary.live && (
        <p className="mb-3 rounded-lg bg-amber-50 p-2 text-sm text-amber-800">No SMS gateway is set up on the server yet: SMS are written to the log but not sent.</p>
      )}
      <div className="mb-4 grid grid-cols-2 gap-2 sm:grid-cols-4">
        <Stat label="Sent" value={summary?.sent ?? 0} />
        <Stat label="Failed" value={summary?.failed ?? 0} tone={summary?.failed ? "red" : undefined} color="orange" />
        <Stat label="SMS parts" value={summary?.parts ?? 0} color="blue" />
        <Stat label="Cost" value={money(summary?.cost ?? 0)} color="indigo" />
      </div>
      <div className="mb-3 grid grid-cols-1 gap-2 sm:grid-cols-3 sm:max-w-2xl">
        <Input type="month" className={inputCls} value={month} max={thisMonth()} onChange={(e) => setMonth(e.target.value || thisMonth())} aria-label="Month" />
        <NativeSelect className={inputCls} value={status} onChange={(e) => setStatus(e.target.value)} aria-label="Status">
          <option value="">All statuses</option>
          {["sent", "failed", "queued", "skipped"].map((s) => <option key={s} value={s}>{s[0].toUpperCase() + s.slice(1)}</option>)}
        </NativeSelect>
        <NativeSelect className={inputCls} value={stage} onChange={(e) => setStage(e.target.value)} aria-label="Stage">
          <option value="">All stages</option>
          {STAGES.map((s) => <option key={s.stage} value={s.stage}>{s.label}</option>)}
        </NativeSelect>
      </div>
      {list.error && <ErrorText message={list.error} />}
      <DataTable serverPaged columns={columns} data={list.rows} loading={list.loading} empty="No SMS this month." />
      <Pagination meta={list.meta} onPage={list.setPage} />
    </>
  );
}
