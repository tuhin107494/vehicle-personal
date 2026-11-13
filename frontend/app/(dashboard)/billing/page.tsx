"use client";

import Link from "next/link";
import { useState } from "react";
import { useRouter } from "next/navigation";
import { CircleX, CreditCard, Download } from "lucide-react";
import { toast } from "sonner";
import { api, ApiError } from "@/lib/api";
import { useFetch } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { fmtDate } from "@/lib/format";
import { featureLabel, planPrice, SUBSCRIPTION_LABEL, SUBSCRIPTION_TONE, taka } from "@/lib/billing";
import { downloadInvoicePdf } from "@/lib/invoicePdf";
import type { BillingOverviewT, InvoiceT, PlanT, SubscriptionT } from "@/lib/types";
import { Badge, Button, Card, ErrorText, Field, inputCls, Loading, Modal, PageHeader, Pagination, StatusBadge } from "@/components/app-ui";
import { Input } from "@/components/ui/input";
import { DataTable, type Col } from "@/components/DataTable";
import { PlanPicker, type PlanAction } from "@/components/PlanPicker";

type Changed = { subscription: SubscriptionT; invoice: InvoiceT | null };

/** How much of each plan limit the shop has used. */
function Usage({ usage }: { usage: BillingOverviewT["usage"] }) {
  const { t } = useT();
  return (
    <dl className="grid gap-3 sm:grid-cols-2">
      {Object.entries(usage).map(([key, u]) => (
        <div key={key} className="rounded-lg bg-slate-50 p-3 text-sm">
          <dt className="font-medium text-slate-700">{t(featureLabel(key))}</dt>
          {u.type === "boolean" ? (
            <dd className="mt-1"><Badge tone={u.allowed ? "green" : "gray"}>{u.allowed ? "Included" : "Not in plan"}</Badge></dd>
          ) : (
            <dd className="mt-1">
              <div className="flex justify-between text-xs text-slate-600"><span>{`${u.used} ${t("used")}`}</span><span>{u.limit === null ? t("Unlimited") : `${t("limit")} ${u.limit}`}</span></div>
              {u.limit !== null && <div className="mt-1 h-2 overflow-hidden rounded-full bg-slate-200" role="progressbar" aria-valuenow={u.used} aria-valuemin={0} aria-valuemax={u.limit}><div className={`h-full ${u.used >= u.limit ? "bg-red-500" : "bg-emerald-500"}`} style={{ width: `${Math.min(100, u.limit ? (u.used / u.limit) * 100 : 0)}%` }} /></div>}
            </dd>
          )}
        </div>
      ))}
    </dl>
  );
}

