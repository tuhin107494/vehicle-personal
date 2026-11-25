"use client";

import { useState } from "react";
import { fmtDate, label, money, yearAgoStr } from "@/lib/format";
import { useFetch } from "@/lib/hooks";
import { useUrlState } from "@/lib/url-state";
import { useAuth } from "@/lib/auth";
import { Button, ErrorText, Field, inputCls, Modal, Stat } from "@/components/app-ui";
import { toast } from "sonner";
import { api, ApiError } from "@/lib/api";
import { PrintButton } from "@/components/PrintButton";
import { ResponsiveFilters } from "@/components/ResponsiveFilters";
import { Col, DataTable } from "@/components/DataTable";
import { InvestorSelect } from "@/components/Selects";
import { Input } from "@/components/ui/input";
import { viewCol } from "@/components/ViewLink";
import { printVoucher } from "@/components/TransactionVoucher";
import { NativeSelect } from "@/components/ui/native-select";

type Row = { id: number; date: string; investor_id: number; investor: string; phone: string | null; type: string; amount: number; note: string | null; auto: boolean; batch?: string | null; investor_sale_id?: number | null; plan_id?: number | null };
/** A line of the report: one entry, or one installment payment (its principal and profit together). */
type Line = Row & { principal?: number; profit?: number; principalId?: number; profitId?: number };

/** The principal and profit saved together for one installment payment become one line, with the split under it. */
function mergeBatches(rows: Row[]): Line[] {
  const out: Line[] = [];
  const byBatch = new Map<string, Line>();
  for (const r of rows) {
    const part = r.type === "principal_return" ? "principal" : r.type === "profit_share" ? "profit" : null;
    if (!r.batch || !part) { out.push(r); continue; }
    const line = byBatch.get(r.batch);
    // one payment has one principal part and one profit part: a second of either is another payment
    if (line && line[part] === undefined) {
      line.amount = Math.round((line.amount + r.amount) * 100) / 100;
      line[part] = (line[part] ?? 0) + r.amount;
      line[part === "principal" ? "principalId" : "profitId"] = r.id;
      line.type = "installment_paid";
    } else {
      const fresh: Line = { ...r, [part]: r.amount, [part === "principal" ? "principalId" : "profitId"]: r.id };
      byBatch.set(r.batch, fresh);
      out.push(fresh);
    }
  }
  return out;
}
type Data = { rows: Row[]; totals: { investment: number; principal_return: number; profit_share: number; withdrawal: number } };

const today = () => new Date().toLocaleDateString("en-CA");

/** Every investor transaction: money in, principal returned, profit paid and withdrawals. Used on the Reports page and as its own menu page. */
const KIND: Record<string, { title: string; what: string; in: boolean }> = {
  investment: { title: "Investor money received", what: "Money received from the investor", in: true },
  installment_paid: { title: "Investor payment", what: "Installment paid to the investor", in: false },
  principal_return: { title: "Investor payment", what: "Principal paid back to the investor", in: false },
  profit_share: { title: "Investor payment", what: "Profit paid to the investor", in: false },
  withdrawal: { title: "Investor payment", what: "Withdrawal by the investor", in: false },
};

/** The voucher for one line: who, when, what and how much. */
function voucherOf(x: Line) {
  const kind = KIND[x.type] ?? { title: "Investor transaction", what: label(x.type), in: false };
  return {
    no: `INV-${x.id}`, date: x.date, person: x.investor, phone: x.phone, description: kind.what, vehicle: null, reference: null,
    charge: 0, paid: x.amount,
    details: [
      ...(x.investor_sale_id ? [["Investor sale", `#${x.investor_sale_id}`] as [string, string]] : []),
      ...(x.note ? [["Note", x.note] as [string, string]] : []),
    ],
    title: kind.title, personLabel: "Investor", amountLabel: kind.in ? "Amount received" : "Amount paid",
  };
}

/** Edit one line: its amount, date and note. An installment keeps its principal/profit split; both entries are saved. */
function EditEntry({ x, onClose, onSaved }: { x: Line; onClose: () => void; onSaved: () => void }) {
  const [amount, setAmount] = useState(String(x.amount));
  const [date, setDate] = useState(x.date);
  const [note, setNote] = useState(x.note ?? "");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const save = async () => {
    const total = Number(amount);
    if (!(total > 0)) return setError("Amount must be greater than zero.");
    const base = { investor_id: x.investor_id, date, note: note.trim() || null, ...(x.investor_sale_id ? { investor_sale_id: x.investor_sale_id } : {}), ...(x.plan_id ? { plan_id: x.plan_id } : {}) };
    // an installment: the new total split in the same proportion as before
    const parts: [number, string, number][] = x.type === "installment_paid" && x.principalId && x.profitId
      ? (() => {
          const principal = Math.round((total * (x.principal ?? 0)) / x.amount * 100) / 100;
          return [[x.principalId, "principal_return", principal], [x.profitId, "profit_share", Math.round((total - principal) * 100) / 100]];
        })()
      : [[x.id, x.type, total]];
    setBusy(true);
    try {
      for (const [id, type, value] of parts) await api(`/investor-settlements/${id}`, { method: "PUT", body: { ...base, type, amount: value } });
      toast.success("Saved");
      onSaved();
      onClose();
    } catch (e) {
      setError(e instanceof ApiError ? e.message : "Failed");
    } finally {
      setBusy(false);
    }
  };
  return (
    <Modal open title={`Edit · ${x.investor}`} onClose={onClose}>
      <div className="space-y-3">
        <p className="text-sm text-slate-600">{KIND[x.type]?.what ?? label(x.type)}{x.investor_sale_id ? ` · investor sale #${x.investor_sale_id}` : ""}</p>
        <Field required label="Amount" error={error ?? undefined}><Input type="number" step="0.01" className={inputCls} value={amount} onChange={(e) => setAmount(e.target.value)} /></Field>
        <Field required label="Date"><Input type="date" max={new Date().toLocaleDateString("en-CA")} className={inputCls} value={date} onChange={(e) => setDate(e.target.value)} /></Field>
        <Field label="Note"><Input className={inputCls} value={note} onChange={(e) => setNote(e.target.value)} /></Field>
        <div className="flex justify-end gap-2">
          <Button variant="outline" onClick={onClose} disabled={busy}>Close</Button>
          <Button onClick={save} disabled={busy}>{busy ? "Saving..." : "Save"}</Button>
        </div>
      </div>
    </Modal>
  );
}

