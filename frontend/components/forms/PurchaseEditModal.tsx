"use client";

import { useEffect } from "react";
import { useForm } from "react-hook-form";
import { toast } from "sonner";
import { api, applyServerErrors } from "@/lib/api";
import { fmtDate, todayStr } from "@/lib/format";
import type { Purchase } from "@/lib/types";
import { Button, Field, inputCls, Modal } from "@/components/app-ui";
import { Input } from "@/components/ui/input";

type Values = { price: string; paid_amount: string; cost_extra: string; purchase_date: string; memo_no: string; notes: string };

const valuesOf = (p: Purchase): Values => ({ price: String(p.price), paid_amount: String(p.paid_amount), cost_extra: String(p.cost_extra), purchase_date: p.purchase_date, memo_no: p.memo_no ?? "", notes: p.notes ?? "" });

/** Edit a purchase's price, paid amount and other details. Every edit is kept in its history. */
export function PurchaseEditModal({ purchase, onClose, onSaved }: { purchase: Purchase | null; onClose: () => void; onSaved: () => void }) {
  const { register, handleSubmit, reset, setError, formState: { errors, isSubmitting } } = useForm<Values>();

  useEffect(() => {
    if (purchase) reset(valuesOf(purchase));
  }, [purchase, reset]);

  // once supplier payments are recorded, the paid amount is their total and only changes through payments
  const paidLocked = !!purchase?.has_payments;

  const onSubmit = async (v: Values) => {
    if (!purchase) return;
    if (!(Number(v.price) > 0)) return setError("price", { message: "Price must be greater than zero." });
    if (!v.purchase_date) return setError("purchase_date", { message: "Date is required." });
    try {
      const res = await api(`/purchases/${purchase.id}`, {
        method: "PUT",
        body: { price: Number(v.price), ...(!paidLocked && { paid_amount: Number(v.paid_amount || 0) }), cost_extra: Number(v.cost_extra || 0), purchase_date: v.purchase_date, memo_no: v.memo_no || null, notes: v.notes || null },
      });
      toast.success(res.message);
      onSaved();
      onClose();
    } catch (e) {
      toast.error(applyServerErrors(e, setError));
    }
  };

  return (
    <Modal open={!!purchase} onClose={onClose} title="Edit purchase">
      <form onSubmit={handleSubmit(onSubmit)} noValidate className="space-y-3">
        <p className="text-xs text-slate-500">Every edit is kept in the history below. The paid amount cannot be more than the price.</p>
        <div className="grid gap-3 sm:grid-cols-2">
          <Field required label="Purchase price" error={errors.price?.message}><Input type="number" step="0.01" min="1" className={inputCls} {...register("price")} /></Field>
          <Field label="Paid amount" error={errors.paid_amount?.message}>
            <Input type="number" step="0.01" min="0" className={inputCls} disabled={paidLocked} {...register("paid_amount")} />
            {paidLocked && <p className="mt-1 text-xs text-amber-700">Payments were recorded against this purchase, so the paid amount changes through payments (edit or delete a payment).</p>}
          </Field>
          <Field label="Extra cost" error={errors.cost_extra?.message}><Input type="number" step="0.01" min="0" className={inputCls} {...register("cost_extra")} /></Field>
          <Field required label="Date" error={errors.purchase_date?.message}><Input type="date" max={todayStr()} className={inputCls} {...register("purchase_date")} /></Field>
          <Field label="Memo no. (optional)" error={errors.memo_no?.message}><Input className={inputCls} {...register("memo_no")} /></Field>
          <Field label="Notes" error={errors.notes?.message}><Input className={inputCls} {...register("notes")} /></Field>
        </div>
        <div className="flex justify-end gap-2">
          <Button type="button" variant="outline" onClick={onClose}>Close</Button>
          <Button type="submit" disabled={isSubmitting}>{isSubmitting ? "Saving..." : "Save changes"}</Button>
        </div>
        <div>
          <h3 className="mb-1 text-sm font-semibold text-slate-700">Edit history</h3>
          {purchase?.edits?.length ? (
            <ul className="space-y-2 text-xs">
              {purchase.edits.map((e) => (
                <li key={e.id} className="rounded-lg bg-slate-50 p-2 ring-1 ring-slate-200">
                  <div className="text-slate-500">{fmtDate(e.at)} · {e.by ?? "-"}</div>
                  {e.changes.map((c) => <div key={c.field}><span className="font-medium">{c.field}:</span> <span className="text-slate-500">{c.from ?? "-"}</span> → <b>{c.to ?? "-"}</b></div>)}
                </li>
              ))}
            </ul>
          ) : <p className="text-xs text-slate-500">This purchase has not been edited.</p>}
        </div>
      </form>
    </Modal>
  );
}
