"use client";

import { useEffect } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { toast } from "sonner";
import { api, applyServerErrors } from "@/lib/api";
import { MOBILE_MSG, MOBILE_RX, savedMsg } from "@/lib/format";
import type { WalkIn } from "@/lib/types";
import { Button, Field, inputCls, Modal } from "@/components/app-ui";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";

const schema = z.object({
  name: z.string().max(255),
  phone: z.string().min(1, "Mobile number is required.").regex(MOBILE_RX, MOBILE_MSG),
  address: z.string().max(1000),
  note: z.string().max(2000),
});
type Values = z.infer<typeof schema>;

const blank: Values = { name: "", phone: "", address: "", note: "" };

/** Add or edit someone who visited the shop but has not bought yet. */
export function WalkInForm({ open, walkIn, onClose, onSaved }: { open: boolean; walkIn?: WalkIn | null; onClose: () => void; onSaved: () => void }) {
  const { register, handleSubmit, reset, setError, formState: { errors, isSubmitting } } = useForm<Values>({ resolver: zodResolver(schema), defaultValues: blank });

  useEffect(() => {
    if (open) reset(walkIn ? { name: walkIn.name ?? "", phone: walkIn.phone, address: walkIn.address ?? "", note: walkIn.note ?? "" } : blank);
  }, [open, walkIn, reset]);

  const onSubmit = async (v: Values) => {
    try {
      await api(walkIn ? `/walk-ins/${walkIn.id}` : "/walk-ins", {
        method: walkIn ? "PUT" : "POST",
        body: { name: v.name || null, phone: v.phone, address: v.address || null, note: v.note || null },
      });
      toast.success(savedMsg("Walk-in customer", !!walkIn));
      onSaved();
      onClose();
    } catch (e) {
      toast.error(applyServerErrors(e, setError));
    }
  };

  return (
    <Modal open={open} onClose={onClose} title={`${walkIn ? "Edit" : "New"} walk-in customer`}>
      <form onSubmit={handleSubmit(onSubmit)} className="space-y-3" noValidate>
        <p className="text-xs text-slate-500"><span className="text-red-600">*</span> Required</p>
        <Field required label="Mobile number" error={errors.phone?.message}><Input className={inputCls} autoComplete="off" {...register("phone")} /></Field>
        <Field label="Name (optional)" error={errors.name?.message}><Input className={inputCls} autoComplete="off" {...register("name")} /></Field>
        <Field label="Address" error={errors.address?.message}><Textarea rows={2} className={inputCls} {...register("address")} /></Field>
        <Field label="Note" error={errors.note?.message}><Textarea rows={3} className={inputCls} placeholder="What they were looking for, when to call back..." {...register("note")} /></Field>
        <div className="flex justify-end gap-2">
          <Button variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={isSubmitting}>{isSubmitting ? "Saving..." : "Save"}</Button>
        </div>
      </form>
    </Modal>
  );
}
