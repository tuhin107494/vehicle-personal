"use client";

import Link from "next/link";
import { useState } from "react";
import { ChartColumn, RotateCcw, TriangleAlert, Undo2 } from "lucide-react";
import { toast } from "sonner";
import { api, ApiError } from "@/lib/api";
import { usePagedList } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { taka, toPaisa } from "@/lib/billing";
import type { GatewayPaymentT } from "@/lib/types";
import { Badge, Button, ErrorText, Field, inputCls, Modal, PageHeader, Pagination } from "@/components/app-ui";
import { Input } from "@/components/ui/input";
import { NativeSelect } from "@/components/ui/native-select";
import { DataTable, type Col } from "@/components/DataTable";

const STATUS_TONE: Record<GatewayPaymentT["status"], string> = { success: "green", partially_refunded: "yellow", refunded: "gray" };
const STATUS_TEXT: Record<GatewayPaymentT["status"], string> = { success: "Received", partially_refunded: "Partly refunded", refunded: "Refunded" };

/** Ask the gateway to send money back, or record a refund made outside it. */
function RefundForm({ payment, onClose, onSaved }: { payment: GatewayPaymentT | null; onClose: () => void; onSaved: () => void }) {
  const { t } = useT();
  const [amount, setAmount] = useState("");
  const [reason, setReason] = useState("");
  const [offline, setOffline] = useState(false);
  const [busy, setBusy] = useState(false);
  const [openedFor, setOpenedFor] = useState(payment);
  if (openedFor !== payment) {
    setOpenedFor(payment);
    setAmount(payment ? String(payment.refundable_paisa / 100) : "");
    setReason("");
    setOffline(payment?.gateway === "manual");
  }
  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!payment) return;
    setBusy(true);
    try {
      const res = await api(`/admin/payments/${payment.id}/refund`, { method: "POST", body: { amount_paisa: toPaisa(amount), reason, offline } });
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
    <Modal open={!!payment} onClose={onClose} title="Refund">
      <form onSubmit={submit} className="space-y-3">
        <p className="text-sm text-slate-600">{payment?.shop?.name} · {payment?.invoice?.invoice_no} · {t("received")} {taka(payment?.amount_paisa)} · {t("refundable")} {taka(payment?.refundable_paisa)}</p>
        <Field required label="Amount (taka)"><Input type="number" min={0.01} step="0.01" className={inputCls} value={amount} onChange={(e) => setAmount(e.target.value)} /></Field>
        <Field required label="Reason"><Input className={inputCls} value={reason} onChange={(e) => setReason(e.target.value)} /></Field>
        <label className="flex cursor-pointer items-center gap-2 text-sm text-slate-700"><input type="checkbox" className="size-4 accent-emerald-600" checked={offline} disabled={payment?.gateway === "manual"} onChange={(e) => setOffline(e.target.checked)} /> {t("Money was sent back outside the gateway (record only)")}</label>
        <div className="flex justify-end gap-2">
          <Button variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" variant="danger" disabled={busy || !reason.trim() || !amount}>Refund</Button>
        </div>
      </form>
    </Modal>
  );
}

/** Every confirmed payment across the platform, super admin only, with refunds; the settlement check and the revenue report are a click away. */
export default function PlatformPaymentsPage() {
  const { t } = useT();
  const [filters, setFilters] = useState({ gateway: "", from: "", to: "" });
  const list = usePagedList<GatewayPaymentT>("/admin/payments", filters);
  const [refunding, setRefunding] = useState<GatewayPaymentT | null>(null);

  const columns: Col<GatewayPaymentT>[] = [
    { header: "Paid", cell: ({ row }) => new Date(row.original.paid_at).toLocaleString() },
    { header: "Shop", cell: ({ row }) => row.original.shop?.name ?? `#${row.original.shop_id}` },
    { header: "Invoice", cell: ({ row }) => row.original.invoice?.invoice_no ?? `#${row.original.invoice_id}` },
    { header: "Gateway", cell: ({ row }) => <span className="capitalize">{row.original.gateway}</span> },
    { header: "Transaction", cell: ({ row }) => <code className="text-xs">{row.original.gateway_trx_id}</code> },
    { header: "Amount", cell: ({ row }) => <span className="font-semibold">{taka(row.original.amount_paisa)}</span> },
    { header: "Fee", cell: ({ row }) => taka(row.original.fee_paisa) },
    { header: "Refunded", cell: ({ row }) => (row.original.refunded_paisa ? taka(row.original.refunded_paisa) : "-") },
    { header: "Status", cell: ({ row }) => <Badge tone={STATUS_TONE[row.original.status]}>{STATUS_TEXT[row.original.status]}</Badge> },
    { header: "Action", id: "actions", cell: ({ row }) => row.original.refundable_paisa > 0 ? <Button size="sm" variant="outline" onClick={() => setRefunding(row.original)}><Undo2 className="size-3.5" /> Refund</Button> : null },
  ];

  return (
    <>
      <PageHeader title="Payments" actions={<>
        <Link href="/platform-payments/refunds"><Button variant="outline"><RotateCcw className="size-4" /> Refunds</Button></Link>
        <Link href="/platform-payments/reconciliation"><Button variant="outline"><TriangleAlert className="size-4" /> Settlement check</Button></Link>
        <Link href="/platform-payments/revenue"><Button><ChartColumn className="size-4" /> Revenue</Button></Link>
      </>} />
      <div className="mb-4 grid gap-3 sm:grid-cols-4">
        <Field label="Search"><Input className={inputCls} placeholder={t("Shop or transaction")} value={list.search} onChange={(e) => list.setSearch(e.target.value)} /></Field>
        <Field label="Gateway">
          <NativeSelect className={inputCls} value={filters.gateway} onChange={(e) => setFilters((f) => ({ ...f, gateway: e.target.value }))}>
            <option value="">{t("All")}</option>
            <option value="bkash">bKash</option>
            <option value="manual">{t("Bank transfer")}</option>
          </NativeSelect>
        </Field>
        <Field label="From"><Input type="date" className={inputCls} value={filters.from} onChange={(e) => setFilters((f) => ({ ...f, from: e.target.value }))} /></Field>
        <Field label="To"><Input type="date" className={inputCls} value={filters.to} onChange={(e) => setFilters((f) => ({ ...f, to: e.target.value }))} /></Field>
      </div>
      {list.error && <ErrorText message={list.error} />}
      <DataTable columns={columns} data={list.rows} loading={list.loading} empty="No payments yet." serverPaged />
      <Pagination meta={list.meta} onPage={list.setPage} />
      <RefundForm payment={refunding} onClose={() => setRefunding(null)} onSaved={list.reload} />
    </>
  );
}
