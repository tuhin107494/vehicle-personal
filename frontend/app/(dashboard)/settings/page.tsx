"use client";

import { useState } from "react";
import { Save, Trash2 } from "lucide-react";
import { toast } from "sonner";
import { api, ApiError } from "@/lib/api";
import { useCategories } from "@/lib/categories";
import { typeColor } from "@/lib/typeColors";
import { Settings, useSettings } from "@/lib/settings";
import { Button, Card, ErrorText, Field, inputCls, Loading, PageHeader } from "@/components/app-ui";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { NativeSelect } from "@/components/ui/native-select";

// The "+ Add field" dropdown is always light green, whatever colour its card is.
const ADD_FIELD_STYLE = "[&_select]:border-dashed [&_select]:border-emerald-400 [&_select]:bg-emerald-50 [&_select]:font-medium [&_select]:text-emerald-700";

// Fields a shop can preset for each vehicle type. "+ Add field" chooses which columns the table shows.
const FIELDS = [
  { key: "purchase_price", label: "Purchase price", type: "number" },
  { key: "sale_price", label: "Sale price", type: "number" },
  { key: "brand", label: "Brand", type: "text" },
  { key: "model", label: "Model", type: "text" },
  { key: "color", label: "Colour", type: "text" },
  { key: "cc", label: "CC", type: "number" },
  { key: "year", label: "Year", type: "number", min: "2017", max: "2030" },
  { key: "fuel_type", label: "Fuel type", type: "text" },
  { key: "mileage", label: "Mileage (km)", type: "number", min: "0" },
] as const;
// Everything except the two prices is a vehicle field preset that fills in the Buy vehicle form.
const VEHICLE_KEYS = ["brand", "model", "color", "cc", "year", "fuel_type", "mileage"] as const;
const NUMERIC_KEYS = ["cc", "year", "mileage"];
type FieldKey = (typeof FIELDS)[number]["key"];
type Grid = Record<FieldKey, Record<string, string>>; // field -> vehicle type -> value

const emptyGrid = (): Grid => Object.fromEntries(FIELDS.map((f) => [f.key, {}])) as Grid;
const strings = (m: Record<string, unknown>) => Object.fromEntries(Object.entries(m).map(([k, v]) => [k, String(v)]));

function gridFrom(s: Settings): Grid {
  const g = emptyGrid();
  g.purchase_price = strings(s.purchase.prices ?? {});
  g.sale_price = strings(s.sale.prices ?? {});
  for (const [type, d] of Object.entries(s.type_defaults ?? {})) {
    for (const f of VEHICLE_KEYS) if (d[f] != null) g[f][type] = String(d[f]);
  }
  return g;
}

