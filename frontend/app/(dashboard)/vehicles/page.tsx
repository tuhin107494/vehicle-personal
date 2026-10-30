"use client";

import { Suspense, useState } from "react";
import { useSearchParams } from "next/navigation";
import Link from "next/link";
import { usePagedList } from "@/lib/hooks";
import { useAuth } from "@/lib/auth";
import { label } from "@/lib/format";
import { typeColor } from "@/lib/typeColors";
import type { Vehicle } from "@/lib/types";
import { Badge, Button, Field, inputCls, PageHeader, Pagination, StatusBadge } from "@/components/app-ui";
import { ResponsiveFilters } from "@/components/ResponsiveFilters";
import { Col, DataTable } from "@/components/DataTable";
import { VehicleForm } from "@/components/forms/VehicleForm";
import { REGISTRATION_STEPS, RegistrationModal } from "@/components/forms/RegistrationModal";
import { Input } from "@/components/ui/input";
import { NativeSelect } from "@/components/ui/native-select";
import { ArrowUpRight, Eye, HandCoins, History, Pencil, Plus } from "lucide-react";

function Vehicles() {
  const params = useSearchParams();
  const wanted = params.get("status"); // e.g. /vehicles?status=in_stock
  // opened from the menu the list starts on what is in stock; ?status=sold or ?status=all opens another view
  const [status, setStatus] = useState(wanted === "sold" ? "sold" : wanted === "all" ? "" : "stock"); // "stock" is in stock plus booked, the booked ones marked
  const [type, setType] = useState("");
  const [registration, setRegistration] = useState(REGISTRATION_STEPS.includes(params.get("registration") ?? "") ? params.get("registration")! : ""); // e.g. ?registration=pending
  const { can } = useAuth();
  const list = usePagedList<Vehicle>("/vehicles", { status, vehicle_type: type, registration_status: registration });
  // the vehicle types come with the list (meta), so this page makes one request
  const categories = list.meta?.categories ?? [];
  const nameOf = (key?: string | null) => categories.find((c) => c.key === key)?.name ?? label(key);
  const [editing, setEditing] = useState<Vehicle | null>(null);
  const [open, setOpen] = useState(false);
  const [reg, setReg] = useState<Vehicle | null>(null);

  const columns: Col<Vehicle>[] = [
    { header: "Type", cell: ({ row }) => <span className={`inline-block rounded-full px-2 py-0.5 text-xs font-medium ${typeColor(categories.find((c) => c.key === row.original.vehicle_type)?.id ?? 0).badge}`}>{nameOf(row.original.vehicle_type)}</span> },
    { header: "Vehicle", cell: ({ row }) => <span className="inline-block max-w-[8.5rem] whitespace-normal leading-tight">{[row.original.brand, row.original.model, row.original.year].filter(Boolean).join(" ") || "-"}</span> },
    { header: "Engine", accessorKey: "engine_no" },
    { header: "Chassis", cell: ({ row }) => <span className="font-medium">{row.original.chassis_no}</span> },
    { header: "Reg. no", meta: { className: "hidden min-[1600px]:table-cell" }, cell: ({ row }) => row.original.registration_no ?? "-" },
    {
      header: "Registration",
      cell: ({ row }) => {
        const v = row.original;
        if (!v.current_sale_id || !v.registration_status) return "-";
        const tone = v.registration_status === "document_provided" ? "green" : v.registration_status === "pending" ? "gray" : "blue";
        const badge = <Badge tone={tone}>{label(v.registration_status)}</Badge>;
        return can("registration.update") ? <button type="button" title="Update registration" onClick={() => setReg(v)} className="rounded-full ring-sky-300 hover:ring-2">{badge} <Pencil className="ml-0.5 inline size-3 text-sky-700" /></button> : badge;
      },
    },
    { header: "Status", cell: ({ row }) => <StatusBadge status={row.original.status} /> },
    {
      header: "Action",
      id: "actions",
      cell: ({ row }) => (
        <div className="flex w-full items-center justify-end gap-1.5 whitespace-nowrap md:w-auto">
          {row.original.status === "in_stock" && can("sales.create") && (
            <Link className="inline-flex min-w-0 flex-1 items-center justify-center gap-1 rounded-lg border border-emerald-300 bg-emerald-50 px-1.5 py-1 text-[11px] font-medium text-emerald-700 hover:bg-emerald-100 md:flex-none md:gap-1.5 md:px-2.5 md:py-1.5 md:text-xs" href={`/sales/new?vehicle=${row.original.id}`} title="Sell"><ArrowUpRight className="size-3 shrink-0 md:size-4" /> <span>Sell</span></Link>
          )}
          {row.original.status === "booked" && row.original.booking_id && can("sales.create") && (
            <Link className="inline-flex min-w-0 flex-1 items-center justify-center gap-1 rounded-lg border border-yellow-400 bg-yellow-100 px-1.5 py-1 text-[11px] font-medium text-yellow-900 hover:bg-yellow-200 md:flex-none md:gap-1.5 md:px-2.5 md:py-1.5 md:text-xs" href={`/sales/new?booking=${row.original.booking_id}`} title="Sell"><HandCoins className="size-3 shrink-0 md:size-4" /> <span>Convert to sale</span></Link>
          )}
          {row.original.status === "booked" && !row.original.booking_id && row.original.draft_id && can("sales.create") && (
            <Link className="inline-flex min-w-0 flex-1 items-center justify-center gap-1 rounded-lg border border-yellow-400 bg-yellow-100 px-1.5 py-1 text-[11px] font-medium text-yellow-900 hover:bg-yellow-200 md:flex-none md:gap-1.5 md:px-2.5 md:py-1.5 md:text-xs" href={`/sales/new?resume=${row.original.draft_id}`} title="Resume sale"><ArrowUpRight className="size-3 shrink-0 md:size-4" /> <span>Resume sale</span></Link>
          )}
          {row.original.status === "sold" && (row.original.sale_due ?? 0) > 0 && can("payments.collect") && (
            <Link className="inline-flex min-w-0 flex-1 items-center justify-center gap-1 rounded-lg border border-sky-200 px-1.5 py-1 text-[11px] md:py-1.5 md:text-xs md:flex-none md:gap-1.5 md:px-2.5 font-medium text-sky-700 hover:bg-sky-50" href={`/due-collection?vehicle_id=${row.original.id}`} title="Due collection"><HandCoins className="size-3 shrink-0 md:size-4" /> <span>Due</span></Link>
          )}
          <Link className="inline-flex min-w-0 flex-1 items-center justify-center gap-1 rounded-lg border border-violet-200 px-1.5 py-1 text-[11px] md:py-1.5 md:text-xs md:flex-none md:gap-1.5 md:px-2.5 font-medium text-violet-700 hover:bg-violet-50" href={`/vehicles/${row.original.id}`} title="View"><Eye className="size-3 shrink-0 md:size-4" /> <span>View</span></Link>
          <Link className="inline-flex min-w-0 flex-1 items-center justify-center gap-1 rounded-lg border border-amber-200 px-1.5 py-1 text-[11px] md:py-1.5 md:text-xs md:flex-none md:gap-1.5 md:px-2.5 font-medium text-amber-700 hover:bg-amber-50" href={`/vehicles/${row.original.id}?tab=history`} title="History"><History className="size-3 shrink-0 md:size-4" /> <span>History</span></Link>
          {can("vehicles.edit") && <Button title="Edit" className="h-7 min-w-0 flex-1 gap-1 px-1.5 text-[11px] md:h-8 md:text-xs md:flex-none md:gap-1.5 md:px-2.5" variant="outline" size="sm" onClick={() => { setEditing(row.original); setOpen(true); }}><Pencil className="size-3 md:size-4 text-emerald-600" /> <span>Edit</span></Button>}
        </div>
      ),
    },
  ];

  return (
    <>
      <PageHeader title="Vehicles" actions={can("purchases.create") && <Link className="inline-flex items-center gap-1.5 rounded-lg bg-gradient-to-r from-emerald-600 to-green-600 px-2.5 py-1.5 text-xs font-medium text-white shadow-sm hover:from-emerald-500 hover:to-green-500 sm:px-3 sm:py-2 sm:text-sm" href="/purchases/new"><Plus className="size-3.5 sm:size-4" /> Buy vehicle</Link>} />
      <ResponsiveFilters desktopClass="hidden lg:block">
        <div className="space-y-4 lg:space-y-0">
          <div className="grid gap-3 lg:mb-4 lg:flex lg:gap-3">
            <Field label="Search">
              <Input className={`${inputCls} w-full lg:w-72`} placeholder="Chassis, engine, model" value={list.search} onChange={(e) => list.setSearch(e.target.value)} />
            </Field>
            <Field label="Status">
              <NativeSelect className={`${inputCls} w-full lg:w-40`} value={status} onChange={(e) => setStatus(e.target.value)}>
                <option value="">All status</option>
                <option value="stock">In stock</option>
                <option value="in_stock">Available</option>
                <option value="sold">Sold</option>
                <option value="booked">Booked</option>
              </NativeSelect>
            </Field>
            <Field label="Registration">
              <NativeSelect className={`${inputCls} w-full lg:w-48`} value={registration} onChange={(e) => setRegistration(e.target.value)}>
                <option value="">All registration</option>
                {REGISTRATION_STEPS.map((s) => <option key={s} value={s}>{label(s)}</option>)}
              </NativeSelect>
            </Field>
          </div>
        </div>
      </ResponsiveFilters>
      {/* the type chips stay on the page, outside the Filters window */}
      <div className="mb-3 flex flex-wrap gap-1.5 sm:gap-2">
        {[{ key: "", name: "All" }, ...categories].map((t) => {
          const counts = list.meta?.type_counts ?? {};
          const count = t.key ? counts[t.key] ?? 0 : Object.values(counts).reduce((a, b) => a + b, 0);
          if (t.key && count === 0 && type !== t.key) return null; // categories with no vehicles stay hidden
          const active = type === t.key;
          return (
            <button
              key={t.key || "all"}
              type="button"
              onClick={() => setType(t.key)}
              className={`flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium transition sm:gap-2 sm:px-4 sm:py-1.5 sm:text-sm ${active ? "bg-gradient-to-r from-emerald-600 to-green-600 text-white shadow" : "bg-white text-emerald-800 ring-1 ring-emerald-200 hover:bg-emerald-50"}`}
            >
              {t.name}
              <span className={`rounded-full px-1.5 text-[11px] sm:px-2 sm:text-xs ${active ? "bg-white/25" : "bg-emerald-100 text-emerald-800"}`}>{count}</span>
            </button>
          );
        })}
      </div>
      <DataTable serverPaged columns={columns} data={list.rows} loading={list.loading} />
      <Pagination meta={list.meta} onPage={list.setPage} />
      <RegistrationModal key={reg?.id} saleId={reg?.current_sale_id ?? null} status={reg?.registration_status ?? "pending"} title={`Registration · ${reg?.chassis_no ?? ""}`} open={!!reg} onClose={() => setReg(null)} onSaved={list.reload} />
      <VehicleForm open={open} vehicle={editing} onClose={() => setOpen(false)} onSaved={() => (editing ? list.reload() : list.showFirst())} />
    </>
  );
}

export default function VehiclesPage() {
  return <Suspense><Vehicles /></Suspense>;
}
