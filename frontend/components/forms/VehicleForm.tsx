"use client";

import { useEffect, useRef, useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { ChevronDown, ChevronUp } from "lucide-react";
import { toast } from "sonner";
import { api, ApiError, applyServerErrors } from "@/lib/api";
import { useFetch } from "@/lib/hooks";
import { toPayload, savedMsg } from "@/lib/format";
import { DEFAULT_FIELDS, useCategories, useTypeDefaults, VehicleCategory } from "@/lib/categories";
import type { Vehicle } from "@/lib/types";
import { Button, Field, inputCls, Modal } from "@/components/app-ui";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { NativeSelect } from "@/components/ui/native-select";

export const vehicleFields = {
  chassis_no: z.string().min(1, "Chassis number is required."),
  engine_no: z.string().min(1, "Engine number is required."),
  registration_no: z.string(),
  vehicle_type: z.string().min(1, "Select the vehicle type."),
  brand: z.string(),
  model: z.string(),
  year: z.string().refine((v) => v === "" || (Number(v) >= 2017 && Number(v) <= 2030), "Year must be between 2017 and 2030."),
  color: z.string(),
  cc: z.string().refine((v) => v === "" || Number(v) > 0, "Enter a valid engine size."),
  fuel_type: z.string(),
};
export const detailFields = {
  registration_date: z.string().refine((v) => v === "" || v <= new Date().toISOString().slice(0, 10), "Registration date cannot be in the future."),
  tax_token_expiry: z.string(),
  fitness_expiry: z.string(),
  insurance_expiry: z.string(),
  mileage: z.string().refine((v) => v === "" || Number(v) >= 0, "Enter a valid mileage."),
  papers_received: z.boolean(),
  condition_notes: z.string(),
};
export const blankDetails = {
  registration_date: "", tax_token_expiry: "", fitness_expiry: "", insurance_expiry: "", mileage: "",
  papers_received: false, condition_notes: "",
};
export const DETAIL_NUMERIC = ["mileage"];

export function detailsFrom(v: Vehicle) {
  return {
    registration_date: v.registration_date ?? "", tax_token_expiry: v.tax_token_expiry ?? "", fitness_expiry: v.fitness_expiry ?? "", 
    insurance_expiry: v.insurance_expiry ?? "",
    mileage: v.mileage == null ? "" : String(v.mileage), papers_received: !!v.papers_received, condition_notes: v.condition_notes ?? "",
  };
}

/** Papers and condition of the vehicle; used on the purchase form and when editing a vehicle. */
/**
 * Papers and condition. Collapsed until you click "Show", which reveals every field at once.
 * `autoOpen` opens it by itself, used when a known vehicle's saved record is being shown.
 */
export function VehicleDetailFields({ register, errors, autoOpen }: { register: any; errors: any; autoOpen?: boolean }) {
  const [open, setOpen] = useState(false);

  useEffect(() => {
    if (autoOpen) setOpen(true);
  }, [autoOpen]);

  return (
    <div className="space-y-3">
      <button
        type="button"
        aria-expanded={open}
        onClick={() => setOpen((o) => !o)}
        className="flex w-full items-center justify-between rounded-lg border border-dashed border-emerald-400 bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-700 hover:bg-emerald-100"
      >
        {open ? "Hide papers & condition fields" : "Show papers & condition fields"}
        {open ? <ChevronUp className="size-4" /> : <ChevronDown className="size-4" />}
      </button>
      {open && (
        <>
          <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
            <Field label="Registration date" error={errors.registration_date?.message}><Input type="date" className={inputCls} {...register("registration_date")} /></Field>
            <Field label="Tax token expiry" error={errors.tax_token_expiry?.message}><Input type="date" className={inputCls} {...register("tax_token_expiry")} /></Field>
            <Field label="Fitness expiry" error={errors.fitness_expiry?.message}><Input type="date" className={inputCls} {...register("fitness_expiry")} /></Field>
            <Field label="Insurance expiry" error={errors.insurance_expiry?.message}><Input type="date" className={inputCls} {...register("insurance_expiry")} /></Field>
            <Field label="Mileage (km)" error={errors.mileage?.message}><Input type="number" className={inputCls} {...register("mileage")} /></Field>
          </div>
          <label className="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" className="accent-emerald-600" {...register("papers_received")} /> Papers received from supplier</label>
          <Field label="Condition notes" error={errors.condition_notes?.message}><Textarea rows={2} className={inputCls} {...register("condition_notes")} /></Field>
        </>
      )}
    </div>
  );
}

const schema = z.object({ ...vehicleFields, ...detailFields });
export type VehicleValues = z.infer<typeof schema>;

export const blankVehicle: Pick<VehicleValues, keyof typeof vehicleFields> = {
  chassis_no: "", engine_no: "", registration_no: "", vehicle_type: "", brand: "", model: "", year: String(new Date().getFullYear()), color: "", cc: "", fuel_type: "",
};


export const VEHICLE_NUMERIC = ["year", "cc"];

/** Shared input grid, reused by the purchase form for a brand new vehicle. */
const COLOURS = [
  { name: "Green", hex: "#16a34a" }, { name: "Red", hex: "#dc2626" }, { name: "Maroon", hex: "#7f1d1d" },
  { name: "Black", hex: "#111827" }, { name: "Blue", hex: "#2563eb" },
];

/**
 * Colour chosen with radio buttons. A type with a default colour (e.g. CNG = Green) offers only that colour;
 * other types offer the standard five plus colours the shop added. Either way a new colour can be added.
 */
function ColourPicker({ value, onChange, only }: { value: string; onChange: (v: string) => void; only?: string | null }) {
  const added = useFetch<string[]>("/vehicle-colors");
  const [adding, setAdding] = useState(false);
  const [name, setName] = useState("");
  const [saving, setSaving] = useState(false);

  const base = only ? COLOURS.filter((c) => c.name === only) : COLOURS;
  const extra = only ? [] : [...(added.data ?? [])];
  if (only && !base.length) base.push({ name: only, hex: "#cbd5e1" });
  const listed = new Set([...base.map((c) => c.name), ...extra]);
  if (value && !listed.has(value)) extra.push(value); // a colour just added, or an older vehicle's colour

  const chip = "flex items-center gap-2 rounded-full bg-white px-3 py-1.5 text-sm text-slate-700 ring-1 ring-emerald-200 transition hover:bg-emerald-50 peer-checked:bg-emerald-50 peer-checked:font-medium peer-checked:text-emerald-800 peer-checked:ring-2 peer-checked:ring-emerald-600 peer-focus-visible:ring-2";

  const add = async () => {
    if (!name.trim()) return;
    setSaving(true);
    try {
      const res = await api<string>("/vehicle-colors", { method: "POST", body: { name } });
      toast.success(res.message);
      added.reload();
      onChange(res.data);
      setName("");
      setAdding(false);
    } catch (e) {
      toast.error(e instanceof ApiError ? e.errors?.name?.[0] ?? e.message : "Failed to add colour");
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="space-y-2">
      <div role="radiogroup" aria-label="Colour" className="flex flex-wrap gap-2">
        {base.map((c) => (
          <label key={c.name} className="cursor-pointer">
            <input type="radio" name="colour" className="peer sr-only" checked={value === c.name} onChange={() => onChange(c.name)} />
            <span className={chip}>
              <span className="size-4 rounded-full ring-1 ring-slate-300" style={{ backgroundColor: c.hex }} />
              {c.name}
            </span>
          </label>
        ))}
        {extra.map((c) => (
          <label key={c} className="cursor-pointer">
            <input type="radio" name="colour" className="peer sr-only" checked={value === c} onChange={() => onChange(c)} />
            <span className={chip}>
              <span className="size-4 rounded-full bg-gradient-to-br from-slate-200 to-slate-400 ring-1 ring-slate-300" />
              {c}
            </span>
          </label>
        ))}
        <button type="button" onClick={() => setAdding((v) => !v)} className="rounded-full border border-dashed border-emerald-400 px-3 py-1.5 text-sm font-medium text-emerald-700 hover:bg-emerald-50">
          + Add colour
        </button>
      </div>
      {adding && (
        <div className="flex gap-2 sm:max-w-sm">
          <Input className="w-full" placeholder="New colour name" value={name} autoFocus onChange={(e) => setName(e.target.value)} onKeyDown={(e) => e.key === "Enter" && (e.preventDefault(), add())} />
          <Button onClick={add} disabled={saving || !name.trim()}>{saving ? "Adding..." : "Add"}</Button>
        </div>
      )}
    </div>
  );
}

const YEAR_FROM = 2017;
const YEAR_TO = 2030;
const YEARS = Array.from({ length: YEAR_TO - YEAR_FROM + 1 }, (_, i) => String(YEAR_TO - i));

/** Adds a vehicle type (like CNG or Car) for this shop, right from the vehicle type dropdown. */
function AddTypeDialog({ open, onClose, onAdded }: { open: boolean; onClose: () => void; onAdded: (c: VehicleCategory & { purchase_price: number | null }) => void }) {
  const [form, setForm] = useState({ name: "", default_brand: "", default_model: "", default_color: "", default_cc: "", purchase_price: "" });
  const [error, setError] = useState("");
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (open) {
      setForm({ name: "", default_brand: "", default_model: "", default_color: "", default_cc: "", purchase_price: "" });
      setError("");
    }
  }, [open]);

  const save = async () => {
    if (!form.name.trim()) return setError("Type name is required.");
    if (form.default_cc && (Number(form.default_cc) < 1 || Number(form.default_cc) > 10000)) return setError("Enter a valid CC.");
    if (form.purchase_price && Number(form.purchase_price) < 1) return setError("Price must be at least 1.");
    setSaving(true);
    try {
      const res = await api<VehicleCategory & { purchase_price: number | null }>("/vehicle-categories", {
        method: "POST",
        body: {
          name: form.name, default_brand: form.default_brand || null, default_model: form.default_model || null, default_color: form.default_color || null,
          default_cc: form.default_cc ? Number(form.default_cc) : null, purchase_price: form.purchase_price ? Number(form.purchase_price) : null,
        },
      });
      toast.success(res.message);
      onAdded(res.data);
      onClose();
    } catch (e) {
      setError(e instanceof ApiError ? e.errors?.name?.[0] ?? e.message : "Failed to add type");
    } finally {
      setSaving(false);
    }
  };

  const set = (k: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement>) => setForm((f) => ({ ...f, [k]: e.target.value }));

  return (
    <Modal open={open} onClose={onClose} title="Add vehicle type">
      <div className="space-y-3" onKeyDown={(e) => e.key === "Enter" && (e.preventDefault(), save())}>
        <Field label="Type name" error={error}><Input className={inputCls} placeholder="e.g. CNG, Car" value={form.name} onChange={set("name")} autoFocus /></Field>
        <p className="text-xs text-slate-500">Optional: filled in automatically whenever this type is chosen.</p>
        <div className="grid grid-cols-2 gap-3">
          <Field label="Brand"><Input className={inputCls} placeholder="Bajaj" value={form.default_brand} onChange={set("default_brand")} /></Field>
          <Field label="Model"><Input className={inputCls} placeholder="RE Compact" value={form.default_model} onChange={set("default_model")} /></Field>
          <Field label="Colour"><Input className={inputCls} placeholder="Green" value={form.default_color} onChange={set("default_color")} /></Field>
          <Field label="CC"><Input type="number" className={inputCls} placeholder="200" value={form.default_cc} onChange={set("default_cc")} /></Field>
        </div>
        <Field label="Purchase price"><Input type="number" step="0.01" className={inputCls} placeholder="Filled in when this type is chosen on a purchase" value={form.purchase_price} onChange={set("purchase_price")} /></Field>
        <div className="flex justify-end gap-2">
          <Button variant="outline" onClick={onClose}>Cancel</Button>
          <Button onClick={save} disabled={saving}>{saving ? "Adding..." : "Add type"}</Button>
        </div>
      </div>
    </Modal>
  );
}

