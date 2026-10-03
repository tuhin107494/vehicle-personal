"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useFetch, usePagedList } from "@/lib/hooks";
import { useAuth } from "@/lib/auth";
import { money } from "@/lib/format";
import type { Investor } from "@/lib/types";
import { api } from "@/lib/api";
import { toast } from "sonner";
import { Badge, Button, inputCls, PageHeader, Pagination } from "@/components/app-ui";
import { Col, DataTable } from "@/components/DataTable";
import { InvestorForm } from "@/components/forms/InvestorForm";
import { SettlementForm, type SettlementMode } from "@/components/forms/SettlementForm";
import { InvestorVehiclePay } from "@/components/forms/InvestorVehiclePay";
import { ProfitCalculator } from "@/components/forms/ProfitCalculator";
import { Input } from "@/components/ui/input";
import { BellRing, Calculator, Eye, Pencil, PiggyBank, Plus, Wallet } from "lucide-react";

export default function InvestorsPage() {
  const { can } = useAuth();
  const router = useRouter();
  const [tab, setTab] = useState<"all" | "due">("all");
  const list = usePagedList<Investor>("/investors", tab === "due" ? { due: "1" } : {});
  const dueCount = useFetch<Investor[]>("/investors?due=1&per_page=1");
  const [editing, setEditing] = useState<Investor | null>(null);
  const [open, setOpen] = useState(false);
  const [calc, setCalc] = useState(false);
  const [vehiclePay, setVehiclePay] = useState<Investor | null>(null);
  const [settle, setSettle] = useState<{ investor: Investor; mode: SettlementMode } | null>(null);
  const [calcFor, setCalcFor] = useState<string | undefined>();
  const [calcInit, setCalcInit] = useState<{ amount?: number; profit?: number } | undefined>();
  const [calcInv, setCalcInv] = useState<Investor | null>(null);
  const [calcKey, setCalcKey] = useState(0);

  // this investor's own invested amount and profit go straight into the formulas
  const openCalc = async (inv?: Investor) => {
    let init: { amount?: number; profit?: number } | undefined;
    let saved: Investor | null = null;
    if (inv) {
      try {
        const res = await api<{ investor: Investor; summary: { invested: number; profit_earned: number } }>(`/investors/${inv.id}/portfolio`);
        init = { amount: res.data.summary.invested, profit: res.data.summary.profit_earned };
        saved = res.data.investor;
      } catch {
        toast.error("Could not load this investor's figures.");
      }
    }
    setCalcFor(inv?.name);
    setCalcInit(init);
    setCalcInv(saved);
    setCalcKey((k) => k + 1);
    setCalc(true);
  };

  const columns: Col<Investor>[] = [
    { header: "Name", cell: ({ row }) => <span className="font-medium">{row.original.name}</span> },
    { header: "Phone", cell: ({ row }) => [row.original.phone, row.original.alt_phone].filter(Boolean).join(" / ") || "-" },
    { header: "Opening balance", cell: ({ row }) => money(row.original.opening_balance) },
    { header: "Due", cell: ({ row }) => (row.original.current_due ?? 0) > 0 ? <span className="inline-flex flex-wrap items-center gap-1.5"><Badge tone="red">{row.original.installments_due} due</Badge><b className="text-amber-700">{money(row.original.current_due ?? 0)}</b></span> : <span className="text-slate-400">-</span> },
    { header: "Status", cell: ({ row }) => <Badge tone={row.original.is_active ? "green" : "gray"}>{row.original.is_active ? "Active" : "Inactive"}</Badge> },
    {
      header: "Action",
      id: "actions",
      cell: ({ row }) => (
        <div className="flex w-full items-center justify-end gap-1 whitespace-nowrap md:w-auto md:gap-1.5">
          {can("settlements.manage") && <Button className="h-auto min-w-0 flex-1 flex-col gap-0.5 px-1 py-1 text-[11px] md:h-8 md:flex-row md:gap-1.5 md:px-2.5 md:py-0 md:text-xs border-emerald-300 bg-emerald-50 text-emerald-700 hover:bg-emerald-100 hover:text-emerald-800" variant="outline" size="sm" onClick={() => setSettle({ investor: row.original, mode: "invest" })}><PiggyBank className="size-4" /> Invest</Button>}
          {can("settlements.manage") && <Button className="h-auto min-w-0 flex-1 flex-col gap-0.5 px-1 py-1 text-[11px] md:h-8 md:flex-row md:gap-1.5 md:px-2.5 md:py-0 md:text-xs border-violet-300 bg-violet-50 text-violet-700 hover:bg-violet-100 hover:text-violet-800" variant="outline" size="sm" onClick={() => setVehiclePay(row.original)}><Wallet className="size-4" /> Pay</Button>}
          <Button className="h-auto min-w-0 flex-1 flex-col gap-0.5 px-1 py-1 text-[11px] md:h-8 md:flex-row md:gap-1.5 md:px-2.5 md:py-0 md:text-xs border-sky-300 bg-sky-50 text-sky-700 hover:bg-sky-100 hover:text-sky-800" variant="outline" size="sm" onClick={() => openCalc(row.original)}><Calculator className="size-4" /> Profit calc</Button>
          <Button className="h-auto min-w-0 flex-1 flex-col gap-0.5 px-1 py-1 text-[11px] md:h-8 md:flex-row md:gap-1.5 md:px-2.5 md:py-0 md:text-xs border-violet-300 bg-violet-50 text-violet-700 hover:bg-violet-100 hover:text-violet-800" variant="outline" size="sm" onClick={() => router.push(`/investors/${row.original.id}`)}><Eye className="size-4" /> View</Button>
          {can("investors.edit") && <Button className="h-auto min-w-0 flex-1 flex-col gap-0.5 px-1 py-1 text-[11px] md:h-8 md:flex-row md:gap-1.5 md:px-2.5 md:py-0 md:text-xs border-amber-300 bg-amber-50 text-amber-700 hover:bg-amber-100 hover:text-amber-800" variant="outline" size="sm" onClick={() => { setEditing(row.original); setOpen(true); }}><Pencil className="size-4" /> Edit</Button>}
        </div>
      ),
    },
  ];

  return (
    <>
      <PageHeader title="Investors" actions={<div className="flex gap-2"><Button variant="outline" onClick={() => openCalc()}><Calculator className="size-4 text-sky-600" /> Profit calculator</Button>{can("investors.create") && <Button onClick={() => { setEditing(null); setOpen(true); }}><Plus className="size-4 " /> New investor</Button>}</div>} />
      <div className="mb-3 flex gap-2">
        <Button variant={tab === "all" ? "primary" : "outline"} onClick={() => setTab("all")}>All</Button>
        <Button variant="outline" className={tab === "due" ? "border-transparent bg-gradient-to-r from-rose-500 to-red-600 text-white hover:from-rose-400 hover:to-red-500 hover:text-white" : "border-red-300 text-red-700 hover:bg-red-50 hover:text-red-800"} onClick={() => setTab("due")}><BellRing className="size-4" /> Due <span className="ml-1 rounded-full bg-black/10 px-1.5 text-xs">{dueCount.meta?.total ?? 0}</span></Button>
      </div>
      <Input className={`${inputCls} mb-3 max-w-xs`} placeholder="Search name or phone" value={list.search} onChange={(e) => list.setSearch(e.target.value)} />
      <DataTable serverPaged columns={columns} data={list.rows} loading={list.loading} empty={tab === "due" ? "No investor has a due payment." : "No records found."} />
      <Pagination meta={list.meta} onPage={list.setPage} />
      {vehiclePay && <InvestorVehiclePay investorId={vehiclePay.id} name={vehiclePay.name} onClose={() => setVehiclePay(null)} />}
      {settle && <SettlementForm open mode={settle.mode} name={settle.investor.name} investorId={settle.investor.id} onClose={() => setSettle(null)} onSaved={() => {}} />}
      <ProfitCalculator key={calcKey} open={calc} name={calcFor} initial={calcInit} investorId={calcInv?.id} saved={calcInv} canSave={can("investors.edit")} onClose={() => setCalc(false)} />
      <InvestorForm open={open} investor={editing} onClose={() => setOpen(false)} onSaved={() => (editing ? list.reload() : list.showFirst())} />
    </>
  );
}
