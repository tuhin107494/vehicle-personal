"use client";

import { Calculator, Plus, RotateCcw } from "lucide-react";
import Link from "next/link";
import { useEffect, useMemo, useRef, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { toast } from "sonner";
import { api, applyServerErrors } from "@/lib/api";
import { money, nextMonth, todayStr, toPayload } from "@/lib/format";
import { useDebounced, useFetch, useLoadMore } from "@/lib/hooks";
import type { Investor, InvestorSale, Sale, Vehicle } from "@/lib/types";
import { ProfitCalculator } from "@/components/forms/ProfitCalculator";
import { Button, Card, ErrorText, Field, inputCls, PageHeader } from "@/components/app-ui";
import { CustomerSelect, InvestorSelect } from "@/components/Selects";
import { SearchSelect } from "@/components/SearchSelect";
import { useCategories } from "@/lib/categories";
import { useAdditionalCustomer } from "@/lib/settings";
import { useT } from "@/lib/i18n";
import { Input } from "@/components/ui/input";
import { NativeSelect } from "@/components/ui/native-select";

const num = (v: string) => v !== "" && !isNaN(Number(v));
const r2 = (n: number) => Math.round((n + Number.EPSILON) * 100) / 100;

const schema = z.object({
  vehicle_id: z.string().min(1, "Select the sold vehicle."),
  investor_id: z.string().min(1, "Select the investor."),
  customer_id: z.string(), // an additional customer besides the sale's buyer (optional)
  date: z.string().min(1, "Sale date is required.").refine((v) => v <= todayStr(), "Sale date cannot be in the future."),
  sale_price: z.string().refine((v) => num(v) && Number(v) >= 1, "Sale price must be at least 1."),
  down_payment: z.string(), // from the customer
  investor_down_payment: z.string(), // what the investor gives the shop now (empty: all of their amount)
  plan_amount: z.string(), // the investor's amount, paid back with interest (empty: the price less the customer's down payment)
  method: z.enum(["cash", "bank", "mobile"]),
  memo_no: z.string(),
  guarantor_name: z.string(),
  guarantor_phone: z.string(),
  profit_mode: z.enum(["percent", "amount"]),
  profit_rate: z.string(),
  profit_amount: z.string(),
  installment_count: z.string().refine((v) => num(v) && Number(v) >= 1 && Number(v) <= 120, "Enter 1 to 120 installments."),
  frequency: z.enum(["monthly", "weekly"]),
  first_due_date: z.string().min(1, "First installment date is required."),
}).superRefine((v, ctx) => {
  const add = (path: string, message: string) => ctx.addIssue({ code: "custom", path: [path], message });
  if (!num(v.down_payment)) add("down_payment", "Enter the down payment (0 if none).");
  else if (Number(v.down_payment) < 0) add("down_payment", "Down payment cannot be negative.");
  else if (Number(v.down_payment) >= Number(v.sale_price)) add("down_payment", "Down payment must be less than the sale price.");
  const afterCustomer = Number(v.sale_price) - Number(v.down_payment || 0);
  const planAmount = v.plan_amount === "" ? afterCustomer : Number(v.plan_amount);
  if (v.plan_amount !== "" && !(num(v.plan_amount) && Number(v.plan_amount) >= 1)) add("plan_amount", "Enter the amount on the plan.");
  else if (planAmount > afterCustomer) add("plan_amount", "The amount on the plan cannot be more than the price less the customer's down payment.");
  if (v.investor_down_payment !== "" && !(num(v.investor_down_payment) && Number(v.investor_down_payment) >= 0)) add("investor_down_payment", "Enter the investor's down payment (0 if none).");
  else if (Number(v.investor_down_payment || 0) > planAmount) add("investor_down_payment", "The investor's down payment cannot be more than the amount on the plan.");
  if (v.guarantor_phone.trim() && !/^\+?[0-9\s-]{7,20}$/.test(v.guarantor_phone.trim())) add("guarantor_phone", "Enter a valid mobile number (7 to 20 digits).");
  if (v.profit_mode === "percent" && !(num(v.profit_rate) && Number(v.profit_rate) >= 0)) add("profit_rate", "Enter the investor profit percentage.");
  if (v.profit_mode === "amount" && !(num(v.profit_amount) && Number(v.profit_amount) >= 0)) add("profit_amount", "Enter the investor profit amount.");
  if (v.first_due_date && v.date && v.first_due_date <= v.date) add("first_due_date", "First installment date must be after the sale date.");
});
type Values = z.infer<typeof schema>;

function Info({ k, v }: { k: string; v: string | number | null | undefined }) {
  return <div className="flex gap-2"><dt className="w-36 shrink-0 text-slate-500">{k}</dt><dd className="font-medium text-slate-800">{v || v === 0 ? v : "-"}</dd></div>;
}

/** The dates and amounts the shop pays the investor: the total split evenly, the rounding on the last one (as the server does). */
function schedule(total: number, n: number, first: string, frequency: "monthly" | "weekly") {
  const each = r2(total / n);
  return Array.from({ length: n }, (_, i) => {
    const d = new Date(first + "T00:00:00");
    if (frequency === "weekly") d.setDate(d.getDate() + 7 * i);
    else {
      const day = d.getDate();
      d.setDate(1);
      d.setMonth(d.getMonth() + i);
      d.setDate(Math.min(day, new Date(d.getFullYear(), d.getMonth() + 1, 0).getDate()));
    }
    return { n: i + 1, date: d.toLocaleDateString("en-CA"), amount: i === n - 1 ? r2(total - each * (n - 1)) : each };
  });
}

/**
 * The second sale of a vehicle: after it is sold to a customer on installments, an investor funds it on their own terms.
 * The customer keeps paying the shop on the sale's plan; the shop pays the investor on the schedule made here.
 */
export function InvestorSaleForm() {
  const router = useRouter();
  const { lang } = useT();
  const { nameOf } = useCategories();
  const blank: Values = { vehicle_id: "", investor_id: "", customer_id: "", date: todayStr(), sale_price: "", down_payment: "", investor_down_payment: "0", plan_amount: "0", method: "cash", memo_no: "", guarantor_name: "", guarantor_phone: "", profit_mode: "percent", profit_rate: "", profit_amount: "", installment_count: "0", frequency: "monthly", first_due_date: nextMonth() };
  const { register, handleSubmit, reset, setValue, getValues, watch, setError, formState: { errors, isSubmitting } } = useForm<Values>({ resolver: zodResolver(schema), defaultValues: blank });
  const w = watch();

  // only vehicles already sold can get an investor sale; the list is searched on the server
  const [opened, setOpened] = useState(false);
  const [search, setSearch] = useState("");
  const searched = useDebounced(search.trim());
  const vehicles = useLoadMore<Vehicle>(opened || w.vehicle_id ? `/vehicles?status=sold${searched ? `&search=${encodeURIComponent(searched)}` : ""}` : null);
  const [chosen, setChosen] = useState<Vehicle | null>(null);
  // opened from a sale's page (?vehicle=): that vehicle is chosen already
  const preset = useSearchParams().get("vehicle");
  const presetVehicle = useFetch<Vehicle>(preset ? `/vehicles/${preset}` : null);
  useEffect(() => {
    const v = presetVehicle.data;
    if (!v) return;
    setChosen(v);
    setValue("vehicle_id", String(v.id));
  }, [presetVehicle.data, setValue]);
  const sale = useFetch<Sale>(chosen?.current_sale_id ? `/sales/${chosen.current_sale_id}` : null);
  const s = sale.data && sale.data.id === chosen?.current_sale_id ? sale.data : null;
  const blocked = !s ? null
    : s.status !== "active" || s.payment_type === "cash" ? "This vehicle was sold for cash, so there is nothing left for an investor to fund."
    : s.investor_sale ? "This sale already has an investor sale."
    : s.investor ? "This sale is already financed by an investor on its own installment plan."
    : null;

  // the schedule starts as the customer's plan's frequency; the amount on the plan and the number of installments start at 0 and are typed in
  useEffect(() => {
    if (s?.plan) setValue("frequency", s.plan.frequency === "weekly" ? "weekly" : "monthly");
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [s?.id]);

  // the first installment is a month after the investor sale date: changing that date moves it (it can still be changed by hand after)
  useEffect(() => {
    if (!w.date) return;
    setValue("first_due_date", nextMonth(w.date), { shouldValidate: !!errors.first_due_date });
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [w.date]);

  // the investor's saved formula fills the percentage; with none saved, the calculator opens to work one out
  const [calcOpen, setCalcOpen] = useState(false);
  const showExtra = useAdditionalCustomer(); // the shop can turn the additional customer option off in Settings
  const [extraOpen, setExtraOpen] = useState(false); // the additional customer field, shown only when asked for
  const investor = useFetch<Investor>(w.investor_id ? `/investors/${w.investor_id}` : null);
  // the saved formula and the calculator give a rate per year; the profit is a flat percentage for the whole schedule,
  // so the yearly rate is spread over the schedule's length (and follows it while the count or frequency changes)
  const [yearRate, setYearRate] = useState<number | null>(null);
  const termYears = (Number(w.installment_count) || 0) / (w.frequency === "weekly" ? 52 : 12);
  useEffect(() => {
    if (yearRate === null || !(termYears > 0)) return;
    setValue("profit_rate", String(r2(yearRate * termYears)), { shouldValidate: true });
  }, [yearRate, termYears, setValue]);
  const applyRate = (rate: number) => {
    setValue("profit_mode", "percent");
    setYearRate(rate);
  };
  useEffect(() => {
    const inv = investor.data;
    if (!inv || String(inv.id) !== w.investor_id) return;
    if (inv.formula_rate_year != null) applyRate(inv.formula_rate_year);
    else setCalcOpen(true);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [investor.data]);

  // the investor's amount (the price less the customer's down payment, unless less is typed), paid back with interest;
  // the investor gives the shop their down payment now and owes the rest (their due)
  const salePrice = Number(w.sale_price || 0);
  const down = Number(w.down_payment || 0);
  const afterCustomer = Math.max(r2(salePrice - down), 0);
  const planAmount = w.plan_amount === "" ? afterCustomer : Math.min(Number(w.plan_amount) || 0, afterCustomer);
  // what the investor gives now (0 unless typed); the rest of the amount on the plan is their due
  const investorDown = Math.min(Number(w.investor_down_payment) || 0, planAmount);
  const investorDue = Math.max(r2(planAmount - investorDown), 0);
  const plan = useMemo(() => {
    const principal = planAmount, n = Number(w.installment_count);
    if (!(principal > 0) || !(n >= 1) || n > 120 || !w.first_due_date) return null;
    const profit = w.profit_mode === "amount" ? r2(Number(w.profit_amount || 0)) : r2((principal * Number(w.profit_rate || 0)) / 100);
    const total = r2(principal + profit);
    return { profit, total, rows: schedule(total, n, w.first_due_date, w.frequency) };
  }, [planAmount, w.installment_count, w.first_due_date, w.frequency, w.profit_mode, w.profit_rate, w.profit_amount]);
  // what the shop keeps: the customer's interest less the investor's profit
  const shopProfit = s?.plan && plan ? r2(s.plan.interest_amount - plan.profit) : null;

  // the amount on the plan follows the price less the customer's down payment, as long as it was not typed by hand
  const lastAuto = useRef("0");
  useEffect(() => {
    const plan = getValues("plan_amount");
    if (plan !== "" && Number(plan) !== 0 && plan !== lastAuto.current) return;
    lastAuto.current = afterCustomer > 0 ? String(afterCustomer) : "0";
    setValue("plan_amount", lastAuto.current, { shouldValidate: !!errors.plan_amount });
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [afterCustomer]);

  const clear = () => {
    reset(blank);
    lastAuto.current = "0";
    setYearRate(null);
    setChosen(null);
    setExtraOpen(false);
  };

  const onSubmit = async (v: Values) => {
    if (!s) return void setError("vehicle_id", { message: "Select the sold vehicle." });
    if (blocked) return void setError("vehicle_id", { message: blocked });
    if (v.customer_id && Number(v.customer_id) === s.customer_id) return void setError("customer_id", { message: "The additional customer must be someone other than the buyer." });
    try {
      const body = toPayload({
        investor_id: v.investor_id, customer_id: v.customer_id, date: v.date, sale_price: v.sale_price, down_payment: v.down_payment || "0",
        investor_down_payment: v.investor_down_payment || "0", plan_amount: v.plan_amount, method: v.method,
        memo_no: v.memo_no, guarantor_name: v.guarantor_name, guarantor_phone: v.guarantor_phone, installment_count: v.installment_count, frequency: v.frequency, first_due_date: v.first_due_date,
        ...(v.profit_mode === "amount" ? { profit_amount: v.profit_amount } : { profit_rate: v.profit_rate }),
      }, ["investor_id", "customer_id", "sale_price", "down_payment", "investor_down_payment", "plan_amount", "installment_count", "profit_rate", "profit_amount"]);
      const res = await api<InvestorSale>(`/sales/${s.id}/investor-sale`, { method: "POST", body });
      toast.success(res.message ?? "Investor sale created");
      router.push(`/sales/${s.id}`);
    } catch (e) {
      toast.error(applyServerErrors(e, setError));
    }
  };

  return (
    <>
      <PageHeader titleClass="text-blue-700" title="New investor sale" actions={<Button variant="outline" onClick={() => setCalcOpen(true)}><Calculator className="size-4 text-sky-600" /> <span className="sm:hidden">Calculate</span><span className="hidden sm:inline">Profit calculation</span></Button>} />
      <p className="mb-4 rounded-lg border border-sky-200 bg-sky-50 p-3 text-sm text-sky-900">
        Sell the vehicle first (Sales), then add the investor sale here.
      </p>
      <form onSubmit={handleSubmit(onSubmit)} className="space-y-4" noValidate>
        <Card title="1. Sold vehicle">
          <Field required label="Vehicle (sold on installment or due)" error={errors.vehicle_id?.message}>
            <SearchSelect
              value={w.vehicle_id}
              onChange={(id) => { setValue("vehicle_id", id, { shouldValidate: true }); setChosen((vehicles.data ?? []).find((v) => String(v.id) === id) ?? null); }}
              onQueryChange={setSearch}
              onOpen={() => setOpened(true)}
              loading={vehicles.loading || search.trim() !== searched}
              hasMore={vehicles.hasMore}
              onLoadMore={vehicles.loadMore}
              placeholder="Search by chassis, model or brand..."
              options={[...(vehicles.data ?? []), ...(chosen && !(vehicles.data ?? []).some((v) => v.id === chosen.id) ? [chosen] : [])].map((v) => ({ value: String(v.id), label: `${v.chassis_no} - ${[v.brand, v.model, v.year].filter(Boolean).join(" ")}`, hint: [nameOf(v.vehicle_type), v.engine_no].join(" · ") }))}
            />
          </Field>
          {sale.loading && !s && <p className="mt-3 text-sm text-slate-500">Loading the sale...</p>}
          {sale.error && <ErrorText message={sale.error} />}
          {s && (
            <>
              {s.customer && (
                <>
                  <h3 className="mt-3 text-sm font-semibold text-slate-700">Customer</h3>
                  <dl className="mt-1 grid gap-x-4 gap-y-1 rounded-md border border-emerald-100 bg-emerald-50/50 p-3 text-sm sm:grid-cols-2">
                    <Info k="Name" v={s.customer.name} />
                    <Info k="Father's name" v={s.customer.father_name} />
                    <Info k="Mobile" v={s.customer.phone} />
                    <Info k="Alt. mobile" v={s.customer.alt_phone} />
                    <Info k="NID" v={s.customer.nid} />
                    <Info k="Address" v={s.customer.address} />
                  </dl>
                </>
              )}
              {chosen && (
                <>
                  <h3 className="mt-3 text-sm font-semibold text-slate-700">Vehicle</h3>
                  <dl className="mt-1 grid gap-x-4 gap-y-1 rounded-md border border-emerald-100 bg-emerald-50/50 p-3 text-sm sm:grid-cols-2">
                    <Info k="Type" v={nameOf(chosen.vehicle_type)} />
                    <Info k="Brand / model" v={[chosen.brand, chosen.model].filter(Boolean).join(" ")} />
                    <Info k="Chassis no." v={chosen.chassis_no} />
                    <Info k="Engine no." v={chosen.engine_no} />
                    <Info k="Registration no." v={chosen.registration_no} />
                    <Info k="Year" v={chosen.year} />
                    <Info k="Colour" v={chosen.color} />
                    <Info k="CC" v={chosen.cc} />
                    <Info k="Fuel" v={chosen.fuel_type} />
                    <Info k="Mileage" v={chosen.mileage} />
                  </dl>
                </>
              )}
              {blocked && (
                <p className="mt-3 text-sm text-rose-700">
                  {blocked}{s.investor_sale && <> <Link className="underline" href={`/sales/${s.id}`}>View the sale</Link></>}
                </p>
              )}
            </>
          )}
        </Card>

        <div className={`space-y-4 ${s && !blocked ? "" : "pointer-events-none opacity-50"}`}>
          <Card title="2. Sale">
            <div className="grid gap-3 md:grid-cols-2">
              <Field required label="Sale date" error={errors.date?.message}><Input type="date" min={s?.sale_date} max={todayStr()} className={inputCls} {...register("date")} /></Field>
              <Field required label="Sale price" error={errors.sale_price?.message}><Input type="number" step="0.01" className={inputCls} {...register("sale_price")} /></Field>
              <Field label="Payment method" error={errors.method?.message}>
                <NativeSelect className={inputCls} {...register("method")}>
                  <option value="cash">Cash</option><option value="bank">Bank</option><option value="mobile">Mobile</option>
                </NativeSelect>
              </Field>
              <Field label="Memo no. (optional)" error={errors.memo_no?.message}><Input className={inputCls} {...register("memo_no")} /></Field>
              <Field required label="Down payment (from customer)" error={errors.down_payment?.message}><Input type="number" step="0.01" className={inputCls} {...register("down_payment")} /></Field>
              <Field label="Down payment (from investor)" error={errors.investor_down_payment?.message}><Input type="number" step="0.01" className={inputCls} {...register("investor_down_payment")} /></Field>
              <Field label="Guarantor name (optional)" error={errors.guarantor_name?.message}><Input className={inputCls} {...register("guarantor_name")} /></Field>
              <Field label="Guarantor mobile (optional)" error={errors.guarantor_phone?.message}><Input type="tel" className={inputCls} {...register("guarantor_phone")} /></Field>
              {/* hidden until needed: the button opens it, removing the customer closes it */}
              {(showExtra || w.customer_id) && <div className={extraOpen || w.customer_id ? "" : "flex items-end"}>
                {extraOpen || w.customer_id ? (
                  <>
                    <CustomerSelect optional label="Additional customer (optional)" value={w.customer_id} onChange={(id) => setValue("customer_id", id, { shouldValidate: true })} error={errors.customer_id?.message} />
                    <button type="button" className="mt-1 text-xs font-medium text-emerald-700 hover:underline" onClick={() => { setValue("customer_id", ""); setExtraOpen(false); }}>Remove customer</button>
                  </>
                ) : <Button variant="outline" onClick={() => setExtraOpen(true)}><Plus className="size-4 text-emerald-600" /> Additional customer</Button>}
              </div>}
            </div>
            <dl className="mt-3 grid grid-cols-2 gap-2 rounded-md border border-emerald-100 bg-emerald-50/50 p-3 text-sm sm:grid-cols-4">
              <div><dt className="text-xs text-slate-500">Sale price</dt><dd className="font-semibold text-slate-800">{money(salePrice)}</dd></div>
              <div><dt className="text-xs text-slate-500">Down payment (customer)</dt><dd className="font-semibold text-slate-800">{money(down)}</dd></div>
              <div><dt className="text-xs text-slate-500">Down payment (investor)</dt><dd className="font-semibold text-slate-800">{money(investorDown)}</dd></div>
              <div><dt className="text-xs text-slate-500">Investor due</dt><dd className={`text-base font-bold ${investorDue > 0 ? "text-amber-700" : "text-emerald-700"}`}>{money(investorDue)}</dd></div>
              {salePrice > 0 && afterCustomer <= 0 && (
                <p className="col-span-full rounded-md border border-red-200 bg-red-50 p-2 text-xs text-red-700">
                  {lang === "bn"
                    ? "গ্রাহকের ডাউন পেমেন্ট পুরো বিক্রয়মূল্যের সমান বা বেশি, তাই ইনভেস্টরের জন্য কিছু বাকি নেই। ডাউন পেমেন্ট বিক্রয়মূল্যের চেয়ে কম দিন।"
                    : "The customer's down payment covers the whole sale price, so nothing is left for the investor. Make the down payment less than the sale price."}
                </p>
              )}
              <p className="col-span-full text-xs text-slate-600">
                {lang === "bn"
                  ? `ইনভেস্টর এখন ${money(investorDown)} এবং পরে ${money(investorDue)} দেবেন। দোকান নিচের পরিকল্পনায় ইনভেস্টরকে ${money(planAmount)} + লাভ ফেরত দেবে।`
                  : `The investor gives ${money(investorDown)} now and ${money(investorDue)} later. The shop pays the investor back ${money(planAmount)} + interest on the plan below.`}
              </p>
            </dl>
          </Card>

          <Card title="Installment plan (the shop pays the investor)">
            <div className="grid gap-3 md:grid-cols-3">
              <InvestorSelect allowCreate value={w.investor_id} onChange={(id) => setValue("investor_id", id, { shouldValidate: true })} error={errors.investor_id?.message} />
              <Field label="Amount on plan" error={errors.plan_amount?.message}><Input type="number" step="0.01" placeholder={afterCustomer > 0 ? String(afterCustomer) : undefined} className={inputCls} {...register("plan_amount")} /></Field>
              <Field required label="Number of installments" error={errors.installment_count?.message}><Input type="number" className={inputCls} {...register("installment_count")} /></Field>
              <Field label="Frequency" error={errors.frequency?.message}>
                <NativeSelect className={inputCls} {...register("frequency")}><option value="monthly">Monthly</option><option value="weekly">Weekly</option></NativeSelect>
              </Field>
              <Field required label="First installment date" error={errors.first_due_date?.message}><Input type="date" min={w.date} className={inputCls} {...register("first_due_date")} /></Field>
              <Field required label="Investor profit" error={errors.profit_rate?.message ?? errors.profit_amount?.message}>
                <div className="flex gap-2">
                  <NativeSelect className={`${inputCls} w-28 shrink-0`} {...register("profit_mode", { onChange: () => setYearRate(null) })}>
                    <option value="percent">Percent %</option><option value="amount">Amount (Tk)</option>
                  </NativeSelect>
                  {w.profit_mode === "amount"
                    ? <Input key="amount" type="number" step="0.01" placeholder="Amount" className={inputCls} {...register("profit_amount")} />
                    : <Input key="percent" type="number" step="0.01" placeholder="Percent" className={inputCls} {...register("profit_rate", { onChange: () => setYearRate(null) })} />}
                </div>
                {yearRate !== null && w.profit_mode === "percent" && termYears > 0 && (
                  <p className="mt-1 text-xs text-slate-500">{yearRate}% a year × {r2(termYears)} {termYears === 1 ? "year" : "years"} = {w.profit_rate}% for the whole schedule</p>
                )}
              </Field>
            </div>
            {plan && s && !blocked && (
              <div className={`mt-3 grid rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-emerald-900 ${shopProfit === null ? "grid-cols-3" : "grid-cols-2 sm:grid-cols-4"}`}>
                <div><p className="text-xs text-emerald-700">Pay the investor</p><p className="text-lg font-bold text-blue-700">{money(plan.total)}</p></div>
                <div><p className="text-xs text-emerald-700">Investor profit</p><p className="text-lg font-bold text-sky-700">{money(plan.profit)}</p></div>
                {shopProfit !== null && (
                  <div><p className="text-xs text-emerald-700">Shop profit</p><p className={`text-lg font-bold ${shopProfit < 0 ? "text-rose-700" : "text-emerald-700"}`}>{money(shopProfit)}</p></div>
                )}
                <div><p className="text-xs text-emerald-700">Per {w.frequency === "weekly" ? "week" : "month"}</p><p className="text-lg font-bold text-orange-600">{money(plan.rows[0]?.amount ?? 0)}</p></div>
              </div>
            )}
          </Card>
        </div>

        {calcOpen && <ProfitCalculator key={w.investor_id} open name={investor.data?.name} investorId={investor.data?.id} canSave saved={investor.data} onSaved={(rate) => { investor.reload(); applyRate(rate); setCalcOpen(false); }} onApply={applyRate} onClose={() => setCalcOpen(false)} />}
        <div className="flex justify-end gap-2">
          <Button variant="outline" onClick={clear} disabled={isSubmitting}><RotateCcw className="size-4" /> Reset</Button>
          <Button type="submit" disabled={isSubmitting || !!blocked}>{isSubmitting ? "Saving..." : "Create investor sale"}</Button>
        </div>
      </form>
    </>
  );
}