export function InvestorTransactions() {
  const { user } = useAuth();
  // the filters and the table's page live in the address, so a refresh or an edit keeps them
  const [from, setFrom] = useUrlState("from", yearAgoStr());
  const [to, setTo] = useUrlState("to", today());
  const [investor, setInvestor] = useUrlState("investor", "");
  const [type, setType] = useUrlState("type", "");
  const [printing, setPrinting] = useState(false); // while printing every row shows, not just this page
  const qs = new URLSearchParams({ ...(from && { from }), ...(to && { to }), ...(investor && { investor_id: investor }), ...(type && { type }) });
  const { data, loading, error, reload } = useFetch<Data>(`/reports/investor-transactions?${qs}`);
  const { can } = useAuth();
  const [editing, setEditing] = useState<Line | null>(null);

  const lines = mergeBatches(data?.rows ?? []);
  const cols: Col<Line>[] = [
    { header: "Date", cell: ({ row }) => fmtDate(row.original.date) },
    { header: "Investor", cell: ({ row }) => <span className="font-medium">{row.original.investor}</span> },
    {
      header: "Type",
      cell: ({ row }) => row.original.type === "installment_paid" ? "Installment paid" : <span className="capitalize">{label(row.original.type)}</span>,
    },
    { header: "In (invested)", cell: ({ row }) => row.original.type === "investment" ? <b className="text-emerald-700 print:text-black">{money(row.original.amount)}</b> : "" },
    { header: "Out (paid)", cell: ({ row }) => row.original.type !== "investment" ? <b className="text-amber-700 print:text-black">{money(row.original.amount)}</b> : "" },
    { header: "Note", cell: ({ row }) => row.original.note ?? "-" },
    // View opens the investor sale it belongs to (or the investor); Print gives a voucher for just this entry
    viewCol<Line>((x) => (x.investor_sale_id ? `/investor-sales/${x.investor_sale_id}` : `/investors/${x.investor_id}`), (x) => printVoucher(voucherOf(x), "supplier", user?.shop?.name ?? "Gari Becho"),
      // entries made automatically (with an investor sale or a customer collection) change only with what made them
      can("settlements.manage") ? (x) => (x.auto ? undefined : () => setEditing(x)) : undefined),
  ];

  const print = () => {
    const old = document.title;
    document.title = "Investor transactions";
    setPrinting(true);
    setTimeout(() => {
      window.print();
      document.title = old;
      setPrinting(false);
    }, 150);
  };

  return (
    <>
      <PrintButton disabled={!data} onClick={print} />
      <ResponsiveFilters desktopClass="mb-4 hidden gap-3 lg:grid lg:grid-cols-4">
        <Field label="From"><Input type="date" className={inputCls} value={from} max={to || undefined} onChange={(e) => setFrom(e.target.value)} /></Field>
        <Field label="To"><Input type="date" className={inputCls} value={to} min={from || undefined} onChange={(e) => setTo(e.target.value)} /></Field>
        <InvestorSelect filter value={investor} onChange={setInvestor} />
        <Field label="Type">
          <NativeSelect className={inputCls} value={type} onChange={(e) => setType(e.target.value)}>
            <option value="">All</option>
            <option value="investment">Investment (money in)</option>
            <option value="principal_return">Principal return</option>
            <option value="profit_share">Profit share</option>
            <option value="withdrawal">Withdrawal</option>
          </NativeSelect>
        </Field>
      </ResponsiveFilters>

      <div className="mb-4 hidden border-b border-slate-300 pb-3 print:block">
        <p className="text-lg font-bold">{user?.shop?.name ?? "Gari Becho"}</p>
        <p className="text-base font-semibold">Investor transactions</p>
        <p className="text-xs text-slate-500">Period: {from ? fmtDate(from) : "Beginning"} to {to ? fmtDate(to) : "today"}</p>
      </div>

      {error && <ErrorText message={error} />}
      {data && (
        <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-5 print:hidden">
          <Stat color="green" label="Invested" value={money(data.totals.investment)} />
          {/* what the shop paid the investors in all: the principal returned and the profit, together */}
          <Stat color="indigo" label="Paid to investor" value={money(Math.round((data.totals.principal_return + data.totals.profit_share) * 100) / 100)} />
          <Stat color="blue" label="Principal returned" value={money(data.totals.principal_return)} />
          <Stat color="amber" label="Profit paid" value={money(data.totals.profit_share)} />
          <Stat color="slate" label="Withdrawn" value={money(data.totals.withdrawal)} />
        </div>
      )}
      {editing && <EditEntry x={editing} onClose={() => setEditing(null)} onSaved={reload} />}
      <DataTable urlKey="page" pageKey={qs.toString()} columns={cols} data={lines} loading={loading} empty="No investor transactions in this period." pageSize={printing ? 100000 : undefined} />
    </>
  );
}
