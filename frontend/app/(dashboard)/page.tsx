"use client";

import Link from "next/link";
import { BadgeDollarSign, ShoppingCart, Wallet } from "lucide-react";
import { money } from "@/lib/format";
import { useFetch } from "@/lib/hooks";
import type { Installment, Promise_ } from "@/lib/types";
import { Card, ErrorText, Loading, PageHeader, Stat } from "@/components/app-ui";
import { Col, DataTable } from "@/components/DataTable";
import { DashboardCharts, type Stats } from "@/components/DashboardCharts";
import { AttentionCard } from "@/components/AttentionCard";
import { TodayCard, type TodayFigures } from "@/components/TodayCard";

type InvestorToday = { plan_id: number; sale_id: number; investor: { id: number; name: string; phone: string | null }; vehicle: { chassis_no: string; engine_no: string | null }; amount: number };

type Dashboard = Stats & {
  stock_count: number; sales_this_month: number; sales_amount_this_month: number; purchases_this_month: number; purchases_amount_this_month: number; total_sales: number; total_sales_amount: number; total_purchases: number; total_purchases_amount: number; total_due: number; overdue_count: number;
  registration_pending: number; old_stock: number; today: TodayFigures | null; investor_payments_today: InvestorToday[]; installments_due_today: Installment[]; installments_due_today_count: number; promises_due_today: Promise_[];
};

export default function DashboardPage() {
  const { data, loading, error } = useFetch<Dashboard>("/dashboard");
  if (loading && !data) return <Loading />;
  if (error || !data) return <ErrorText message={error ?? "Failed to load"} />;

  const overdueCols: Col<Installment>[] = [
    { header: "Sale", cell: ({ row }) => <Link className="underline" href={`/sales/${row.original.plan?.sale?.id}`}>#{row.original.plan?.sale?.id}</Link> },
    { header: "Buyer", cell: ({ row }) => row.original.plan?.sale?.customer?.name },
    { header: "Vehicle", cell: ({ row }) => <div className="leading-tight"><div className="font-medium">{row.original.plan?.sale?.vehicle?.chassis_no}</div><div className="text-xs text-slate-500">{row.original.plan?.sale?.vehicle?.engine_no}</div></div> },
    { header: "Remaining", cell: ({ row }) => money(row.original.amount - row.original.paid_amount) },
    { header: "Mobile", cell: ({ row }) => row.original.plan?.sale?.customer?.phone ?? "-" },
  ];
  const investorCols: Col<InvestorToday>[] = [
    { header: "Sale", cell: ({ row }) => <Link className="underline" href={`/sales/${row.original.sale_id}`}>#{row.original.sale_id}</Link> },
    { header: "Investor", cell: ({ row }) => <Link className="underline" href={`/investors/${row.original.investor.id}`}>{row.original.investor.name}</Link> },
    { header: "Vehicle", cell: ({ row }) => <div className="leading-tight"><div className="font-medium">{row.original.vehicle.chassis_no}</div><div className="text-xs text-slate-500">{row.original.vehicle.engine_no}</div></div> },
    { header: "To pay", cell: ({ row }) => money(row.original.amount) },
    { header: "Mobile", cell: ({ row }) => row.original.investor.phone ?? "-" },
  ];
  const promiseCols: Col<Promise_>[] = [
    { header: "Sale", cell: ({ row }) => <Link className="underline" href={`/sales/${row.original.sale_id}`}>#{row.original.sale_id}</Link> },
    { header: "Buyer", cell: ({ row }) => row.original.sale?.customer?.name },
    { header: "Vehicle", cell: ({ row }) => <div className="leading-tight"><div className="font-medium">{row.original.sale?.vehicle?.chassis_no}</div><div className="text-xs text-slate-500">{row.original.sale?.vehicle?.engine_no}</div></div> },
    { header: "Promised", cell: ({ row }) => money(row.original.promised_amount) },
    { header: "Mobile", cell: ({ row }) => row.original.sale?.customer?.phone ?? "-" },
  ];

  return (
    <>
      <PageHeader title="Dashboard" />
      <div className="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
        <Stat compact href="/reports?tab=purchases" icon={ShoppingCart} color="blue" label="Total purchase" value={<>{money(data.total_purchases_amount)}<span className="block text-[10px] font-medium text-white/85 sm:text-xs">{`${data.total_purchases} vehicles bought`}</span><span className="block text-[10px] font-medium text-white/85 sm:text-xs">This month: {money(data.purchases_amount_this_month)} ({data.purchases_this_month})</span></>} />
        <Stat compact href="/reports?tab=sales" icon={BadgeDollarSign} label="Total sales" value={<>{money(data.total_sales_amount)}<span className="block text-[10px] font-medium text-white/85 sm:text-xs">{`${data.total_sales} vehicles sold`}</span><span className="block text-[10px] font-medium text-white/85 sm:text-xs">This month: {money(data.sales_amount_this_month)} ({data.sales_this_month})</span></>} />
        <Stat compact href="/collections" icon={Wallet} color="amber" label="Total due" value={money(data.total_due)} />
      </div>
      <div className="mb-3 grid gap-3 lg:grid-cols-2">
        <DashboardCharts stats={data} />
        <AttentionCard payable={data.payable} overdue={data.overdue_count} registrationPending={data.registration_pending} oldStock={data.old_stock} />
      </div>
      <div className="grid gap-3 min-[1300px]:grid-cols-2">
        <Card className="h-full gap-2 py-3" title="Installments due today" actions={<Link className="text-sm font-medium text-emerald-700 underline" href="/collections">View all</Link>}><DataTable columns={overdueCols} data={data.installments_due_today.slice(0, 3)} pageSize={3} pager={false} compact empty="No installments due today." /></Card>
        <Card className="h-full gap-2 py-3" title="Promises due today" actions={<Link className="text-sm font-medium text-emerald-700 underline" href="/collections">View all</Link>}><DataTable columns={promiseCols} data={data.promises_due_today.slice(0, 3)} pageSize={3} pager={false} compact empty="No promises due today." /></Card>
        <Card className="h-full gap-2 py-3" title="Investor payments today" actions={<Link className="text-sm font-medium text-emerald-700 underline" href="/investors">View all</Link>}><DataTable columns={investorCols} data={data.investor_payments_today.slice(0, 3)} pageSize={3} pager={false} compact empty="No investor payments today." /></Card>
        {data.today && <TodayCard today={data.today} />}
      </div>
    </>
  );
}
