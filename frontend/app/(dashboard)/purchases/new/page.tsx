"use client";

import { Suspense, useEffect, useRef, useState } from "react";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { toast } from "sonner";
import { api, ApiError, applyServerErrors } from "@/lib/api";
import { fmtDate, money, todayStr, toPayload } from "@/lib/format";
import { useFetch } from "@/lib/hooks";
import type { Customer, DraftT, Purchase, Sale, Vehicle } from "@/lib/types";
import { DraftsBar, draftValues } from "@/components/DraftsBar";
import { Button, Card, Field, inputCls, KV, PageHeader, StatusBadge } from "@/components/app-ui";
import { CustomerSelect, SupplierSelect } from "@/components/Selects";
import { SearchSelect } from "@/components/SearchSelect";
import { DEFAULT_FIELDS, useCategories, useTypeDefaults } from "@/lib/categories";
import { useSettings } from "@/lib/settings";
import { blankDetails, blankVehicle, DETAIL_NUMERIC, detailFields, detailsFrom, VEHICLE_NUMERIC, VehicleDetailFields, VehicleFields, vehicleFields } from "@/components/forms/VehicleForm";
import { NativeSelect } from "@/components/ui/native-select";
import { Input } from "@/components/ui/input";

const amount = (msg: string) => z.string().min(1, msg).refine((v) => Number(v) > 0, "Must be greater than zero.");
const optional = z.string().refine((v) => v === "" || Number(v) >= 0, "Enter a valid amount.");

const schema = z.object({
  ...vehicleFields,
  ...detailFields,
  payment_method: z.string(),
  payment_reference: z.string(),
  party_id: z.string().min(1, "Select the seller."),
  memo_no: z.string(),
  purchase_date: z.string().min(1, "Purchase date is required.").refine((v) => v <= todayStr(), "Purchase date cannot be in the future."),
  price: amount("Purchase price is required."),
  paid_amount: optional,
  cost_extra: optional,
  notes: z.string(),
}).refine((v) => v.paid_amount === "" || Number(v.paid_amount) <= Number(v.price), { path: ["paid_amount"], message: "Paid amount cannot be more than the price." });
type Values = z.infer<typeof schema>;

const vehicleValuesFrom = (v: Vehicle) => ({
  engine_no: v.engine_no, registration_no: v.registration_no ?? "", vehicle_type: v.vehicle_type ?? "", brand: v.brand ?? "", model: v.model ?? "",
  year: v.year == null ? "" : String(v.year), color: v.color ?? "", cc: v.cc == null ? "" : String(v.cc), fuel_type: v.fuel_type ?? "",
  ...detailsFrom(v),
});

