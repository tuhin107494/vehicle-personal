"use client";

import { useMemo, useState } from "react";
import Link from "next/link";
import { toast } from "sonner";
import { ArrowRight, Ban, HandCoins, Wallet } from "lucide-react";
import { api, ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { fmtDate, money, todayStr } from "@/lib/format";
import type { InvestorSale } from "@/lib/types";
import { Badge, Button, Card, Field, inputCls, KV, Modal, StatusBadge } from "@/components/app-ui";
import { Input } from "@/components/ui/input";
import { Col, DataTable } from "@/components/DataTable";
import { SettlementForm } from "@/components/forms/SettlementForm";

type Row = InvestorSale["schedule"][number] & { paidAmount: number; left: number };

/** The rest of the investor's amount, given to the shop after the investor sale: no more than they still owe. */
export function ReceiveModal({ x, owed, onClose, onSaved }: { x: InvestorSale; owed: number; onClose: () => void; onSaved: () => void }) {
  const [amount, setAmount] = useState(String(owed));
  const [date, setDate] = useState(todayStr());
  const [note, setNote] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const save = async () => {
    const n = Number(amount);
    if (!(n > 0)) return setError("Enter the amount received.");
    if (n > owed) return setError(`The investor owes only ${money(owed)}.`);
    setBusy(true);
    try {
      const res = await api("/investor-settlements", { method: "POST", body: { investor_id: x.investor_id, investor_sale_id: x.id, type: "investment", amount: n, date, note: note || undefined } });
      toast.success(res.message ?? "Saved");
      onSaved();
      onClose();
    } catch (e) {
      setError(e instanceof ApiError ? e.message : "Failed");
    } finally {
      setBusy(false);
    }
  };
  return (
    <Modal open title="Receive from investor" onClose={onClose}>
      <div className="space-y-3">
        <p className="text-sm text-slate-600">{x.investor?.name} still owes <b>{money(owed)}</b> on this investor sale.</p>
        <Field required label="Amount" error={error ?? undefined}><Input type="number" step="0.01" className={inputCls} value={amount} onChange={(e) => setAmount(e.target.value)} /></Field>
        <Field required label="Date"><Input type="date" max={todayStr()} className={inputCls} value={date} onChange={(e) => setDate(e.target.value)} /></Field>
        <Field label="Note (optional)"><Input className={inputCls} value={note} onChange={(e) => setNote(e.target.value)} /></Field>
        <div className="flex justify-end gap-2">
          <Button variant="outline" onClick={onClose} disabled={busy}>Close</Button>
          <Button onClick={save} disabled={busy}>{busy ? "Saving..." : "Save"}</Button>
        </div>
      </div>
    </Modal>
  );
}

/** The investor sale on a sale's page: the investor's terms, where the payments to them stand, and their schedule. */
/**
 * investorOnly: just the investor's and the shop's side (on the investor sale's own page), without the customer's price, down payment or guarantor.
 * summary: a short view (on the customer's sale page) with no actions or schedule, and a link to the investor sale's own page.
 */
export function InvestorSaleCard({ investorSale: x, onChanged, investorOnly = false, summary = false, paying: payingFromPage, onPayingChange, receiving: receivingFromPage, onReceivingChange }: { investorSale: InvestorSale; onChanged: () => void; investorOnly?: boolean; summary?: boolean; paying?: boolean; onPayingChange?: (open: boolean) => void; receiving?: boolean; onReceivingChange?: (open: boolean) => void }) {
  const { can } = useAuth();
  const [payingHere, setPayingHere] = useState(false);
  // the page can hold the Pay investor button itself (in its title bar): then it opens the form, and the card has no button of its own
  const payFromPage = onPayingChange !== undefined;
  const paying = payFromPage ? !!payingFromPage : payingHere;
  const setPaying = payFromPage ? onPayingChange : setPayingHere;
  const [receivingHere, setReceivingHere] = useState(false);
  // likewise the page can hold the Collect due button (in its title bar)
  const receiveFromPage = onReceivingChange !== undefined;
  const receiving = receiveFromPage ? !!receivingFromPage : receivingHere;
  const setReceiving = receiveFromPage ? onReceivingChange : setReceivingHere;
  const owes = x.investor_owes ?? 0; // what the investor still has to give the shop of their amount
  const [busy, setBusy] = useState(false);
  const [showSchedule, setShowSchedule] = useState(false);
  const p = x.position;
  const paid = p ? p.principal_paid + p.profit_paid : 0;
  const defaults = useMemo(() => ({ principal: p ? p.principal - p.principal_paid : undefined, profit: p ? p.profit - p.profit_paid : undefined }), [p]);
  // the installment due now, for the pay form (memoised: the form starts over when it changes)
  const due = useMemo(() => (p ? { monthly: p.monthly, current: p.current_due, principalLeft: p.principal - p.principal_paid, profitLeft: p.profit - p.profit_paid } : undefined), [p]);

  // an installment counts as paid once the payments so far cover it and every one before it
  // what has been paid back fills the installments in order (as the stored installments are filled)
  const rows = useMemo(() => {
    const out: Row[] = [];
    let rest = paid;
    for (const r of x.schedule) {
      const paidAmount = Math.round(Math.min(rest, r.amount) * 100) / 100;
      rest = Math.max(rest - paidAmount, 0);
      out.push({ ...r, paidAmount, left: Math.round((r.amount - paidAmount) * 100) / 100 });
    }
    return out;
  }, [x.schedule, paid]);
  const cols: Col<Row>[] = [
    { header: "#", accessorKey: "seq_no" },
    { header: "Date", cell: ({ row }) => fmtDate(row.original.due_date) },
    { header: "Amount", cell: ({ row }) => money(row.original.amount) },
    { header: "Paid", cell: ({ row }) => row.original.paidAmount > 0 ? <span className="text-emerald-700">{money(row.original.paidAmount)}</span> : "-" },
    { header: "Left", cell: ({ row }) => row.original.left > 0.004 ? money(row.original.left) : "-" },
    {
      header: "Status",
      cell: ({ row }) => {
        const r = row.original;
        if (r.left <= 0.004) return <Badge tone="green">Paid</Badge>;
        if (r.due_date <= new Date().toLocaleDateString("en-CA")) return <Badge tone="yellow">Due</Badge>;
        return r.paidAmount > 0 ? <Badge tone="blue">Partly paid</Badge> : <Badge tone="gray">Upcoming</Badge>;
      },
    },
  ];

  const cancel = async () => {
    if (!confirm("Cancel this investor sale? The investor's investment for it is removed.")) return;
    setBusy(true);
    try {
      const res = await api(`/investor-sales/${x.id}/cancel`, { method: "POST" });
      toast.success(res.message);
      onChanged();
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : "Failed");
    } finally {
      setBusy(false);
    }
  };

  return (
    <Card id="investor-sale" title="Investor sale" className="mb-4" actions={summary ? (
      <Link className="inline-flex h-10 items-center gap-1.5 rounded-lg border border-emerald-200 bg-white px-4 text-sm font-medium text-emerald-700 hover:bg-emerald-50 hover:text-emerald-800" href={`/investor-sales/${x.id}`}>Open investor sale <ArrowRight className="size-4" /></Link>
    ) : (
      <div className="flex flex-wrap gap-2">
        {!receiveFromPage && x.status === "active" && can("settlements.manage") && owes > 0 && <Button variant="outline" onClick={() => setReceiving(true)}><HandCoins className="size-4 text-emerald-600" /> Receive from investor</Button>}
        {!payFromPage && can("settlements.manage") && p && p.total_due > 0 && <Button variant="outline" onClick={() => setPaying(true)}><Wallet className="size-4 text-emerald-600" /> Pay investor</Button>}
        {/* on the investor sale's own page Cancel is in the title bar */}
        {!investorOnly && x.status === "active" && can("sales.cancel") && paid === 0 && <Button variant="danger" disabled={busy} onClick={cancel}><Ban className="size-4" /> Cancel</Button>}
      </div>
    )}>
      {/* on the customer's sale page: only who and when, the amounts are on the investor sale's own page */}
      {summary ? (
        <KV items={[
          ["Investor", x.investor ? <Link key="i" className="underline" href={`/investors/${x.investor.id}`}>{x.investor.name}</Link> : "-"],
          ["Date", fmtDate(x.date)],
          ["Installments", `${x.number_of_installments} ${x.frequency === "weekly" ? "weeks" : "months"}`],
          ["Status", <StatusBadge key="s" status={x.status} />],
        ]} />
      ) : <KV items={[
        ["Investor", x.investor ? <Link key="i" className="underline" href={`/investors/${x.investor.id}`}>{x.investor.name}</Link> : "-"],
        ...(x.customer && !investorOnly ? [["Additional customer", <Link key="c" className="underline" href={`/customers/${x.customer.id}`}>{x.customer.name}{x.customer.phone ? ` · ${x.customer.phone}` : ""}</Link>] as [string, React.ReactNode]] : []),
        ["Date", fmtDate(x.date)],
        ...(x.sale_price !== null && !investorOnly ? [["Sale price", money(x.sale_price)] as [string, React.ReactNode]] : []),
        ...(x.down_payment !== null && !investorOnly ? [["Down payment (customer)", `${money(x.down_payment)}${x.method ? ` (${x.method})` : ""}`] as [string, React.ReactNode]] : []),
        ["Investor's money", money(x.principal)],
        // only when the investor has given part of their money (all of it is the same as the line above)
        ...(x.investor_paid !== undefined && owes > 0 ? [["Received from investor", money(x.investor_paid)] as [string, React.ReactNode]] : []),
        ...(owes > 0 ? [["Investor still has to give", <b key="o" className="text-amber-700">{money(owes)}</b>] as [string, React.ReactNode]] : []),
        ...(x.memo_no ? [["Memo no.", x.memo_no] as [string, React.ReactNode]] : []),
        ...(x.guarantor_name && !investorOnly ? [["Guarantor name", x.guarantor_name] as [string, React.ReactNode]] : []),
        ...(x.guarantor_phone && !investorOnly ? [["Guarantor", <a key="g" className="underline" href={`tel:${x.guarantor_phone}`}>{x.guarantor_phone}</a>] as [string, React.ReactNode]] : []),
        ["Investor's profit", `${money(x.profit_amount)} (${x.profit_rate}%)`],
        ["We pay back in total", money(x.total_payable)],
        ["Installments", `${x.number_of_installments} ${x.frequency === "weekly" ? "weeks" : "months"}`],
        ...(p ? [
          ["Paid back so far", money(paid)] as [string, React.ReactNode],
          ["To pay now", <b key="d" className={p.current_due > 0 ? "text-amber-700" : ""}>{money(p.current_due)}</b>] as [string, React.ReactNode],
          ["Left to pay back", money(p.total_due)] as [string, React.ReactNode],
        ] : []),
      ]} />}
      {/* on the investor sale's own page the schedule is always shown; elsewhere it opens on a click */}
      {investorOnly ? (
        <><h3 className="mb-2 mt-4 text-sm font-semibold text-slate-700">Schedule</h3><DataTable columns={cols} data={rows} /></>
      ) : !summary && (
        <>
          <Button variant="outline" className="mt-3" onClick={() => setShowSchedule((v) => !v)}>{showSchedule ? "Hide schedule" : "Show schedule"}</Button>
          {showSchedule && <div className="mt-3"><DataTable columns={cols} data={rows} /></div>}
        </>
      )}
      {receiving && <ReceiveModal x={x} owed={owes} onClose={() => setReceiving(false)} onSaved={onChanged} />}
      {paying && x.investor && (
        <SettlementForm open mode="pay" name={x.investor.name} investorId={x.investor.id} investorSaleId={x.id} outstanding={defaults.principal} defaults={defaults} due={due}
          onClose={() => setPaying(false)} onSaved={onChanged} />
      )}
    </Card>
  );
}
