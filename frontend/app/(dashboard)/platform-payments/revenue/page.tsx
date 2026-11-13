"use client";

import Link from "next/link";
import { useState } from "react";
import { ArrowLeft, Banknote, Receipt, TrendingUp, Undo2 } from "lucide-react";
import { useFetch } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { SUBSCRIPTION_LABEL, taka } from "@/lib/billing";
import type { RevenueReportT, SubscriptionStatus } from "@/lib/types";
import { Button, Card, ErrorText, Field, inputCls, Loading, PageHeader, Stat } from "@/components/app-ui";
import { Input } from "@/components/ui/input";
import { DataTable, type Col } from "@/components/DataTable";

const year = new Date().getFullYear();

/** The platform's money over a period and where every shop stands today. Super admin only. */
export default function RevenuePage() {
  const { t } = useT();
  const [from, setFrom] = useState(`${year}-01-01`);
  const [to, setTo] = useState(new Date().toLocaleDateString("en-CA"));
  const report = useFetch<RevenueReportT>(`/admin/reports/revenue?from=${from}&to=${to}`);
  const r = report.data;

  const monthCols: Col<RevenueReportT["by_month"][number]>[] = [
    { header: "Month", cell: ({ row }) => row.original.month },
    { header: "Payments", cell: ({ row }) => row.original.payments },
    { header: "Received", cell: ({ row }) => taka(row.original.amount_paisa) },
    { header: "Fees", cell: ({ row }) => taka(row.original.fee_paisa) },
    { header: "Refunded", cell: ({ row }) => taka(row.original.refunded_paisa) },
    { header: "Net", cell: ({ row }) => <span className="font-semibold">{taka(row.original.amount_paisa - row.original.fee_paisa - row.original.refunded_paisa)}</span> },
  ];
  const gatewayCols: Col<RevenueReportT["by_gateway"][number]>[] = [
    { header: "Gateway", cell: ({ row }) => <span className="capitalize">{row.original.gateway}</span> },
    { header: "Payments", cell: ({ row }) => row.original.payments },
    { header: "Received", cell: ({ row }) => taka(row.original.amount_paisa) },
    { header: "Fees", cell: ({ row }) => taka(row.original.fee_paisa) },
  ];
  const planCols: Col<RevenueReportT["subscriptions"]["by_plan"][number]>[] = [
    { header: "Plan", cell: ({ row }) => row.original.plan },
    { header: "Shops", cell: ({ row }) => row.original.shops },
    { header: "Paying", cell: ({ row }) => row.original.paying },
  ];

  return (
    <>
      <PageHeader title="Revenue" actions={<Link href="/platform-payments"><Button variant="outline"><ArrowLeft className="size-4" /> Payments</Button></Link>} />
      <div className="mb-4 grid max-w-md grid-cols-2 gap-3">
        <Field label="From"><Input type="date" className={inputCls} value={from} onChange={(e) => setFrom(e.target.value)} /></Field>
        <Field label="To"><Input type="date" className={inputCls} value={to} onChange={(e) => setTo(e.target.value)} /></Field>
      </div>
      {report.error && <ErrorText message={report.error} />}
      {!r ? <Loading /> : (
        <div className="space-y-6">
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <Stat icon={Banknote} label="Received" value={taka(r.totals.amount_paisa)} />
            <Stat icon={Undo2} color="amber" label="Fees and refunds" value={taka(r.totals.fee_paisa + r.totals.refunded_paisa)} />
            <Stat icon={TrendingUp} color="blue" label="Net" value={taka(r.totals.net_paisa)} />
            <Stat icon={Receipt} color="indigo" label="Monthly recurring revenue" value={taka(r.subscriptions.mrr_paisa)} />
          </div>
          <div className="grid gap-4 lg:grid-cols-2">
            <Card title="By month"><DataTable columns={monthCols} data={r.by_month} empty="No payments in this period." compact pager={false} /></Card>
            <Card title="By gateway"><DataTable columns={gatewayCols} data={r.by_gateway} empty="No payments in this period." compact pager={false} /></Card>
          </div>
          <div className="grid gap-4 lg:grid-cols-2">
            <Card title="Shops by status">
              <dl className="grid grid-cols-2 gap-2 text-sm sm:grid-cols-3">
                {(Object.keys(SUBSCRIPTION_LABEL) as SubscriptionStatus[]).map((s) => <div key={s} className="rounded-lg bg-slate-50 p-3"><dt className="text-xs uppercase tracking-wide text-slate-500">{t(SUBSCRIPTION_LABEL[s])}</dt><dd className="text-xl font-semibold text-slate-800">{r.subscriptions.by_status[s] ?? 0}</dd></div>)}
                <div className="rounded-lg bg-amber-50 p-3"><dt className="text-xs uppercase tracking-wide text-amber-700">{t("Unpaid invoices")}</dt><dd className="text-xl font-semibold text-amber-800">{r.outstanding.invoices} · {taka(r.outstanding.amount_paisa)}</dd></div>
              </dl>
            </Card>
            <Card title="Shops by plan"><DataTable columns={planCols} data={r.subscriptions.by_plan} empty="No shop is on a plan." compact pager={false} /></Card>
          </div>
        </div>
      )}
    </>
  );
}
