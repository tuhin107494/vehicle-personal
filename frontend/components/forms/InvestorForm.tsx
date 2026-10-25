"use client";

import { useEffect, useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { toast } from "sonner";
import { api, applyServerErrors } from "@/lib/api";
import type { Investor } from "@/lib/types";
import { MOBILE_MSG, MOBILE_RX, savedMsg } from "@/lib/format";
import { Button, Field, inputCls, Modal } from "@/components/app-ui";
import { PhotoField } from "@/components/PhotoField";
import { savePhoto, type PhotoChange } from "@/lib/photo";
import { BankFields, BANK_FIELDS } from "@/components/forms/BankFields";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";

const schema = z.object({
  name: z.string().min(1, "Investor name is required."),
  phone: z.string().min(1, "Mobile number is required.").regex(MOBILE_RX, MOBILE_MSG),
  alt_phone: z.string().refine((v) => v === "" || MOBILE_RX.test(v), MOBILE_MSG),
  nid: z.string(),
  address: z.string(),
  bank_name: z.string(),
  bank_branch: z.string(),
  bank_account_name: z.string(),
  bank_account_no: z.string(),
  bank_info: z.string(),
  opening_balance: z.string().refine((v) => v === "" || Number(v) >= 0, "Enter a valid amount."),
  notes: z.string(),
  is_active: z.boolean(),
});
type Values = z.infer<typeof schema>;

const blank: Values = { name: "", phone: "", alt_phone: "", nid: "", address: "", bank_name: "", bank_branch: "", bank_account_name: "", bank_account_no: "", bank_info: "", opening_balance: "0", notes: "", is_active: true };

export function InvestorForm({ open, investor, onClose, onSaved }: { open: boolean; investor?: Investor | null; onClose: () => void; onSaved: () => void }) {
  const { register, handleSubmit, reset, setValue, setError, watch, formState: { errors, isSubmitting } } = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: blank,
  });

  const [photo, setPhoto] = useState<PhotoChange>(null);

  useEffect(() => {
    if (!open) return;
    setPhoto(null);
    reset(investor ? {
      name: investor.name, phone: investor.phone ?? "", alt_phone: investor.alt_phone ?? "", nid: investor.nid ?? "", address: investor.address ?? "",
      bank_name: investor.bank_name ?? "", bank_branch: investor.bank_branch ?? "", bank_account_name: investor.bank_account_name ?? "", bank_account_no: investor.bank_account_no ?? "", bank_info: investor.bank_info ?? "", opening_balance: String(investor.opening_balance ?? 0),
      notes: investor.notes ?? "", is_active: investor.is_active,
    } : blank);
  }, [open, investor, reset]);

  const onSubmit = async (v: Values) => {
    try {
      const res = await api<Investor>(investor ? `/investors/${investor.id}` : "/investors", {
        method: investor ? "PUT" : "POST",
        body: {
          ...v, phone: v.phone || null, alt_phone: v.alt_phone || null, nid: v.nid || null, address: v.address || null, bank_name: v.bank_name || null, bank_branch: v.bank_branch || null, bank_account_name: v.bank_account_name || null, bank_account_no: v.bank_account_no || null, bank_info: v.bank_info || null,
          notes: v.notes || null, opening_balance: Number(v.opening_balance || 0),
        },
      });
      try {
        await savePhoto(`/investors/${res.data.id}`, photo);
      } catch {
        toast.error("The investor was saved, but the photo could not be uploaded.");
      }
      toast.success(savedMsg("Investor", !!investor));
      onSaved();
      onClose();
    } catch (e) {
      toast.error(applyServerErrors(e, setError));
    }
  };

  return (
    <Modal open={open} onClose={onClose} title={investor ? "Edit investor" : "New investor"}>
      <form onSubmit={handleSubmit(onSubmit)} className="space-y-3" noValidate>
        <p className="text-xs text-slate-500"><span className="text-red-600">*</span> Required</p>
        <PhotoField name={watch("name")} url={investor?.photo_url} change={photo} onChange={setPhoto} />
        <Field required label="Name" error={errors.name?.message}><Input className={inputCls} {...register("name")} /></Field>
        <div className="grid grid-cols-2 gap-3">
          <Field required label="Mobile number" error={errors.phone?.message}><Input className={inputCls} {...register("phone")} /></Field>
          <Field label="Alt. mobile number" error={errors.alt_phone?.message}><Input className={inputCls} {...register("alt_phone")} /></Field>
        </div>
        <Field label="NID" error={errors.nid?.message}><Input className={inputCls} {...register("nid")} /></Field>
        <Field label="Address" error={errors.address?.message}><Textarea rows={2} className={inputCls} {...register("address")} /></Field>
        <Field label="Opening balance" error={errors.opening_balance?.message}><Input type="number" step="0.01" className={inputCls} {...register("opening_balance")} /></Field>
        <BankFields key={`${open}-${investor?.id}`} register={register} errors={errors} setValue={setValue} startOpen={!!investor && BANK_FIELDS.some((f) => investor[f])} />
        <Field label="Notes" error={errors.notes?.message}><Textarea rows={2} className={inputCls} {...register("notes")} /></Field>
        <label className="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" {...register("is_active")} /> Active</label>
        <div className="flex justify-end gap-2">
          <Button variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={isSubmitting}>{isSubmitting ? "Saving..." : "Save"}</Button>
        </div>
      </form>
    </Modal>
  );
}
