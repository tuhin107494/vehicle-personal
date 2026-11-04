"use client";

import { useEffect } from "react";
import { useForm } from "react-hook-form";
import { toast } from "sonner";
import { api, applyServerErrors } from "@/lib/api";
import { fmtDate, todayStr } from "@/lib/format";
import { useFetch } from "@/lib/hooks";
import { Button, Field, inputCls, Modal } from "@/components/app-ui";
import { Input } from "@/components/ui/input";
import { NativeSelect } from "@/components/ui/native-select";

export type Tx = { type: "customer_payment" | "supplier_payment" | "supplier_advance"; id: number };
type Values = { amount: string; paid_at: string; method: string; reference: string; memo_no: string; note: string };
type Detail = { values: Values & { amount_lock: string | null; note_locked: string | null }; edits: { id: number; by: string | null; at: string; changes: { field: string; from: string | null; to: string | null }[] }[] };

/** Edit a recorded payment's details (date, method, reference, memo number, note). The amount is never changed here; each edit is kept in its history. */
export function TransactionEditModal({ tx, onClose, onDone }: { tx: Tx | null; onClose: () => void; onDone: () => void }) {
  const detail = useFetch<Detail>(tx ? `/transactions/${tx.type}/${tx.id}` : null);
  const { register, handleSubmit, reset, setError, formState: { errors, isSubmitting } } = useForm<Values>({ defaultValues: { amount: "", paid_at: "", method: "cash", reference: "", memo_no: "", note: "" } });

  useEffect(() => {
    const v = detail.data?.values;
    if (v) reset({ amount: v.amount, paid_at: v.paid_at, method: v.method, reference: v.reference ?? "", memo_no: v.memo_no ?? "", note: v.note ?? "" });
  }, [detail.data, reset]);

  const locked = detail.data?.values.amount_lock ?? null; // why the amount cannot change, if it cannot
  const noteLocked = !!detail.data?.values.note_locked;

  const onSubmit = async (v: Values) => {
    if (!tx) return;
    if (!v.paid_at) return setError("paid_at", { message: "Date is required." });
    if (!locked && !(Number(v.amount) > 0)) return setError("amount", { message: "Amount must be greater than zero." });
    try {
      const res = await api(`/transactions/${tx.type}/${tx.id}`, { method: "PUT", body: { ...(!locked && { amount: Number(v.amount) }), paid_at: v.paid_at, method: v.method, reference: v.reference || null, memo_no: tx.type === "customer_payment" ? v.memo_no || null : null, note: v.note || null } });
      toast.success(res.message);
      onDone();
      onClose();
    } catch (e) {
      toast.error(applyServerErrors(e, setError));
    }
  };

  return (
    <Modal open={!!tx} onClose={onClose} title="Edit transaction">
      {detail.loading && !detail.data ? <p className="text-sm text-slate-500">Loading...</p> : (
        <form onSubmit={handleSubmit(onSubmit)} noValidate className="space-y-3">
          <p className="text-xs text-slate-500">Every edit is kept in the history below. A new amount updates the installment, sale or purchase it was paid against.</p>
          <Field required label="Amount" error={errors.amount?.message}>
            <Input type="number" step="0.01" min="1" className={inputCls} disabled={!!locked} {...register("amount")} />
            {locked && <p className="mt-1 text-xs text-amber-700">{locked}</p>}
          </Field>
          <div className="grid gap-3 sm:grid-cols-2">
            <Field required label="Date" error={errors.paid_at?.message}><Input type="date" max={todayStr()} className={inputCls} {...register("paid_at")} /></Field>
            <Field required label="Method" error={errors.method?.message}>
              <NativeSelect className={inputCls} {...register("method")}><option value="cash">Cash</option><option value="bank">Bank</option><option value="mobile">Mobile</option></NativeSelect>
            </Field>
            <Field label="Reference" error={errors.reference?.message}><Input className={inputCls} {...register("reference")} /></Field>
            {tx?.type === "customer_payment" && <Field label="Memo no. (optional)" error={errors.memo_no?.message}><Input className={inputCls} {...register("memo_no")} /></Field>}
          </div>
          <Field label="Note" error={errors.note?.message}><Input className={inputCls} disabled={noteLocked} {...register("note")} /></Field>
          <div className="flex justify-end gap-2">
            <Button type="button" variant="outline" onClick={onClose}>Close</Button>
            <Button type="submit" disabled={isSubmitting}>{isSubmitting ? "Saving..." : "Save changes"}</Button>
          </div>
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
            ) : <p className="text-xs text-slate-500">This transaction has not been edited.</p>}
          </div>
        </form>
      )}
    </Modal>
  );
}
