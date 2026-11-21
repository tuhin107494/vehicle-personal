"use client";

import { useAutoDate, useFillDate } from "@/lib/settings";
import { useEffect } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { toast } from "sonner";
import { api, applyServerErrors } from "@/lib/api";
import { money, todayStr } from "@/lib/format";
import { Button, Field, inputCls, Modal } from "@/components/app-ui";
import { Textarea } from "@/components/ui/textarea";
import { NativeSelect } from "@/components/ui/native-select";
import { Input } from "@/components/ui/input";

/** invest: money the investor puts in · pay: an instalment paid to the investor (principal and/or profit) · other: any settlement, e.g. a withdrawal */
export type SettlementMode = "invest" | "pay" | "other";

const schema = z.object({
  type: z.enum(["investment", "principal_return", "profit_share", "withdrawal"]),
  amount: z.string(),
  installment: z.string(), // pay: the amount paid now, split into principal and profit
  principal: z.string(),
  profit: z.string(),
  date: z.string().min(1, "Date is required.").refine((v) => v <= todayStr(), "Date cannot be in the future."),
  note: z.string(),
});
type Values = z.infer<typeof schema>;

const TITLE: Record<SettlementMode, string> = { invest: "Add investment", pay: "Pay investor installment", other: "Record settlement" };
/** What an installment owes: the monthly amount, what is due now, and the principal and profit still left (to split a payment between them). */
export type InstallmentDue = { monthly: number; current: number; principalLeft: number; profitLeft: number };

const r2 = (n: number) => Math.round((n + Number.EPSILON) * 100) / 100;

/** An amount paid split into principal and profit in the proportion still owed of each (never more than is left of either). */
function split(amount: number, due: InstallmentDue) {
  const left = due.principalLeft + due.profitLeft;
  if (!(amount > 0) || !(left > 0)) return { principal: 0, profit: 0 };
  const paid = Math.min(amount, left);
  const principal = Math.min(r2((paid * due.principalLeft) / left), due.principalLeft);
  return { principal, profit: Math.min(r2(paid - principal), due.profitLeft) };
}

/** What a payment starts at: the current due, or one installment when nothing is due yet (never more than is left). */
const suggested = (due: InstallmentDue) => r2(Math.min(due.current > 0 ? due.current : due.monthly, due.principalLeft + due.profitLeft));

const blank = (mode: SettlementMode, d?: { principal?: number; profit?: number }): Values => ({ type: mode === "invest" ? "investment" : "withdrawal", amount: "", installment: "", principal: d?.principal ? String(d.principal) : "", profit: d?.profit ? String(d.profit) : "", date: "", note: "" });