export default function SettingsPage() {
  const settings = useSettings();
  const { categories } = useCategories();
  const [saving, setSaving] = useState(false);
  // The vehicle type each form starts on: the Buy vehicle form preselects it, the New sale form filters its vehicles by it.
  const [primary, setPrimary] = useState({ purchase: "", sale: "" });
  const [savedPrimary, setSavedPrimary] = useState("");
  // The per-vehicle-type table lives outside the form fields: which types and fields are shown, and their values.
  const [grid, setGrid] = useState<Grid>(emptyGrid());
  const [savedGrid, setSavedGrid] = useState("");
  const [rows, setRows] = useState<string[]>([]);
  const [typeCols, setTypeCols] = useState<Record<string, FieldKey[]>>({}); // fields shown on each type's card

  // start from what is saved each time the settings (re)load
  const [loadedFrom, setLoadedFrom] = useState<Settings | null>(null);
  if (settings.data && settings.data !== loadedFrom) {
    setLoadedFrom(settings.data);
    const start = { purchase: settings.data.purchase.vehicle_type ?? "", sale: settings.data.sale.vehicle_type ?? "" };
    setPrimary(start);
    setSavedPrimary(JSON.stringify(start));
    const loaded = gridFrom(settings.data);
    setGrid(loaded);
    setSavedGrid(JSON.stringify(loaded));
    setRows([...new Set(Object.values(loaded).flatMap((m) => Object.keys(m)))]);
    const types = new Set(Object.values(loaded).flatMap((m) => Object.keys(m)));
    setTypeCols(Object.fromEntries([...types].map((t) => [t, FIELDS.map((f) => f.key).filter((k) => loaded[k][t] !== undefined)])));
  }

  if (settings.loading && !settings.data) return <Loading />;
  if (settings.error || !settings.data) return <ErrorText message={settings.error ?? "Failed to load"} />;

  const tableDirty = JSON.stringify(grid) !== savedGrid || JSON.stringify(primary) !== savedPrimary;
  const listed = rows.map((k) => categories.find((c) => c.key === k)).filter((c): c is NonNullable<typeof c> => !!c);
  const availableTypes = categories.filter((c) => !rows.includes(c.key));

  // Removing a row or a column empties its values, and an emptied value is deleted on save.
  const removeRow = (type: string) => {
    setRows((r) => r.filter((k) => k !== type));
    setGrid((g) => Object.fromEntries(Object.entries(g).map(([f, m]) => [f, { ...m, [type]: "" }])) as Grid);
  };
  const addType = (type: string) => {
    setRows((r) => [...r, type]);
    setTypeCols((c) => ({ ...c, [type]: ["purchase_price", "sale_price"] }));
  };
  const addField = (type: string, field: FieldKey) => setTypeCols((c) => ({ ...c, [type]: [...(c[type] ?? []), field] }));
  const removeField = (type: string, field: FieldKey) => {
    setTypeCols((c) => ({ ...c, [type]: (c[type] ?? []).filter((k) => k !== field) }));
    setGrid((g) => ({ ...g, [field]: { ...g[field], [type]: "" } }));
  };
  const set = (field: FieldKey, type: string, value: string) => setGrid((g) => ({ ...g, [field]: { ...g[field], [type]: value } }));
  const num = (field: FieldKey, type: string) => (grid[field][type] ? Number(grid[field][type]) : null);
  const text = (field: FieldKey, type: string) => grid[field][type]?.trim() || null;

  const onSubmit = async () => {
    for (const c of categories) {
      if ((["purchase_price", "sale_price"] as const).some((f) => num(f, c.key) !== null && num(f, c.key)! < 1)) return toast.error(`Price for ${c.name} must be at least 1.`);
      if (num("cc", c.key) !== null && (num("cc", c.key)! < 1 || num("cc", c.key)! > 10000)) return toast.error(`CC for ${c.name} must be between 1 and 10000.`);
      if (num("year", c.key) !== null && (num("year", c.key)! < 2017 || num("year", c.key)! > 2030)) return toast.error(`Year for ${c.name} must be between 2017 and 2030.`);
      if (num("mileage", c.key) !== null && num("mileage", c.key)! < 0) return toast.error(`Mileage for ${c.name} cannot be negative.`);
    }
    setSaving(true);
    try {
      const res = await api<Settings>("/settings", {
        method: "PUT",
        body: {
          purchase: { vehicle_type: primary.purchase || null, prices: Object.fromEntries(categories.map((c) => [c.key, num("purchase_price", c.key)])) },
          sale: { vehicle_type: primary.sale || null, prices: Object.fromEntries(categories.map((c) => [c.key, num("sale_price", c.key)])) },
          type_defaults: Object.fromEntries(categories.map((c) => [c.key, Object.fromEntries(VEHICLE_KEYS.map((f) => [f, NUMERIC_KEYS.includes(f) ? num(f, c.key) : text(f, c.key)]))])),
        },
      });
      toast.success(res.message);
      const saved = gridFrom(res.data);
      setSavedGrid(JSON.stringify({ ...saved }));
      setSavedPrimary(JSON.stringify({ purchase: res.data.purchase.vehicle_type ?? "", sale: res.data.sale.vehicle_type ?? "" }));
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : "Failed to save");
    } finally {
      setSaving(false);
    }
  };

  return (
    <form onSubmit={(e) => { e.preventDefault(); onSubmit(); }}>
      <PageHeader title="Settings" actions={<Button type="submit" disabled={saving || !tableDirty}><Save className="size-4" /> {saving ? "Saving..." : "Save settings"}</Button>} />
      <p className="mb-4 text-sm text-slate-600">Presets for your shop. Choose the primary vehicle type for each form, then set each type&apos;s defaults.</p>
      <Card title="Primary vehicle type" className="mb-4">
        <div className="grid gap-3 md:grid-cols-2">
          <Field label="Purchase form (Buy vehicle)">
            <NativeSelect className={inputCls} value={primary.purchase} onChange={(e) => setPrimary((p) => ({ ...p, purchase: e.target.value }))}>
              <option value="">None (choose each time)</option>
              {categories.map((c) => <option key={c.key} value={c.key}>{c.name}</option>)}
            </NativeSelect>
          </Field>
          <Field label="Sales form (New sale)">
            <NativeSelect className={inputCls} value={primary.sale} onChange={(e) => setPrimary((p) => ({ ...p, sale: e.target.value }))}>
              <option value="">None (show all types)</option>
              {categories.map((c) => <option key={c.key} value={c.key}>{c.name}</option>)}
            </NativeSelect>
          </Field>
        </div>
        <p className="mt-2 text-xs text-slate-500">The Buy vehicle form opens with the primary type selected, and its presets below are filled in. The New sale form opens showing only that type&apos;s in-stock vehicles, and you can switch to all types.</p>
      </Card>
      <Card title="Defaults by vehicle type">
        <p className="mb-3 text-sm text-slate-600">Add the vehicle types you want presets for, then add the fields to preset inside each type&apos;s card. Choosing that type on a form then fills them in, overriding the type&apos;s own default.</p>
        <div className="mb-3 flex flex-wrap gap-2">
          <div className="w-full sm:w-56">
            <NativeSelect className={inputCls} value="" disabled={!availableTypes.length} onChange={(e) => e.target.value && addType(e.target.value)}>
              <option value="">{availableTypes.length ? "+ Add type..." : "All types added"}</option>
              {availableTypes.map((c) => <option key={c.key} value={c.key}>{c.name}</option>)}
            </NativeSelect>
          </div>
        </div>
        {listed.length > 0 ? (
          <div className="space-y-3">
            {listed.map((c) => (
              <div key={c.key} className={`rounded-xl bg-white p-4 shadow-sm ring-2 ${typeColor(c.id).ring}`}>
                <div className="mb-3 flex items-center justify-between border-b border-emerald-50 pb-2">
                  <h3 className={`text-xl font-extrabold tracking-tight ${typeColor(c.id).title}`}>{c.name}</h3>
                  <button type="button" aria-label={`Remove ${c.name}`} className="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100 hover:text-slate-700" onClick={() => removeRow(c.key)}><Trash2 className="size-4" /></button>
                </div>
                {/* one column on phones, two per row on desktop */}
                <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                  {(typeCols[c.key] ?? []).map((k) => {
                    const f = FIELDS.find((x) => x.key === k)!;
                    return (
                      <div key={k} className="space-y-1.5">
                        <div className="flex items-center justify-between">
                          <Label className="text-slate-700">{f.label}</Label>
                          <button type="button" aria-label={`Remove ${f.label}`} className="flex size-5 items-center justify-center rounded-full text-slate-400 hover:bg-slate-100 hover:text-slate-700" onClick={() => removeField(c.key, k)}>×</button>
                        </div>
                        <Input type={f.type} step={k.endsWith("price") ? "0.01" : undefined} min={"min" in f ? f.min : f.type === "number" ? "1" : undefined} max={"max" in f ? f.max : undefined} className="w-full" value={grid[k][c.key] ?? ""} onChange={(e) => set(k, c.key, e.target.value)} />
                      </div>
                    );
                  })}
                  {FIELDS.some((f) => !(typeCols[c.key] ?? []).includes(f.key)) && (
                    <div className="flex items-end">
                      <NativeSelect className={`${inputCls} ${ADD_FIELD_STYLE}`} value="" onChange={(e) => e.target.value && addField(c.key, e.target.value as FieldKey)}>
                        <option value="">+ Add field...</option>
                        {FIELDS.filter((f) => !(typeCols[c.key] ?? []).includes(f.key)).map((f) => <option key={f.key} value={f.key}>{f.label}</option>)}
                      </NativeSelect>
                    </div>
                  )}
                </div>
              </div>
            ))}
          </div>
        ) : (
          <p className="rounded-lg bg-emerald-50 px-3 py-4 text-sm text-emerald-800">No vehicle types added yet.</p>
        )}
      </Card>
    </form>
  );
}
