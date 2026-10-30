"use client";

import { useAutoDate } from "@/lib/settings";
import { useEffect } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { toast } from "sonner";
import { api, applyServerErrors } from "@/lib/api";
import { addDays, fmtDate, money, todayStr, toPayload } from "@/lib/format";
import type { Sale } from "@/lib/types";
import { Button, Field, inputCls, Modal } from "@/components/app-ui";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { NativeSelect } from "@/components/ui/native-select";

function Actions({ onClose, busy, submit, danger }: { onClose: () => void; busy: boolean; submit: string; danger?: boolean }) {
  return (
    <div className="flex justify-end gap-2">
      <Button variant="outline" onClick={onClose}>Close</Button>
      <Button type="submit" variant={danger ? "danger" : "primary"} disabled={busy}>{busy ? "Saving..." : submit}</Button>
    </div>
  );
}

export function CollectPaymentModal({ open, sale, onClose, onDone }: { open: boolean; sale: Sale; onClose: () => void; onDone: () => void }) {
  const schema = z.object({
    amount: z.string().min(1, "Enter the amount received.").refine((v) => Number(v) > 0, "Amount must be greater than zero.")
      .refine((v) => Number(v) <= sale.due_amount, `Amount cannot be more than the due amount (${money(sale.due_amount)}).`),
    installment_id: z.string(),
    method: z.enum(["cash", "bank", "mobile"]),
    paid_at: z.string().min(1, "Payment date is required.").refine((v) => v <= todayStr(), "Payment date cannot be in the future."),
    reference: z.string(),
    note: z.string(),
  });
  type Values = z.infer<typeof schema>;
  const autoDate = useAutoDate();
  const { register, handleSubmit, reset, setError, formState: { errors, isSubmitting } } = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: { amount: "", installment_id: "", method: "cash", paid_at: autoDate, reference: "", note: "" },
  });

  useEffect(() => {
    if (open) reset({ amount: "", installment_id: "", method: "cash", paid_at: autoDate, reference: "", note: "" });
  }, [open, reset, autoDate]);

  const unpaid = (sale.plan?.installments ?? []).filter((i) => !["paid", "cancelled"].includes(i.status));

  const onSubmit = async (v: Values) => {
    try {
      const res = await api(`/sales/${sale.id}/payments`, { method: "POST", body: toPayload(v, ["amount", "installment_id"]) });
      toast.success(res.message);
      onDone();
      onClose();
    } catch (e) {
      toast.error(applyServerErrors(e, setError));
    }
  };

  return (
    <Modal open={open} onClose={onClose} title="Collect payment">
      <form onSubmit={handleSubmit(onSubmit)} className="space-y-3">
        <p className="text-sm text-gray-600">Amount due: <b>{money(sale.due_amount)}</b></p>
        <div className="grid grid-cols-2 gap-3">
          <Field required label="Amount" error={errors.amount?.message}><Input type="number" step="0.01" className={inputCls} {...register("amount")} /></Field>
          <Field required label="Date" error={errors.paid_at?.message}><Input type="date" className={inputCls} {...register("paid_at")} /></Field>
        </div>
        {sale.payment_type === "installment" && (
          <Field label="Apply to installment (optional)" error={errors.installment_id?.message}>
            <NativeSelect className={inputCls} {...register("installment_id")}>
              <option value="">Oldest unpaid first</option>
              {unpaid.map((i) => <option key={i.id} value={i.id}>#{i.seq_no} · {fmtDate(i.due_date)} · remaining {money(i.amount - i.paid_amount)}</option>)}
            </NativeSelect>
          </Field>
        )}
        <div className="grid grid-cols-2 gap-3">
          <Field label="Method" error={errors.method?.message}>
            <NativeSelect className={inputCls} {...register("method")}><option value="cash">Cash</option><option value="bank">Bank</option><option value="mobile">Mobile</option></NativeSelect>
          </Field>
          <Field label="Reference" error={errors.reference?.message}><Input className={inputCls} {...register("reference")} /></Field>
        </div>
        <Field label="Note" error={errors.note?.message}><Input className={inputCls} {...register("note")} /></Field>
        <Actions onClose={onClose} busy={isSubmitting} submit="Collect" />
      </form>
    </Modal>
  );
}

export function PromiseModal({ open, sale, onClose, onDone }: { open: boolean; sale: Sale; onClose: () => void; onDone: () => void }) {
  const schema = z.object({
    promised_date: z.string().min(1, "Promise date is required.").refine((v) => v >= todayStr(), "Promise date cannot be in the past."),
    promised_amount: z.string().refine((v) => v === "" || (Number(v) > 0 && Number(v) <= sale.due_amount), "Enter an amount up to the due amount."),
    note: z.string(),
  });
  type Values = z.infer<typeof schema>;
  const { register, handleSubmit, reset, setError, formState: { errors, isSubmitting } } = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: { promised_date: addDays(7), promised_amount: "", note: "" },
  });

  useEffect(() => {
    if (open) reset({ promised_date: addDays(7), promised_amount: "", note: "" });
  }, [open, reset]);

  const onSubmit = async (v: Values) => {
    try {
      const res = await api(`/sales/${sale.id}/promises`, { method: "POST", body: toPayload(v, ["promised_amount"]) });
      toast.success(res.message);
      onDone();
      onClose();
    } catch (e) {
      toast.error(applyServerErrors(e, setError));
    }
  };

  return (
    <Modal open={open} onClose={onClose} title="Add / renew promise date">
      <form onSubmit={handleSubmit(onSubmit)} className="space-y-3">
        <p className="text-sm text-gray-600">Adding a new promise replaces any promise that is still open. Amount due: <b>{money(sale.due_amount)}</b></p>
        <div className="grid grid-cols-2 gap-3">
          <Field required label="Promised date" error={errors.promised_date?.message}><Input type="date" className={inputCls} {...register("promised_date")} /></Field>
          <Field label="Amount (defaults to full due)" error={errors.promised_amount?.message}><Input type="number" step="0.01" className={inputCls} {...register("promised_amount")} /></Field>
        </div>
        <Field label="Note" error={errors.note?.message}><Input className={inputCls} {...register("note")} /></Field>
        <Actions onClose={onClose} busy={isSubmitting} submit="Save promise" />
      </form>
    </Modal>
  );
}

