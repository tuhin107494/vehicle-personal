"use client";

import { Calculator, Plus, Printer, RotateCcw } from "lucide-react";
import Link from "next/link";
import { useEffect, useMemo, useRef, useState } from "react";
import { useSearchParams } from "next/navigation";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { toast } from "sonner";
import { api, ApiError, applyServerErrors } from "@/lib/api";
import { addDays, fmtDate, money, todayStr, toPayload, savedMsg } from "@/lib/format";
import { useFetch } from "@/lib/hooks";
import type { Booking, DraftT, Customer, Investor, Sale, Vehicle } from "@/lib/types";
import { ProfitCalculator } from "@/components/forms/ProfitCalculator";
import { draftValues } from "@/components/DraftsBar";
import { Button, Card, Field, inputCls, Modal, PageHeader } from "@/components/app-ui";
import { openSaleMemo } from "@/components/SaleMemo";
import { useAuth } from "@/lib/auth";
import { CustomerSelect, InvestorSelect } from "@/components/Selects";
import { SearchSelect } from "@/components/SearchSelect";
import { useSettings } from "@/lib/settings";
import { useCategories } from "@/lib/categories";
import { Input } from "@/components/ui/input";
import { NativeSelect } from "@/components/ui/native-select";

const num = (v: string) => v !== "" && !isNaN(Number(v));

const schema = z.object({
  vehicle_id: z.string().min(1, "Select a vehicle."),
  party_id: z.string().min(1, "Select the buyer."),
  sale_date: z.string().min(1, "Sale date is required.").refine((v) => v <= todayStr(), "Sale date cannot be in the future."),
  sale_price: z.string().min(1, "Sale price is required.").refine((v) => Number(v) >= 1, "Sale price must be at least 1."),
  payment_type: z.enum(["cash", "due", "installment"]),
  advance_amount: z.string(), // money the customer paid earlier; it counts in the down payment but is kept as its own payment
  advance_date: z.string(),
  down_payment: z.string(),
  method: z.enum(["cash", "bank", "mobile"]),
  promised_date: z.string(),
  promise_note: z.string(),
  investor_id: z.string(),
  guarantor_phone: z.string(),
  installment_count: z.string(),
  first_due_date: z.string(),
  frequency: z.enum(["monthly", "weekly"]),
  interest_rate: z.string(),
  interest_mode: z.enum(["percent", "amount"]),
  interest_amount: z.string(),
  investor_share_percent: z.string(),
  investor_share_mode: z.enum(["percent", "amount"]),
  investor_share_amount: z.string(),
  investor_rate: z.string(),
}).superRefine((v, ctx) => {
  const add = (path: string, message: string) => ctx.addIssue({ code: "custom", path: [path], message });
  const price = Number(v.sale_price);
  if (v.guarantor_phone.trim() && !/^\+?[0-9\s-]{7,20}$/.test(v.guarantor_phone.trim())) add("guarantor_phone", "Enter a valid mobile number (7 to 20 digits).");
  const down = v.down_payment === "" ? 0 : Number(v.down_payment);

  if (v.advance_amount !== "") {
    if (!(Number(v.advance_amount) > 0)) add("advance_amount", "Enter an amount above zero.");
    else if (Number(v.advance_amount) >= price) add("advance_amount", "The advance must be less than the sale price.");
  }
  if (v.advance_date && v.advance_date > v.sale_date) add("advance_date", "The advance date cannot be after the sale date.");

  if (v.payment_type !== "cash") {
    if (!num(v.down_payment)) add("down_payment", "Enter the down payment (0 if none).");
    else if (down < 0) add("down_payment", "Down payment cannot be negative.");
    else if (down >= price) add("down_payment", "Down payment must be less than the sale price.");
  }
  if (v.payment_type === "due") {
    if (v.promised_date && v.promised_date <= v.sale_date) add("promised_date", "Promise date must be after the sale date.");
  }
  if (v.payment_type === "installment") {
    if (!num(v.installment_count) || Number(v.installment_count) < 1 || Number(v.installment_count) > 120) add("installment_count", "Enter 1 to 120 installments.");
    if (!v.first_due_date) add("first_due_date", "First installment date is required.");
    else if (v.first_due_date <= v.sale_date) add("first_due_date", "First installment date must be after the sale date.");
    if (v.interest_mode === "percent" && v.interest_rate !== "" && Number(v.interest_rate) < 0) add("interest_rate", "Interest cannot be negative.");
    if (v.interest_mode === "amount" && v.interest_amount !== "" && Number(v.interest_amount) < 0) add("interest_amount", "Interest cannot be negative.");
    if (!v.investor_id) {
      // no investor: the shop finances the plan, so there is no share to check
    } else if (v.investor_share_mode === "percent") {
      if (v.investor_share_percent !== "" && (Number(v.investor_share_percent) < 0 || Number(v.investor_share_percent) > 100)) add("investor_share_percent", "Enter 0 to 100.");
    } else if (v.investor_share_amount !== "") {
      const interest = v.interest_mode === "amount" ? Number(v.interest_amount || 0) : ((price - Number(v.down_payment || 0)) * Number(v.interest_rate || 0)) / 100;
      if (Number(v.investor_share_amount) < 0) add("investor_share_amount", "Amount cannot be negative.");
      else if (Number(v.investor_share_amount) > interest + 0.005) add("investor_share_amount", "Cannot be more than the total interest.");
    }
  }
});
type Values = z.infer<typeof schema>;

