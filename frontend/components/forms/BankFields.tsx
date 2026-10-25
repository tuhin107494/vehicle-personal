"use client";

import { useState } from "react";
import type { UseFormRegisterReturn } from "react-hook-form";
import { Plus } from "lucide-react";
import { Field, inputCls } from "@/components/app-ui";
import { Input } from "@/components/ui/input";

export const BANK_FIELDS = ["bank_name", "bank_branch", "bank_account_name", "bank_account_no", "bank_info"] as const;

type BankField = (typeof BANK_FIELDS)[number];

/** Optional bank details for a supplier or investor: hidden behind a button until they are needed. Takes any form that has the bank fields. */
export function BankFields({ register, errors, setValue, startOpen }: {
  register: (name: BankField) => UseFormRegisterReturn;
  errors: Partial<Record<BankField, { message?: string }>>;
  setValue: (name: BankField, value: string) => void;
  startOpen: boolean;
}) {
  const [open, setOpen] = useState(startOpen);
  const err = (f: BankField) => errors[f]?.message;

  if (!open) {
    return (
      <button type="button" onClick={() => setOpen(true)} className="inline-flex items-center gap-1.5 rounded-lg border border-sky-200 px-3 py-1.5 text-sm font-medium text-sky-700 hover:bg-sky-50"><Plus className="size-4" /> Add bank details</button>
    );
  }

  return (
    <fieldset className="space-y-3 rounded-lg border border-sky-200 p-3">
      <legend className="flex items-center gap-2 px-1 text-xs font-semibold uppercase tracking-wide text-sky-700">
        Bank details
        <button type="button" onClick={() => { setOpen(false); BANK_FIELDS.forEach((f) => setValue(f, "")); }} className="font-normal normal-case text-slate-500 underline hover:text-red-600">remove</button>
      </legend>
      <div className="grid grid-cols-2 gap-3">
        <Field label="Bank name" error={err("bank_name")}><Input className={inputCls} {...register("bank_name")} /></Field>
        <Field label="Branch" error={err("bank_branch")}><Input className={inputCls} {...register("bank_branch")} /></Field>
        <Field label="Account name" error={err("bank_account_name")}><Input className={inputCls} {...register("bank_account_name")} /></Field>
        <Field label="Account number" error={err("bank_account_no")}><Input className={inputCls} {...register("bank_account_no")} /></Field>
      </div>
      <Field label="Other (mobile banking, notes)" error={err("bank_info")}><Input className={inputCls} placeholder="e.g. bKash 01711000000" {...register("bank_info")} /></Field>
    </fieldset>
  );
}
