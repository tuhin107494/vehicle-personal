"use client";

import { useState } from "react";
import { CalendarPlus, Eye } from "lucide-react";
import { toast } from "sonner";
import { api, ApiError } from "@/lib/api";
import { useFetch, usePagedList } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { fmtDate } from "@/lib/format";
import { planPrice, SUBSCRIPTION_LABEL, SUBSCRIPTION_TONE, taka } from "@/lib/billing";
import type { InvoiceT, PlanT, SubscriptionStatus, SubscriptionT } from "@/lib/types";
import { Badge, Button, ErrorText, Field, inputCls, KV, Loading, Modal, PageHeader, Pagination, StatusBadge } from "@/components/app-ui";
import { Input } from "@/components/ui/input";
import { NativeSelect } from "@/components/ui/native-select";
import { DataTable, type Col } from "@/components/DataTable";

type ShopBilling = { shop: { id: number; name: string }; subscription: SubscriptionT | null; invoices: InvoiceT[] };
const STATUSES: SubscriptionStatus[] = ["trialing", "active", "past_due", "expired", "cancelled"];

/** One shop's subscription and its invoices. */
function ShopDetails({ shopId, onClose }: { shopId: number | null; onClose: () => void }) {
  const { t } = useT();
  const detail = useFetch<ShopBilling>(shopId ? `/admin/shops/${shopId}/subscription` : null);
  const s = detail.data?.subscription;
  const columns: Col<InvoiceT>[] = [
    { header: "Invoice", cell: ({ row }) => row.original.invoice_no },
    { header: "Period", cell: ({ row }) => `${fmtDate(row.original.period_starts_at)} – ${fmtDate(row.original.period_ends_at)}` },
    { header: "Total", cell: ({ row }) => taka(row.original.total_paisa) },
    { header: "Status", cell: ({ row }) => <StatusBadge status={row.original.status} /> },
  ];
  return (
    <Modal open={shopId !== null} onClose={onClose} title={detail.data?.shop.name ?? "Shop"} wide>
      {detail.error && <ErrorText message={detail.error} />}
      {!detail.data ? <Loading /> : (
        <div className="space-y-4">
          {s ? <KV items={[["Plan", s.plan?.name], ["Status", <Badge key="s" tone={SUBSCRIPTION_TONE[s.status]}>{SUBSCRIPTION_LABEL[s.status]}</Badge>], ["Ends", fmtDate(s.ends_at)], ["Grace ends", fmtDate(s.grace_ends_at)], ["Auto renew", s.auto_renew ? t("Yes") : t("No")], ["Next period", s.pending_plan?.name ?? "-"]]} /> : <p className="text-sm text-slate-600">{t("No subscription yet.")}</p>}
          <DataTable columns={columns} data={detail.data.invoices} empty="No invoices yet." compact pageSize={8} />
        </div>
      )}
    </Modal>
  );
}

/** Give a shop time without a payment. A shop with no subscription needs a plan too. */
function ExtendForm({ sub, plans, onClose, onSaved }: { sub: SubscriptionT | null; plans: PlanT[]; onClose: () => void; onSaved: () => void }) {
  const [endsAt, setEndsAt] = useState("");
  const [planId, setPlanId] = useState("");
  const [note, setNote] = useState("");
  const [busy, setBusy] = useState(false);
  const [openedFor, setOpenedFor] = useState(sub);
  if (openedFor !== sub) {
    setOpenedFor(sub);
    setEndsAt("");
    setPlanId("");
    setNote("");
  }
  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!sub) return;
    setBusy(true);
    try {
      const res = await api(`/admin/shops/${sub.shop_id}/subscription/extend`, { method: "POST", body: { ends_at: endsAt, plan_id: planId ? Number(planId) : null, note: note || null } });
      toast.success(res.message);
      onSaved();
      onClose();
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : "Failed");
    } finally {
      setBusy(false);
    }
  };
  return (
    <Modal open={!!sub} onClose={onClose} title="Extend subscription">
      <form onSubmit={submit} className="space-y-3">
        <p className="text-sm text-slate-600">{sub?.shop?.name} · {sub?.plan?.name} · ends {fmtDate(sub?.ends_at)}</p>
        <Field required label="New end date"><Input type="date" className={inputCls} value={endsAt} onChange={(e) => setEndsAt(e.target.value)} /></Field>
        <Field label="Move to plan">
          <NativeSelect className={inputCls} value={planId} onChange={(e) => setPlanId(e.target.value)}>
            <option value="">Keep the current plan</option>
            {plans.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
          </NativeSelect>
        </Field>
        <Field label="Note"><Input className={inputCls} value={note} onChange={(e) => setNote(e.target.value)} /></Field>
        <div className="flex justify-end gap-2">
          <Button variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={busy || !endsAt}>Extend</Button>
        </div>
      </form>
    </Modal>
  );
}

