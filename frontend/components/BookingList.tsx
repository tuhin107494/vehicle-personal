"use client";

import { useState } from "react";
import Link from "next/link";
import { Eye, HandCoins, Pencil, Plus, Printer, Trash2, X } from "lucide-react";
import { toast } from "sonner";
import { api, ApiError } from "@/lib/api";
import { usePagedList } from "@/lib/hooks";
import { useAuth } from "@/lib/auth";
import { fmtDate, money } from "@/lib/format";
import type { Booking, DraftT } from "@/lib/types";
import { Button, inputCls, Pagination, StatusBadge } from "@/components/app-ui";
import { Col, DataTable } from "@/components/DataTable";
import { openBookingReceipt } from "@/components/BookingReceipt";
import { BookingView } from "@/components/forms/BookingView";
import { BookingForm } from "@/components/forms/BookingForm";
import { CancelBooking } from "@/components/forms/BookingActions";
import { PhoneChips } from "@/components/PhoneChips";
import { Input } from "@/components/ui/input";

const TABS: [string, string][] = [["active", "Active"], ["cancelled", "Cancelled"], ["expired", "Expired"], ["", "All"]];

/**
 * Vehicles held for customers who paid an advance, how each booking ended, and the sale drafts saved for later. It is the "Booking" tab of the Sales page;
 * a new booking is made here ("New booking") and later turned into a sale.
 */
