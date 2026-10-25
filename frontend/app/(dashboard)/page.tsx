"use client";

import Link from "next/link";
import { BadgeDollarSign, ShoppingCart, Wallet } from "lucide-react";
import { fmtDate, money } from "@/lib/format";
import { useFetch } from "@/lib/hooks";
import type { Installment, Promise_ } from "@/lib/types";
import { Card, ErrorText, Loading, PageHeader, Stat } from "@/components/app-ui";
import { Col, DataTable } from "@/components/DataTable";
import { DashboardCharts, type Stats } from "@/components/DashboardCharts";

type Dashboard = Stats & {
  stock_count: number; sales_this_month: number; sales_amount_this_month: number; purchases_this_month: number; purchases_amount_this_month: number; total_sales: number; total_sales_amount: number; total_purchases: number; total_purchases_amount: number; total_due: number; overdue_count: number;
  installments_due_today: Installment[]; installments_due_today_count: number; promises_due_today: Promise_[];
};

export default function DashboardPage() {
  const { data, loading, error } = useFetch<Dashboard>("/dashboard");
  if (loading && !data) return <Loading />;
  if (error || !data) return <ErrorText message={error ?? "Failed to load"} />;

  const overdueCols: Col<Installment>[] = [
    { header: "Sale", cell: ({ row }) => <Link className="underline" href={`/sales/${row.original.plan?.sale?.id}`}>#{row.original.plan?.sale?.id}</Link> },
    { header: "Buyer", cell: ({ row }) => row.original.plan?.sale?.customer?.name },
    { header: "Vehicle", cell: ({ row }) => row.original.plan?.sale?.vehicle?.chassis_no },
    { header: "Installment", cell: ({ row }) => `#${row.original.seq_no}` },
    { header: "Remaining", cell: ({ row }) => money(row.original.amount - row.original.paid_amount) },
  ];
  const promiseCols: Col<Promise_>[] = [
    { header: "Sale", cell: ({ row }) => <Link className="underline" href={`/sales/${row.original.sale_id}`}>#{row.original.sale_id}</Link> },
    { header: "Buyer", cell: ({ row }) => row.original.sale?.customer?.name },
    { header: "Vehicle", cell: ({ row }) => row.original.sale?.vehicle?.chassis_no },
    { header: "Promised", cell: ({ row }) => money(row.original.promised_amount) },
  ];

  return (
    <>
      <PageHeader title="Dashboard" />
      <div className="mb-6 grid grid-cols-1 gap-3 sm:grid-cols-3">
        <Stat href="/reports?tab=purchases" icon={ShoppingCart} color="blue" label="Total purchase" value={<>{money(data.total_purchases_amount)}<span className="block text-[10px] font-medium text-white/85 sm:text-xs">{data.total_purchases} vehicles bought</span><span className="block text-[10px] font-medium text-white/85 sm:text-xs">This month: {money(data.purchases_amount_this_month)} ({data.purchases_this_month})</span></>} />
        <Stat href="/reports?tab=sales" icon={BadgeDollarSign} label="Total sales" value={<>{money(data.total_sales_amount)}<span className="block text-[10px] font-medium text-white/85 sm:text-xs">{data.total_sales} vehicles sold</span><span className="block text-[10px] font-medium text-white/85 sm:text-xs">This month: {money(data.sales_amount_this_month)} ({data.sales_this_month})</span></>} />
        <Stat href="/collections" icon={Wallet} color="amber" label="Total due" value={money(data.total_due)} />
      </div>
      <DashboardCharts stats={data} />
      <Card title="Installments due today" className="mb-4" actions={<Link className="text-sm font-medium text-emerald-700 underline" href="/collections">View all</Link>}><DataTable columns={overdueCols} data={data.installments_due_today.slice(0, 5)} pageSize={5} pager={false} empty="No installments due today." /></Card>
      <Card title="Promises due today" actions={<Link className="text-sm font-medium text-emerald-700 underline" href="/collections">View all</Link>}><DataTable columns={promiseCols} data={data.promises_due_today.slice(0, 5)} pageSize={5} pager={false} empty="No promises due today." /></Card>
    </>
  );
}
