"use client";

import { useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { toast } from "sonner";
import { api, ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { fmtDate, label, money, todayStr } from "@/lib/format";
import { useFetch } from "@/lib/hooks";
import type { Installment, Payment, Promise_, RegLog, Sale } from "@/lib/types";
import { Badge, Button, Card, ErrorText, KV, Loading, PageHeader, StatusBadge } from "@/components/app-ui";
import { Col, DataTable } from "@/components/DataTable";
import { CancelSaleModal, CollectPaymentModal, PromiseModal } from "@/components/forms/SaleModals";
import { SaleEditModal } from "@/components/forms/SaleEditModal";
import { SaleChargeModal } from "@/components/forms/SaleChargeModal";
import { Ban, CalendarClock, HandCoins, Landmark, Pencil, Plus, Printer, Trash2 } from "lucide-react";
import { openSaleMemo } from "@/components/SaleMemo";
import { InvestorSaleCard } from "@/components/InvestorSaleCard";
import { RegistrationStepper } from "@/components/RegistrationStepper";

export default function SaleDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { can, user } = useAuth();
  const { data: sale, loading, error, reload } = useFetch<Sale>(`/sales/${id}`);
  const [chargeFor, setChargeFor] = useState<number | null | "new">(null); // the charge being edited, or "new"
  const [modal, setModal] = useState<"pay" | "promise" | "cancel" | "edit" | null>(null);

  if (loading && !sale) return <Loading />;
  if (error || !sale) return <ErrorText message={error ?? "Not found"} />;

  const active = sale.status === "active";
  const installments = sale.plan?.installments ?? [];
  // what is payable now (dates reached), as the Accounts page works it out: an installment's or promise's date reached;
  // a due with no promised date is all payable now
  const today = todayStr();
  const dated = sale.payment_type === "installment" && sale.plan
    ? installments.filter((i) => ["pending", "partial", "overdue"].includes(i.status)).map((i) => [i.due_date, i.amount - i.paid_amount] as const)
    : (sale.promises ?? []).filter((p) => ["open", "broken"].includes(p.status)).map((p) => [p.promised_date, Math.min(p.promised_amount, sale.due_amount)] as const);
  const currentDue = dated.length ? Math.min(dated.filter(([d]) => d.slice(0, 10) <= today).reduce((t, [, a]) => t + a, 0), sale.due_amount) : sale.due_amount;

  // when each installment was paid: the date of the latest payment that went to it (fully or in part)
  const paidOn = new Map<number, string>();
  for (const pay of sale.payments ?? []) {
    if (!pay.installment_id) continue;
    const before = paidOn.get(pay.installment_id);
    if (!before || pay.paid_at > before) paidOn.set(pay.installment_id, pay.paid_at);
  }
  for (const i of sale.plan?.installments ?? []) if (!paidOn.has(i.id) && i.paid_at && i.paid_amount > 0) paidOn.set(i.id, i.paid_at);

  const instCols: Col<Installment>[] = [
    // number 0 is the promised amount (paid by its date, outside the installments)
    { header: "#", cell: ({ row }) => (row.original.seq_no === 0 ? "Promise" : row.original.seq_no) },
    { header: "Due date", cell: ({ row }) => fmtDate(row.original.due_date) },
    { header: "Amount", cell: ({ row }) => money(row.original.amount) },
    { header: "Paid", cell: ({ row }) => money(row.original.paid_amount) },
    { header: "Remaining", cell: ({ row }) => money(row.original.amount - row.original.paid_amount) },
    { header: "Paid on", cell: ({ row }) => (paidOn.get(row.original.id) ? fmtDate(paidOn.get(row.original.id)!) : "-") },
    { header: "Status", cell: ({ row }) => <StatusBadge status={row.original.status} /> },
  ];
  // one collection spread over several installments is one row: the amounts add up and the installments are listed together
  const payRows: (Payment & { installmentIds: (number | null)[] })[] = [];
  for (const pay of sale.payments ?? []) {
    const same = pay.batch_no ? payRows.find((r) => r.batch_no === pay.batch_no) : undefined;
    if (same) { same.amount += pay.amount; same.installmentIds.push(pay.installment_id); } else payRows.push({ ...pay, installmentIds: [pay.installment_id] });
  }
  const payCols: Col<Payment & { installmentIds: (number | null)[] }>[] = [
    { header: "Date", cell: ({ row }) => fmtDate(row.original.paid_at) },
    { header: "Amount", cell: ({ row }) => <span className={row.original.amount < 0 ? "text-red-600" : ""}>{money(row.original.amount)}</span> },
    { header: "Method", accessorKey: "method" },
    { header: "Installment", cell: ({ row }) => row.original.installmentIds.filter(Boolean).map((id) => `#${installments.find((i) => i.id === id)?.seq_no ?? ""}`).join(", ") || "-" },
    { header: "Reference", cell: ({ row }) => row.original.reference ?? "-" },
    { header: "Memo no.", cell: ({ row }) => row.original.memo_no ?? "-" },
    { header: "Received by", cell: ({ row }) => row.original.receiver?.name ?? "-" },
    { header: "Note", cell: ({ row }) => row.original.note ?? "-" },
  ];
  const promiseCols: Col<Promise_>[] = [
    { header: "Promised date", cell: ({ row }) => fmtDate(row.original.promised_date) },
    { header: "Amount", cell: ({ row }) => money(row.original.promised_amount) },
    { header: "Status", cell: ({ row }) => <StatusBadge status={row.original.status} /> },
    { header: "Note", cell: ({ row }) => row.original.note ?? "-" },
  ];
  const logCols: Col<RegLog>[] = [
    { header: "When", cell: ({ row }) => fmtDate(row.original.changed_at) },
    { header: "Change", cell: ({ row }) => `${label(row.original.from_status)} → ${label(row.original.to_status)}` },
    { header: "By", cell: ({ row }) => row.original.user?.name ?? "-" },
    { header: "Note", cell: ({ row }) => row.original.note ?? "-" },
  ];

  const removeCharge = async (chargeId: number) => {
    if (!confirm("Delete this charge?")) return;
    try {
      const res = await api(`/charges/${chargeId}`, { method: "DELETE" });
      toast.success(res.message);
      reload();
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : "Failed");
    }
  };
  const chargeCols: Col<NonNullable<Sale["charges"]>[number]>[] = [
    { header: "Date", cell: ({ row }) => fmtDate(row.original.charged_on) },
    { header: "Purpose", cell: ({ row }) => row.original.purpose },
    { header: "Amount", cell: ({ row }) => money(row.original.amount) },
    { header: "Paid", cell: ({ row }) => money(row.original.paid ?? 0) },
    { header: "Remaining", cell: ({ row }) => money(row.original.amount - (row.original.paid ?? 0)) },
    { header: "Note", cell: ({ row }) => row.original.note ?? "-" },
    ...(active && can("sales.charge") ? [{
      header: "Action",
      id: "actions",
      cell: ({ row }: { row: { original: NonNullable<Sale["charges"]>[number] } }) => (
        <div className="flex justify-end gap-1.5">
          <Button variant="outline" size="sm" onClick={() => setChargeFor(row.original.id)}><Pencil className="size-3.5 text-emerald-600" /> Edit</Button>
          {!(row.original.paid ?? 0) && <Button variant="danger" size="sm" onClick={() => removeCharge(row.original.id)}><Trash2 className="size-3.5" /> Delete</Button>}
        </div>
      ),
    }] : []),
  ];
  const editCols: Col<NonNullable<Sale["edits"]>[number]>[] = [
    { header: "When", cell: ({ row }) => new Date(row.original.at).toLocaleString("en-GB", { day: "2-digit", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" }) },
    { header: "By", cell: ({ row }) => row.original.by ?? "-" },
    {
      header: "Changes",
      cell: ({ row }) => (
        <ul className="space-y-0.5">
          {row.original.changes.map((c) => (
            <li key={c.field}><span className="font-medium">{c.field}:</span> <span className="text-slate-500">{c.from ?? "-"}</span> → <b>{c.to ?? "-"}</b></li>
          ))}
        </ul>
      ),
    },
  ];

  return (
    <>
      <PageHeader
        title={`Sale #${sale.id}`}
        actions={<>
          <Button variant="outline" onClick={() => openSaleMemo(sale, user?.shop?.name ?? "Gari Becho", { installments: false })}><Printer className="size-4 text-sky-600" /> Memo</Button>
          {active && can("payments.collect") && sale.due_amount > 0 && <Button onClick={() => setModal("pay")}><HandCoins className="size-4 " /> Collect payment</Button>}
          {active && can("promises.manage") && sale.due_amount > 0 && sale.payment_type === "due" && <Button variant="outline" onClick={() => setModal("promise")}><CalendarClock className="size-4 text-amber-500" /> Add promise</Button>}
          {active && can("sales.charge") && <Button variant="outline" onClick={() => setChargeFor("new")}><Plus className="size-4 text-amber-600" /> Add charge</Button>}
          {active && can("sales.create") && sale.payment_type !== "cash" && !sale.investor && !sale.investor_sale && (
            <Link className="inline-flex items-center gap-1.5 rounded-lg border border-sky-300 bg-white px-3 py-2 text-sm font-medium text-sky-700 hover:bg-sky-50" href={`/sales/new?tab=investor&vehicle=${sale.vehicle_id}`}><Landmark className="size-4" /> Add investor sale</Link>
          )}
          {active && can("sales.edit") && <Button variant="outline" onClick={() => setModal("edit")}><Pencil className="size-4 text-emerald-600" /> Edit</Button>}
          {active && can("sales.cancel") && <Button variant="danger" onClick={() => setModal("cancel")}><Ban className="size-4 " /> Cancel sale</Button>}
        </>}
      />
      {!active && <div className="mb-4 rounded border border-red-200 bg-red-50 p-3 text-sm text-red-800">This sale was cancelled: {sale.cancel_reason}</div>}

      <Card className="mb-4">
        <KV items={[
          ["Vehicle", <Link key="v" className="underline" href={`/vehicles/${sale.vehicle_id}`}>{sale.vehicle?.chassis_no}</Link>],
          ["Buyer", <Link key="b" className="underline" href={`/customers/${sale.customer_id}`}>{sale.customer?.name}</Link>],
          ...(sale.additional_customer ? [["Additional customer", <Link key="ac" className="underline" href={`/customers/${sale.additional_customer.id}`}>{sale.additional_customer.name}{sale.additional_customer.phone ? ` · ${sale.additional_customer.phone}` : ""}</Link>] as [string, React.ReactNode]] : []),
          ["Sale date", fmtDate(sale.sale_date)], ["Sale price", money(sale.sale_price)], ["Down payment", money(Number(sale.down_payment) - Number(sale.advance?.amount ?? 0))],
          ["Type", <Badge key="t" tone="blue">{sale.payment_type}</Badge>], ["Status", <StatusBadge key="s" status={sale.status} />],
          ...(active && sale.payment_type !== "cash" ? [
            ["Total due", <b key="td" className={sale.due_amount > 0 ? "text-amber-700" : "text-emerald-700"}>{money(sale.due_amount)}</b>] as [string, React.ReactNode],
            ["Current due", <b key="cd" className={currentDue > 0 ? "text-rose-700" : "text-emerald-700"}>{money(Math.round(currentDue * 100) / 100)}</b>] as [string, React.ReactNode],
          ] : []),
          ...(sale.advance ? [[sale.advance.booking_id ? "Booking advance" : "Advance", `${money(sale.advance.amount)} received on ${fmtDate(sale.advance.date)} (${sale.advance.method})`] as [string, React.ReactNode]] : []),
          ...(sale.memo_no ? [["Memo no.", sale.memo_no] as [string, React.ReactNode]] : []),
          ...(sale.guarantor_name ? [["Guarantor name", sale.guarantor_name] as [string, React.ReactNode]] : []),
          ...(sale.guarantor_phone ? [["Guarantor", <a key="g" className="underline" href={`tel:${sale.guarantor_phone}`}>{sale.guarantor_phone}</a>] as [string, React.ReactNode]] : []),
          ...(sale.investor ? [["Investor", <Link key="i" className="underline" href={`/investors/${sale.investor.id}`}>{sale.investor.name}</Link>] as [string, React.ReactNode]] : []),
          ...(sale.plan ? [["Plan", `${money(sale.plan.principal)} + ${money(sale.plan.interest_amount)} = ${money(sale.plan.total_payable)}`] as [string, React.ReactNode]] : []),
        ]} />
      </Card>

      {sale.booking && (
        <Card title={`Booking history · #${sale.booking.id}`} className="mb-4">
          <KV items={[
            ["Booked on", fmtDate(sale.booking.booked_on)],
            ["Booked price", sale.booking.agreed_price ? money(sale.booking.agreed_price) : "Not fixed"],
            ["Advance", `${money(sale.booking.advance_amount)} (${label(sale.booking.advance_method)})`],
            ["Held until", sale.booking.expires_on ? fmtDate(sale.booking.expires_on) : "No deadline"],
            ["Converted to sale", fmtDate(sale.sale_date)],
            ...(sale.booking.booked_by ? [["Booked by", sale.booking.booked_by] as [string, React.ReactNode]] : []),
            ...(sale.booking.note ? [["Note", sale.booking.note] as [string, React.ReactNode]] : []),
          ]} />
        </Card>
      )}

      {/* paying the investor, collecting from them and their schedule are on the investor sale's own page */}
      {sale.investor_sale && <InvestorSaleCard investorSale={sale.investor_sale} onChanged={reload} summary />}

      <Card title="Registration" className="mb-4"><RegistrationStepper sale={sale} onChanged={reload} /></Card>

      {sale.plan && (<><h2 className="mb-2 font-semibold">Installments</h2><DataTable columns={instCols} data={installments} /></>)}
      {sale.payment_type === "due" && (<><h2 className="mb-2 mt-6 font-semibold">Promises</h2><DataTable columns={promiseCols} data={sale.promises ?? []} empty="No promises." /></>)}
      <h2 className="mb-2 mt-6 font-semibold">Payments</h2>
      <DataTable columns={payCols} data={payRows} empty="No payments yet." />
      {(sale.charges?.length ?? 0) > 0 && (<><h2 className="mb-2 mt-6 font-semibold">Charges</h2><DataTable columns={chargeCols} data={sale.charges ?? []} /></>)}
      <h2 className="mb-2 mt-6 font-semibold">Edit history</h2>
      <DataTable columns={editCols} data={sale.edits ?? []} empty="This sale has not been edited." />
      <h2 className="mb-2 mt-6 font-semibold">Registration log</h2>
      <DataTable columns={logCols} data={sale.registration_logs ?? []} empty="No registration changes yet." />

      <CollectPaymentModal open={modal === "pay"} sale={sale} onClose={() => setModal(null)} onDone={reload} />
      <PromiseModal open={modal === "promise"} sale={sale} onClose={() => setModal(null)} onDone={reload} />
      <SaleChargeModal open={chargeFor !== null} saleId={sale.id} chargeId={typeof chargeFor === "number" ? chargeFor : null} onClose={() => setChargeFor(null)} onDone={reload} />
      <SaleEditModal open={modal === "edit"} sale={sale} onClose={() => setModal(null)} onDone={reload} />
      <CancelSaleModal open={modal === "cancel"} sale={sale} onClose={() => setModal(null)} onDone={reload} />
    </>
  );
}
