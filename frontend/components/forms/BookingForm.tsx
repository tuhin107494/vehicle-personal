"use client";

import { useEffect, useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { toast } from "sonner";
import { api, applyServerErrors } from "@/lib/api";
import { addDays, money, todayStr, toPayload } from "@/lib/format";
import { useFetch } from "@/lib/hooks";
import { useSettings } from "@/lib/settings";
import { useCategories } from "@/lib/categories";
import type { Booking, Vehicle } from "@/lib/types";
import { Button, Field, inputCls, Modal } from "@/components/app-ui";
import { CustomerSelect } from "@/components/Selects";
import { SearchSelect } from "@/components/SearchSelect";
import { Input } from "@/components/ui/input";
import { NativeSelect } from "@/components/ui/native-select";
import { Textarea } from "@/components/ui/textarea";

const schema = z
  .object({
    customer_id: z.string().min(1, "Choose the customer."),
    vehicle_id: z.string(), // optional: the vehicle can be chosen later, when the booking becomes a sale
    agreed_price: z.string().refine((v) => v === "" || Number(v) > 0, "Enter an amount above zero."), // optional: the price can be set later, when the booking becomes a sale
    advance_amount: z.string().refine((v) => v === "" || Number(v) >= 0, "Enter an amount of zero or more."), // optional: a vehicle can be held with no money taken
    advance_method: z.enum(["cash", "bank", "mobile"]),
    expires_on: z.string().refine((v) => v === "" || v >= todayStr(), "The deadline cannot be in the past."), // optional: with no date the booking is held until it is sold or cancelled
    note: z.string(),
  })
  .refine((v) => v.agreed_price === "" || v.advance_amount === "" || Number(v.advance_amount) < Number(v.agreed_price), { path: ["advance_amount"], message: "The advance must be less than the sale price." });
type Values = z.infer<typeof schema>;

/** Book a vehicle (or just take an advance) for a customer, or correct an active booking when `booking` is given: a chosen vehicle is held until the booking is turned into a sale, cancelled or expires. */
export function BookingForm({ open, booking, onClose, onSaved }: { open: boolean; booking?: Booking | null; onClose: () => void; onSaved: (b: Booking) => void }) {
  const settings = useSettings();
  const { nameOf } = useCategories();
  const days = settings.data?.booking?.days ?? 7;
  const blank: Values = { customer_id: "", vehicle_id: "", agreed_price: "", advance_amount: "", advance_method: "cash", expires_on: addDays(days), note: "" };
  const { register, handleSubmit, reset, setValue, setError, watch, formState: { errors, isSubmitting } } = useForm<Values>({ resolver: zodResolver(schema), defaultValues: blank });
  const w = watch();

  // each time the form opens it starts empty, and the vehicles that can still be booked are fetched
  const [wasOpen, setWasOpen] = useState(false);
  const [vehiclesWanted, setVehiclesWanted] = useState(false); // the vehicle field is optional: its list is fetched only when someone uses it
  if (open !== wasOpen) {
    setWasOpen(open);
    if (open) {
      reset(booking
        ? { customer_id: String(booking.customer_id), vehicle_id: booking.vehicle_id ? String(booking.vehicle_id) : "", agreed_price: booking.agreed_price ? String(Number(booking.agreed_price)) : "", advance_amount: String(Number(booking.advance_amount)), advance_method: booking.advance_method, expires_on: booking.expires_on ?? "", note: booking.note ?? "" }
        : { ...blank, expires_on: addDays(days) });
      setVehiclesWanted(false);
    }
  }
  const vehicles = useFetch<Vehicle[]>(open && vehiclesWanted ? "/vehicles?status=in_stock&per_page=100" : null);

  // choosing a vehicle fills in the shop's usual sale price for its type
  const chooseVehicle = (id: string) => {
    setValue("vehicle_id", id, { shouldValidate: true });
    const v = vehicles.data?.find((x) => String(x.id) === id);
    const usual = v ? settings.data?.sale?.prices?.[v.vehicle_type] : undefined;
    if (usual && !watch("agreed_price")) setValue("agreed_price", String(usual), { shouldValidate: true });
  };

  useEffect(() => {
    if (open && !booking) setValue("expires_on", addDays(days));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [days]);

  const onSubmit = async (values: Values) => {
    try {
      const res = await api<Booking>(booking ? `/bookings/${booking.id}` : "/bookings", { method: booking ? "PUT" : "POST", body: toPayload(values, ["customer_id", "vehicle_id", "agreed_price", "advance_amount"]) });
      toast.success(res.message);
      onSaved(res.data);
      onClose();
    } catch (e) {
      toast.error(applyServerErrors(e, setError));
    }
  };

  const balance = Number(w.agreed_price || 0) - Number(w.advance_amount || 0);

  return (
    <Modal open={open} title={booking ? `Edit booking #${booking.id}` : "New booking"} onClose={onClose}>
      <form onSubmit={handleSubmit(onSubmit)} className="space-y-3" noValidate>
        <CustomerSelect allowCreate label="Customer" selectedLabel={booking?.customer?.name} value={w.customer_id} onChange={(id) => setValue("customer_id", id, { shouldValidate: true })} error={errors.customer_id?.message} />
        <Field label="Vehicle (optional, in stock)" error={errors.vehicle_id?.message}>
          <SearchSelect
            value={w.vehicle_id}
            onChange={chooseVehicle}
            onOpen={() => setVehiclesWanted(true)}
            loading={vehicles.loading}
            placeholder="Search by chassis, model or brand..."
            fallbackLabel={booking?.vehicle ? `${booking.vehicle.chassis_no} - ${[booking.vehicle.brand, booking.vehicle.model, booking.vehicle.year].filter(Boolean).join(" ")}` : undefined}
            options={(vehicles.data ?? []).map((v) => ({ value: String(v.id), label: `${v.chassis_no} - ${[v.brand, v.model, v.year].filter(Boolean).join(" ")}`, hint: nameOf(v.vehicle_type) }))}
          />
        </Field>
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label="Sale price (optional)" error={errors.agreed_price?.message}><Input type="number" min="0" className={inputCls} {...register("agreed_price")} /></Field>
          <Field label="Advance received (optional)" error={errors.advance_amount?.message}><Input type="number" min="0" className={inputCls} {...register("advance_amount")} /></Field>
          <Field label="Advance paid by">
            <NativeSelect className={inputCls} {...register("advance_method")}>
              <option value="cash">Cash</option>
              <option value="bank">Bank</option>
              <option value="mobile">Mobile banking</option>
            </NativeSelect>
          </Field>
          <Field label="Hold until (optional)" error={errors.expires_on?.message}><Input type="date" min={todayStr()} className={inputCls} {...register("expires_on")} /></Field>
        </div>
        {!w.vehicle_id && <p className="text-xs text-slate-500">No vehicle yet: nothing is held, and the vehicle is chosen when the booking becomes a sale.</p>}
        {!w.agreed_price && <p className="text-xs text-slate-500">No price yet: it is set when the booking becomes a sale.</p>}
        {!(Number(w.advance_amount) > 0) && <p className="text-xs text-slate-500">No advance: nothing is received now.</p>}
        {!w.expires_on && <p className="text-xs text-slate-500">No date: the booking is held until it is sold or cancelled.</p>}
        {Number(w.advance_amount) > 0 && Number(w.agreed_price) > 0 && balance > 0 && <p className="rounded-lg bg-emerald-50 p-2 text-sm text-emerald-800">Balance to pay later: <b>{money(balance)}</b></p>}
        <Field label="Note"><Textarea className="w-full" rows={2} {...register("note")} /></Field>
        <div className="flex justify-end gap-2">
          <Button variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={isSubmitting}>{isSubmitting ? "Saving..." : booking ? "Save changes" : "Book vehicle"}</Button>
        </div>
      </form>
    </Modal>
  );
}
