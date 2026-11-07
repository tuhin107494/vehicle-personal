"use client";

import { useEffect } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { toast } from "sonner";
import { api, applyServerErrors } from "@/lib/api";
import { Button, Field, inputCls, Modal } from "@/components/app-ui";
import { Input } from "@/components/ui/input";
import type { Tx } from "@/components/forms/TransactionEditModal";

const schema = z.object({ reason: z.string().trim().min(1, "Enter the reason for cancelling.").max(255, "The reason is too long.") });
type Values = z.infer<typeof schema>;

/** Cancels a customer or supplier payment. It stays on the statement as cancelled, with the reason; its amount comes out of the sale or purchase it was paid against. */
export function CancelTransactionModal({ tx, onClose, onDone }: { tx: Tx | null; onClose: () => void; onDone: () => void }) {
  const { register, handleSubmit, reset, setError, formState: { errors, isSubmitting } } = useForm<Values>({ resolver: zodResolver(schema), defaultValues: { reason: "" } });

  useEffect(() => {
    if (tx) reset({ reason: "" });
  }, [tx, reset]);

  const onSubmit = async (v: Values) => {
    if (!tx) return;
    try {
      const res = await api(`/transactions/${tx.type}/${tx.id}/cancel`, { method: "POST", body: { reason: v.reason } });
      toast.success(res.message);
      onDone();
      onClose();
    } catch (e) {
      toast.error(applyServerErrors(e, setError));
    }
  };

  return (
    <Modal open={!!tx} onClose={onClose} title="Cancel transaction">
      <form onSubmit={handleSubmit(onSubmit)} className="space-y-3">
        <p className="text-sm text-slate-600">The payment stays on the statement as cancelled. Its amount is taken back out of the sale or purchase it was paid against.</p>
        <Field required label="Reason" error={errors.reason?.message}><Input className={inputCls} {...register("reason")} autoFocus /></Field>
        <div className="flex justify-end gap-2">
          <Button variant="outline" onClick={onClose}>Back</Button>
          <Button type="submit" variant="danger" disabled={isSubmitting}>{isSubmitting ? "Saving..." : "Cancel transaction"}</Button>
        </div>
      </form>
    </Modal>
  );
}