function Info({ k, v }: { k: string; v: string | number | null | undefined }) {
  return <div className="flex gap-2"><dt className="w-32 shrink-0 text-slate-500">{k}</dt><dd className="font-medium text-slate-800">{v || v === 0 ? v : "-"}</dd></div>;
}

const PROMISE_OPTIONS = [
  { value: "d7", label: "7 days" },
  { value: "d15", label: "15 days" },
  ...[1, 2, 3, 4].map((m) => ({ value: `m${m}`, label: `${m} month${m > 1 ? "s" : ""}` })),
  { value: "other", label: "Other (pick a date)" },
];

// The promise date a quick choice means, counted from the sale date.
function promiseDate(choice: string, from: string) {
  const d = new Date((from || todayStr()) + "T00:00:00");
  if (choice.startsWith("d")) d.setDate(d.getDate() + Number(choice.slice(1)));
  else {
    const day = d.getDate();
    d.setDate(1);
    d.setMonth(d.getMonth() + Number(choice.slice(1)));
    d.setDate(Math.min(day, new Date(d.getFullYear(), d.getMonth() + 1, 0).getDate()));
  }
  return d.toLocaleDateString("en-CA");
}

const r2 = (n: number) => Math.round((n + Number.EPSILON) * 100) / 100;

/**
 * The sale form. With a `booking`, the sale is made from it: the vehicle and buyer are the booking's, the price starts at the agreed price,
 * and the advance already paid is counted in the down payment, so staff only choose how the rest is paid (cash, due or installment).
 */
