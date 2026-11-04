"use client";

import { useEffect } from "react";
import { useForm } from "react-hook-form";
import { toast } from "sonner";
import { api, applyServerErrors } from "@/lib/api";
import { toPayload } from "@/lib/format";
import { useMemoField } from "@/lib/settings";
import type { Sale } from "@/lib/types";
import { Button, Field, inputCls, Modal } from "@/components/app-ui";
import { CustomerSelect, InvestorSelect } from "@/components/Selects";
import { Input } from "@/components/ui/input";
import { NativeSelect } from "@/components/ui/native-select";

type Values = {
  party_id: string; sale_date: string; sale_price: string; payment_type: "cash" | "due" | "installment"; down_payment: string; method: string;
  installment_count: string; first_due_date: string; frequency: string; interest_amount: string; investor_id: string; investor_share_percent: string;
  promised_date: string; promise_note: string; memo_no: string; guarantor_name: string; guarantor_phone: string;
};

/** What the sale holds now, as the form's text fields. */
function valuesOf(sale: Sale): Values {
  const plan = sale.plan;
  const promise = [...(sale.promises ?? [])].sort((a, b) => a.id - b.id)[0];
  const atSale = (sale.payments ?? []).find((p) => ["Down payment", "Full payment at sale"].includes(p.note ?? ""));
  return {
    party_id: String(sale.customer_id), sale_date: sale.sale_date, sale_price: String(sale.sale_price), payment_type: sale.payment_type,
    down_payment: sale.payment_type === "cash" ? "" : String(sale.down_payment), method: atSale?.method ?? "cash",
    installment_count: plan ? String(plan.number_of_installments) : "12", first_due_date: plan?.start_date ?? "", frequency: plan?.frequency ?? "monthly",
    interest_amount: plan ? String(plan.interest_amount) : "", investor_id: plan && sale.investor ? String(sale.investor.id) : "",
    investor_share_percent: plan?.investor_share_percent ? String(plan.investor_share_percent) : "",
    promised_date: promise?.promised_date ?? "", promise_note: promise?.note ?? "", memo_no: sale.memo_no ?? "",
    guarantor_name: sale.guarantor_name ?? "", guarantor_phone: sale.guarantor_phone ?? "",
  };
}