export function CancelSaleModal({ open, sale, onClose, onDone }: { open: boolean; sale: Sale; onClose: () => void; onDone: () => void }) {
  const schema = z.object({
    reason: z.string().min(1, "A reason is required to cancel a sale."),
    refund_amount: z.string().refine((v) => v === "" || (Number(v) >= 0 && Number(v) <= sale.total_paid), `Refund cannot be more than the amount paid (${money(sale.total_paid)}).`),
  });
  type Values = z.infer<typeof schema>;
  const { register, handleSubmit, reset, setError, formState: { errors, isSubmitting } } = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: { reason: "", refund_amount: "" },
  });

  useEffect(() => {
    if (open) reset({ reason: "", refund_amount: "" });
  }, [open, reset]);

  const onSubmit = async (v: Values) => {
    try {
      const res = await api(`/sales/${sale.id}/cancel`, { method: "POST", body: toPayload(v, ["refund_amount"]) });
      toast.success(res.message);
      onDone();
      onClose();
    } catch (e) {
      toast.error(applyServerErrors(e, setError));
    }
  };

  return (
    <Modal open={open} onClose={onClose} title="Cancel sale">
      <form onSubmit={handleSubmit(onSubmit)} className="space-y-3">
        <p className="text-sm text-gray-600">The vehicle returns to stock, pending installments and promises are cancelled, and the history is kept. Paid so far: <b>{money(sale.total_paid)}</b></p>
        <Field required label="Reason" error={errors.reason?.message}><Textarea rows={2} className={inputCls} {...register("reason")} /></Field>
        <Field label="Refund to buyer (optional)" error={errors.refund_amount?.message}><Input type="number" step="0.01" className={inputCls} {...register("refund_amount")} /></Field>
        <Actions onClose={onClose} busy={isSubmitting} submit="Cancel sale" danger />
      </form>
    </Modal>
  );
}
