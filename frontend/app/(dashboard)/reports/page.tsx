"use client";

import { viewCol } from "@/components/ViewLink";
import { toast } from "sonner";
import { api } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { openSaleMemo } from "@/components/SaleMemo";
import { printVoucher } from "@/components/TransactionVoucher";
import { purchaseVoucher } from "@/lib/purchaseVoucher";
import { Suspense, useState } from "react";
import { useSearchParams } from "next/navigation";
import { fmtDate, label, money } from "@/lib/format";
import { useCategories } from "@/lib/categories";
import { typeColor } from "@/lib/typeColors";
import { useFetch } from "@/lib/hooks";
import type { Investor, Purchase, Sale, Summary, Vehicle } from "@/lib/types";
import { Button, ErrorText, Field, inputCls, Loading, Modal, PageHeader, Stat, StatusBadge } from "@/components/app-ui";
import { ChevronDown } from "lucide-react";
import { Input } from "@/components/ui/input";
import { NativeSelect } from "@/components/ui/native-select";
import { Col, DataTable } from "@/components/DataTable";
import { CustomerSelect, SupplierSelect } from "@/components/Selects";
import { ResponsiveFilters } from "@/components/ResponsiveFilters";
import { InvestorTransactions } from "@/components/InvestorTransactions";
import { PersonStatement } from "@/components/PersonStatement";

type Stock = { total_count: number; total_cost: number; vehicles: Vehicle[] };
type Dues = { total_due: number; sales: (Sale & { next_due_date: string | null })[] };
type InvRow = { investor: Investor; summary: Summary };

function StockReport() {
  const { data, loading, error } = useFetch<Stock>("/reports/stock");
  if (loading && !data) return <Loading />;
  if (error || !data) return <ErrorText message={error ?? "Failed"} />;
  const cols: Col<Vehicle>[] = [
    { header: "Chassis", cell: ({ row }) => <span className="font-medium">{row.original.chassis_no}</span> },
    { header: "Vehicle", cell: ({ row }) => [row.original.brand, row.original.model, row.original.year].filter(Boolean).join(" ") },
    { header: "Seller", cell: ({ row }) => row.original.current_purchase?.seller?.name ?? "-" },
    { header: "Bought", cell: ({ row }) => fmtDate(row.original.current_purchase?.purchase_date) },
    { header: "Cost", cell: ({ row }) => money((row.original.current_purchase?.price ?? 0) + (row.original.current_purchase?.cost_extra ?? 0)) },
    { header: "Days in stock", cell: ({ row }) => row.original.days_in_stock ?? "-" },
    viewCol<Vehicle>((v) => `/vehicles/${v.id}`),
  ];
  return (
    <>
      <div className="mb-3 grid grid-cols-2 gap-3 md:max-w-md"><Stat color="blue" label="Vehicles in stock" value={data.total_count} /><Stat color="slate" label="Stock cost" value={money(data.total_cost)} /></div>
      <DataTable columns={cols} data={data.vehicles} empty="No vehicles in stock." />
    </>
  );
}

