"use client";

import { useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { toast } from "sonner";
import { api, ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { fmtDate, label, money } from "@/lib/format";
import { useFetch } from "@/lib/hooks";
import type { Installment, Payment, Promise_, RegLog, Sale } from "@/lib/types";
import { Badge, Button, Card, ErrorText, KV, Loading, PageHeader, StatusBadge } from "@/components/app-ui";
import { Col, DataTable } from "@/components/DataTable";
import { CancelSaleModal, CollectPaymentModal, PromiseModal } from "@/components/forms/SaleModals";
import { Ban, CalendarClock, HandCoins, Printer } from "lucide-react";
import { openSaleMemo } from "@/components/SaleMemo";

const STEPS = ["pending", "bank_chalan_done", "file_received", "file_processing", "registration_complete", "document_provided"];

function Stepper({ sale, onChanged }: { sale: Sale; onChanged: () => void }) {
  const { can } = useAuth();
  const current = STEPS.indexOf(sale.registration_status);
  const [busy, setBusy] = useState(false);

  const move = async (to: string, back: boolean) => {
    const note = back ? prompt("Reason for moving back a step (required):") : prompt("Note (optional):");
    if (note === null || (back && !note.trim())) return;
    setBusy(true);
    try {
      const res = await api(`/sales/${sale.id}/registration`, { method: "PATCH", body: { status: to, note: note || undefined } });
      toast.success(res.message);
      onChanged();
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : "Failed");
    } finally {
      setBusy(false);
    }
  };

  return (
    <>
      <ol className="flex flex-wrap gap-2">
        {STEPS.map((s, i) => (
          <li key={s} className={`rounded-full border px-3 py-1 text-xs capitalize ${i < current ? "border-green-600 bg-green-50 text-green-800" : i === current ? "border-gray-900 bg-gray-900 text-white" : "border-gray-300 text-gray-500"}`}>
            {i + 1}. {label(s)}
          </li>
        ))}
      </ol>
      {sale.status === "active" && can("registration.update") && (
        <div className="mt-3 flex gap-2">
          {current > 0 && <Button variant="outline" disabled={busy} onClick={() => move(STEPS[current - 1], true)}>Back one step</Button>}
          {current < STEPS.length - 1 && <Button disabled={busy} onClick={() => move(STEPS[current + 1], false)}>Mark “{label(STEPS[current + 1])}”</Button>}
        </div>
      )}
    </>
  );
}

export default function SaleDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { can, user } = useAuth();
  const { data: sale, loading, error, reload } = useFetch<Sale>(`/sales/${id}`);
  const [modal, setModal] = useState<"pay" | "promise" | "cancel" | null>(null);

  if (loading && !sale) return <Loading />;
  if (error || !sale) return <ErrorText message={error ?? "Not found"} />;

  const active = sale.status === "active";
  const installments = sale.plan?.installments ?? [];

  const instCols: Col<Installment>[] = [
    { header: "#", accessorKey: "seq_no" },
    { header: "Due date", cell: ({ row }) => fmtDate(row.original.due_date) },
    { header: "Amount", cell: ({ row }) => money(row.original.amount) },
    { header: "Paid", cell: ({ row }) => money(row.original.paid_amount) },
    { header: "Remaining", cell: ({ row }) => money(row.original.amount - row.original.paid_amount) },
    { header: "Status", cell: ({ row }) => <StatusBadge status={row.original.status} /> },
  ];
  const payCols: Col<Payment>[] = [
    { header: "Date", cell: ({ row }) => fmtDate(row.original.paid_at) },
    { header: "Amount", cell: ({ row }) => <span className={row.original.amount < 0 ? "text-red-600" : ""}>{money(row.original.amount)}</span> },
    { header: "Method", accessorKey: "method" },
    { header: "Installment", cell: ({ row }) => (row.original.installment_id ? `#${installments.find((i) => i.id === row.original.installment_id)?.seq_no ?? ""}` : "-") },
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

  return (
    <>
      <PageHeader
        title={`Sale #${sale.id}`}
        actions={<>
          <Button variant="outline" onClick={() => openSaleMemo(sale, user?.shop?.name ?? "Vehicle Hub", { installments: false })}><Printer className="size-4 text-sky-600" /> Memo</Button>
          {active && can("payments.collect") && sale.due_amount > 0 && <Button onClick={() => setModal("pay")}><HandCoins className="size-4 " /> Collect payment</Button>}
          {active && can("promises.manage") && sale.due_amount > 0 && sale.payment_type === "due" && <Button variant="outline" onClick={() => setModal("promise")}><CalendarClock className="size-4 text-amber-500" /> Add promise</Button>}
          {active && can("sales.cancel") && <Button variant="danger" onClick={() => setModal("cancel")}><Ban className="size-4 " /> Cancel sale</Button>}
        </>}
      />
      {!active && <div className="mb-4 rounded border border-red-200 bg-red-50 p-3 text-sm text-red-800">This sale was cancelled: {sale.cancel_reason}</div>}

      <Card className="mb-4">
        <KV items={[
          ["Vehicle", <Link key="v" className="underline" href={`/vehicles/${sale.vehicle_id}`}>{sale.vehicle?.chassis_no}</Link>],
          ["Buyer", <Link key="b" className="underline" href={`/customers/${sale.customer_id}`}>{sale.customer?.name}</Link>],
          ["Sale date", fmtDate(sale.sale_date)], ["Sale price", money(sale.sale_price)], ["Down payment", money(Number(sale.down_payment) - Number(sale.advance?.amount ?? 0))],
          ["Type", <Badge key="t" tone="blue">{sale.payment_type}</Badge>], ["Status", <StatusBadge key="s" status={sale.status} />],
          ...(sale.advance ? [[sale.advance.booking_id ? "Booking advance" : "Advance", `${money(sale.advance.amount)} received on ${fmtDate(sale.advance.date)} (${sale.advance.method})`] as [string, React.ReactNode]] : []),
          ...(sale.guarantor_phone ? [["Guarantor", <a key="g" className="underline" href={`tel:${sale.guarantor_phone}`}>{sale.guarantor_phone}</a>] as [string, React.ReactNode]] : []),
          ...(sale.investor ? [["Investor", <Link key="i" className="underline" href={`/investors/${sale.investor.id}`}>{sale.investor.name}</Link>] as [string, React.ReactNode]] : []),
          ...(sale.plan ? [["Plan", `${money(sale.plan.principal)} + ${sale.plan.interest_rate}% = ${money(sale.plan.total_payable)}`] as [string, React.ReactNode]] : []),
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

      <Card title="Registration" className="mb-4"><Stepper sale={sale} onChanged={reload} /></Card>

      {sale.plan && (<><h2 className="mb-2 font-semibold">Installments</h2><DataTable columns={instCols} data={installments} /></>)}
      {sale.payment_type === "due" && (<><h2 className="mb-2 mt-6 font-semibold">Promises</h2><DataTable columns={promiseCols} data={sale.promises ?? []} empty="No promises." /></>)}
      <h2 className="mb-2 mt-6 font-semibold">Payments</h2>
      <DataTable columns={payCols} data={sale.payments ?? []} empty="No payments yet." />
      <h2 className="mb-2 mt-6 font-semibold">Registration log</h2>
      <DataTable columns={logCols} data={sale.registration_logs ?? []} empty="No registration changes yet." />

      <CollectPaymentModal open={modal === "pay"} sale={sale} onClose={() => setModal(null)} onDone={reload} />
      <PromiseModal open={modal === "promise"} sale={sale} onClose={() => setModal(null)} onDone={reload} />
      <CancelSaleModal open={modal === "cancel"} sale={sale} onClose={() => setModal(null)} onDone={reload} />
    </>
  );
}
