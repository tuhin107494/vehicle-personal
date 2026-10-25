"use client";

import { useEffect, useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { toast } from "sonner";
import { api, applyServerErrors } from "@/lib/api";
import { toPayload, MOBILE_MSG, MOBILE_RX, savedMsg } from "@/lib/format";
import type { Supplier } from "@/lib/types";
import { Button, Field, inputCls, Modal } from "@/components/app-ui";
import { PhotoField } from "@/components/PhotoField";
import { savePhoto, type PhotoChange } from "@/lib/photo";
import { BankFields, BANK_FIELDS } from "@/components/forms/BankFields";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";

const schema = z.object({
  name: z.string().min(1, "Name is required."),
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

const blank: Values = { name: "", phone: "", alt_phone: "", nid: "", address: "", bank_name: "", bank_branch: "", bank_account_name: "", bank_account_no: "", bank_info: "", notes: "" };

/** Add or edit a supplier, with optional bank details. `initialText` pre-fills the name (or the mobile number) from a search box. */
export function SupplierForm({ open, supplier, onClose, onSaved, initialText = "" }: { open: boolean; supplier?: Supplier | null; onClose: () => void; onSaved: (s: Supplier) => void; initialText?: string }) {
  const { register, handleSubmit, reset, setValue, setError, watch, formState: { errors, isSubmitting } } = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: blank,
  });

  const [photo, setPhoto] = useState<PhotoChange>(null);

  useEffect(() => {
    if (open) setPhoto(null);
    if (open) reset(supplier ? { ...blank, ...Object.fromEntries(Object.entries(supplier).filter(([k]) => k in blank).map(([k, v]) => [k, v ?? ""])) } : (/^[+\d][\d\s-]{4,}$/.test(initialText) ? { ...blank, phone: initialText } : { ...blank, name: initialText }));
  }, [open, supplier, reset, initialText]);

  const onSubmit = async (values: Values) => {
    try {
      const res = await api<Supplier>(supplier ? `/suppliers/${supplier.id}` : "/suppliers", {
        method: supplier ? "PUT" : "POST",
        body: {
          ...toPayload(values), alt_phone: values.alt_phone || null, nid: values.nid || null, address: values.address || null,
          bank_name: values.bank_name || null, bank_branch: values.bank_branch || null, bank_account_name: values.bank_account_name || null, bank_account_no: values.bank_account_no || null, bank_info: values.bank_info || null,
          notes: values.notes || null,
        },
      });
      let saved = res.data;
      try {
        const url = await savePhoto(`/suppliers/${res.data.id}`, photo);
        if (url !== undefined) saved = { ...saved, photo_url: url };
      } catch {
        toast.error("The supplier was saved, but the photo could not be uploaded.");
      }
      toast.success(savedMsg("Supplier", !!supplier));
      onSaved(saved);
      onClose();
    } catch (e) {
      toast.error(applyServerErrors(e, setError));
    }
  };

  return (
    <Modal open={open} onClose={onClose} title={`${supplier ? "Edit" : "New"} supplier`}>
      <form onSubmit={handleSubmit(onSubmit)} className="space-y-3" noValidate>
        <p className="text-xs text-slate-500"><span className="text-red-600">*</span> Required</p>
        <PhotoField name={watch("name")} url={supplier?.photo_url} change={photo} onChange={setPhoto} />
        <Field required label="Name" error={errors.name?.message}><Input className={inputCls} {...register("name")} /></Field>
        <div className="grid grid-cols-2 gap-3">
          <Field required label="Mobile number" error={errors.phone?.message}><Input className={inputCls} {...register("phone")} /></Field>
          <Field label="Alt. mobile number" error={errors.alt_phone?.message}><Input className={inputCls} {...register("alt_phone")} /></Field>
        </div>
        <Field label="NID" error={errors.nid?.message}><Input className={inputCls} {...register("nid")} /></Field>
        <Field label="Address" error={errors.address?.message}><Textarea rows={2} className={inputCls} {...register("address")} /></Field>
        <BankFields key={`${open}-${supplier?.id}`} register={register} errors={errors} setValue={setValue} startOpen={!!supplier && BANK_FIELDS.some((f) => supplier[f])} />
        <Field label="Notes" error={errors.notes?.message}><Textarea rows={2} className={inputCls} {...register("notes")} /></Field>
        <div className="flex justify-end gap-2">
          <Button variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={isSubmitting}>{isSubmitting ? "Saving..." : "Save"}</Button>
        </div>
      </form>
    </Modal>
  );
}
