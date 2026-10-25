"use client";

import { viewCol } from "@/components/ViewLink";
import { useState } from "react";
import { useParams } from "next/navigation";
import { useAuth } from "@/lib/auth";
import { fmtDate, label, money } from "@/lib/format";
import { useFetch } from "@/lib/hooks";
import type { Investor, Sale, Settlement, Summary } from "@/lib/types";
import { Button, Card, ErrorText, KV, Loading, PageHeader, Stat, StatusBadge } from "@/components/app-ui";
import { Avatar } from "@/components/Avatar";
import { Col, DataTable } from "@/components/DataTable";
import { InvestorVehiclePay } from "@/components/forms/InvestorVehiclePay";
import { ProfitCalculator } from "@/components/forms/ProfitCalculator";
import { SettlementForm, type SettlementMode } from "@/components/forms/SettlementForm";
import { Calculator, Landmark, PiggyBank, Wallet } from "lucide-react";

type Portfolio = { investor: Investor; summary: Summary; sales: Sale[]; settlements: Settlement[] };

export default function InvestorPortfolioPage() {
  const { id } = useParams<{ id: string }>();
  const { can } = useAuth();
  const { data, loading, error, reload } = useFetch<Portfolio>(`/investors/${id}/portfolio`);
  const [mode, setMode] = useState<SettlementMode | null>(null);
  const [calc, setCalc] = useState(false);
  const [vehicles, setVehicles] = useState(false);
  const [statement, setStatement] = useState(false); // the settlements statement stays hidden until asked for
  const due = useFetch<{ totals: { current_due: number; installments_due: number } }>(`/investors/${id}/vehicle-payments`);

  if (loading && !data) return <Loading />;
  if (error || !data) return <ErrorText message={error ?? "Not found"} />;
  const { investor, summary, sales, settlements } = data;

  const saleCols: Col<Sale>[] = [
    { header: "Sale", cell: ({ row }) => <span className="font-medium">#{row.original.id}</span> },
    { header: "Vehicle", cell: ({ row }) => row.original.vehicle?.chassis_no },
    { header: "Buyer", cell: ({ row }) => row.original.customer?.name },
    { header: "Financed", cell: ({ row }) => money(row.original.plan?.principal) },
    { header: "Payable", cell: ({ row }) => money(row.original.plan?.total_payable) },
    { header: "Due", cell: ({ row }) => money(row.original.due_amount) },
    { header: "Status", cell: ({ row }) => <StatusBadge status={row.original.status} /> },
    viewCol<Sale>((x) => `/sales/${x.id}`),
  ];
  const setCols: Col<Settlement>[] = [
    { header: "Date", cell: ({ row }) => fmtDate(row.original.date) },
    { header: "Type", cell: ({ row }) => label(row.original.type) },
    { header: "Amount", cell: ({ row }) => money(row.original.amount) },
    { header: "Note", cell: ({ row }) => row.original.note ?? "-" },
  ];

  return (
    <>
      <PageHeader title={investor.name} actions={(
        <div className="flex flex-wrap gap-2">
          {can("settlements.manage") && <Button onClick={() => setMode("invest")}><PiggyBank className="size-4" /> Add investment</Button>}
          {can("settlements.manage") && <Button variant="outline" onClick={() => setVehicles(true)}><Wallet className="size-4 text-sky-600" /> Pay installment</Button>}
          <Button variant="outline" onClick={() => setCalc(true)}><Calculator className="size-4 text-sky-600" /> Profit calculation</Button>
          {can("settlements.manage") && <Button variant="outline" onClick={() => setMode("other")}><Landmark className="size-4 text-emerald-600" /> Other</Button>}
        </div>
      )} />
      <div className="mb-4 grid grid-cols-2 gap-3 md:max-w-2xl md:grid-cols-4">
        <Stat color="blue" label="Vehicles" value={summary.sales_count} />
        <Stat color="green" label="Total amount" value={money(summary.invested)} />
        <Stat color="slate" label="Installments due" value={due.data?.totals.installments_due ?? "-"} />
        <Stat color="amber" label="Due amount" value={due.data ? money(due.data.totals.current_due) : "-"} />
      </div>
      <Card className="mb-4"><div className="mb-3"><Avatar name={investor.name} url={investor.photo_url} size="lg" /></div><KV items={[["Phone", investor.phone], ["Alt. phone", investor.alt_phone], ["NID", investor.nid], ["Opening balance", money(investor.opening_balance)], ["Bank", investor.bank_name], ["Branch", investor.bank_branch], ["Account name", investor.bank_account_name], ["Account no", investor.bank_account_no], ["Other bank details", investor.bank_info], ["Address", investor.address]]} /></Card>
      <h2 className="mb-2 font-semibold">Assigned sales</h2>
      <DataTable columns={saleCols} data={sales} empty="No sales assigned." />
      <div className="mb-2 mt-6 flex items-center justify-between gap-2">
        <h2 className="font-semibold">Statement <span className="text-sm font-normal text-slate-500">({settlements.length})</span></h2>
        <Button variant="outline" onClick={() => setStatement((v) => !v)}>{statement ? "Hide statement" : "Show statement"}</Button>
      </div>
      {statement && <DataTable columns={setCols} data={settlements} empty="No settlements yet." />}
      <ProfitCalculator key={`${investor.id}-${calc}`} open={calc} name={investor.name} initial={{ amount: summary.invested, profit: summary.profit_earned }} investorId={investor.id} saved={investor} canSave={can("investors.edit")} onSaved={reload} onClose={() => setCalc(false)} />
      {vehicles && <InvestorVehiclePay investorId={investor.id} name={investor.name} onClose={() => setVehicles(false)} onSaved={reload} />}
      <SettlementForm open={!!mode} mode={mode ?? "other"} investorId={investor.id} outstanding={summary.outstanding} onClose={() => setMode(null)} onSaved={reload} />
    </>
  );
}