export function SettlementForm({ open, investorId, mode = "other", name, outstanding, planId, investorSaleId, defaults, due, onClose, onSaved }: { open: boolean; investorId: number; mode?: SettlementMode; name?: string; outstanding?: number; planId?: number | null; investorSaleId?: number | null; defaults?: { principal?: number; profit?: number }; due?: InstallmentDue; onClose: () => void; onSaved: () => void }) {
  const autoDate = useAutoDate();
  const { register, handleSubmit, reset, setValue, getValues, setError, watch, formState: { errors, isSubmitting } } = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: blank(mode, defaults),
  });
  useFillDate(autoDate, () => !getValues("date"), (d) => setValue("date", d));

  useEffect(() => {
    if (!open) return;
    // an installment payment starts at what is due now, split into principal and profit
    if (mode === "pay" && due) {
      const amount = suggested(due), parts = split(amount, due);
      reset({ ...blank(mode), installment: amount ? String(amount) : "", principal: parts.principal ? String(parts.principal) : "", profit: parts.profit ? String(parts.profit) : "", date: autoDate });
    } else reset({ ...blank(mode, defaults), date: autoDate });
  }, [open, mode, reset, defaults, due, autoDate]);
  const typeInstallment = (value: string) => {
    if (!due) return;
    const parts = split(Number(value || 0), due);
    setValue("principal", parts.principal ? String(parts.principal) : "");
    setValue("profit", parts.profit ? String(parts.profit) : "");
  };

  const w = watch();
  const total = Number(w.principal || 0) + Number(w.profit || 0);

  const post = (type: Values["type"], amount: number, v: Values) =>
    api("/investor-settlements", { method: "POST", body: { type, amount, date: v.date, note: v.note || null, investor_id: investorId, ...(planId && { plan_id: planId }), ...(investorSaleId && { investor_sale_id: investorSaleId }) } });

  const onSubmit = async (v: Values) => {
    if (mode === "pay" && due && Number(v.installment || 0) > r2(due.principalLeft + due.profitLeft)) {
      setError("installment", { message: `The investor is owed only ${money(r2(due.principalLeft + due.profitLeft))}.` });
      return;
    }
    const lines: [Values["type"], number][] =
      mode === "pay"
        ? ([["principal_return", Number(v.principal || 0)], ["profit_share", Number(v.profit || 0)]] as [Values["type"], number][]).filter(([, a]) => a > 0)
        : [[mode === "invest" ? "investment" : v.type, Number(v.amount || 0)]];

    if (!lines.length || lines.some(([, a]) => !(a > 0))) {
      setError(mode === "pay" ? (due ? "installment" : "principal") : "amount", { message: mode === "pay" ? (due ? "Enter the installment amount." : "Enter the principal or the profit being paid.") : "Amount must be greater than zero." });
      return;
    }

    try {
      for (const [type, amount] of lines) await post(type, amount, v);
      toast.success(mode === "invest" ? "Investment added" : mode === "pay" ? "Installment paid" : "Settlement recorded");
      onSaved();
      onClose();
    } catch (e) {
      toast.error(applyServerErrors(e, setError));
    }
  };

  return (
    <Modal open={open} onClose={onClose} title={name ? `${TITLE[mode]} · ${name}` : TITLE[mode]}>
      <form onSubmit={handleSubmit(onSubmit)} className="space-y-3" noValidate>
        {mode === "other" && (
          <Field required label="Type" error={errors.type?.message}>
            <NativeSelect className={inputCls} {...register("type")}>
              <option value="withdrawal">Withdrawal</option>
              <option value="investment">Investment (money in)</option>
              <option value="principal_return">Principal return</option>
              <option value="profit_share">Profit share</option>
            </NativeSelect>
          </Field>
        )}
        {mode === "pay" ? (
          <>
            {due && (
              <div className="grid grid-cols-3 gap-2 rounded-md border border-amber-200 bg-amber-50 p-2 text-sm">
                <div><p className="text-xs text-slate-500">Total due</p><p className="font-semibold text-rose-700">{money(r2(due.principalLeft + due.profitLeft))}</p></div>
                <div><p className="text-xs text-slate-500">Current due</p><p className="font-bold text-amber-700">{money(due.current)}</p></div>
                <div><p className="text-xs text-slate-500">Monthly installment</p><p className="font-semibold text-blue-700">{money(due.monthly)}</p></div>
              </div>
            )}
            {!due && outstanding !== undefined && <p className="text-xs text-slate-500">Principal still outstanding: <b>{money(outstanding)}</b></p>}
            {due && (
              <Field label="Installment amount" error={errors.installment?.message}>
                <Input type="number" step="0.01" className={inputCls} {...register("installment", { onChange: (e) => typeInstallment(e.target.value) })} />
              </Field>
            )}
            {/* with an installment amount the split into principal and profit is made for you */}
            {!due && (
              <>
                <div className="grid grid-cols-2 gap-3">
                  <Field label="Principal paid" error={errors.principal?.message}><Input type="number" step="0.01" className={inputCls} {...register("principal")} /></Field>
                  <Field label="Profit paid" error={errors.profit?.message}><Input type="number" step="0.01" className={inputCls} {...register("profit")} /></Field>
                </div>
                {total > 0 && <p className="rounded-md bg-emerald-50 p-2 text-sm">Total paid to the investor: <b>{money(total)}</b></p>}
              </>
            )}
          </>
        ) : (
          <Field required label="Amount" error={errors.amount?.message}><Input type="number" step="0.01" className={inputCls} {...register("amount")} /></Field>
        )}
        <Field required label="Date" error={errors.date?.message}><Input type="date" className={inputCls} max={todayStr()} {...register("date")} /></Field>
        <Field label="Note" error={errors.note?.message}><Textarea rows={2} className={inputCls} {...register("note")} /></Field>
        <div className="flex justify-end gap-2">
          <Button variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={isSubmitting}>{isSubmitting ? "Saving..." : mode === "invest" ? "Add investment" : mode === "pay" ? "Pay" : "Save"}</Button>
        </div>
      </form>
    </Modal>
  );
}