/** The shop's plan, what it has used of it, the plans it can move to, and its invoices. Owners only. */
export default function BillingPage() {
  const { t } = useT();
  const router = useRouter();
  const overview = useFetch<BillingOverviewT>("/billing/subscription");
  const plans = useFetch<PlanT[]>("/plans");
  const [page, setPage] = useState(1);
  const invoices = useFetch<InvoiceT[]>(`/billing/invoices?page=${page}`);
  const [picked, setPicked] = useState<{ plan: PlanT; action: PlanAction } | null>(null);
  const [coupon, setCoupon] = useState("");
  const [cancelling, setCancelling] = useState(false);
  const [immediately, setImmediately] = useState(false);
  const [busy, setBusy] = useState(false);

  const sub = overview.data?.subscription ?? null;

  const confirmPick = async () => {
    if (!picked) return;
    setBusy(true);
    try {
      const res = await api<Changed>(`/billing/${picked.action}`, { method: "POST", body: { plan_id: picked.plan.id, ...(coupon.trim() && picked.action !== "downgrade" ? { coupon_code: coupon.trim() } : {}) } });
      toast.success(res.message);
      setPicked(null);
      setCoupon("");
      overview.reload();
      invoices.reload();
      if (res.data.invoice?.payable) router.push(`/billing/pay/${res.data.invoice.id}`);
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : "Failed");
    } finally {
      setBusy(false);
    }
  };

  const cancel = async () => {
    setBusy(true);
    try {
      const res = await api(`/billing/cancel`, { method: "POST", body: { immediately } });
      toast.success(res.message);
      setCancelling(false);
      overview.reload();
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : "Failed");
    } finally {
      setBusy(false);
    }
  };

  const pdf = async (inv: InvoiceT) => {
    try {
      const res = await api<InvoiceT>(`/billing/invoices/${inv.id}`);
      downloadInvoicePdf(res.data, t("Gari Becho"));
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : "Failed");
    }
  };

  const columns: Col<InvoiceT>[] = [
    { header: "Invoice", cell: ({ row }) => <span className="font-medium">{row.original.invoice_no}</span> },
    { header: "Plan", cell: ({ row }) => row.original.plan?.name ?? "-" },
    { header: "Period", cell: ({ row }) => `${fmtDate(row.original.period_starts_at)} – ${fmtDate(row.original.period_ends_at)}` },
    { header: "Total", cell: ({ row }) => <span className="font-semibold">{taka(row.original.total_paisa)}</span> },
    { header: "Due date", cell: ({ row }) => fmtDate(row.original.due_date) },
    { header: "Status", cell: ({ row }) => <StatusBadge status={row.original.status} /> },
    {
      header: "Action", id: "actions",
      cell: ({ row }) => (
        <div className="flex flex-wrap gap-1.5">
          {row.original.payable && <Link href={`/billing/pay/${row.original.id}`}><Button size="sm"><CreditCard className="size-3.5" /> Pay</Button></Link>}
          <Button size="sm" variant="outline" onClick={() => pdf(row.original)}><Download className="size-3.5" /> PDF</Button>
        </div>
      ),
    },
  ];

  if (overview.error) return <ErrorText message={overview.error} />;
  if (!overview.data) return <Loading />;
  const open = overview.data.open_invoice;

  return (
    <>
      <PageHeader title="Billing" actions={open?.payable ? <Link href={`/billing/pay/${open.id}`}><Button><CreditCard className="size-4" /> Pay now</Button></Link> : undefined} />
      <div className="grid gap-4 lg:grid-cols-2">
        <Card title="Your plan" actions={sub && ["trialing", "active", "past_due"].includes(sub.status) && !sub.cancelled_at ? <Button variant="outline" size="sm" onClick={() => { setImmediately(false); setCancelling(true); }}><CircleX className="size-3.5" /> Cancel</Button> : undefined}>
          {sub ? (
            <div className="space-y-2 text-sm">
              <div className="flex flex-wrap items-center gap-2">
                <span className="text-xl font-semibold text-slate-900">{sub.plan?.name}</span>
                <Badge tone={SUBSCRIPTION_TONE[sub.status]}>{SUBSCRIPTION_LABEL[sub.status]}</Badge>
              </div>
              {sub.plan && <p className="text-slate-600">{t(planPrice(sub.plan))}</p>}
              <p className="text-slate-700">{sub.status === "trialing" ? t("Trial ends") : sub.status === "past_due" ? t("Period ended") : t("Period ends")}: <strong>{fmtDate(sub.ends_at)}</strong>{sub.days_left > 0 && ` (${sub.days_left} ${t("days left")})`}</p>
              {sub.status === "past_due" && sub.grace_ends_at && <p className="text-amber-700">{t("Pay before")} {fmtDate(sub.grace_ends_at)} {t("to keep the shop open.")}</p>}
              {sub.status === "expired" && <p className="text-red-700">{t("The subscription has expired. Choose a plan below to continue.")}</p>}
              {sub.cancelled_at && sub.status !== "cancelled" && <p className="text-slate-600">{t("Renewal is stopped. The plan runs until the period ends.")}</p>}
              {sub.pending_plan && <p className="text-slate-600">{t("Next period")}: <strong>{sub.pending_plan.name}</strong></p>}
              {open?.payable && <p className="text-amber-700">{t("Invoice")} {open.invoice_no} ({taka(open.total_paisa)}) {t("is waiting to be paid.")}</p>}
            </div>
          ) : <p className="text-sm text-slate-600">{t("This shop is not on a plan yet. Choose one below.")}</p>}
        </Card>
        <Card title="Usage"><Usage usage={overview.data.usage} /></Card>
      </div>

      <h2 className="mb-3 mt-6 text-lg font-semibold text-emerald-700">{t("Plans")}</h2>
      {plans.error && <ErrorText message={plans.error} />}
      {plans.loading && !plans.data ? <Loading /> : <PlanPicker plans={plans.data ?? []} current={sub} busy={busy} onPick={(plan, action) => { setCoupon(""); setPicked({ plan, action }); }} />}

      <h2 className="mb-3 mt-6 text-lg font-semibold text-emerald-700">{t("Invoices")}</h2>
      {invoices.error && <ErrorText message={invoices.error} />}
      <DataTable columns={columns} data={invoices.data ?? []} loading={invoices.loading} empty="No invoices yet." serverPaged />
      <Pagination meta={invoices.meta} onPage={setPage} />

      <Modal open={!!picked} onClose={() => setPicked(null)} title={picked?.action === "downgrade" ? "Downgrade plan" : picked?.action === "upgrade" ? "Upgrade plan" : "Choose plan"}>
        {picked && (
          <div className="space-y-3">
            <p className="text-sm text-slate-700"><strong>{picked.plan.name}</strong> · {t(planPrice(picked.plan))}</p>
            {picked.action === "downgrade"
              ? <p className="text-sm text-slate-600">{t("The cheaper plan takes over when the current period ends. Nothing changes until then.")}</p>
              : <p className="text-sm text-slate-600">{picked.action === "upgrade" ? t("You pay the difference for the rest of the current period.") : t("An invoice is created; the plan starts once it is paid.")}</p>}
            {picked.action !== "downgrade" && <Field label="Coupon code (optional)"><Input className={inputCls} value={coupon} onChange={(e) => setCoupon(e.target.value)} autoComplete="off" /></Field>}
            <div className="flex justify-end gap-2">
              <Button variant="outline" onClick={() => setPicked(null)}>Back</Button>
              <Button disabled={busy} onClick={confirmPick}>Confirm</Button>
            </div>
          </div>
        )}
      </Modal>

      <Modal open={cancelling} onClose={() => setCancelling(false)} title="Cancel subscription">
        <div className="space-y-3">
          <p className="text-sm text-slate-600">{t("Renewal stops. The plan keeps running until the period ends, unless you end it now.")}</p>
          <label className="flex cursor-pointer items-center gap-2 text-sm text-slate-700"><input type="checkbox" className="size-4 accent-emerald-600" checked={immediately} onChange={(e) => setImmediately(e.target.checked)} /> {t("End the subscription now")}</label>
          <div className="flex justify-end gap-2">
            <Button variant="outline" onClick={() => setCancelling(false)}>Back</Button>
            <Button variant="danger" disabled={busy} onClick={cancel}>Cancel subscription</Button>
          </div>
        </div>
      </Modal>
    </>
  );
}