export function VehicleFields({ register, errors, lockIds, setValue, getValues, watch, onTypeAdded }: { register: any; errors: any; lockIds?: boolean; setValue: (name: any, value: any) => void; getValues: (name: any) => any; watch: (name: any) => any; onTypeAdded?: (c: VehicleCategory & { purchase_price: number | null }) => void }) {
  const { categories, reload } = useCategories();
  const [addingType, setAddingType] = useState(false);
  const currentType = watch("vehicle_type") as string;
  const previousType = useRef("");
  useEffect(() => {
    if (currentType !== "__new") previousType.current = currentType;
  }, [currentType]);

  const { defaultsFor, isAuto } = useTypeDefaults();

  // Fill brand, model, colour and CC from the type, unless something custom was already typed or picked.
  const applyDefaults = (key: string, extra?: VehicleCategory) => {
    const d = defaultsFor(key, extra);
    for (const field of DEFAULT_FIELDS) {
      const current = getValues(field) as string | undefined;
      const untouchedYear = field === "year" && current === String(new Date().getFullYear()); // the form starts on this year
      if (d[field] != null && (!current || untouchedYear || isAuto(field, current))) setValue(field, String(d[field]));
    }
  };

  // Choosing a type fills in that category's default brand, colour and CC, unless a custom value was already typed or picked.
  const onTypeChange = (e: { target: { value: string } }) => {
    if (e.target.value === "__new") {
      setValue("vehicle_type", previousType.current); // keep the old choice until the new type is saved
      setAddingType(true);
      return;
    }
    applyDefaults(e.target.value);
  };

  return (
    <>
      <Field required label="Vehicle type" error={errors.vehicle_type?.message}>
        <NativeSelect className={inputCls} {...register("vehicle_type", { onChange: onTypeChange })}>
          <option value="">Select type</option>
          {categories.map((c) => <option key={c.key} value={c.key}>{c.name}</option>)}
          <option value="__new">+ Add new type...</option>
        </NativeSelect>
        <AddTypeDialog open={addingType} onClose={() => setAddingType(false)} onAdded={(c) => { reload(); setValue("vehicle_type", c.key); applyDefaults(c.key, c); onTypeAdded?.(c); }} />
      </Field>
      <div className="grid grid-cols-2 gap-3">
        <Field required label="Chassis no." error={errors.chassis_no?.message}><Input className={inputCls} {...register("chassis_no")} /></Field>
        <Field required label="Engine no." error={errors.engine_no?.message}><Input className={`${inputCls} ${lockIds ? "bg-slate-100" : ""}`} readOnly={lockIds} {...register("engine_no")} /></Field>
      </div>
      <div className="grid grid-cols-2 gap-3">
        <Field label="Brand" error={errors.brand?.message}><Input className={inputCls} {...register("brand")} /></Field>
        <Field label="Model" error={errors.model?.message}><Input className={inputCls} {...register("model")} /></Field>
      </div>
      <div className="grid grid-cols-2 gap-3">
        <Field label="Year" error={errors.year?.message}>
          <NativeSelect className={inputCls} {...register("year")}>
            <option value="">Select year</option>
            {YEARS.map((y) => <option key={y} value={y}>{y}</option>)}
          </NativeSelect>
        </Field>
        <Field label="CC" error={errors.cc?.message}><Input type="number" className={inputCls} {...register("cc")} /></Field>
      </div>
      <div className="grid grid-cols-2 gap-3">
        <Field label="Fuel type" error={errors.fuel_type?.message}><Input className={inputCls} {...register("fuel_type")} /></Field>
        <Field label="Registration no." error={errors.registration_no?.message}><Input className={inputCls} {...register("registration_no")} /></Field>
      </div>
      <Field label="Colour" error={errors.color?.message}>
        <ColourPicker value={watch("color") ?? ""} onChange={(v) => setValue("color", v)} only={categories.find((c) => c.key === watch("vehicle_type"))?.default_color} />
      </Field>
    </>
  );
}

