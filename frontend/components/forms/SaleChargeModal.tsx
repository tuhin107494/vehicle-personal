"use client";

import { useEffect } from "react";
import { useForm } from "react-hook-form";
import { toast } from "sonner";
import { api, applyServerErrors } from "@/lib/api";
import { fmtDate, todayStr } from "@/lib/format";
import { useFetch } from "@/lib/hooks";
import { useAutoDate } from "@/lib/settings";
import { Button, Field, inputCls, Modal } from "@/components/app-ui";
import { Input } from "@/components/ui/input";

type Values = { purpose: string; amount: string; charged_on: string; note: string };
type Detail = { values: Values; edits: { id: number; by: string | null; at: string; changes: { field: string; from: string | null; to: string | null }[] }[] };

/** Add a charge to a sale (extra money the customer owes), or edit one. Each edit is kept in its history. */
export function SaleChargeModal({ open, saleId, chargeId, onClose, onDone }: { open: boolean; saleId: number; chargeId: number | null; onClose: () => void; onDone: () => void }) {
  const autoDate = useAutoDate();
  const detail = useFetch<Detail>(open && chargeId ? `/charges/${chargeId}` : null);
  const { register, handleSubmit, reset, setError, formState: { errors, isSubmitting } } = useForm<Values>({ defaultValues: { purpose: "", amount: "", charged_on: autoDate, note: "" } });

  useEffect(() => {
    if (!open) return;
    const v = detail.data?.values;
    if (chargeId) {
      if (v) reset({ purpose: v.purpose, amount: v.amount, charged_on: v.charged_on, note: v.note ?? "" });
    } else {
      reset({ purpose: "", amount: "", charged_on: autoDate, note: "" });
    }
  }, [open, chargeId, detail.data, autoDate, reset]);

  const onSubmit = async (v: Values) => {
    if (!v.purpose.trim()) return setError("purpose", { message: "Enter what the charge is for." });
    if (!(Number(v.amount) > 0)) return setError("amount", { message: "Amount must be greater than zero." });
    if (!v.charged_on) return setError("charged_on", { message: "Date is required." });
    try {
      const res = await api(chargeId ? `/charges/${chargeId}` : `/sales/${saleId}/charges`, { method: chargeId ? "PUT" : "POST", body: { purpose: v.purpose.trim(), amount: Number(v.amount), charged_on: v.charged_on, note: v.note || null } });
      toast.success(res.message);
      onDone();
      onClose();
    } catch (e) {
      toast.error(applyServerErrors(e, setError));
    }
  };

  return (
    <Modal open={open} onClose={onClose} title={chargeId ? "Edit charge" : "Add charge"}>
      <form onSubmit={handleSubmit(onSubmit)} noValidate className="space-y-3">
        <p className="text-xs text-slate-500">The customer owes this on top of the sale. It is collected through Due collection, after the sale's own balance.</p>
        <Field required label="Purpose" error={errors.purpose?.message}>
          <Input className={inputCls} {...register("purpose")} />
        </Field>
        <div className="grid gap-3 sm:grid-cols-2">
          <Field required label="Amount" error={errors.amount?.message}><Input type="number" step="0.01" min="1" className={inputCls} {...register("amount")} /></Field>
          <Field required label="Date" error={errors.charged_on?.message}><Input type="date" max={todayStr()} className={inputCls} {...register("charged_on")} /></Field>
        </div>
        <Field label="Note" error={errors.note?.message}><Input className={inputCls} {...register("note")} /></Field>
        <div className="flex justify-end gap-2">
          <Button type="button" variant="outline" onClick={onClose}>Close</Button>
          <Button type="submit" disabled={isSubmitting}>{isSubmitting ? "Saving..." : chargeId ? "Save changes" : "Add charge"}</Button>
        </div>
        {chargeId && (
          <div>
            <h3 className="mb-1 text-sm font-semibold text-slate-700">Edit history</h3>
            {detail.data?.edits.length ? (
              <ul className="space-y-2 text-xs">
                {detail.data.edits.map((e) => (
                  <li key={e.id} className="rounded-lg bg-slate-50 p-2 ring-1 ring-slate-200">
                    <div className="text-slate-500">{fmtDate(e.at)} · {e.by ?? "-"}</div>
                    {e.changes.map((c) => <div key={c.field}><span className="font-medium">{c.field}:</span> <span className="text-slate-500">{c.from ?? "-"}</span> → <b>{c.to ?? "-"}</b></div>)}
                  </li>
                ))}
              </ul>
            ) : <p className="text-xs text-slate-500">This charge has not been edited.</p>}
          </div>
        )}
      </form>
    </Modal>
  );
}