export function BookingList() {
  const { can, user } = useAuth();
  const [status, setStatus] = useState("active");
  const list = usePagedList<Booking>("/bookings", { status });
  const [open, setOpen] = useState(false);
  const [editing, setEditing] = useState<Booking | null>(null);
  const [viewing, setViewing] = useState<Booking | null>(null);
  const [cancelling, setCancelling] = useState<Booking | null>(null);
  const counts = list.meta?.status_counts ?? {};
  const total = Object.values(counts).reduce((sum, n) => sum + n, 0);

  const removeDraft = async (d: DraftT) => {
    if (!confirm("Delete this draft?")) return;
    try {
      await api(`/drafts/${d.id}`, { method: "DELETE" });
      toast.success("Draft deleted");
      list.reload();
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : "Failed to delete");
    }
  };

  // a saved sale draft is a booking in the making (it holds its vehicle): it is listed with the active bookings, shaped like one
  type Row = Booking & { draft?: DraftT };
  const draftRows: Row[] = (list.meta?.drafts ?? []).map((d) => {
    const values = (d.data?.values ?? {}) as Record<string, string | null>;
    return {
      id: -d.id, draft: d, vehicle_id: d.vehicle_id ?? null, customer_id: d.customer?.id ?? 0, agreed_price: values.sale_price ? String(values.sale_price) : null,
      advance_amount: String(values.advance_amount || 0), advance_method: "cash", booked_on: d.updated_at, expires_on: null, status: "draft" as Booking["status"], sale_id: null,
      refund_amount: "0", kept_amount: "0", closed_on: null, note: null, close_note: null, balance: null, days_left: null, vehicle: d.vehicle ? { ...d.vehicle, engine_no: "", status: "booked" } : undefined, customer: d.customer ?? undefined,
    };
  });
  const rows: Row[] = [...draftRows, ...list.rows.filter((r) => "vehicle_id" in r)];

  const columns: Col<Row>[] = [
    {
      header: "Vehicle",
      cell: ({ row }) => (
        <span className="inline-block max-w-[10rem] whitespace-normal leading-tight">
          <span className="font-medium">{row.original.vehicle?.chassis_no ?? <span className="text-slate-500">No vehicle yet</span>}</span>
          <span className="block text-xs text-slate-500">{[row.original.vehicle?.brand, row.original.vehicle?.model, row.original.vehicle?.year].filter(Boolean).join(" ")}</span>
        </span>
      ),
    },
    { header: "Customer", cell: ({ row }) => <span className="flex flex-col gap-1"><span className="font-medium">{row.original.customer?.name ?? <span className="text-slate-500">No customer yet</span>}</span><PhoneChips phone={row.original.customer?.phone} alt={row.original.customer?.alt_phone} /></span> },
    { header: "Sale price", cell: ({ row }) => money(row.original.agreed_price) },
    { header: "Advance", cell: ({ row }) => <span className="font-medium text-emerald-700">{Number(row.original.advance_amount) > 0 ? money(row.original.advance_amount) : "-"}</span> },
    { header: "Balance", cell: ({ row }) => money(row.original.balance) },
    {
      header: "Booked / Hold until",
      cell: ({ row }) => {
        const b = row.original;
        if (b.draft) return <span className="whitespace-nowrap">Saved {fmtDate(b.booked_on)}<span className="block text-xs text-slate-500">{b.draft.expires_on ? `Auto-deleted ${fmtDate(b.draft.expires_on)}` : ""}</span></span>;
        const left = b.days_left;
        return (
          <span className="whitespace-nowrap">
            {fmtDate(b.booked_on)}
            <span className="block text-xs text-slate-500">{b.expires_on ? `until ${fmtDate(b.expires_on)}` : "No deadline"}</span>
            {left !== null && <span className={`text-xs font-medium ${left < 0 ? "text-red-600" : left <= 1 ? "text-amber-600" : "text-slate-500"}`}>{left < 0 ? `${-left} days late` : left === 0 ? "ends today" : `${left} days left`}</span>}
          </span>
        );
      },
    },
    { header: "Status", cell: ({ row }) => <StatusBadge status={row.original.status} /> },
    {
      header: "Action",
      id: "actions",
      cell: ({ row }) => {
        const b = row.original;
        if (b.draft) {
          return (
            <div className="flex items-center justify-end gap-1.5">
              <Link className="inline-flex h-8 items-center rounded-lg border border-emerald-200 px-2.5 text-xs font-medium text-emerald-700 hover:bg-emerald-50" href={`/sales/new?${(b.draft.data as { investor?: boolean } | undefined)?.investor ? "tab=investor&" : ""}resume=${b.draft.id}`}>Resume</Link>
              <Button size="sm" variant="outline" className="border-rose-300 text-rose-700 hover:bg-rose-50 hover:text-rose-800" onClick={() => removeDraft(b.draft!)}><Trash2 className="size-4" /> Delete</Button>
            </div>
          );
        }
        const view = <button type="button" aria-label="View booking" title="View" className="rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-violet-700" onClick={() => setViewing(b)}><Eye className="size-4" /></button>;
        const print = <button type="button" aria-label="Print booking" title="Print" className="rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-sky-700" onClick={() => openBookingReceipt(b, user?.shop?.name ?? "Vehicle Hub")}><Printer className="size-4" /></button>;
        if (b.status === "active") {
          return (
            <div className="flex flex-wrap items-center justify-end gap-1.5 md:flex-nowrap md:whitespace-nowrap">
              {view}
              {print}
              {can("sales.create") && <button type="button" aria-label="Edit booking" title="Edit" className="rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-emerald-700" onClick={() => setEditing(b)}><Pencil className="size-4" /></button>}
              {can("sales.create") && <Link className="inline-flex h-8 items-center gap-1.5 rounded-lg bg-gradient-to-r from-emerald-600 to-green-600 px-2.5 text-xs font-medium text-white shadow-sm hover:from-emerald-500 hover:to-green-500" href={`/sales/new?booking=${b.id}`}><HandCoins className="size-4" /> Convert to sale</Link>}
              {can("sales.cancel") && <Button size="sm" variant="outline" className="border-rose-300 text-rose-700 hover:bg-rose-50 hover:text-rose-800" onClick={() => setCancelling(b)}><X className="size-4" /> Cancel</Button>}
            </div>
          );
        }
        return (
          <div className="flex flex-col items-end gap-1 text-xs text-slate-600">
            <span className="flex items-center">{view}{print}</span>
            {b.status === "completed" && b.sale_id && (
              <Link className="inline-flex items-center gap-1.5 rounded-lg border border-emerald-200 px-2.5 py-1.5 text-xs font-medium text-emerald-700 hover:bg-emerald-50" href={`/sales/${b.sale_id}`}><Eye className="size-4" /> Sales details</Link>
            )}
            {b.status !== "completed" && <span>Refunded {money(b.refund_amount)} · Kept {money(b.kept_amount)}</span>}
            {b.closed_on && <span>{fmtDate(b.closed_on)}</span>}
          </div>
        );
      },
    },
  ];

  return (
    <>
      <div className="mb-3 mt-7 flex flex-wrap items-center gap-1.5 sm:gap-2">
        {TABS.map(([key, text]) => {
          const count = key ? counts[key] ?? 0 : total;
          const active = status === key;
          return (
            <button key={key || "all"} type="button" onClick={() => setStatus(key)} className={`flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium transition sm:gap-2 sm:px-4 sm:py-1.5 sm:text-sm ${active ? "bg-gradient-to-r from-emerald-600 to-green-600 text-white shadow" : "bg-white text-emerald-800 ring-1 ring-emerald-200 hover:bg-emerald-50"}`}>
              {text}
              <span className={`rounded-full px-1.5 text-[11px] sm:px-2 sm:text-xs ${active ? "bg-white/25" : "bg-emerald-100 text-emerald-800"}`}>{count}</span>
            </button>
          );
        })}
        {can("sales.create") && <Button className="ml-auto" onClick={() => setOpen(true)}><Plus className="size-4" /> New booking</Button>}
      </div>
      <Input className={`${inputCls} mb-3 max-w-xs`} placeholder="Search customer, phone, chassis" value={list.search} onChange={(e) => list.setSearch(e.target.value)} />
      <DataTable serverPaged columns={columns} data={status === "active" || status === "" ? rows : rows.filter((r) => !r.draft)} loading={list.loading} empty="No bookings here." />
      <Pagination meta={list.meta} onPage={list.setPage} />
      <BookingForm open={!!editing} booking={editing} onClose={() => setEditing(null)} onSaved={(b) => list.mutate((rows, meta) => ({ data: (rows ?? []).map((r) => ("vehicle_id" in r && r.id === b.id ? b : r)), meta }))} />
      <BookingForm open={open} onClose={() => setOpen(false)} onSaved={(b) => {
        if (status !== "active") return setStatus("active"); // another tab was open: the Active list loads
        // already on the Active list: the saved booking goes to its top and the tab counts follow, with no request
        list.mutate((rows, meta) => ({
          data: [b, ...(rows ?? [])],
          meta: meta && { ...meta, total: meta.total + 1, status_counts: { ...meta.status_counts, active: (meta.status_counts?.active ?? 0) + 1 } },
        }));
      }} />
      <BookingView booking={viewing} onClose={() => setViewing(null)} />
      <CancelBooking booking={cancelling} onClose={() => setCancelling(null)} onDone={list.reload} />
    </>
  );
}
