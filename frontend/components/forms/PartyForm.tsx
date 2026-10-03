"use client";

import { useEffect } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { toast } from "sonner";
import { api, applyServerErrors } from "@/lib/api";
import { toPayload, MOBILE_MSG, MOBILE_RX, savedMsg } from "@/lib/format";
import type { Party } from "@/lib/types";
import { Button, Field, inputCls, Modal } from "@/components/app-ui";
import { BankFields, BANK_FIELDS } from "@/components/forms/BankFields";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";

const schema = z.object({
  name: z.string().min(1, "Name is required."),
  father_name: z.string(),
  phone: z.string().min(1, "Mobile number is required.").regex(MOBILE_RX, MOBILE_MSG),
  alt_phone: z.string().refine((v) => v === "" || MOBILE_RX.test(v), MOBILE_MSG),
  nid: z.string(),
  address: z.string(),
  bank_name: z.string(),
  bank_branch: z.string(),
  bank_account_name: z.string(),
  bank_account_no: z.string(),
  bank_info: z.string(),
  notes: z.string(),
});
type Values = z.infer<typeof schema>;

const blank: Values = { name: "", father_name: "", phone: "", alt_phone: "", nid: "", address: "", bank_name: "", bank_branch: "", bank_account_name: "", bank_account_no: "", bank_info: "", notes: "" };

/** `kind` is what a NEW party becomes: customers are added on the Customers page, suppliers on the Suppliers page. */
export function PartyForm({ open, party, onClose, onSaved, kind = "customer", initialText = "" }: { open: boolean; party?: Party | null; onClose: () => void; onSaved: (p: Party) => void; kind?: "customer" | "supplier"; initialText?: string }) {
  const { register, handleSubmit, reset, setValue, setError, formState: { errors, isSubmitting } } = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: blank,
  });

  useEffect(() => {
    if (open) reset(party ? { ...blank, ...Object.fromEntries(Object.entries(party).map(([k, v]) => [k, v ?? ""])) } : (/^[+\d][\d\s-]{4,}$/.test(initialText) ? { ...blank, phone: initialText } : { ...blank, name: initialText }));
  }, [open, party, reset, initialText]);

  const isCustomer = (party ? party.type : kind) !== "supplier"; // father's name is asked for customers only

  const onSubmit = async (values: Values) => {
    try {
      const res = await api<Party>(party ? `/parties/${party.id}` : "/parties", {
        method: party ? "PUT" : "POST",
        body: { ...toPayload(values), type: party ? party.type : kind, father_name: isCustomer ? values.father_name || null : null, alt_phone: values.alt_phone || null, nid: values.nid || null, address: values.address || null, bank_name: isCustomer ? null : values.bank_name || null, bank_branch: isCustomer ? null : values.bank_branch || null, bank_account_name: isCustomer ? null : values.bank_account_name || null, bank_account_no: isCustomer ? null : values.bank_account_no || null, bank_info: isCustomer ? null : values.bank_info || null, notes: values.notes || null },
      });
      toast.success(savedMsg((party ? party.type : kind) === "supplier" ? "Supplier" : "Customer", !!party));
      onSaved(res.data);
      onClose();
    } catch (e) {
      toast.error(applyServerErrors(e, setError));
    }
  };

  return (
    <Modal open={open} onClose={onClose} title={`${party ? "Edit" : "New"} ${(party ? party.type : kind) === "supplier" ? "supplier" : "customer"}`}>
      <form onSubmit={handleSubmit(onSubmit)} className="space-y-3" noValidate>
        <p className="text-xs text-slate-500"><span className="text-red-600">*</span> Required</p>
        <Field required label="Name" error={errors.name?.message}><Input className={inputCls} {...register("name")} /></Field>
        {isCustomer && <Field label="Father's name" error={errors.father_name?.message}><Input className={inputCls} {...register("father_name")} /></Field>}
        <div className="grid grid-cols-2 gap-3">
          <Field required label="Mobile number" error={errors.phone?.message}><Input className={inputCls} {...register("phone")} /></Field>
          <Field label="Alt. mobile number" error={errors.alt_phone?.message}><Input className={inputCls} {...register("alt_phone")} /></Field>
        </div>
        <Field label="NID" error={errors.nid?.message}><Input className={inputCls} {...register("nid")} /></Field>
        <Field label="Address" error={errors.address?.message}><Textarea rows={2} className={inputCls} {...register("address")} /></Field>
        {!isCustomer && <BankFields key={`${open}-${party?.id}`} register={register} errors={errors} setValue={setValue} startOpen={!!party && BANK_FIELDS.some((f) => party[f])} />}
        <Field label="Notes" error={errors.notes?.message}><Textarea rows={2} className={inputCls} {...register("notes")} /></Field>
        <div className="flex justify-end gap-2">
          <Button variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={isSubmitting}>{isSubmitting ? "Saving..." : "Save"}</Button>
        </div>
      </form>
    </Modal>
  );
}