export function SaleForm({ investorMode = false, booking = null, onSold }: { investorMode?: boolean; booking?: Booking | null; onSold?: (sale: Sale) => void }) {
  const { user } = useAuth();
  const [created, setCreated] = useState<Sale | null>(null); // the sale just saved: the form stays and offers the memo
  const params = useSearchParams();
  const [vehicleListOpened, setVehicleListOpened] = useState(false); // the vehicle list loads when the field is first used, or when a vehicle is already chosen
  const { categories, nameOf } = useCategories();

  const blank = (vehicleId: string): Values => ({
      vehicle_id: booking?.vehicle_id ? String(booking.vehicle_id) : vehicleId, party_id: booking ? String(booking.customer_id) : "", sale_date: todayStr(), sale_price: booking?.agreed_price ? String(Number(booking.agreed_price)) : "", payment_type: investorMode ? "installment" : "cash", down_payment: booking ? String(Number(booking.advance_amount)) : "", // from a booking this is the total received at the sale: the advance, plus what is paid now (typed apart from it)
      method: "cash", advance_amount: "", advance_date: "", promised_date: "", promise_note: "", investor_id: "", guarantor_phone: "", installment_count: "24", first_due_date: addDays(30),
      frequency: "monthly", interest_rate: "0", interest_mode: "percent", interest_amount: "", investor_share_percent: "0", investor_share_mode: "percent", investor_share_amount: "", investor_rate: "",
  });

  const { register, handleSubmit, reset, setValue, getValues, watch, setError, formState: { errors, isSubmitting } } = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: blank(params.get("vehicle") ?? ""),
  });

  const settings = useSettings();

  // The vehicle list starts on the shop's primary type for sales (Settings); "All types" shows everything.
  const [typeFilter, setTypeFilter] = useState("");
  const filterApplied = useRef(false);
  useEffect(() => {
    if (filterApplied.current || !settings.data) return;
    filterApplied.current = true;
    setTypeFilter(settings.data.sale.vehicle_type ?? "");
  }, [settings.data]);

  const w = watch();
  // money received before the sale: the booking's advance, or an advance typed here (its button opens the amount and date)
  const [advanceOpen, setAdvanceOpen] = useState(false);
  const advance = booking ? Number(booking.advance_amount) : Number(w.advance_amount || 0);
  const advanceDate = booking ? booking.booked_on : w.advance_date || todayStr();
  // the down payment kept in the form is everything received at the sale, the advance included; changing the advance keeps what is paid on top
  const changeAdvance = (value: string) => {
    const extra = Math.max(Number(getValues("down_payment") || 0) - Number(getValues("advance_amount") || 0), 0);
    setValue("advance_amount", value, { shouldValidate: true });
    setValue("down_payment", Number(value || 0) > 0 ? String(Number(value) + extra) : extra ? String(extra) : "", { shouldValidate: true });
  };
  // the advance cannot be dated after the sale: moving the sale date back pulls the advance date with it
  useEffect(() => {
    if (w.advance_date && w.sale_date && w.advance_date > w.sale_date) setValue("advance_date", w.sale_date, { shouldValidate: true });
  }, [w.sale_date, w.advance_date, setValue]);
  const removeAdvance = () => {
    changeAdvance("");
    setValue("advance_date", "");
    setAdvanceOpen(false);
  };
  const [draftId, setDraftId] = useState<number | null>(null); // the draft being edited: it holds the vehicle chosen in it
  const vehicles = useFetch<Vehicle[]>(booking ? "/vehicles?status=booked&per_page=100" : vehicleListOpened || w.vehicle_id ? (investorMode ? "/vehicles?per_page=100" : `/vehicles?status=in_stock&per_page=100${draftId ? `&draft_id=${draftId}` : ""}`) : null);
  // from a booking: the booked vehicle is already chosen, and the buyer may take another one that is in stock (fetched when the field is first used)
  const moreVehicles = useFetch<Vehicle[]>(booking && (vehicleListOpened || !booking.vehicle_id) ? "/vehicles?status=in_stock&per_page=100" : null);
  const allVehicles = booking ? [...(vehicles.data ?? []), ...(moreVehicles.data ?? [])] : vehicles.data ?? [];
  const [promiseChoice, setPromiseChoice] = useState("");
  // A resumed draft keeps the date it has; a quick choice follows the sale date.
  const choice = promiseChoice || (w.promised_date ? "other" : "");
  useEffect(() => {
    if (promiseChoice && promiseChoice !== "other") setValue("promised_date", promiseDate(promiseChoice, w.sale_date), { shouldValidate: true });
  }, [promiseChoice, w.sale_date, setValue]);
  const type = w.payment_type;
  const salePrice = Number(w.sale_price || 0);
  const paidNow = type === "cash" ? salePrice : Number(w.down_payment || 0);
  const dueNow = Math.max(salePrice - paidNow, 0);
  // the investor's saved formula fills the percentage; with none saved, the calculator opens to work one out
  const [calcOpen, setCalcOpen] = useState(false);
  const chosenInvestor = useFetch<Investor>(investorMode && w.investor_id ? `/investors/${w.investor_id}` : null);
  useEffect(() => {
    const inv = chosenInvestor.data;
    if (!inv || String(inv.id) !== w.investor_id) return;
    if (inv.formula_rate_year != null) {
      setValue("investor_share_mode", "percent");
      setValue("investor_rate", String(inv.formula_rate_year));
    }
    else setCalcOpen(true);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [chosenInvestor.data]);

  const buyer = useFetch<Customer>(w.party_id ? `/customers/${w.party_id}` : null);
  const picked = allVehicles.find((v) => String(v.id) === w.vehicle_id);

  // Choosing a vehicle brings its type's default sale price (Settings), unless a different price was already typed.
  const vehicleType = allVehicles.find((v) => String(v.id) === w.vehicle_id)?.vehicle_type;
  const autoPrice = useRef("");
  useEffect(() => {
    const price = vehicleType ? settings.data?.sale.prices?.[vehicleType] : undefined;
    if (!price) return;
    const current = getValues("sale_price");
    if (!current || current === autoPrice.current) {
      setValue("sale_price", String(price));
      autoPrice.current = String(price);
    }
  }, [vehicleType, settings.data, getValues, setValue]);

  const plan = useMemo(() => {
    if (type !== "installment") return null;
    const price = Number(w.sale_price), down = Number(w.down_payment || 0), n = Number(w.installment_count);
    if (!(price > down) || !(n >= 1) || n > 120 || !w.first_due_date) return null;
    const principal = r2(price - down), interest = investorMode ? (w.investor_share_mode === "amount" ? r2(Number(w.investor_share_amount || 0)) : r2((principal * Number(w.investor_rate || 0)) / 100)) : w.interest_mode === "amount" ? r2(Number(w.interest_amount || 0)) : r2((principal * Number(w.interest_rate || 0)) / 100), total = r2(principal + interest);
    const each = r2(total / n);
    const rows = Array.from({ length: n }, (_, i) => {
      const d = new Date(w.first_due_date + "T00:00:00");
      if (w.frequency === "weekly") d.setDate(d.getDate() + 7 * i);
      else {
        const day = d.getDate();
        d.setDate(1);
        d.setMonth(d.getMonth() + i);
        d.setDate(Math.min(day, new Date(d.getFullYear(), d.getMonth() + 1, 0).getDate()));
      }
      return { n: i + 1, date: d.toLocaleDateString("en-CA"), amount: i === n - 1 ? r2(total - each * (n - 1)) : each };
    });
    return { principal, interest, total, rows, profit: investorMode ? interest : w.investor_share_mode === "amount" ? r2(Number(w.investor_share_amount || 0)) : r2((interest * Number(w.investor_share_percent || 0)) / 100) };
  }, [type, w.sale_price, w.down_payment, w.installment_count, w.first_due_date, w.frequency, w.interest_rate, w.interest_mode, w.interest_amount, w.investor_share_percent, w.investor_share_mode, w.investor_share_amount, w.investor_rate, investorMode]);

  // the owner's profit is the interest less the investor's part; the investor's part shows under it
  const investorProfit = investorMode ? (plan?.profit ?? 0) : 0;
  const ownerProfit = investorMode ? Math.max(r2((plan?.interest ?? 0) - investorProfit), 0) : plan?.interest ?? 0;
  const showProfit = investorMode ? (w.investor_share_mode === "amount" ? w.investor_share_amount !== "" : w.investor_rate !== "") : ownerProfit > 0;

  // Drafts: the form as typed, kept for later. Saving one creates no sale and takes nothing out of stock.
  // back to an empty form (a booking's own details stay); a draft that was open is left saved but no longer the one being updated
  const resetForm = () => {
    reset(blank(""));
    setAdvanceOpen(false);
    setPromiseChoice("");
    setDraftId(null);
  };
  const [savingDraft, setSavingDraft] = useState(false);

  const saveDraft = async () => {
    const values = getValues();
    const chassis = allVehicles.find((x) => String(x.id) === values.vehicle_id)?.chassis_no;
    const label = `${chassis ?? "New sale"}${values.sale_price ? ` · ${money(Number(values.sale_price))}` : ""} · ${values.payment_type}`;
    setSavingDraft(true);
    try {
      const body = { label, data: { values, typeFilter, investor: investorMode } };
      const res = draftId ? await api<DraftT>(`/drafts/${draftId}`, { method: "PUT", body }) : await api<DraftT>("/drafts", { method: "POST", body: { type: "sale", ...body } });
      setDraftId(res.data.id);
      toast.success(res.message);
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : "Could not save the draft");
    } finally {
      setSavingDraft(false);
    }
  };

  const resumeId = params.get("resume");
  const resumeList = useFetch<DraftT[]>(resumeId ? "/drafts?type=sale&resume=1" : null);
  const resumed = useRef(false);
  useEffect(() => {
    const d = resumeList.data?.find((x) => String(x.id) === resumeId);
    if (!d || resumed.current) return;
    resumed.current = true;
    resumeDraft(d);
    window.history.replaceState(null, "", investorMode ? "/sales/new?tab=investor" : "/sales/new"); // the draft is open now
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [resumeList.data]);

  const resumeDraft = (d: DraftT) => {
    reset(draftValues(d.data.values, getValues()));
    setTypeFilter(d.data.typeFilter ?? "");
    setPromiseChoice("");
    setDraftId(d.id);
    toast.success("Draft opened");
  };

  const onSubmit = async (v: Values) => {
    if (investorMode && picked && picked.status !== "in_stock") {
      setError("vehicle_id", { message: "This vehicle is already sold, so it cannot be sold again. Cancel its sale first." });
      return;
    }
    if (investorMode && !v.investor_id) {
      setError("investor_id", { message: "Select the investor." });
      return;
    }
    if (investorMode && v.investor_share_mode === "amount" && !(Number(v.investor_share_amount) > 0)) {
      setError("investor_share_amount", { message: "Enter the investor profit amount." });
      return;
    }
    if (investorMode && v.investor_share_mode !== "amount" && (v.investor_rate.trim() === "" || Number(v.investor_rate) < 0)) {
      setError("investor_rate", { message: "Enter the investor profit percentage." });
      return;
    }
    if (booking && v.sale_date < booking.booked_on) {
      setError("sale_date", { message: `The sale date cannot be before the booking date (${fmtDate(booking.booked_on)}).` });
      return;
    }
    if (booking && v.payment_type !== "cash" && Number(v.down_payment || 0) < Number(booking.advance_amount)) {
      setError("down_payment", { message: `The down payment must include the booking advance of ${money(booking.advance_amount)}.` });
      return;
    }
    try {
      const body = toPayload({
        ...(booking && { booking_id: booking.id }),
        vehicle_id: v.vehicle_id, customer_id: v.party_id, sale_date: v.sale_date, sale_price: v.sale_price, payment_type: v.payment_type, method: v.method,
        down_payment: v.payment_type === "cash" ? v.sale_price : v.down_payment,
        ...(!booking && Number(v.advance_amount) > 0 && { advance_amount: v.advance_amount, advance_date: v.advance_date }),
        ...(v.payment_type !== "cash" && { guarantor_phone: v.guarantor_phone }),
        ...(v.payment_type === "due" && { promised_date: v.promised_date, promise_note: v.promise_note }),
        ...(v.payment_type === "installment" && {
          investor_id: v.investor_id, installment_count: v.installment_count, first_due_date: v.first_due_date, frequency: v.frequency,
          ...(investorMode ? (v.investor_share_mode === "amount" ? { interest_amount: v.investor_share_amount } : { interest_rate: v.investor_rate }) : v.interest_mode === "amount" ? { interest_amount: v.interest_amount || "0" } : { interest_rate: v.interest_rate }),
          // An amount given directly is turned into the percentage of the plan's interest that the server keeps.
          investor_share_percent: !v.investor_id ? "0" : investorMode
            ? "100" // the one percentage is the whole plan profit, all of it the investor's
            : v.investor_share_mode === "amount"
            ? (plan && plan.interest > 0 ? String(Math.round((Number(v.investor_share_amount || 0) / plan.interest) * 1000000) / 10000) : "0")
            : v.investor_share_percent,
        }),
      }, ["booking_id", "vehicle_id", "customer_id", "sale_price", "down_payment", "advance_amount", "investor_id", "installment_count", "interest_rate", "interest_amount", "investor_share_percent"]);
      const res = await api<Sale>("/sales", { method: "POST", body: draftId ? { ...body, draft_id: draftId } : body });
      toast.success(savedMsg("Sale", false));
      // stay on this page: clear the form, refresh the stock list and offer the memo
      const full = await api<Sale>(`/sales/${res.data.id}`).then((r) => r.data).catch(() => res.data);
      if (onSold) return void onSold(full); // made from a booking: go on to the sale itself
      setCreated(full);
      reset(blank(""));
      setAdvanceOpen(false);
      setDraftId(null);
      vehicles.reload();
    } catch (e) {
      toast.error(applyServerErrors(e, setError));
    }
  };

  return (
    <>
      <PageHeader titleClass={investorMode ? "text-blue-700" : undefined} title={investorMode ? "New investor sale" : "New sale"} actions={investorMode && <Button variant="outline" onClick={() => setCalcOpen(true)}><Calculator className="size-4 text-sky-600" /> <span className="sm:hidden">Calculate</span><span className="hidden sm:inline">Profit calculation</span></Button>} />
      {booking && (
        <div className="mb-4 rounded-lg border border-violet-200 bg-violet-50 p-3 text-sm text-violet-900">
          From <b>booking #{booking.id}</b> · {Number(booking.advance_amount) > 0 ? <>advance <b>{money(booking.advance_amount)}</b> ({fmtDate(booking.booked_on)})</> : "no advance"}
        </div>
      )}
      <form onSubmit={handleSubmit(onSubmit)} className="space-y-4" noValidate>
        <Card title="1. Customer">
          {booking
            ? <p className="text-sm text-slate-600">Buyer on the booking: <b className="text-slate-900">{booking.customer?.name}</b></p>
            : <CustomerSelect label="Buyer" value={w.party_id} onChange={(id) => setValue("party_id", id, { shouldValidate: true })} error={errors.party_id?.message} />}
          {buyer.data && (
            <dl className="mt-3 grid gap-x-4 gap-y-1 rounded-md border border-emerald-100 bg-emerald-50/50 p-3 text-sm sm:grid-cols-2">
              <Info k="Name" v={buyer.data.name} />
              <Info k="Father's name" v={buyer.data.father_name} />
              <Info k="Mobile" v={buyer.data.phone} />
              <Info k="Alt. mobile" v={buyer.data.alt_phone} />
              <Info k="NID" v={buyer.data.nid} />
              <Info k="Address" v={buyer.data.address} />
            </dl>
          )}
        </Card>

        <Card title={booking ? "2. Vehicle" : "2. Vehicle from stock"}>
          {booking && !booking.vehicle_id && <p className="mb-3 text-sm text-slate-600">No vehicle was booked yet: choose the one the customer takes.</p>}
          {!booking && !w.party_id && <p className="mb-3 text-sm text-slate-500">Select or add the customer first, then choose the vehicle.</p>}
          <div className={`grid gap-3 md:grid-cols-2 ${booking || w.party_id ? "" : "pointer-events-none opacity-50"}`}>
            <Field label="Vehicle type">
              <NativeSelect className={inputCls} value={typeFilter} onChange={(e) => setTypeFilter(e.target.value)}>
                <option value="">All types</option>
                {categories.map((c) => <option key={c.key} value={c.key}>{c.name}</option>)}
              </NativeSelect>
            </Field>
            <Field required label={investorMode ? "Vehicle (in stock or sold)" : "Vehicle (in stock)"} error={errors.vehicle_id?.message}>
              <SearchSelect
                value={w.vehicle_id}
                onChange={(id) => setValue("vehicle_id", id, { shouldValidate: true })}
                onOpen={() => setVehicleListOpened(true)}
                loading={vehicles.loading}
                placeholder="Search by chassis, model or brand..."
                options={allVehicles.filter((v) => !typeFilter || v.vehicle_type === typeFilter).map((v) => ({ value: String(v.id), label: `${v.chassis_no} - ${[v.brand, v.model, v.year].filter(Boolean).join(" ")}${investorMode ? (v.status === "in_stock" ? " · In stock" : " · Sold") : ""}`, hint: [nameOf(v.vehicle_type), v.engine_no].join(" · ") }))}
              />
            </Field>
          </div>
          {picked && (
            <dl className="mt-3 grid gap-x-4 gap-y-1 rounded-md border border-emerald-100 bg-emerald-50/50 p-3 text-sm sm:grid-cols-2">
              <Info k="Type" v={nameOf(picked.vehicle_type)} />
              <Info k="Brand / model" v={[picked.brand, picked.model].filter(Boolean).join(" ")} />
              <Info k="Chassis no." v={picked.chassis_no} />
              <Info k="Engine no." v={picked.engine_no} />
              <Info k="Registration no." v={picked.registration_no} />
              <Info k="Year" v={picked.year} />
              <Info k="Colour" v={picked.color} />
              <Info k="CC" v={picked.cc} />
              <Info k="Fuel" v={picked.fuel_type} />
              <Info k="Mileage" v={picked.mileage} />
              <Info k="Days in stock" v={picked.days_in_stock} />
            </dl>
          )}
        </Card>

        <Card title="3. Sale" actions={!booking && (advanceOpen || advance > 0
          ? <Button variant="outline" onClick={removeAdvance}>Remove advance</Button>
          : <Button variant="outline" onClick={() => setAdvanceOpen(true)}><Plus className="size-4" /> Advance</Button>)}>
          <div className="grid gap-3 md:grid-cols-2">
            <Field required label="Sale date" error={errors.sale_date?.message}><Input type="date" min={booking?.booked_on} max={todayStr()} className={inputCls} {...register("sale_date")} /></Field>
            <Field required label="Sale price" error={errors.sale_price?.message}>
              <Input type="number" step="0.01" className={inputCls} {...register("sale_price")} />
            </Field>
            {!booking && (advanceOpen || advance > 0) && (
              <>
                <Field label="Advance amount" error={errors.advance_amount?.message}>
                  <Input type="number" step="0.01" min="0" className={inputCls} value={w.advance_amount} placeholder="0" onChange={(e) => changeAdvance(e.target.value)} />
                </Field>
                <Field label="Advance date" error={errors.advance_date?.message}><Input type="date" max={w.sale_date || todayStr()} className={inputCls} {...register("advance_date")} /></Field>
              </>
            )}
            {!investorMode && <Field required label="Payment type" error={errors.payment_type?.message}>
              <NativeSelect className={inputCls} {...register("payment_type")}>
                <option value="cash">Cash (paid in full)</option>
                <option value="due">Due (pay the rest later)</option>
                <option value="installment">Installment</option>
              </NativeSelect>
            </Field>}
            <Field label="Payment method" error={errors.method?.message}>
              <NativeSelect className={inputCls} {...register("method")}>
                <option value="cash">Cash</option><option value="bank">Bank</option><option value="mobile">Mobile</option>
              </NativeSelect>
            </Field>
            {type !== "cash" && (
              advance > 0 ? (
                <Field required label="Down payment (without the advance)" error={errors.down_payment?.message}>
                  <Input
                    type="number" step="0.01" min="0" className={inputCls}
                    value={String(Math.max(Number(w.down_payment || 0) - advance, 0) || "")}
                    placeholder="0"
                    onChange={(e) => setValue("down_payment", String(advance + Number(e.target.value || 0)), { shouldValidate: true })}
                  />
                </Field>
              ) : (
                <Field required label="Down payment" error={errors.down_payment?.message}><Input type="number" step="0.01" className={inputCls} {...register("down_payment")} /></Field>
              )
            )}
            {type !== "cash" && (
              <Field label="Guarantor mobile (optional)" error={errors.guarantor_phone?.message}><Input type="tel" className={inputCls} {...register("guarantor_phone")} /></Field>
            )}
          </div>
          {advance > 0 && (
            <>
              <dl className={`mt-3 grid gap-2 rounded-md border border-emerald-100 bg-emerald-50/50 p-3 text-sm grid-cols-2 sm:grid-cols-4`}>
                <div><dt className="text-xs text-slate-500">Sale price</dt><dd className="font-semibold text-slate-800">{money(salePrice)}</dd></div>
                <div><dt className="text-xs text-slate-500">Advance · {fmtDate(advanceDate)}</dt><dd className="font-semibold text-violet-800">{money(advance)}</dd></div>
                <div><dt className="text-xs text-slate-500">Down payment</dt><dd className="font-semibold text-slate-800">{money(Math.max(paidNow - advance, 0))}</dd></div>
                <div>
                  <dt className="text-xs text-slate-500">Due amount</dt>
                  <dd className={`text-base font-bold ${dueNow > 0 ? "text-amber-700" : "text-emerald-700"}`}>{money(dueNow)}</dd>
                </div>
              </dl>
              <p className="mt-2 text-xs text-slate-600">Total received (advance and down payment): <b>{money(paidNow)}</b>.</p>
            </>
          )}
          {advance <= 0 && (
            <dl className="mt-3 grid grid-cols-3 gap-2 rounded-md border border-emerald-100 bg-emerald-50/50 p-3 text-sm">
              <div><dt className="text-xs text-slate-500">Sale price</dt><dd className="font-semibold text-slate-800">{money(salePrice)}</dd></div>
              <div><dt className="text-xs text-slate-500">Down payment</dt><dd className="font-semibold text-slate-800">{money(paidNow)}</dd></div>
              <div>
                <dt className="text-xs text-slate-500">Due amount</dt>
                <dd className={`text-base font-bold ${dueNow > 0 ? "text-amber-700" : "text-emerald-700"}`}>{money(dueNow)}</dd>
              </div>
            </dl>
          )}
        </Card>

        {type === "due" && (
          <Card title="Due payment promise">
            <div className="grid gap-3 md:grid-cols-2">
              <Field label="Promised pay date (optional)" error={errors.promised_date?.message}>
                <NativeSelect className={inputCls} value={choice} onChange={(e) => { setPromiseChoice(e.target.value); if (!e.target.value) setValue("promised_date", ""); }}>
                  <option value="">Select...</option>
                  {PROMISE_OPTIONS.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                </NativeSelect>
                {choice && (choice === "other"
                  ? <Input type="date" className={`${inputCls} mt-2`} {...register("promised_date")} />
                  : <p className="mt-1 text-xs text-slate-500">Pay by <b className="text-slate-800">{w.promised_date ? fmtDate(w.promised_date) : "-"}</b></p>)}
              </Field>
              <Field label="Note" error={errors.promise_note?.message}><Input className={inputCls} {...register("promise_note")} /></Field>
            </div>
          </Card>
        )}

        {type === "installment" && (
          <Card title="Installment plan">
            <div className="grid gap-3 md:grid-cols-3">
              {investorMode && <InvestorSelect value={w.investor_id} onChange={(id) => setValue("investor_id", id, { shouldValidate: true })} error={errors.investor_id?.message} />}
              <Field required label="Number of installments" error={errors.installment_count?.message}><Input type="number" className={inputCls} {...register("installment_count")} /></Field>
              <Field label="Frequency" error={errors.frequency?.message}>
                <NativeSelect className={inputCls} {...register("frequency")}><option value="monthly">Monthly</option><option value="weekly">Weekly</option></NativeSelect>
              </Field>
              <Field required label="First installment date" error={errors.first_due_date?.message}><Input type="date" className={inputCls} {...register("first_due_date")} /></Field>
              {!investorMode && <Field label="Interest (flat, whole plan)" error={errors.interest_rate?.message ?? errors.interest_amount?.message}>
                <div className="flex gap-2">
                  <NativeSelect className={`${inputCls} w-28 shrink-0`} {...register("interest_mode")}>
                    <option value="percent">Percent %</option><option value="amount">Amount (Tk)</option>
                  </NativeSelect>
                  {w.interest_mode === "amount"
                    ? <Input type="number" step="0.01" placeholder="Amount" className={inputCls} {...register("interest_amount")} />
                    : <Input type="number" step="0.01" placeholder="Percent" className={inputCls} {...register("interest_rate")} />}
                </div>
                {plan && w.interest_mode === "amount" && plan.principal > 0 && <p className="mt-1 text-xs text-slate-500">That is {r2((plan.interest / plan.principal) * 100)}% of {money(plan.principal)}</p>}
              </Field>}
              {investorMode && (
                <Field required label="Investor profit" error={errors.investor_rate?.message ?? errors.investor_share_amount?.message}>
                  <div className="flex gap-2">
                    <NativeSelect className={`${inputCls} w-28 shrink-0`} {...register("investor_share_mode")}>
                      <option value="percent">Percent %</option><option value="amount">Amount (Tk)</option>
                    </NativeSelect>
                    {w.investor_share_mode === "amount"
                      ? <Input key="amount" type="number" step="0.01" placeholder="Amount" className={inputCls} {...register("investor_share_amount")} />
                      : <Input key="percent" type="number" step="0.01" placeholder="Percent" className={inputCls} {...register("investor_rate")} />}
                  </div>
                </Field>
              )}
            </div>
            {plan && (
              <div className={`mt-3 grid rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-emerald-900 ${showProfit ? "grid-cols-3" : "grid-cols-2"}`}>
                <div>
                  <p className="text-xs text-emerald-700">Total</p>
                  <p className="text-lg font-bold text-blue-700">{money(plan.total)}</p>
                </div>
                {showProfit && (
                  <div>
                    <p className="text-xs text-emerald-700">Profit</p>
                    <p className="text-lg font-bold text-emerald-700">{money(ownerProfit)}</p>
                    {investorProfit > 0 && <p className="text-xs text-slate-600">Investor profit {money(investorProfit)}</p>}
                  </div>
                )}
                <div>
                  <p className="text-xs text-emerald-700">Per {w.frequency === "weekly" ? "week" : "month"}</p>
                  <p className="text-lg font-bold text-orange-600">{money(plan.rows[0]?.amount ?? 0)}</p>
                </div>
              </div>
            )}
          </Card>
        )}

        {investorMode && calcOpen && <ProfitCalculator key={w.investor_id} open name={chosenInvestor.data?.name} investorId={chosenInvestor.data?.id} canSave saved={chosenInvestor.data} onSaved={(rate) => { chosenInvestor.reload(); setValue("investor_share_mode", "percent"); setValue("investor_rate", String(rate), { shouldValidate: true }); setCalcOpen(false); }} onApply={(rate) => { setValue("investor_share_mode", "percent"); setValue("investor_rate", String(rate), { shouldValidate: true }); }} onClose={() => setCalcOpen(false)} />}
        <div className="flex justify-end gap-2">
          <Button variant="outline" onClick={resetForm} disabled={isSubmitting}><RotateCcw className="size-4" /> Reset</Button>
          {!booking && <Button variant="outline" onClick={saveDraft} disabled={savingDraft || isSubmitting}>{savingDraft ? "Saving..." : draftId ? "Update draft" : "Save as draft"}</Button>}
          <Button type="submit" disabled={isSubmitting}>{isSubmitting ? "Saving..." : "Create sale"}</Button>
        </div>
      </form>

      <Modal open={!!created} onClose={() => setCreated(null)} title="Sale created">
        {created && (
          <div className="space-y-3">
            <p className="text-sm text-slate-600">Sale #{created.id} · {created.customer?.name} · {created.vehicle?.chassis_no}</p>
            <p className="text-sm font-semibold text-slate-800">Sale price {money(created.sale_price)}</p>
            <div className="flex flex-wrap justify-end gap-2">
              <Button variant="outline" onClick={() => setCreated(null)}>Close</Button>
              <Link className="inline-flex items-center rounded-lg border border-violet-200 bg-white px-3 py-2 text-sm font-medium text-violet-700 hover:bg-violet-50" href={`/sales/${created.id}`}>View sale</Link>
              <Button onClick={() => { openSaleMemo(created, user?.shop?.name ?? "Vehicle Hub"); setCreated(null); }}><Printer className="size-4" /> Print memo</Button>
            </div>
          </div>
        )}
      </Modal>
    </>
  );
}
