"use client";

import { useState } from "react";
import { toast } from "sonner";
import { api, ApiError } from "@/lib/api";
import { money } from "@/lib/format";
import { useSettings } from "@/lib/settings";
import type { Booking } from "@/lib/types";
import { Button, Field, inputCls, Modal } from "@/components/app-ui";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";

const fail = (e: unknown) => toast.error(e instanceof ApiError ? e.message : "Failed");

/** The customer backs out: the vehicle goes back in stock, and the advance is given back as far as the shop agrees. */
export function CancelBooking({ booking, onClose, onDone }: { booking: Booking | null; onClose: () => void; onDone: () => void }) {
  const settings = useSettings();
  const [refund, setRefund] = useState("");
  const [note, setNote] = useState("");
  const [saving, setSaving] = useState(false);
  // what the shop's policy gives back by default, until staff type another amount
  const advance = Number(booking?.advance_amount ?? 0);
  const policyRefund = settings.data?.booking?.on_cancel === "keep" ? 0 : advance;
  const shown = refund === "" ? String(policyRefund) : refund;

  const submit = async () => {
    if (!booking) return;
    const amount = Number(shown);
    if (!(amount >= 0) || amount > advance) return void toast.error(`The refund must be between 0 and ${money(advance)}.`);
    setSaving(true);
    try {
      const res = await api(`/bookings/${booking.id}/cancel`, { method: "POST", body: { refund_amount: amount, note: note.trim() || undefined } });
      toast.success(res.message);
      onDone();
      onClose();
    } catch (e) {
      fail(e);
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal open={!!booking} title="Cancel booking" onClose={() => { setRefund(""); setNote(""); onClose(); }}>
      {booking && (
        <div className="space-y-3">
          <p className="text-sm text-slate-600">{booking.customer?.name} · {booking.vehicle?.chassis_no ?? "no vehicle yet"} · {advance > 0 ? `advance ${money(advance)}` : "no advance"}</p>
          {advance > 0 && (
            <>
              <Field label="Refund to the customer">
                <Input type="number" min="0" max={advance} className={inputCls} value={shown} onChange={(e) => setRefund(e.target.value)} />
              </Field>
              <p className="text-sm text-slate-700">The shop keeps <b>{money(Math.max(advance - Number(shown || 0), 0))}</b> of the advance.</p>
            </>
          )}
          <Field label="Reason"><Textarea className="w-full" rows={2} value={note} onChange={(e) => setNote(e.target.value)} /></Field>
          <div className="flex justify-end gap-2">
            <Button variant="outline" onClick={onClose}>Close</Button>
            <Button variant="danger" disabled={saving} onClick={submit}>{saving ? "Saving..." : "Cancel booking"}</Button>
          </div>
        </div>
      )}
    </Modal>
  );
}
