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
  principal: z.string(),
  profit: z.string(),
  date: z.string().min(1, "Date is required.").refine((v) => v <= todayStr(), "Date cannot be in the future."),
  note: z.string(),
});
type Values = z.infer<typeof schema>;

const TITLE: Record<SettlementMode, string> = { invest: "Add investment", pay: "Pay investor installment", other: "Record settlement" };
const blank = (mode: SettlementMode, d?: { principal?: number; profit?: number }): Values => ({ type: mode === "invest" ? "investment" : "withdrawal", amount: "", principal: d?.principal ? String(d.principal) : "", profit: d?.profit ? String(d.profit) : "", date: "", note: "" });

export function SettlementForm({ open, investorId, mode = "other", name, outstanding, planId, defaults, onClose, onSaved }: { open: boolean; investorId: number; mode?: SettlementMode; name?: string; outstanding?: number; planId?: number; defaults?: { principal?: number; profit?: number }; onClose: () => void; onSaved: () => void }) {
  const autoDate = useAutoDate();
  const { register, handleSubmit, reset, setValue, getValues, setError, watch, formState: { errors, isSubmitting } } = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: blank(mode, defaults),
  });
  useFillDate(autoDate, () => !getValues("date"), (d) => setValue("date", d));

  useEffect(() => {
    if (open) reset({ ...blank(mode, defaults), date: autoDate });
  }, [open, mode, reset, defaults, autoDate]);

  const w = watch();
  const total = Number(w.principal || 0) + Number(w.profit || 0);

  const post = (type: Values["type"], amount: number, v: Values) =>
    api("/investor-settlements", { method: "POST", body: { type, amount, date: v.date, note: v.note || null, investor_id: investorId, ...(planId && { plan_id: planId }) } });

  const onSubmit = async (v: Values) => {
    const lines: [Values["type"], number][] =
      mode === "pay"
        ? ([["principal_return", Number(v.principal || 0)], ["profit_share", Number(v.profit || 0)]] as [Values["type"], number][]).filter(([, a]) => a > 0)
        : [[mode === "invest" ? "investment" : v.type, Number(v.amount || 0)]];

    if (!lines.length || lines.some(([, a]) => !(a > 0))) {
      setError(mode === "pay" ? "principal" : "amount", { message: mode === "pay" ? "Enter the principal or the profit being paid." : "Amount must be greater than zero." });
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
            {outstanding !== undefined && <p className="text-xs text-slate-500">Principal still outstanding: <b>{money(outstanding)}</b></p>}
            <div className="grid grid-cols-2 gap-3">
              <Field label="Principal paid" error={errors.principal?.message}><Input type="number" step="0.01" className={inputCls} {...register("principal")} /></Field>
              <Field label="Profit paid" error={errors.profit?.message}><Input type="number" step="0.01" className={inputCls} {...register("profit")} /></Field>
            </div>
            {total > 0 && <p className="rounded-md bg-emerald-50 p-2 text-sm">Total paid to the investor: <b>{money(total)}</b></p>}
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