function DuesReport() {
  const { data, loading, error } = useFetch<Dues>("/reports/dues");
  if (loading && !data) return <Loading />;
  if (error || !data) return <ErrorText message={error ?? "Failed"} />;
  const cols: Col<Dues["sales"][number]>[] = [
    { header: "Sale", cell: ({ row }) => <span className="font-medium">#{row.original.id}</span> },
    { header: "Buyer", cell: ({ row }) => row.original.customer?.name },
    { header: "Phone", cell: ({ row }) => row.original.customer?.phone },
    { header: "Vehicle", cell: ({ row }) => row.original.vehicle?.chassis_no },
    { header: "Type", accessorKey: "payment_type" },
    { header: "Paid", cell: ({ row }) => money(row.original.total_paid) },
    { header: "Due", cell: ({ row }) => <b className="text-red-600">{money(row.original.due_amount)}</b> },
    { header: "Next due", cell: ({ row }) => fmtDate(row.original.next_due_date) },
    viewCol<Dues["sales"][number]>((x) => `/sales/${x.id}`),
  ];
  return (
    <>
      <div className="mb-3 md:max-w-xs"><Stat label="Total due" value={money(data.total_due)} tone="red" /></div>
      <DataTable columns={cols} data={data.sales} empty="Nothing due." />
    </>
  );
}

function InvestorsReport() {
  const { data, loading, error } = useFetch<InvRow[]>("/reports/investors");
  if (loading && !data) return <Loading />;
  if (error || !data) return <ErrorText message={error ?? "Failed"} />;
  const cols: Col<InvRow>[] = [
    { header: "Investor", cell: ({ row }) => <span className="font-medium">{row.original.investor.name}</span> },
    { header: "Invested", cell: ({ row }) => money(row.original.summary.invested) },
    { header: "Returned", cell: ({ row }) => money(row.original.summary.returned) },
    { header: "Profit earned", cell: ({ row }) => money(row.original.summary.profit_earned) },
    { header: "Withdrawn", cell: ({ row }) => money(row.original.summary.withdrawn) },
    { header: "Outstanding", cell: ({ row }) => money(row.original.summary.outstanding) },
    { header: "Still due from buyers", cell: ({ row }) => money(row.original.summary.sales_due) },
    viewCol<InvRow>((x) => `/investors/${x.investor.id}`),
  ];
  return <DataTable columns={cols} data={data} empty="No investors." />;
}

type TypeRow = { type: string; count: number; amount: number; profit?: number };

const monthStart = () => new Date(new Date().getFullYear(), new Date().getMonth(), 1).toLocaleDateString("en-CA");
const today = () => new Date().toLocaleDateString("en-CA");

/** Date range + vehicle type filters shared by the purchase and sales reports. */
function Filters({ from, to, type, setFrom, setTo, setType, children }: { from: string; to: string; type: string; setFrom: (v: string) => void; setTo: (v: string) => void; setType: (v: string) => void; children?: React.ReactNode }) {
  const { categories } = useCategories();
  return (
    <ResponsiveFilters desktopClass="mb-4 hidden lg:flex lg:items-start lg:gap-3 lg:[&>*]:min-w-0 lg:[&>*]:flex-1">
      <Field label="From"><Input type="date" className={inputCls} value={from} max={to || undefined} onChange={(e) => setFrom(e.target.value)} /></Field>
      <Field label="To"><Input type="date" className={inputCls} value={to} min={from || undefined} onChange={(e) => setTo(e.target.value)} /></Field>
      <Field label="Vehicle type">
        <NativeSelect className={inputCls} value={type} onChange={(e) => setType(e.target.value)}>
          <option value="">All types</option>
          {categories.map((c) => <option key={c.key} value={c.key}>{c.name}</option>)}
        </NativeSelect>
      </Field>
      {children}
    </ResponsiveFilters>
  );
}

/** One card per vehicle type with its count and amount, in that type's colour. */
function ByType({ rows, withProfit }: { rows: TypeRow[]; withProfit?: boolean }) {
  const { categories } = useCategories();
  if (!rows.length) return null;
  return (
    <div className="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
      {rows.map((r) => {
        const cat = categories.find((c) => c.key === r.type);
        return (
          <div key={r.type} className={`rounded-xl bg-white p-4 shadow-sm ring-2 ${typeColor(cat?.id ?? 0).ring}`}>
            <p className={`text-lg font-extrabold ${typeColor(cat?.id ?? 0).title}`}>{cat?.name ?? label(r.type)}</p>
            <p className="text-sm text-slate-600">{r.count} vehicle{r.count === 1 ? "" : "s"}</p>
            <p className="mt-1 text-xl font-bold text-slate-900">{money(r.amount)}</p>
            {withProfit && <p className="text-sm text-emerald-700">Profit {money(r.profit)}</p>}
          </div>
        );
      })}
    </div>
  );
}

function PurchasesReport() {
  const [from, setFrom] = useState(monthStart());
  const [to, setTo] = useState(today());
  const [type, setType] = useState("");
  const [source, setSource] = useState("");
  const [supplier, setSupplier] = useState("");
  const { categories } = useCategories();
  const qs = new URLSearchParams({ ...(from && { from }), ...(to && { to }), ...(type && { vehicle_type: type }), ...(source && { source }), ...(supplier && { supplier_id: supplier }) });
  const { data, loading, error } = useFetch<{ totals: Record<string, number>; by_type: TypeRow[]; purchases: (Purchase & { total_cost: number; unpaid: number })[] }>(`/reports/purchases?${qs}`);

  const { user } = useAuth();
  const cols: Col<NonNullable<typeof data>["purchases"][number]>[] = [
    { header: "Date", cell: ({ row }) => fmtDate(row.original.purchase_date) },
    { header: "Memo no.", cell: ({ row }) => row.original.memo_no ?? "-" },
    { header: "Vehicle", cell: ({ row }) => <span className="font-medium">{row.original.vehicle?.chassis_no}</span> },
    { header: "Type", cell: ({ row }) => categories.find((c) => c.key === row.original.vehicle?.vehicle_type)?.name ?? "-" },
    { header: "Bought from", cell: ({ row }) => <span>{row.original.seller?.name} <span className="text-xs text-slate-500">({row.original.source === "customer" ? "customer, second hand" : "supplier"})</span></span> },
    { header: "Price", cell: ({ row }) => money(row.original.price) },
    { header: "Extra cost", cell: ({ row }) => money(row.original.cost_extra) },
    { header: "Total cost", cell: ({ row }) => <b>{money(row.original.total_cost)}</b> },
    { header: "Paid", cell: ({ row }) => money(row.original.paid_amount) },
    { header: "Unpaid", cell: ({ row }) => <span className={row.original.unpaid > 0 ? "font-medium text-amber-700" : ""}>{money(row.original.unpaid)}</span> },
    viewCol<NonNullable<typeof data>["purchases"][number]>((x) => `/vehicles/${x.vehicle_id}`, (x) => printVoucher(purchaseVoucher(x), x.source === "customer" ? "customer" : "supplier", user?.shop?.name ?? "Vehicle Hub")),
  ];

  return (
    <>
      <Filters from={from} to={to} type={type} setFrom={setFrom} setTo={setTo} setType={setType}>
        <Field label="Bought from">
          <NativeSelect className={inputCls} value={source} onChange={(e) => setSource(e.target.value)}>
            <option value="">All</option><option value="supplier">Suppliers</option><option value="customer">Customers (second hand)</option>
          </NativeSelect>
        </Field>
        <SupplierSelect filter allowCreate={false} value={supplier} onChange={setSupplier} />
      </Filters>
      {error && <ErrorText message={error} />}
      {data && (
        <>
          <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-5">
            <Stat color="blue" label="Purchases" value={data.totals.count} />
            <Stat color="slate" label="Total cost" value={money(data.totals.total_cost)} />
            <Stat color="green" label="Paid" value={money(data.totals.paid)} />
            <Stat color="amber" label="Unpaid" value={money(data.totals.unpaid)} />
            <Stat color="slate" label="Extra cost" value={money(data.totals.extra_cost)} />
          </div>
        </>
      )}
      <DataTable columns={cols} data={data?.purchases ?? []} loading={loading} empty="No purchases in this period." />
    </>
  );
}

function SalesReport() {
  const [from, setFrom] = useState(monthStart());
  const [to, setTo] = useState(today());
  const [type, setType] = useState("");
  const [paymentType, setPaymentType] = useState("");
  const [status, setStatus] = useState("");
  const [customer, setCustomer] = useState("");
  const { categories } = useCategories();
  const qs = new URLSearchParams({ ...(from && { from }), ...(to && { to }), ...(type && { vehicle_type: type }), ...(paymentType && { payment_type: paymentType }), ...(status && { status }), ...(customer && { customer_id: customer }) });
  const { data, loading, error } = useFetch<{ totals: Record<string, number>; by_type: TypeRow[]; sales: (Sale & { cost: number; profit: number })[] }>(`/reports/sales?${qs}`);

  const { user } = useAuth();
  const cols: Col<NonNullable<typeof data>["sales"][number]>[] = [
    { header: "Date", cell: ({ row }) => fmtDate(row.original.sale_date) },
    { header: "Sale", cell: ({ row }) => <span className="font-medium">#{row.original.id}</span> },
    { header: "Vehicle", cell: ({ row }) => row.original.vehicle?.chassis_no },
    { header: "Type", cell: ({ row }) => categories.find((c) => c.key === row.original.vehicle?.vehicle_type)?.name ?? "-" },
    { header: "Buyer", cell: ({ row }) => row.original.customer?.name },
    { header: "Payment", cell: ({ row }) => <span className="capitalize">{row.original.payment_type}</span> },
    { header: "Price", cell: ({ row }) => money(row.original.sale_price) },
    { header: "Paid", cell: ({ row }) => money(row.original.total_paid) },
    { header: "Due", cell: ({ row }) => <span className={row.original.due_amount > 0 ? "font-medium text-amber-700" : ""}>{money(row.original.due_amount)}</span> },
    { header: "Cost", cell: ({ row }) => money(row.original.cost) },
    { header: "Profit", cell: ({ row }) => <b className="text-emerald-700">{row.original.status === "active" ? money(row.original.profit) : "-"}</b> },
    { header: "Status", cell: ({ row }) => <StatusBadge status={row.original.status} /> },
    viewCol<NonNullable<typeof data>["sales"][number]>((x) => `/sales/${x.id}`, async (x) => {
      try {
        openSaleMemo((await api<Sale>(`/sales/${x.id}`)).data, user?.shop?.name ?? "Vehicle Hub"); // the memo needs the full sale: plan and payments
      } catch {
        toast.error("Could not load the sale.");
      }
    }),
  ];

  return (
    <>
      <Filters from={from} to={to} type={type} setFrom={setFrom} setTo={setTo} setType={setType}>
        <Field label="Payment type">
          <NativeSelect className={inputCls} value={paymentType} onChange={(e) => setPaymentType(e.target.value)}>
            <option value="">All</option><option value="cash">Cash</option><option value="due">Due</option><option value="installment">Installment</option>
          </NativeSelect>
        </Field>
        <CustomerSelect filter allowCreate={false} value={customer} onChange={setCustomer} />
        <Field label="Sale status">
          <NativeSelect className={inputCls} value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="">All</option><option value="active">Active</option><option value="cancelled">Cancelled</option>
          </NativeSelect>
        </Field>
      </Filters>
      {error && <ErrorText message={error} />}
      {data && (
        <>
          <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-5">
            <Stat color="blue" label={`Sales${data.totals.cancelled ? ` (${data.totals.cancelled} cancelled)` : ""}`} value={data.totals.count} />
            <Stat color="slate" label="Sales amount" value={money(data.totals.sales_amount)} />
            <Stat color="green" label="Paid" value={money(data.totals.paid)} />
            <Stat color="amber" label="Due" value={money(data.totals.due)} />
            <Stat color="green" label="Profit" value={money(data.totals.profit)} />
          </div>
        </>
      )}
      <DataTable columns={cols} data={data?.sales ?? []} loading={loading} empty="No sales in this period." />
    </>
  );
}

const TABS = [
  ["purchases", "Purchases"], ["sales", "Sales"], ["stock", "Stock"], ["dues", "Dues"], ["investors", "Investors"],
  ["customer", "Customer transactions"], ["supplier", "Supplier transactions"], ["investor", "Investor transactions"],
] as const;

const HAS_FILTERS: string[] = ["purchases", "sales", "customer", "supplier", "investor"];

function Reports() {
  const wanted = useSearchParams().get("tab"); // e.g. /reports?tab=sales opens that report
  const [tab, setTab] = useState<(typeof TABS)[number][0]>(TABS.find(([k]) => k === wanted)?.[0] ?? "purchases");
  const [menu, setMenu] = useState(false);
  const current = TABS.find(([k]) => k === tab)?.[1];
  return (
    <>
      <div className="print:hidden"><PageHeader title="Reports" actions={<span id="print-slot" />} /></div>
      {/* desktop: every report as a tab */}
      <div className="mb-4 hidden flex-wrap gap-2 print:hidden lg:flex">
        {TABS.map(([k, text]) => <Button key={k} variant={tab === k ? "primary" : "outline"} onClick={() => setTab(k)}>{text}</Button>)}
      </div>
      {/* phone and tablet: the report picker takes two thirds, the Filters button (when the report has one) the last third */}
      <div className="mb-4 grid grid-cols-3 gap-2 print:hidden lg:hidden">
        <Button variant="outline" className={`justify-between ${HAS_FILTERS.includes(tab) ? "col-span-2" : "col-span-3"}`} onClick={() => setMenu(true)}>
          <span className="truncate">{current}</span><ChevronDown className="size-4 shrink-0" />
        </Button>
        {HAS_FILTERS.includes(tab) && <div id="report-filters-slot" className="col-span-1" />}
      </div>
      <Modal open={menu} title="Choose a report" onClose={() => setMenu(false)}>
        <div className="grid gap-2">
          {TABS.map(([k, text]) => <Button key={k} variant={tab === k ? "primary" : "outline"} className="justify-start" onClick={() => { setTab(k); setMenu(false); }}>{text}</Button>)}
        </div>
      </Modal>
      {tab === "purchases" && <PurchasesReport />}
      {tab === "sales" && <SalesReport />}
      {tab === "stock" && <StockReport />}
      {tab === "dues" && <DuesReport />}
      {tab === "investors" && <InvestorsReport />}
      {tab === "customer" && <PersonStatement side="customer" />}
      {tab === "supplier" && <PersonStatement side="supplier" />}
      {tab === "investor" && <InvestorTransactions />}
    </>
  );
}

export default function ReportsPage() {
  return <Suspense><Reports /></Suspense>;
}
