"use client";

import Link from "next/link";
import { AlertTriangle, BadgeDollarSign, Car, Wallet } from "lucide-react";
import { fmtDate, money } from "@/lib/format";
import { useFetch } from "@/lib/hooks";
import type { Installment, Promise_ } from "@/lib/types";
import { Card, ErrorText, Loading, PageHeader, Stat } from "@/components/app-ui";
import { Col, DataTable } from "@/components/DataTable";
import { DashboardCharts, type Stats } from "@/components/DashboardCharts";

type Dashboard = Stats & {
  stock_count: number; sales_this_month: number; sales_amount_this_month: number; total_due: number; overdue_count: number;
  overdue_installments: Installment[]; promises_due_today: Promise_[];
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
    { header: "Due date", cell: ({ row }) => <span className="font-medium text-red-600">{fmtDate(row.original.due_date)}</span> },
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
      <div className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-4">
        <Stat icon={Car} color="blue" label="Vehicles in stock" value={data.stock_count} />
        <Stat icon={BadgeDollarSign} label="Sales this month" value={`${data.sales_this_month} · ${money(data.sales_amount_this_month)}`} />
        <Stat icon={Wallet} color="amber" label="Total due" value={money(data.total_due)} />
        <Stat icon={AlertTriangle} label="Overdue installments" value={data.overdue_count} tone={data.overdue_count > 0 ? "red" : undefined} />
      </div>
      <DashboardCharts stats={data} />
      <Card title="Overdue installments" className="mb-4"><DataTable columns={overdueCols} data={data.overdue_installments} empty="Nothing overdue." /></Card>
      <Card title="Promises due today"><DataTable columns={promiseCols} data={data.promises_due_today} empty="No promises due today." /></Card>
    </>
  );
}
