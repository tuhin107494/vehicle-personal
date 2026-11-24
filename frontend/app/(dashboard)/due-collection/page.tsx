"use client";

import { Suspense, useState } from "react";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { useAuth } from "@/lib/auth";
import { money } from "@/lib/format";
import { useDebounced, useFetch } from "@/lib/hooks";
import { useHiddenMenu } from "@/lib/menu";
import type { InvestorSale } from "@/lib/types";
import { Button, ErrorText, inputCls, PageHeader } from "@/components/app-ui";
import { Col, DataTable } from "@/components/DataTable";
import { AccountForm } from "@/components/forms/AccountForm";
import { ReceiveModal } from "@/components/InvestorSaleCard";
import { Input } from "@/components/ui/input";

/** What investors still have to give the shop on their investor sales, each with a Collect button. */
function InvestorDues() {
  const [q, setQ] = useState("");
  const search = useDebounced(q.trim());
  const { data, loading, error, reload } = useFetch<InvestorSale[]>(`/investor-sales/dues${search ? `?search=${encodeURIComponent(search)}` : ""}`);
  const [collect, setCollect] = useState<InvestorSale | null>(null);
  const rows = data ?? [];
  const total = rows.reduce((t, x) => t + (x.investor_owes ?? 0), 0);

  const cols: Col<InvestorSale>[] = [
    { header: "Investor", cell: ({ row }) => row.original.investor ? <Link className="font-medium underline" href={`/investors/${row.original.investor.id}`}>{row.original.investor.name}</Link> : "-" },
    { header: "Vehicle", cell: ({ row }) => row.original.sale?.vehicle?.chassis_no ?? "-" },
    { header: "Investor sale", cell: ({ row }) => <Link className="underline" href={`/investor-sales/${row.original.id}`}>#{row.original.id}</Link> },
    { header: "Investor's money", cell: ({ row }) => money(row.original.principal) },
    { header: "Received", cell: ({ row }) => money(row.original.investor_paid ?? 0) },
    { header: "Due", cell: ({ row }) => <b className="text-amber-700">{money(row.original.investor_owes ?? 0)}</b> },
    { header: "Action", id: "collect", cell: ({ row }) => <Button onClick={() => setCollect(row.original)}>Collect</Button> },
  ];

  return (
    <>
      <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
        <Input className={`${inputCls} max-w-xs`} placeholder="Search investor, chassis or buyer" value={q} onChange={(e) => setQ(e.target.value)} />
        <p className="text-sm text-slate-600">Total due from investors: <b className="text-amber-700">{money(total)}</b></p>
      </div>
      {error && <ErrorText message={error} />}
      <DataTable columns={cols} data={rows} loading={loading} empty="No investor owes the shop anything." />
      {collect && <ReceiveModal x={collect} owed={collect.investor_owes ?? 0} onClose={() => setCollect(null)} onSaved={reload} />}
    </>
  );
}

function Form() {
  const q = useSearchParams();
  const { can } = useAuth();
  const { hidden } = useHiddenMenu();
  // investor dues are collected here too, for those who may manage investor money (and while investors are in the menu)
  const canInvestor = can("settlements.manage") && !hidden.includes("/investors");
  const [tab, setTab] = useState<"customer" | "investor">(q.get("tab") === "investor" && canInvestor ? "investor" : "customer");
  const go = (t: "customer" | "investor") => {
    setTab(t);
    window.history.replaceState(null, "", t === "investor" ? "/due-collection?tab=investor" : "/due-collection");
  };

  return (
    <>
      {canInvestor && (
        <div className="mb-4 flex flex-wrap gap-2">
          <Button variant={tab === "customer" ? "primary" : "outline"} onClick={() => go("customer")}>Customer</Button>
          <Button variant={tab === "investor" ? "primary" : "outline"} onClick={() => go("investor")}>Investor</Button>
        </div>
      )}
      {tab === "investor" && canInvestor
        ? <InvestorDues />
        : <AccountForm side="customer" vehicleId={q.get("vehicle_id")} partyId={q.get("customer_id")} />}
    </>
  );
}

export default function Page() {
  return (
    <>
      <PageHeader title="Due collection" />
      <Suspense>
        <Form />
      </Suspense>
    </>
  );
}