/** Edit a sale: every field can change, and each edit is kept in the sale's history. Money terms lock once a payment is collected (the server says so). */
export function SaleEditModal({ open, sale, onClose, onDone }: { open: boolean; sale: Sale; onClose: () => void; onDone: () => void }) {
  const showMemo = useMemoField() || !!sale.memo_no;
  const { register, handleSubmit, reset, setValue, setError, watch, formState: { errors, isSubmitting } } = useForm<Values>({ defaultValues: valuesOf(sale) });
  const w = watch();

  useEffect(() => {
    if (open) reset(valuesOf(sale));
  }, [open, sale, reset]);

  const type = w.payment_type;

  const onSubmit = async (v: Values) => {
    if (!v.party_id) return setError("party_id", { message: "Select the buyer." });
    if (!v.sale_date) return setError("sale_date", { message: "Sale date is required." });
    if (!v.sale_price || Number(v.sale_price) < 1) return setError("sale_price", { message: "Sale price is required." });
    if (type !== "cash" && v.down_payment === "") return setError("down_payment", { message: "Enter the down payment." });
    try {
      const body = toPayload({
        customer_id: v.party_id, sale_date: v.sale_date, sale_price: v.sale_price, payment_type: type, method: v.method, memo_no: v.memo_no,
        down_payment: type === "cash" ? v.sale_price : v.down_payment,
        ...(type !== "cash" && { guarantor_name: v.guarantor_name, guarantor_phone: v.guarantor_phone }),
        ...(type === "due" && { promised_date: v.promised_date, promise_note: v.promise_note }),
        ...(type === "installment" && {
          installment_count: v.installment_count, first_due_date: v.first_due_date, frequency: v.frequency, interest_amount: v.interest_amount || "0",
          investor_id: v.investor_id, ...(v.investor_id && { investor_share_percent: v.investor_share_percent || "0" }),
        }),
      }, ["customer_id", "sale_price", "down_payment", "installment_count", "interest_amount", "investor_id", "investor_share_percent"]);
      const res = await api<Sale>(`/sales/${sale.id}`, { method: "PUT", body });
      toast.success(res.message);
      onDone();
      onClose();
    } catch (e) {
      toast.error(applyServerErrors(e, setError));
    }
  };

  return (
    <Modal open={open} onClose={onClose} title="Edit sale" wide>
      <form onSubmit={handleSubmit(onSubmit)} noValidate className="space-y-3">
        <p className="text-xs text-slate-500">Every change is saved in the sale's history. The price, type and installments can change until a payment is collected.</p>
        <CustomerSelect value={w.party_id} selectedLabel={sale.customer?.name} allowCreate={false} onChange={(id) => setValue("party_id", id)} error={errors.party_id?.message} />
        <div className="grid gap-3 sm:grid-cols-2">
          <Field required label="Sale date" error={errors.sale_date?.message}><Input type="date" className={inputCls} {...register("sale_date")} /></Field>
          <Field required label="Sale price" error={errors.sale_price?.message}><Input type="number" step="0.01" className={inputCls} {...register("sale_price")} /></Field>
          <Field required label="Payment type" error={errors.payment_type?.message}>
            <NativeSelect className={inputCls} {...register("payment_type")}>
              <option value="cash">Cash (paid in full)</option><option value="due">Due (pay the rest later)</option><option value="installment">Installment</option>
            </NativeSelect>
          </Field>
          <Field label="Payment method" error={errors.method?.message}>
            <NativeSelect className={inputCls} {...register("method")}><option value="cash">Cash</option><option value="bank">Bank</option><option value="mobile">Mobile</option></NativeSelect>
          </Field>
          {type !== "cash" && <Field required label="Down payment" error={errors.down_payment?.message}><Input type="number" step="0.01" className={inputCls} {...register("down_payment")} /></Field>}
          {showMemo && <Field label="Memo no. (optional)" error={errors.memo_no?.message}><Input className={inputCls} {...register("memo_no")} /></Field>}
          {type !== "cash" && <Field label="Guarantor name (optional)" error={errors.guarantor_name?.message}><Input className={inputCls} {...register("guarantor_name")} /></Field>}
          {type !== "cash" && <Field label="Guarantor mobile (optional)" error={errors.guarantor_phone?.message}><Input type="tel" className={inputCls} {...register("guarantor_phone")} /></Field>}
          {type === "due" && <Field label="Promise date (optional)" error={errors.promised_date?.message}><Input type="date" className={inputCls} {...register("promised_date")} /></Field>}
          {type === "due" && <Field label="Promise note (optional)" error={errors.promise_note?.message}><Input className={inputCls} {...register("promise_note")} /></Field>}
        </div>
        {type === "installment" && (
          <div className="grid gap-3 sm:grid-cols-2">
            <Field required label="Number of installments" error={errors.installment_count?.message}><Input type="number" min="1" max="120" className={inputCls} {...register("installment_count")} /></Field>
            <Field required label="First installment date" error={errors.first_due_date?.message}><Input type="date" className={inputCls} {...register("first_due_date")} /></Field>
            <Field label="Frequency" error={errors.frequency?.message}>
              <NativeSelect className={inputCls} {...register("frequency")}><option value="monthly">Monthly</option><option value="weekly">Weekly</option></NativeSelect>
            </Field>
            <Field label="Interest amount" error={errors.interest_amount?.message}><Input type="number" step="0.01" min="0" className={inputCls} {...register("interest_amount")} /></Field>
            <InvestorSelect optional value={w.investor_id} onChange={(id) => setValue("investor_id", id)} error={errors.investor_id?.message} />
            {w.investor_id && <Field label="Investor share %" error={errors.investor_share_percent?.message}><Input type="number" step="0.01" min="0" max="100" className={inputCls} {...register("investor_share_percent")} /></Field>}
          </div>
        )}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="outline" onClick={onClose}>Close</Button>
          <Button type="submit" disabled={isSubmitting}>{isSubmitting ? "Saving..." : "Save changes"}</Button>
        </div>
      </form>
    </Modal>
  );
}
