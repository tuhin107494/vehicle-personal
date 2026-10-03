"use client";

import { useState } from "react";
import { toast } from "sonner";
import { api, applyServerErrors } from "@/lib/api";
import { money } from "@/lib/format";
import { Button, Field, inputCls, Modal } from "@/components/app-ui";
import { Input } from "@/components/ui/input";
import { NativeSelect } from "@/components/ui/native-select";

const num = (v: string) => (v.trim() === "" || Number.isNaN(Number(v)) ? 0 : Number(v));
const pct = (n: number) => `${Math.round(n * 100) / 100}%`;
const exact = (n: number) => `${Math.round(n * 10000) / 10000}%`; // for the worked lines, so the arithmetic checks out

/** Flat (simple) profit: no compounding, the same amount for every month. */
export type SavedFormula = { formula_invested?: number | null; formula_profit?: number | null; formula_months?: number | null; formula_rate_month?: number | null; formula_rate_year?: number | null };

export function ProfitCalculator({ open, onClose, name, initial, investorId, saved, canSave, onSaved, onApply }: { open: boolean; onClose: () => void; name?: string; initial?: { amount?: number; profit?: number }; investorId?: number; saved?: SavedFormula | null; canSave?: boolean; onSaved?: (ratePerYear: number) => void; onApply?: (ratePerYear: number) => void }) {
  // a saved formula wins over the investor's running totals
  const hasSaved = !!saved?.formula_invested && saved.formula_months != null;
  const savedYears = hasSaved && (saved!.formula_months as number) % 12 === 0;
  // 1. what rate did an earlier investor earn?
  const [amount, setAmount] = useState(hasSaved ? String(saved!.formula_invested) : initial?.amount ? String(initial.amount) : "");
  const [profit, setProfit] = useState(hasSaved ? String(saved!.formula_profit ?? 0) : initial?.profit ? String(initial.profit) : "");
  const [period, setPeriod] = useState(hasSaved ? String(savedYears ? (saved!.formula_months as number) / 12 : saved!.formula_months) : "");
  const [unit, setUnit] = useState<"years" | "months">(hasSaved && !savedYears ? "months" : "years");
  const [saving, setSaving] = useState(false);
  const months = num(period) * (unit === "years" ? 12 : 1);
  const total = num(amount) > 0 ? (num(profit) / num(amount)) * 100 : 0;
  const perMonth = months > 0 ? total / months : 0;
  const known = num(amount) > 0 && num(profit) > 0 && months > 0;

  const wholeMonths = Number.isInteger(months) && months >= 1;
  const saveFormula = async () => {
    setSaving(true);
    try {
      const res = await api<SavedFormula>(`/investors/${investorId}/profit-formula`, { method: "PUT", body: { invested: num(amount), profit: num(profit), months } });
      toast.success(res.message);
      onSaved?.(res.data.formula_rate_year ?? 0);
    } catch (e) {
      toast.error(applyServerErrors(e, () => {}));
    } finally {
      setSaving(false);
    }
  };

  // 2. what will a new investor earn at that rate?
  const [newAmount, setNewAmount] = useState("");
  const [newPeriod, setNewPeriod] = useState("");
  const [newUnit, setNewUnit] = useState<"years" | "months">("months");
  const [rateIn, setRateIn] = useState(""); // per year, in %; blank = the rate found above
  const newMonths = num(newPeriod) * (newUnit === "years" ? 12 : 1);
  const monthlyRate = rateIn.trim() !== "" ? num(rateIn) / 12 : perMonth;
  const newProfit = (num(newAmount) * monthlyRate * newMonths) / 100;
  const ready = num(newAmount) > 0 && newMonths > 0 && monthlyRate > 0;

  const unitSelect = (value: string, set: (v: "years" | "months") => void) => (
    <NativeSelect className={`${inputCls} max-w-28`} value={value} onChange={(e) => set(e.target.value as "years" | "months")}>
      <option value="months">Months</option>
      <option value="years">Years</option>
    </NativeSelect>
  );

  return (
    <Modal open={open} onClose={onClose} title={name ? `Profit calculator · ${name}` : "Investor profit calculator"} compact>
      <div className="space-y-3 max-sm:[&_label]:text-[11px] max-sm:[&_input]:h-8 max-sm:[&_input]:px-2 max-sm:[&_input]:text-xs max-sm:[&_select]:h-8 max-sm:[&_select]:text-xs max-sm:[&_button]:h-7 max-sm:[&_button]:px-2 max-sm:[&_button]:text-[11px] max-sm:[&_dt]:text-[10px] max-sm:[&_dd]:text-xs max-sm:[&_legend]:text-[10px] max-sm:[&_fieldset]:space-y-2 max-sm:[&_fieldset]:p-2 sm:space-y-5">
        <fieldset className="space-y-3 rounded-lg border border-sky-200 p-3">
          <legend className="px-1 text-xs font-semibold uppercase tracking-wide text-sky-700">1. Find the profit rate</legend>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Invested amount"><Input type="number" min="0" className={inputCls} value={amount} onChange={(e) => setAmount(e.target.value)} placeholder="e.g. 30000" /></Field>
            <Field label="Profit earned"><Input type="number" min="0" className={inputCls} value={profit} onChange={(e) => setProfit(e.target.value)} placeholder="e.g. 8800" /></Field>
          </div>
          <Field label="Time">
            <div className="flex gap-2"><Input type="number" min="0" className={inputCls} value={period} onChange={(e) => setPeriod(e.target.value)} placeholder="e.g. 2" />{unitSelect(unit, setUnit)}</div>
          </Field>
          {known && (
            <dl className="grid grid-cols-2 gap-2 rounded-md bg-sky-50 p-2 text-center text-sm sm:grid-cols-4">
              <div><dt className="text-xs text-slate-500">Rate per year</dt><dd className="font-bold text-sky-800">{pct(perMonth * 12)}</dd></div>
              <div><dt className="text-xs text-slate-500">Total profit</dt><dd className="font-bold text-sky-800">{money(num(profit))}</dd></div>
              <div><dt className="text-xs text-slate-500">Total (with profit)</dt><dd className="font-bold text-sky-800">{money(Math.round(num(amount) + num(profit)))}</dd></div>
              <div><dt className="text-xs text-slate-500">Per month pay</dt><dd className="font-bold text-sky-800">{money(Math.round((num(amount) + num(profit)) / months))}</dd></div>
            </dl>
          )}
          {((investorId && canSave) || onApply) && (
            <div className="flex items-center justify-between gap-2">
              <p className="text-xs text-slate-500">{known && !wholeMonths && investorId && canSave ? "Time must be a whole number of months to save." : ""}</p>
              <div className="flex gap-2">
                {investorId && canSave && <Button variant="outline" disabled={!known || !wholeMonths || saving} onClick={saveFormula}>{saving ? "Saving..." : "Save formula"}</Button>}
                {onApply && <Button disabled={!known} onClick={() => { onApply(Math.round(perMonth * 12 * 100) / 100); onClose(); }}>Use {known ? pct(perMonth * 12) : "rate"}</Button>}
              </div>
            </div>
          )}
        </fieldset>

        <fieldset className="space-y-3 rounded-lg border border-emerald-200 p-3">
          <legend className="px-1 text-xs font-semibold uppercase tracking-wide text-emerald-700">2. Profit for a new investor</legend>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Amount"><Input type="number" min="0" className={inputCls} value={newAmount} onChange={(e) => setNewAmount(e.target.value)} placeholder="e.g. 40000" /></Field>
            <Field label="Rate per year (%)"><Input type="number" min="0" className={inputCls} value={rateIn} onChange={(e) => setRateIn(e.target.value)} placeholder={known ? `${Math.round(perMonth * 12 * 100) / 100} (from step 1)` : "type a rate"} /></Field>
          </div>
          <Field label="Time">
            <div className="flex gap-2"><Input type="number" min="0" className={inputCls} value={newPeriod} onChange={(e) => setNewPeriod(e.target.value)} placeholder="e.g. 30" />{unitSelect(newUnit, setNewUnit)}</div>
          </Field>
          {ready ? (
            <dl className="grid grid-cols-3 gap-2 rounded-md bg-emerald-50 p-2 text-center text-sm">
              <div><dt className="text-xs text-slate-500">Profit</dt><dd className="font-bold text-emerald-800">{money(Math.round(newProfit))}</dd></div>
              <div><dt className="text-xs text-slate-500">Total (with profit)</dt><dd className="font-bold text-emerald-800">{money(Math.round(num(newAmount) + newProfit))}</dd></div>
              <div><dt className="text-xs text-slate-500">Per month pay</dt><dd className="font-bold text-emerald-800">{money(Math.round((num(newAmount) + newProfit) / newMonths))}</dd></div>
            </dl>
          ) : null}
        </fieldset>
      </div>
    </Modal>
  );
}
