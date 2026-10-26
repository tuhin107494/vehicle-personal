"use client";

import Link from "next/link";
import { BadgeDollarSign, ShoppingCart, Wallet } from "lucide-react";
import { money } from "@/lib/format";
import { useFetch } from "@/lib/hooks";
import type { Installment, Promise_ } from "@/lib/types";
import { Badge, Card, ErrorText, Loading, PageHeader, Stat } from "@/components/app-ui";
import { Col, DataTable } from "@/components/DataTable";
import { DashboardCharts, type Stats } from "@/components/DashboardCharts";
import { AttentionCard } from "@/components/AttentionCard";
import { useHiddenDashboard } from "@/lib/dashboard";
import { TodayCard, type TodayFigures } from "@/components/TodayCard";

type InvestorToday = { plan_id: number; sale_id: number; investor: { id: number; name: string; phone: string | null }; vehicle: { chassis_no: string; engine_no: string | null }; amount: number };

type Dashboard = Stats & {
  stock_count: number; sales_this_month: number; sales_amount_this_month: number; purchases_this_month: number; purchases_amount_this_month: number; total_sales: number; total_sales_amount: number; total_purchases: number; total_purchases_amount: number; total_due: number; overdue_count: number;
  registration_pending: number; old_stock: number; today: TodayFigures | null; investor_payments_today: InvestorToday[]; installments_due_today: Installment[]; installments_due_today_count: number; promises_due_today: Promise_[];
};

export default function DashboardPage() {
  const { data, loading, error } = useFetch<Dashboard>("/dashboard");
  const { hidden } = useHiddenDashboard();
  const show = (key: string) => !hidden.includes(key);
  if (loading && !data) return <Loading />;
  if (error || !data) return <ErrorText message={error ?? "Failed to load"} />;

  // installments and normal (promised) dues together, in one list
  type DueRow = { key: string; saleId?: number; buyer?: string; chassis?: string; engine?: string | null; kind: string; amount: number; phone?: string | null };
  const dueRows: DueRow[] = [
    ...data.installments_due_today.map((i) => ({ key: `i${i.id}`, saleId: i.plan?.sale?.id, buyer: i.plan?.sale?.customer?.name, chassis: i.plan?.sale?.vehicle?.chassis_no, engine: i.plan?.sale?.vehicle?.engine_no, kind: "Installment", amount: i.amount - i.paid_amount, phone: i.plan?.sale?.customer?.phone })),
    ...data.promises_due_today.map((p) => ({ key: `p${p.id}`, saleId: p.sale_id, buyer: p.sale?.customer?.name, chassis: p.sale?.vehicle?.chassis_no, engine: p.sale?.vehicle?.engine_no, kind: "Due", amount: p.promised_amount, phone: p.sale?.customer?.phone })),
  ];
  const dueCols: Col<DueRow>[] = [
    { header: "Sale", cell: ({ row }) => <Link className="underline" href={`/sales/${row.original.saleId}`}>#{row.original.saleId}</Link> },
    { header: "Buyer", cell: ({ row }) => row.original.buyer },
    { header: "Vehicle", cell: ({ row }) => <div className="leading-tight"><div className="font-medium">{row.original.chassis}</div><div className="text-xs text-slate-500">{row.original.engine}</div></div> },
    { header: "For", cell: ({ row }) => <Badge tone={row.original.kind === "Installment" ? "blue" : "gray"}>{row.original.kind}</Badge> },
    { header: "Amount", cell: ({ row }) => money(row.original.amount) },
    { header: "Mobile", cell: ({ row }) => row.original.phone ?? "-" },
  ];
  const investorCols: Col<InvestorToday>[] = [
    { header: "Sale", cell: ({ row }) => <Link className="underline" href={`/sales/${row.original.sale_id}`}>#{row.original.sale_id}</Link> },
    { header: "Investor", cell: ({ row }) => <Link className="underline" href={`/investors/${row.original.investor.id}`}>{row.original.investor.name}</Link> },
    { header: "Vehicle", cell: ({ row }) => <div className="leading-tight"><div className="font-medium">{row.original.vehicle.chassis_no}</div><div className="text-xs text-slate-500">{row.original.vehicle.engine_no}</div></div> },
    { header: "To pay", cell: ({ row }) => money(row.original.amount) },
    { header: "Mobile", cell: ({ row }) => row.original.investor.phone ?? "-" },
  ];

  return (
    <>
      <PageHeader title="Dashboard" />
      {(show("total_purchase") || show("total_sales") || show("total_due")) && <div className="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
        {show("total_purchase") && <Stat compact href="/reports?tab=purchases" icon={ShoppingCart} color="blue" label="Total purchase" value={<>{money(data.total_purchases_amount)}<span className="block text-[10px] font-medium text-white/85 sm:text-xs">{`${data.total_purchases} vehicles bought`}</span><span className="block text-[10px] font-medium text-white/85 sm:text-xs">This month: {money(data.purchases_amount_this_month)} ({data.purchases_this_month})</span></>} />}
        {show("total_sales") && <Stat compact href="/reports?tab=sales" icon={BadgeDollarSign} label="Total sales" value={<>{money(data.total_sales_amount)}<span className="block text-[10px] font-medium text-white/85 sm:text-xs">{`${data.total_sales} vehicles sold`}</span><span className="block text-[10px] font-medium text-white/85 sm:text-xs">This month: {money(data.sales_amount_this_month)} ({data.sales_this_month})</span></>} />}
        {show("total_due") && <Stat compact href="/collections" icon={Wallet} color="amber" label="Total due" value={money(data.total_due)} />}
      </div>}
      {/* two cards per row; when one is hidden the next takes its place, and a lone last card widens to the full row */}
      <div className="flex flex-wrap gap-3 [&>*]:min-w-0 [&>*]:grow [&>*]:!h-auto [&>*]:basis-full sm:[&>*]:basis-[calc(50%-0.375rem)]">
        {show("charts") && <DashboardCharts stats={data} />}
        {show("attention") && <AttentionCard payable={data.payable} overdue={data.overdue_count} registrationPending={data.registration_pending} oldStock={data.old_stock} />}
        {show("dues") && <Card className="h-full gap-2 bg-sky-50 py-3 ring-sky-300 [&_[data-report-table]]:!bg-sky-50 [&_tbody_tr:hover]:!bg-sky-100 [&_thead]:!bg-none [&_thead]:!bg-sky-200 [&_th]:!border-sky-300 [&_th]:!text-sky-800" title="Dues today" actions={<Link className="text-sm font-semibold text-sky-800 underline underline-offset-2 hover:text-sky-600" href="/collections">View all</Link>}><DataTable columns={dueCols} data={dueRows.slice(0, 3)} pageSize={3} pager={false} compact empty="No dues today." /></Card>}
        {show("investors") && <Card className="h-full gap-2 bg-emerald-50 py-3 ring-emerald-300 [&_[data-report-table]]:!bg-emerald-50 [&_tbody_tr:hover]:!bg-emerald-100 [&_thead]:!bg-none [&_thead]:!bg-emerald-200 [&_th]:!border-emerald-300 [&_th]:!text-emerald-800" title="Investor payments today" actions={<Link className="text-sm font-semibold text-emerald-800 underline underline-offset-2 hover:text-emerald-600" href="/investors">View all</Link>}><DataTable columns={investorCols} data={data.investor_payments_today.slice(0, 3)} pageSize={3} pager={false} compact empty="No investor payments today." /></Card>}
        {show("today") && data.today && <TodayCard today={data.today} />}
      </div>
    </>
  );
}
