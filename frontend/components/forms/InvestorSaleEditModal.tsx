"use client";

import { useState } from "react";
import { toast } from "sonner";
import { api, ApiError } from "@/lib/api";
import { money, todayStr } from "@/lib/format";
import type { InvestorSale } from "@/lib/types";
import { Button, Field, inputCls, Modal } from "@/components/app-ui";
import { Input } from "@/components/ui/input";
import { NativeSelect } from "@/components/ui/native-select";

/**
 * Edit an investor sale. The details (date, memo, guarantor) can always change; the amounts and the schedule only while
 * no money has moved on it since it was made (the server checks this too, and draws the schedule again when they change).
 */
export function InvestorSaleEditModal({ x, onClose, onSaved }: { x: InvestorSale; onClose: () => void; onSaved: () => void }) {
  const p = x.position;
  const investorDownNow = x.investor_down_payment ?? x.principal;
  // money has moved once the shop paid the investor, or the investor gave more than their down payment
  const locked = (p ? p.principal_paid + p.profit_paid : 0) > 0 || (x.investor_paid ?? 0) > investorDownNow + 0.004;
  const [v, setV] = useState({
    date: x.date, memo_no: x.memo_no ?? "", guarantor_name: x.guarantor_name ?? "", guarantor_phone: x.guarantor_phone ?? "",
    sale_price: String(x.sale_price ?? ""), down_payment: String(x.down_payment ?? ""), investor_down_payment: String(investorDownNow),
    plan_amount: String(x.principal), profit_rate: String(x.profit_rate), installment_count: String(x.number_of_installments),
    frequency: x.frequency, first_due_date: x.start_date,
  });
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const set = (k: keyof typeof v) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => setV((o) => ({ ...o, [k]: e.target.value }));

  const save = async () => {
    setBusy(true);
    setError(null);
    try {
      const res = await api(`/investor-sales/${x.id}`, {
        method: "PUT",
        body: {
          ...v, customer_id: x.customer_id ?? undefined, method: x.method ?? undefined,
          memo_no: v.memo_no || undefined, guarantor_name: v.guarantor_name || undefined, guarantor_phone: v.guarantor_phone || undefined,
          sale_price: Number(v.sale_price), down_payment: Number(v.down_payment), investor_down_payment: Number(v.investor_down_payment || 0),
          plan_amount: Number(v.plan_amount), profit_rate: Number(v.profit_rate || 0), installment_count: Number(v.installment_count),
        },
      });
      toast.success(res.message ?? "Saved");
      onSaved();
      onClose();
    } catch (e) {
      setError(e instanceof ApiError ? e.message : "Failed");
    } finally {
      setBusy(false);
    }
  };

  const total = Number(v.plan_amount || 0) * (1 + Number(v.profit_rate || 0) / 100);

  return (
    <Modal open wide title={`Edit investor sale #${x.id}`} onClose={onClose}>
      <div className="space-y-4">
        <div className="grid gap-3 md:grid-cols-2">
          <Field required label="Date"><Input type="date" max={todayStr()} className={inputCls} value={v.date} onChange={set("date")} /></Field>
          <Field label="Memo no. (optional)"><Input className={inputCls} value={v.memo_no} onChange={set("memo_no")} /></Field>
          <Field label="Guarantor name (optional)"><Input className={inputCls} value={v.guarantor_name} onChange={set("guarantor_name")} /></Field>
          <Field label="Guarantor mobile (optional)"><Input type="tel" className={inputCls} value={v.guarantor_phone} onChange={set("guarantor_phone")} /></Field>
        </div>

        {locked && (
          <p className="rounded-md border border-amber-200 bg-amber-50 p-2 text-sm text-amber-800">
            Money has already been paid on this investor sale, so its amounts and schedule cannot change. Only the details above can be edited.
          </p>
        )}
        <fieldset disabled={locked} className={`grid gap-3 md:grid-cols-3 ${locked ? "opacity-60" : ""}`}>
          <Field required label="Sale price"><Input type="number" step="0.01" className={inputCls} value={v.sale_price} onChange={set("sale_price")} /></Field>
          <Field required label="Down payment (from customer)"><Input type="number" step="0.01" className={inputCls} value={v.down_payment} onChange={set("down_payment")} /></Field>
          <Field label="Down payment (from investor)"><Input type="number" step="0.01" className={inputCls} value={v.investor_down_payment} onChange={set("investor_down_payment")} /></Field>
          <Field required label="Amount on plan"><Input type="number" step="0.01" className={inputCls} value={v.plan_amount} onChange={set("plan_amount")} /></Field>
          <Field required label="Investor profit (%)"><Input type="number" step="0.01" className={inputCls} value={v.profit_rate} onChange={set("profit_rate")} /></Field>
          <Field required label="Number of installments"><Input type="number" className={inputCls} value={v.installment_count} onChange={set("installment_count")} /></Field>
          <Field label="Frequency">
            <NativeSelect className={inputCls} value={v.frequency} onChange={set("frequency")}><option value="monthly">Monthly</option><option value="weekly">Weekly</option></NativeSelect>
          </Field>
          <Field required label="First installment date"><Input type="date" min={v.date} className={inputCls} value={v.first_due_date} onChange={set("first_due_date")} /></Field>
          <div className="self-end rounded-md bg-emerald-50 p-2 text-sm">We pay back in total: <b>{money(Math.round(total * 100) / 100)}</b></div>
        </fieldset>

        {error && <p className="text-sm text-red-700">{error}</p>}
        <div className="flex justify-end gap-2">
          <Button variant="outline" onClick={onClose} disabled={busy}>Close</Button>
          <Button onClick={save} disabled={busy}>{busy ? "Saving..." : "Save"}</Button>
        </div>
      </div>
    </Modal>
  );
}
