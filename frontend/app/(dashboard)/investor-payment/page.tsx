"use client";

import { useMemo, useState } from "react";
import Link from "next/link";
import { Wallet } from "lucide-react";
import { money } from "@/lib/format";
import { useFetch } from "@/lib/hooks";
import { useAuth } from "@/lib/auth";
import { Button, ErrorText, inputCls, PageHeader, Stat } from "@/components/app-ui";
import { Input } from "@/components/ui/input";
import { Col, DataTable } from "@/components/DataTable";
import { InvestorSelect } from "@/components/Selects";
import { SettlementForm } from "@/components/forms/SettlementForm";
import type { Investor } from "@/lib/types";

type Row = {
  plan_id: number; sale_id: number; vehicle: string; vehicle_name: string; buyer: string;
  principal: number; principal_paid: number; principal_due: number; profit: number; profit_paid: number; profit_due: number;
};
type Data = { rows: Row[]; totals: { principal_due: number; profit_due: number; paid: number } };

/** Pay an investor back vehicle by vehicle: what each financed vehicle owes in principal and profit, and a button to pay it. */
export default function InvestorPaymentPage() {
  const { can } = useAuth();
  const [investorId, setInvestorId] = useState("");
  const investor = useFetch<Investor>(investorId ? `/investors/${investorId}` : null);
  const { data, loading, error, reload } = useFetch<Data>(investorId ? `/investors/${investorId}/vehicle-payments` : null);
  const [pay, setPay] = useState<Row | null>(null);
  const [q, setQ] = useState("");
  const payDefaults = useMemo(() => ({ principal: pay?.principal_due, profit: pay?.profit_due }), [pay]); // a stable object, so typing in the form is not reset

  const cols: Col<Row>[] = [
    { header: "Vehicle", cell: ({ row }) => <span><Link className="font-medium underline" href={`/sales/${row.original.sale_id}`}>{row.original.vehicle}</Link> <span className="text-xs text-slate-500">{row.original.vehicle_name}</span></span> },
    { header: "Buyer", cell: ({ row }) => row.original.buyer },
    { header: "Principal", cell: ({ row }) => money(row.original.principal) },
    { header: "Principal paid", cell: ({ row }) => money(row.original.principal_paid) },
    { header: "Principal due", cell: ({ row }) => <b className={row.original.principal_due > 0 ? "text-amber-700" : ""}>{money(row.original.principal_due)}</b> },
    { header: "Profit", cell: ({ row }) => money(row.original.profit) },
    { header: "Profit paid", cell: ({ row }) => money(row.original.profit_paid) },
    { header: "Profit due", cell: ({ row }) => <b className={row.original.profit_due > 0 ? "text-amber-700" : ""}>{money(row.original.profit_due)}</b> },
    { header: "Action", id: "pay", cell: ({ row }) => can("settlements.manage") && (row.original.principal_due > 0 || row.original.profit_due > 0) && (
      <Button variant="outline" size="sm" className="border-violet-300 bg-violet-50 text-violet-700 hover:bg-violet-100 hover:text-violet-800" onClick={() => setPay(row.original)}><Wallet className="size-4" /> Pay</Button>
    ) },
  ];

  return (
    <>
      <PageHeader title="Investor payment" />
      <div className="mb-4 max-w-md"><InvestorSelect filter value={investorId} onChange={(id) => { setInvestorId(id); setQ(""); }} /></div>
      {!investorId && <p className="rounded-xl bg-white/90 py-8 text-center text-sm text-slate-500 ring-1 ring-emerald-300">Select an investor to see what each vehicle owes.</p>}
      {error && <ErrorText message={error} />}
      {data && (
        <div className="mb-4 grid grid-cols-3 gap-3">
          <Stat color="amber" label="Principal due" value={money(data.totals.principal_due)} />
          <Stat color="blue" label="Profit due" value={money(data.totals.profit_due)} />
          <Stat color="green" label="Paid so far" value={money(data.totals.paid)} />
        </div>
      )}
      {investorId && <Input className={`${inputCls} mb-3 max-w-xs`} placeholder="Search chassis, vehicle or buyer" value={q} onChange={(e) => setQ(e.target.value)} />}
      {investorId && <DataTable columns={cols} data={(data?.rows ?? []).filter((r) => `${r.vehicle} ${r.vehicle_name} ${r.buyer}`.toLowerCase().includes(q.trim().toLowerCase()))} loading={loading} empty="This investor has not financed any active sale." />}
      {pay && investor.data && (
        <SettlementForm open mode="pay" name={`${investor.data.name} · ${pay.vehicle}`} investorId={investor.data.id} planId={pay.plan_id} outstanding={pay.principal_due}
          defaults={payDefaults} onClose={() => setPay(null)} onSaved={reload} />
      )}
    </>
  );
}