export function VehicleForm({ open, vehicle, onClose, onSaved }: { open: boolean; vehicle?: Vehicle | null; onClose: () => void; onSaved: () => void }) {
  const { register, handleSubmit, reset, setValue, getValues, watch, setError, formState: { errors, isSubmitting } } = useForm<VehicleValues>({
    resolver: zodResolver(schema),
    defaultValues: { ...blankVehicle, ...blankDetails },
  });

  useEffect(() => {
    if (!open) return;
    reset({ ...Object.fromEntries(Object.keys(blankVehicle).map((k) => [k, String((vehicle as any)?.[k] ?? "")])), ...(vehicle ? detailsFrom(vehicle) : blankDetails) } as VehicleValues);
  }, [open, vehicle, reset]);

  const onSubmit = async (v: VehicleValues) => {
    try {
      await api(vehicle ? `/vehicles/${vehicle.id}` : "/vehicles", {
        method: vehicle ? "PUT" : "POST",
        body: { ...toPayload(v, [...VEHICLE_NUMERIC, ...DETAIL_NUMERIC]), papers_received: v.papers_received },
      });
      toast.success(savedMsg("Vehicle", !!vehicle));
      onSaved();
      onClose();
    } catch (e) {
      toast.error(applyServerErrors(e, setError));
    }
  };

  return (
    <Modal open={open} onClose={onClose} title="Edit vehicle">
      <form onSubmit={handleSubmit(onSubmit)} className="space-y-3">
        <VehicleFields register={register} errors={errors} setValue={setValue} getValues={getValues} watch={watch} />
        <VehicleDetailFields register={register} errors={errors} autoOpen />
        <div className="flex justify-end gap-2">
          <Button variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={isSubmitting}>{isSubmitting ? "Saving..." : "Save"}</Button>
        </div>
      </form>
    </Modal>
  );
}