function PurchaseForm() {
  const router = useRouter();
  const params = useSearchParams();
  const [source, setSource] = useState<"supplier" | "customer">("supplier"); // where the vehicle is bought from
  const [vehicle, setVehicle] = useState<Vehicle | null>(null); // set when the chassis or registration number matches an existing vehicle

  const { register, handleSubmit, reset, setValue, getValues, watch, setError, formState: { errors, isSubmitting } } = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: { ...blankVehicle, ...blankDetails, chassis_no: params.get("chassis") ?? "", payment_method: "", payment_reference: "", party_id: "", memo_no: "", purchase_date: todayStr(), price: "", paid_amount: "", cost_extra: "", notes: "" },
  });

  const settings = useSettings();
  const { defaultsFor, ready: defaultsReady } = useTypeDefaults();
  const { nameOf } = useCategories();

  // "From customer": pick the customer first, then one of the vehicles they bought from us (or another vehicle).
  const customerId = source === "customer" ? watch("party_id") : "";
  const customer = useFetch<{ customer: Customer; sales: Sale[] }>(customerId ? `/customers/${customerId}/history` : null);
  const activeSales = (customer.data?.sales ?? []).filter((s) => s.status === "active");
  const ownedSales = activeSales.filter((s) => s.vehicle?.status === "sold" && s.vehicle.current_sale_id === s.id); // still with this customer
  const totalDue = activeSales.reduce((sum, s) => sum + s.due_amount, 0);
  const [picked, setPicked] = useState(""); // a sale id, or "other" for a vehicle we did not sell them

  const clearVehicle = () => {
    setVehicle(null);
    setValue("chassis_no", "");
    setValue("engine_no", "");
  };
  // Forget the vehicle that was selected: blank every vehicle field, not just the chassis and engine numbers.
  const resetVehicleFields = () => {
    clearVehicle();
    for (const [k, val] of Object.entries({ ...blankVehicle, ...blankDetails })) setValue(k as keyof Values, val as never);
  };
  const changeCustomer = (id: string) => {
    setValue("party_id", id, { shouldValidate: true });
    setPicked("");
    resetVehicleFields();
    resetAdjust();
  };
  const pickSale = (sale: Sale) => {
    const v = sale.vehicle!;
    setPicked(String(sale.id));
    setVehicle(v);
    setValue("chassis_no", v.chassis_no);
    for (const [k, val] of Object.entries(vehicleValuesFrom(v))) setValue(k as keyof Values, val as never);
  };
  const pickOther = () => {
    setPicked("other"); // deselects the vehicle card that was chosen before
    resetVehicleFields();
    resetAdjust();
  };
  // Quick find: one search box for customer name, phone, engine or chassis number.
  const [findText, setFindText] = useState("");
  const [findQuery, setFindQuery] = useState("");
  useEffect(() => {
    const t = setTimeout(() => setFindQuery(findText.trim()), 300);
    return () => clearTimeout(t);
  }, [findText]);
  const found = useFetch<Sale[]>(source === "customer" && findQuery.length >= 2 ? `/vehicles/owned?search=${encodeURIComponent(findQuery)}` : null);
  const chooseFound = (saleId: string) => {
    const sale = found.data?.find((s) => String(s.id) === saleId);
    if (!sale?.vehicle) return;
    changeCustomer(String(sale.customer_id)); // fills the customer, whose details and vehicles then load
    pickSale(sale);
  };

  const pickedSale = ownedSales.find((s) => String(s.id) === picked);

  // Due adjustment: set some or all of the due on THIS vehicle's sale off against the purchase (never other sales).
  const adjustSale = pickedSale && pickedSale.due_amount > 0 ? pickedSale : undefined;
  const [adjustOn, setAdjustOn] = useState(false);
  const [adjustAmount, setAdjustAmount] = useState("");
  const [amountEdited, setAmountEdited] = useState(false); // once typed by hand, stop auto-filling
  const priceNow = Number(watch("price")) || 0;
  const adjustMax = adjustSale ? Math.min(adjustSale.due_amount, priceNow) : 0;
  const adjusting = adjustOn && !!adjustSale ? Number(adjustAmount) || 0 : 0;

  const toggleAdjust = (on: boolean) => {
    setAdjustOn(on);
    if (!on || !adjustSale) return;
    const max = Math.min(adjustSale.due_amount, priceNow);
    setAdjustAmount(String(max));
    if (!getValues("paid_amount")) setValue("paid_amount", String(Math.max(priceNow - max, 0))); // the rest is paid to the customer
  };
  const resetAdjust = () => {
    setAdjustOn(false);
    setAdjustAmount("");
    setAmountEdited(false);
  };
  useEffect(resetAdjust, [picked]); // a different vehicle means a different sale, so start over
  useEffect(() => {
    // Auto-fill: the most that can be adjusted (this vehicle's due, or the price if lower), kept up to date as the price changes.
    if (adjustOn && adjustSale && !amountEdited) setAdjustAmount(String(adjustMax));
  }, [adjustOn, adjustSale, adjustMax, amountEdited]);

  // A vehicle that is currently with one of our customers brings that customer along; otherwise the customer is chosen by hand.
  const autoCustomer = (v: Vehicle) => {
    const sale = v.current_sale;
    if (source !== "customer" || v.status !== "sold" || !sale || sale.status !== "active") return;
    if (String(sale.customer_id) !== getValues("party_id")) setValue("party_id", String(sale.customer_id), { shouldValidate: true });
    setPicked(String(sale.id));
  };

  // Start on the shop's primary vehicle type (Settings), once, with that type's presets.
  const primaryApplied = useRef(false);
  useEffect(() => {
    if (primaryApplied.current || !settings.data || !defaultsReady) return;
    primaryApplied.current = true;
    const type = settings.data.purchase.vehicle_type;
    if (!type || params.get("chassis")) return;
    setValue("vehicle_type", type);
    const d = defaultsFor(type);
    for (const field of DEFAULT_FIELDS) if (d[field] != null) setValue(field, String(d[field]));
  }, [settings.data, defaultsReady, defaultsFor, params, setValue]);

  // A vehicle type brings its default price (Settings), unless a different price was already typed.
  const vehicleType = watch("vehicle_type");
  const autoPrice = useRef("");
  useEffect(() => {
    const price = settings.data?.purchase.prices?.[vehicleType];
    const current = getValues("price");
    if (!price) {
      // This type has no preset price: drop the previous type's price if it was only auto-filled.
      if (autoPrice.current && current === autoPrice.current) {
        setValue("price", "");
        autoPrice.current = "";
      }
      return;
    }
    if (!current || current === autoPrice.current) {
      setValue("price", String(price));
      autoPrice.current = String(price);
    }
  }, [vehicleType, settings.data, getValues, setValue]);

  // Typing a chassis number that already exists loads that vehicle, so a repurchase keeps its details.
  const chassis = watch("chassis_no").trim();
  useEffect(() => {
    if (source !== "customer" || chassis.length < 3) {
      setVehicle(null);
      return;
    }
    const t = setTimeout(async () => {
      try {
        const res = await api<Vehicle | null>(`/vehicles/lookup?chassis_no=${encodeURIComponent(chassis)}`);
        setVehicle(res.data);
        if (res.data) {
          for (const [k, val] of Object.entries(vehicleValuesFrom(res.data))) setValue(k as keyof Values, val as never);
          autoCustomer(res.data);
        }
      } catch {
        setVehicle(null);
      }
    }, 500);
    return () => clearTimeout(t);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [chassis, source]);

  // A registration number that already belongs to a vehicle finds that vehicle too (its registration details are shown below).
  const registration = watch("registration_no").trim();
  useEffect(() => {
    if (source !== "customer" || vehicle || registration.length < 3) return; // only second-hand buys look up vehicles we already know
    const t = setTimeout(async () => {
      try {
        const res = await api<Vehicle | null>(`/vehicles/lookup?registration_no=${encodeURIComponent(registration)}`);
        if (!res.data) return;
        setValue("chassis_no", res.data.chassis_no);
        setVehicle(res.data);
        for (const [k, val] of Object.entries(vehicleValuesFrom(res.data))) setValue(k as keyof Values, val as never);
        autoCustomer(res.data);
      } catch {
        // a failed lookup just leaves the form as a new vehicle
      }
    }, 500);
    return () => clearTimeout(t);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [registration, vehicle]);

  const blocked = !!vehicle && vehicle.status !== "sold";

  // Drafts: the form as typed, kept for later. Saving one changes nothing in stock or money.
  const [draftId, setDraftId] = useState<number | null>(null);
  const [draftsKey, setDraftsKey] = useState(0);
  const [savingDraft, setSavingDraft] = useState(false);

  const saveDraft = async () => {
    const values = getValues();
    const label = `${values.chassis_no || values.engine_no || "New vehicle"} · ${source === "customer" ? "second hand" : "from supplier"}${values.price ? ` · ${money(Number(values.price))}` : ""}`;
    setSavingDraft(true);
    try {
      const body = { label, data: { source, values, picked } };
      const res = draftId ? await api<DraftT>(`/drafts/${draftId}`, { method: "PUT", body }) : await api<DraftT>("/drafts", { method: "POST", body: { type: "purchase", ...body } });
      setDraftId(res.data.id);
      setDraftsKey((k) => k + 1);
      toast.success(res.message);
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : "Could not save the draft");
    } finally {
      setSavingDraft(false);
    }
  };

  const resumeDraft = (d: DraftT) => {
    setSource(d.data.source ?? "supplier");
    setVehicle(null);
    resetAdjust();
    reset(draftValues(d.data.values, getValues())); // the chassis lookup below then reloads the vehicle and customer on its own
    setPicked(d.data.picked ?? "");
    setDraftId(d.id);
    toast.success("Draft opened");
  };

  const onSubmit = async (v: Values) => {
    if (adjustOn) {
      if (!adjustSale) return toast.error("Choose the sale to adjust the due against.");
      if (!(adjusting > 0)) return toast.error("Enter the amount of due to adjust.");
      if (adjusting > adjustMax) return toast.error(`The adjustment cannot be more than ${money(adjustMax)} (the due or the purchase price, whichever is lower).`);
      if (adjusting + (Number(v.paid_amount) || 0) > Number(v.price)) return toast.error("The adjustment and the amount paid are more than the purchase price.");
    }
    try {
      const fields: Record<string, unknown> = {
        source, ...toPayload({ [source === "customer" ? "customer_id" : "supplier_id"]: v.party_id, memo_no: v.memo_no, purchase_date: v.purchase_date, price: v.price, paid_amount: v.paid_amount, cost_extra: v.cost_extra, notes: v.notes, payment_method: v.payment_method, payment_reference: v.payment_reference }, []),
        ...toPayload({ registration_date: v.registration_date, tax_token_expiry: v.tax_token_expiry, fitness_expiry: v.fitness_expiry, insurance_expiry: v.insurance_expiry, mileage: v.mileage, condition_notes: v.condition_notes }, []),
        papers_received: v.papers_received ? "1" : "0",
        ...(adjusting > 0 && adjustSale ? { due_adjustment: adjusting, adjusted_sale_id: adjustSale.id } : {}),
        ...(vehicle
          ? { vehicle_id: vehicle.id, ...toPayload({ vehicle_type: v.vehicle_type, registration_no: v.registration_no, brand: v.brand, model: v.model, year: v.year, color: v.color, cc: v.cc, fuel_type: v.fuel_type }, []) }
          : toPayload({ chassis_no: v.chassis_no, engine_no: v.engine_no, registration_no: v.registration_no, vehicle_type: v.vehicle_type, brand: v.brand, model: v.model, year: v.year, color: v.color, cc: v.cc, fuel_type: v.fuel_type }, [])),
      };
      const form = new FormData();
      for (const [k, val] of Object.entries(fields)) form.append(k, String(val));
      const res = await api<Purchase>("/purchases", { method: "POST", body: form });
      if (draftId) await api(`/drafts/${draftId}`, { method: "DELETE" }).catch(() => {}); // the real record exists now
      toast.success("Vehicle added successfully");
      router.replace("/vehicles"); // back to the list; the message above confirms the purchase
    } catch (e) {
      toast.error(applyServerErrors(e, setError));
    }
  };

  return (
    <>
      <PageHeader title="Buy vehicle" />
      <DraftsBar type="purchase" refreshKey={draftsKey} activeId={draftId} onResume={resumeDraft} onDeleted={(id) => id === draftId && setDraftId(null)} />
      <div role="tablist" className="mb-4 inline-flex gap-1 rounded-xl bg-white p-1 ring-1 ring-emerald-300">
        {([["supplier", "From supplier"], ["customer", "From customer (second hand)"]] as const).map(([key, text]) => (
          <button
            key={key}
            type="button"
            role="tab"
            aria-selected={source === key}
            onClick={() => { if (source !== key) { setSource(key); setValue("party_id", ""); setPicked(""); if (vehicle) resetVehicleFields(); else clearVehicle(); resetAdjust(); } }}
            className={`rounded-lg px-4 py-2 text-sm font-medium transition ${source === key ? "bg-gradient-to-r from-emerald-600 to-green-600 text-white shadow" : "text-emerald-800 hover:bg-emerald-50"}`}
          >
            {text}
          </button>
        ))}
      </div>
      <form onSubmit={handleSubmit(onSubmit)} className="space-y-4" noValidate>
        <p className="text-xs text-slate-500"><span className="text-red-600">*</span> Required</p>
        {source === "customer" && (
          <>
            <Card title="1. Customer (seller)">
              <div className="space-y-3">
                <CustomerSelect allowCreate label="Customer" value={watch("party_id")} onChange={changeCustomer} selectedLabel={customer.data?.customer.name} error={errors.party_id?.message} />
                {customer.data && (
                  <div className="rounded-lg border border-emerald-200 bg-emerald-50 p-3">
                    <KV items={[
                      ["Name", customer.data.customer.name], ["Father's name", customer.data.customer.father_name], ["NID number", customer.data.customer.nid], ["Mobile", customer.data.customer.phone],
                      ["Alt. mobile", customer.data.customer.alt_phone], ["Address", customer.data.customer.address],
                    ]} />
                    <div className="mt-3 flex flex-wrap items-center gap-x-5 gap-y-1 text-sm">
                      <span>Vehicles bought from us: <b>{activeSales.length}</b></span>
                      <span className={totalDue > 0 ? "font-semibold text-amber-700" : "font-semibold text-emerald-700"}>Total due: {money(totalDue)}</span>
                      <Link className="underline" href={`/customers/${customer.data.customer.id}`} target="_blank">View customer</Link>
                    </div>
                  </div>
                )}
              </div>
            </Card>
            <Card title="2. Find or choose the vehicle">
              <div className="space-y-3">
                <SearchSelect
                value={picked && picked !== "other" ? picked : ""}
                onChange={chooseFound}
                onQueryChange={setFindText}
                loading={found.loading}
                fallbackLabel={pickedSale?.vehicle ? `${pickedSale.vehicle.chassis_no} · ${customer.data?.customer.name ?? ""}` : undefined}
                placeholder="Search by customer name, mobile, NID, engine no. or chassis no...."
                options={(found.data ?? []).map((sale) => ({
                  value: String(sale.id),
                  label: `${sale.vehicle?.chassis_no} · ${sale.customer?.name}`,
                  hint: [[sale.customer?.phone, sale.customer?.alt_phone].filter(Boolean).join(" / "), sale.customer?.nid ? `NID ${sale.customer.nid}` : "", `Engine ${sale.vehicle?.engine_no}`, [sale.vehicle?.brand, sale.vehicle?.model].filter(Boolean).join(" "), sale.due_amount > 0 ? `Due ${money(sale.due_amount)}` : "Fully paid"].filter(Boolean).join(" · "),
                }))}
              />
                <p className="text-xs text-slate-500">Picking a vehicle we sold fills in its customer.</p>
                {customerId && (
                  <div className="border-t border-emerald-100 pt-3">
                <p className="mb-3 text-sm text-slate-600">
                  {ownedSales.length ? "Pick the vehicle they are selling back." : "No vehicle from our sales. Enter the vehicle below."}
                </p>
                <div className="grid gap-3 md:grid-cols-2">
                  {ownedSales.map((s) => (
                    <button
                      key={s.id}
                      type="button"
                      onClick={() => pickSale(s)}
                      className={`rounded-xl p-3 text-left ring-1 transition ${picked === String(s.id) ? "bg-emerald-50 ring-2 ring-emerald-600" : "bg-white ring-emerald-300 hover:bg-emerald-50"}`}
                    >
                      <p className="font-semibold text-slate-900">{s.vehicle?.chassis_no}</p>
                      <p className="text-sm text-slate-600">{[s.vehicle?.brand, s.vehicle?.model, s.vehicle?.year].filter(Boolean).join(" ") || "-"} · Sale #{s.id} · {fmtDate(s.sale_date)}</p>
                      <p className="mt-1 text-sm">Price {money(s.sale_price)} · Paid {money(s.total_paid)}</p>
                      <p className={`text-sm font-semibold ${s.due_amount > 0 ? "text-amber-700" : "text-emerald-700"}`}>{s.due_amount > 0 ? `Due ${money(s.due_amount)}` : "Fully paid"}</p>
                    </button>
                  ))}
                  <button
                    type="button"
                    onClick={pickOther}
                    className={`rounded-xl border border-dashed p-3 text-left text-sm font-medium transition ${picked === "other" ? "border-emerald-600 bg-emerald-50 text-emerald-800" : "border-emerald-400 text-emerald-700 hover:bg-emerald-50"}`}
                  >
                    + Another vehicle (not from our sales)
                  </button>
                </div>
                  </div>
                )}
              </div>
            </Card>
          </>
        )}

        <Card title={source === "customer" ? "3. Vehicle information" : "Vehicle information"}>
          {source === "customer" && !vehicle && (
            <p className="mb-3 text-sm text-slate-600">Enter the chassis, engine or registration no. A vehicle we sold selects its customer.</p>
          )}
          {vehicle && (
            <div className={`mb-4 space-y-1 rounded-lg border p-3 text-sm ${blocked ? "border-red-200 bg-red-50" : "border-emerald-200 bg-emerald-50"}`}>
              <p className="flex flex-wrap items-center gap-2 font-medium">
                Existing vehicle: {[vehicle.brand, vehicle.model, vehicle.year].filter(Boolean).join(" ") || vehicle.chassis_no} <StatusBadge status={vehicle.status} />
                <Link className="text-xs font-normal underline" href={`/vehicles/${vehicle.id}`} target="_blank">View history</Link>
              </p>
              {blocked
                ? <p className="text-red-700">Currently {vehicle.status.replace("_", " ")}. It can be bought again only after it is sold.</p>
                : <p className="text-emerald-800">Already in our records. This will be recorded as a repurchase.</p>}
              {pickedSale && (
                <p className="text-sm">
                  Sold to this customer on sale #{pickedSale.id}: price {money(pickedSale.sale_price)}, paid {money(pickedSale.total_paid)},{" "}
                  <b className={pickedSale.due_amount > 0 ? "text-amber-700" : "text-emerald-700"}>{pickedSale.due_amount > 0 ? `due ${money(pickedSale.due_amount)}` : "fully paid"}</b>{" "}
                  <Link className="underline" href={`/sales/${pickedSale.id}`} target="_blank">View sale</Link>
                </p>
              )}
              <div className="pt-2">
                <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-emerald-700">Registration information</p>
                <KV items={[
                  ["Registration no.", vehicle.registration_no], ["Registration date", fmtDate(vehicle.registration_date)], ["Tax token expiry", fmtDate(vehicle.tax_token_expiry)],
                  ["Fitness expiry", fmtDate(vehicle.fitness_expiry)], ["Insurance expiry", fmtDate(vehicle.insurance_expiry)], ["Papers received", vehicle.papers_received ? "Yes" : "No"],
                ]} />
              </div>
            </div>
          )}
          {vehicle ? (
            // A vehicle we already know is shown as information only; its record is used as it is.
            <div className="rounded-lg border border-emerald-100 bg-white p-3">
              <KV items={[
                ["Vehicle type", nameOf(vehicle.vehicle_type)], ["Chassis no.", vehicle.chassis_no], ["Engine no.", vehicle.engine_no],
                ["Brand", vehicle.brand], ["Model", vehicle.model], ["Year", vehicle.year],
                ["CC", vehicle.cc], ["Fuel type", vehicle.fuel_type], ["Registration no.", vehicle.registration_no],
                ["Colour", vehicle.color],
              ]} />
            </div>
          ) : (
            <div className="space-y-3"><VehicleFields register={register} errors={errors} lockIds={!!vehicle} setValue={setValue} getValues={getValues} watch={watch} onTypeAdded={(c) => { settings.reload(); if (c.purchase_price && !getValues("price")) setValue("price", String(c.purchase_price)); }} /></div>
          )}
        </Card>

        <Card title={source === "customer" ? "4. Purchase details" : "Supplier & purchase details"}>
          <div className="grid gap-3 md:grid-cols-2">
            {source === "supplier" && <SupplierSelect allowCreate={false} label="Supplier" value={watch("party_id")} onChange={(id) => setValue("party_id", id, { shouldValidate: true })} error={errors.party_id?.message} />}
            {source === "customer" && (
              <div className="space-y-3 md:col-span-2">
                <div className="grid items-end gap-3 md:grid-cols-2 lg:grid-cols-4">
                  <Field label="Customer's total due">
                    <Input readOnly className={`${inputCls} bg-slate-50 font-semibold ${totalDue > 0 ? "text-amber-700" : "text-emerald-700"}`} value={money(customerId ? totalDue : 0)} />
                  </Field>
                  <Field label={pickedSale ? `Vehicle's due (sale #${pickedSale.id})` : "Vehicle's due"}>
                    <div className="flex gap-2">
                      <Input readOnly className={`${inputCls} bg-slate-50 font-semibold ${pickedSale && pickedSale.due_amount > 0 ? "text-amber-700" : "text-emerald-700"}`} value={money(pickedSale ? pickedSale.due_amount : 0)} />
                      <label className={`flex shrink-0 items-center gap-2 rounded-lg border px-3 text-sm font-medium ${adjustSale ? "cursor-pointer border-emerald-400 bg-emerald-50 text-emerald-800" : "border-slate-200 bg-slate-50 text-slate-400"}`}>
                        <input type="checkbox" className="accent-emerald-600" disabled={!adjustSale} checked={adjustOn} onChange={(e) => toggleAdjust(e.target.checked)} />
                        Adjust due
                      </label>
                    </div>
                  </Field>
                  <Field required label="Purchase price" error={errors.price?.message}><Input type="number" step="0.01" className={inputCls} {...register("price")} /></Field>
                  <Field label="Payable amount">
                    <Input readOnly className={`${inputCls} bg-slate-50 font-semibold text-emerald-800`} value={money(Math.max(priceNow - adjusting, 0))} />
                  </Field>
                </div>
                {adjustOn && (
                  <div className="rounded-lg border border-emerald-200 bg-emerald-50 p-3">
                    <div className="grid gap-3 md:grid-cols-2">
                      <Field label={`Amount to adjust (up to ${money(adjustMax)})`}>
                        <Input type="number" step="0.01" min="0" max={adjustMax} className={inputCls} value={adjustAmount} onChange={(e) => { setAmountEdited(true); setAdjustAmount(e.target.value); }} />
                      </Field>
                    </div>
                    {adjustSale && (
                      <p className="mt-2 text-sm text-emerald-900">
                        {money(priceNow)} − {money(adjusting)} adjusted = <b>{money(Math.max(priceNow - adjusting, 0))}</b> payable. Due on sale #{adjustSale.id}: {money(adjustSale.due_amount)} → <b className="text-amber-700">{money(Math.max(adjustSale.due_amount - adjusting, 0))}</b>.
                      </p>
                    )}
                  </div>
                )}
              </div>
            )}
            <Field required label="Purchase date" error={errors.purchase_date?.message}><Input type="date" className={inputCls} {...register("purchase_date")} /></Field>
            <Field label={source === "customer" ? "Memo no." : "Supplier memo no."} error={errors.memo_no?.message}><Input className={inputCls} placeholder="Memo / invoice number" {...register("memo_no")} /></Field>
            {source === "supplier" && <Field required label="Price" error={errors.price?.message}><Input type="number" step="0.01" className={inputCls} {...register("price")} /></Field>}
            <Field label="Paid amount" error={errors.paid_amount?.message}><Input type="number" step="0.01" className={inputCls} {...register("paid_amount")} /></Field>
            <Field label="Extra cost (repairs etc.)" error={errors.cost_extra?.message}><Input type="number" step="0.01" className={inputCls} {...register("cost_extra")} /></Field>
            <Field label="Payment method" error={errors.payment_method?.message}>
              <NativeSelect className={inputCls} {...register("payment_method")}>
                <option value="">Not specified</option><option value="cash">Cash</option><option value="bank">Bank</option><option value="mobile">Mobile</option>
              </NativeSelect>
            </Field>
            <Field label="Payment reference" error={errors.payment_reference?.message}><Input className={inputCls} placeholder="Transaction / cheque no." {...register("payment_reference")} /></Field>
            <div className="md:col-span-2"><Field label="Notes" error={errors.notes?.message}><Input className={inputCls} {...register("notes")} /></Field></div>
          </div>
        </Card>
        <Card title="Vehicle papers & condition">
            <VehicleDetailFields register={register} errors={errors} autoOpen={!!vehicle} />
          </Card>
        <div className="flex justify-end gap-2">
          <Button variant="outline" onClick={saveDraft} disabled={savingDraft || isSubmitting}>{savingDraft ? "Saving..." : draftId ? "Update draft" : "Save as draft"}</Button>
          <Button type="submit" disabled={isSubmitting || blocked}>{isSubmitting ? "Saving..." : vehicle ? "Record repurchase" : "Record purchase"}</Button>
        </div>
      </form>
    </>
  );
}

export default function NewPurchasePage() {
  return <Suspense><PurchaseForm /></Suspense>;
}
