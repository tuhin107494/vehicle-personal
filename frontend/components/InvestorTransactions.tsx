"use client";

import { useState } from "react";
import { fmtDate, label, money } from "@/lib/format";
import { useFetch } from "@/lib/hooks";
import { useAuth } from "@/lib/auth";
import { ErrorText, Field, inputCls, Stat } from "@/components/app-ui";
import { PrintButton } from "@/components/PrintButton";
import { ResponsiveFilters } from "@/components/ResponsiveFilters";
import { Col, DataTable } from "@/components/DataTable";
import { InvestorSelect } from "@/components/Selects";
import { Input } from "@/components/ui/input";
import { NativeSelect } from "@/components/ui/native-select";

type Row = { id: number; date: string; investor_id: number; investor: string; phone: string | null; type: string; amount: number; note: string | null; auto: boolean };
type Data = { rows: Row[]; totals: { investment: number; principal_return: number; profit_share: number; withdrawal: number } };

const monthStart = () => new Date(new Date().getFullYear(), new Date().getMonth(), 1).toLocaleDateString("en-CA");
const today = () => new Date().toLocaleDateString("en-CA");

/** Every investor transaction: money in, principal returned, profit paid and withdrawals. Used on the Reports page and as its own menu page. */
export function InvestorTransactions() {
  const { user } = useAuth();
  const [from, setFrom] = useState(monthStart());
  const [to, setTo] = useState(today());
  const [investor, setInvestor] = useState("");
  const [type, setType] = useState("");
  const [printing, setPrinting] = useState(false); // while printing every row shows, not just this page
  const qs = new URLSearchParams({ ...(from && { from }), ...(to && { to }), ...(investor && { investor_id: investor }), ...(type && { type }) });
  const { data, loading, error } = useFetch<Data>(`/reports/investor-transactions?${qs}`);

  const cols: Col<Row>[] = [
    { header: "Date", cell: ({ row }) => fmtDate(row.original.date) },
    { header: "Investor", cell: ({ row }) => <span className="font-medium">{row.original.investor}</span> },
    { header: "Type", cell: ({ row }) => <span className="capitalize">{label(row.original.type)}</span> },
    { header: "In (invested)", cell: ({ row }) => row.original.type === "investment" ? <b className="text-emerald-700 print:text-black">{money(row.original.amount)}</b> : "" },
    { header: "Out (paid)", cell: ({ row }) => row.original.type !== "investment" ? <b className="text-amber-700 print:text-black">{money(row.original.amount)}</b> : "" },
    { header: "Note", cell: ({ row }) => row.original.note ?? "-" },
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
        <p className="text-lg font-bold">{user?.shop?.name ?? "Vehicle Hub"}</p>
        <p className="text-base font-semibold">Investor transactions</p>
        <p className="text-xs text-slate-500">Period: {from ? fmtDate(from) : "Beginning"} to {to ? fmtDate(to) : "today"}</p>
      </div>

      {error && <ErrorText message={error} />}
      {data && (
        <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4 print:hidden">
          <Stat color="green" label="Invested" value={money(data.totals.investment)} />
          <Stat color="blue" label="Principal returned" value={money(data.totals.principal_return)} />
          <Stat color="amber" label="Profit paid" value={money(data.totals.profit_share)} />
          <Stat color="slate" label="Withdrawn" value={money(data.totals.withdrawal)} />
        </div>
      )}
      <DataTable columns={cols} data={data?.rows ?? []} loading={loading} empty="No investor transactions in this period." pageSize={printing ? 100000 : 15} />
    </>
  );
}
