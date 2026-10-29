"use client";

import Link from "next/link";
import { Printer } from "lucide-react";
import { useAuth } from "@/lib/auth";
import { fmtDate, label, money } from "@/lib/format";
import type { Booking } from "@/lib/types";
import { Button, KV, Modal, StatusBadge } from "@/components/app-ui";
import { openBookingReceipt } from "@/components/BookingReceipt";
import { PhoneChips } from "@/components/PhoneChips";

/** Everything about one booking, with its receipt to print and links to the vehicle and (once made) the sale. */
export function BookingView({ booking, onClose }: { booking: Booking | null; onClose: () => void }) {
  const { user } = useAuth();
  const b = booking;
  const v = b?.vehicle;

  return (
    <Modal open={!!b} title={b ? `Booking #${b.id}` : "Booking"} onClose={onClose} wide>
      {b && (
        <div className="space-y-4">
          <KV items={[
            ["Status", <StatusBadge key="s" status={b.status} />],
            ["Customer", <span key="c" className="flex flex-col gap-1">{b.customer ? <Link className="underline" href={`/customers/${b.customer.id}`}>{b.customer.name}</Link> : "-"}<PhoneChips phone={b.customer?.phone} alt={b.customer?.alt_phone} /></span>],
            ["Vehicle", v ? <Link key="v" className="underline" href={`/vehicles/${v.id}`}>{v.chassis_no} · {[v.brand, v.model, v.year].filter(Boolean).join(" ")}</Link> : "No vehicle yet"],
            ["Sale price", b.agreed_price ? money(b.agreed_price) : "Not fixed"],
            ["Advance", Number(b.advance_amount) > 0 ? `${money(b.advance_amount)} (${label(b.advance_method)})` : "No advance"],
            ["Balance", b.balance === null ? "-" : money(b.balance)],
            ["Booked on", fmtDate(b.booked_on)],
            ["Hold until", b.expires_on ? fmtDate(b.expires_on) : "No deadline"],
            ...(b.booked_by ? [["Booked by", b.booked_by] as [string, React.ReactNode]] : []),
            ...(b.closed_on ? [["Closed on", fmtDate(b.closed_on)] as [string, React.ReactNode]] : []),
            ...(b.status === "cancelled" || b.status === "expired" ? [["Refunded", money(b.refund_amount)], ["Kept by shop", money(b.kept_amount)]] as [string, React.ReactNode][] : []),
            ...(b.note ? [["Note", b.note] as [string, React.ReactNode]] : []),
            ...(b.close_note ? [["Closing note", b.close_note] as [string, React.ReactNode]] : []),
          ]} />
          <div className="flex flex-wrap justify-end gap-2">
            {b.sale_id && <Link className="inline-flex h-9 items-center rounded-lg border border-emerald-200 px-3 text-sm font-medium text-emerald-700 hover:bg-emerald-50" href={`/sales/${b.sale_id}`}>Sales details</Link>}
            <Button variant="outline" onClick={() => openBookingReceipt(b, user?.shop?.name ?? "Vehicle Hub")}><Printer className="size-4" /> Print</Button>
          </div>
        </div>
      )}
    </Modal>
  );
}