/** Every shop's subscription, super admin only: filter by status or plan, look into one, or extend it by hand. */
export default function PlatformSubscriptionsPage() {
  const { t } = useT();
  const [status, setStatus] = useState("");
  const list = usePagedList<SubscriptionT>("/admin/subscriptions", { status });
  const plans = useFetch<PlanT[]>("/admin/plans");
  const [viewing, setViewing] = useState<number | null>(null);
  const [extending, setExtending] = useState<SubscriptionT | null>(null);
  const counts = (list.meta as { status_counts?: Record<string, number> } | null)?.status_counts ?? {};

  const columns: Col<SubscriptionT>[] = [
    { header: "Shop", cell: ({ row }) => <div><p className="font-medium">{row.original.shop?.name}</p><p className="text-xs text-slate-500">{row.original.shop?.phone}</p></div> },
    { header: "Plan", cell: ({ row }) => <div><p>{row.original.plan?.name}</p>{row.original.plan && <p className="text-xs text-slate-500">{t(planPrice(row.original.plan))}</p>}</div> },
    { header: "Status", cell: ({ row }) => <Badge tone={SUBSCRIPTION_TONE[row.original.status]}>{SUBSCRIPTION_LABEL[row.original.status]}</Badge> },
    { header: "Ends", cell: ({ row }) => <span>{fmtDate(row.original.ends_at)}{row.original.days_left > 0 && <span className="block text-xs text-slate-500">{`${row.original.days_left} ${t("days left")}`}</span>}</span> },
    { header: "Next period", cell: ({ row }) => row.original.pending_plan?.name ?? (row.original.auto_renew && !row.original.cancelled_at ? t("Renews") : t("Stops")) },
    {
      header: "Action", id: "actions",
      cell: ({ row }) => (
        <div className="flex flex-wrap gap-1.5">
          <Button size="sm" variant="outline" onClick={() => setViewing(row.original.shop_id)}><Eye className="size-3.5" /> Details</Button>
          <Button size="sm" variant="outline" onClick={() => setExtending(row.original)}><CalendarPlus className="size-3.5" /> Extend</Button>
        </div>
      ),
    },
  ];

  return (
    <>
      <PageHeader title="Subscriptions" />
      <div className="mb-4 flex flex-wrap items-end gap-3">
        <div className="w-full max-w-xs"><Field label="Search shop"><Input className={inputCls} value={list.search} onChange={(e) => list.setSearch(e.target.value)} /></Field></div>
        <div className="flex flex-wrap gap-1.5">
          <Button size="sm" variant={status === "" ? "primary" : "outline"} onClick={() => setStatus("")}>All</Button>
          {STATUSES.map((s) => <Button key={s} size="sm" variant={status === s ? "primary" : "outline"} onClick={() => setStatus(s)}>{`${t(SUBSCRIPTION_LABEL[s])} (${counts[s] ?? 0})`}</Button>)}
        </div>
      </div>
      {list.error && <ErrorText message={list.error} />}
      <DataTable columns={columns} data={list.rows} loading={list.loading} empty="No subscriptions." serverPaged />
      <Pagination meta={list.meta} onPage={list.setPage} />
      <ShopDetails shopId={viewing} onClose={() => setViewing(null)} />
      <ExtendForm sub={extending} plans={plans.data ?? []} onClose={() => setExtending(null)} onSaved={list.reload} />
    </>
  );
}
