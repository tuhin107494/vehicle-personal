"use client";

import { useState } from "react";
import { Save, Trash2 } from "lucide-react";
import { toast } from "sonner";
import { api, ApiError } from "@/lib/api";
import { useCategories } from "@/lib/categories";
import { useT } from "@/lib/i18n";
import { useAuth } from "@/lib/auth";
import { useHiddenMenu } from "@/lib/menu";
import { DASHBOARD_SECTIONS, useHiddenDashboard } from "@/lib/dashboard";
import { allowedNav } from "@/components/AppShell";
import { ShopProfileCard } from "@/components/ShopProfileCard";
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
  const { lang, setLang, showSwitch, setShowSwitch, t } = useT();
  const { user, can } = useAuth();
  const { hidden, setHidden } = useHiddenMenu();
  const dash = useHiddenDashboard();
  const [recallDays, setRecallDays] = useState<number | null>(null); // saved on its own, apart from the settings table
  const [memoNo, setMemoNo] = useState<boolean | null>(null); // same, for the memo number field
  const [autoDate, setAutoDate] = useState<boolean | null>(null); // same, for the date setting
  const [keepSelection, setKeepSelection] = useState<boolean | null>(null); // same, for the payment forms
  const [fromCustomer, setFromCustomer] = useState<boolean | null>(null); // what was just saved, until the settings are fetched again
  const [booking, setBooking] = useState<Partial<{ days: number; on_cancel: string; on_expire: string }>>({}); // what was just saved, until the settings are fetched again
  const menuItems = allowedNav(user?.role, can);
  const { categories } = useCategories();
  const [saving, setSaving] = useState(false);
  // The vehicle type each form starts on: the Buy vehicle form preselects it, the New sale form filters its vehicles by it.
  const [primary, setPrimary] = useState(""); // one vehicle type for both the Buy and the Sale form
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
    const start = settings.data.purchase.vehicle_type ?? settings.data.sale.vehicle_type ?? "";
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
          purchase: { vehicle_type: primary || null, prices: Object.fromEntries(categories.map((c) => [c.key, num("purchase_price", c.key)])) },
          sale: { vehicle_type: primary || null, prices: Object.fromEntries(categories.map((c) => [c.key, num("sale_price", c.key)])) },
          type_defaults: Object.fromEntries(categories.map((c) => [c.key, Object.fromEntries(VEHICLE_KEYS.map((f) => [f, NUMERIC_KEYS.includes(f) ? num(f, c.key) : text(f, c.key)]))])),
        },
      });
      toast.success(res.message);
      const saved = gridFrom(res.data);
      setSavedGrid(JSON.stringify({ ...saved }));
      setSavedPrimary(JSON.stringify(res.data.purchase.vehicle_type ?? ""));
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : "Failed to save");
    } finally {
      setSaving(false);
    }
  };

  return (
    <form onSubmit={(e) => { e.preventDefault(); onSubmit(); }}>
      <PageHeader title="Settings" actions={<Button type="submit" disabled={saving || !tableDirty}><Save className="size-4" /> {saving ? "Saving..." : "Save settings"}</Button>} />
      <p className="mb-4 text-sm text-slate-600">Starting values for the Buy and Sale forms.</p>
      <Card title="Primary vehicle type" className="mb-4">
        <div className="max-w-sm">
          <Field label="Vehicle type">
            <NativeSelect className={inputCls} value={primary} onChange={(e) => setPrimary(e.target.value)}>
              <option value="">None (choose each time)</option>
              {categories.map((c) => <option key={c.key} value={c.key}>{c.name}</option>)}
            </NativeSelect>
          </Field>
        </div>
        <p className="mt-2 text-xs text-slate-500">The Buy vehicle and New sale forms both open with this type selected.</p>
      </Card>
      <Card title="Defaults by vehicle type" className="mb-4">
        <p className="mb-3 text-sm text-slate-600">Add a vehicle type, then the fields to fill in when it is chosen.</p>
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
      <ShopProfileCard />
      <Card title="Language" className="mb-4">
        <div className="mb-3 flex items-center gap-3 text-sm text-slate-700">
          <span>App language</span>
          <div className="flex overflow-hidden rounded-lg ring-1 ring-emerald-300 text-sm font-semibold" role="group" aria-label="Language">
            <button type="button" onClick={() => setLang("en")} className={`px-3 py-1.5 ${lang === "en" ? "bg-emerald-600 text-white" : "bg-white text-emerald-700 hover:bg-emerald-50"}`}>English</button>
            <button type="button" onClick={() => setLang("bn")} className={`px-3 py-1.5 ${lang === "bn" ? "bg-emerald-600 text-white" : "bg-white text-emerald-700 hover:bg-emerald-50"}`}>বাংলা</button>
          </div>
        </div>
        <label className="flex cursor-pointer items-center gap-2 text-sm text-slate-700">
          <input type="checkbox" className="size-4 accent-emerald-600" checked={showSwitch} onChange={(e) => setShowSwitch(e.target.checked)} />
          Show the language switch (EN / বাং) in the menu
        </label>
        <p className="mt-1 text-xs text-slate-500">Applies at once, on this device.</p>
      </Card>
      <Card title="Call follow-up" className="mb-4">
        <p className="mb-3 text-xs text-slate-500">A "Call done" row on Due alert returns for another call after this many days.</p>
        <Field label="Call again after">
          <NativeSelect className={inputCls} value={recallDays ?? settings.data.collections?.recall_days ?? 7} onChange={async (e) => {
            const days = Number(e.target.value);
            try {
              await api("/settings", { method: "PUT", body: { collections: { recall_days: days } } });
              setRecallDays(days);
              toast.success("Saved");
            } catch (err) {
              toast.error(err instanceof ApiError ? err.message : "Failed to save");
            }
          }}>
            {[3, 7, 10, 15, 30, 60, 90, 180, 360].map((d) => <option key={d} value={d}>{d} days</option>)}
          </NativeSelect>
        </Field>
      </Card>
      <Card title="Buying from customers" className="mb-4">
        <p className="mb-3 text-xs text-slate-500">Show a "From customer" tab on Buy vehicle. Off if you buy only from suppliers.</p>
        <label className="flex cursor-pointer items-center gap-2 text-sm text-slate-700">
          <input type="checkbox" className="size-4 accent-emerald-600" checked={fromCustomer ?? settings.data.purchase?.from_customer ?? true} onChange={async (e) => {
            const value = e.target.checked;
            try {
              await api("/settings", { method: "PUT", body: { purchase: { from_customer: value } } });
              setFromCustomer(value);
              toast.success("Saved");
            } catch (err) {
              toast.error(err instanceof ApiError ? err.message : "Failed to save");
            }
          }} />
          Buy vehicles from customers (second hand)
        </label>
      </Card>
      <Card title="Forms" className="mb-4">
        <p className="mb-3 text-xs text-slate-500">After a payment, keep the chosen customer, supplier and vehicle selected on Due collection and Supplier payment.</p>
        <label className="flex cursor-pointer items-center gap-2 text-sm text-slate-700">
          <input type="checkbox" className="size-4 accent-emerald-600" checked={keepSelection ?? settings.data.collections?.keep_selection ?? false} onChange={async (e) => {
            const value = e.target.checked;
            try {
              await api("/settings", { method: "PUT", body: { collections: { keep_selection: value } } });
              setKeepSelection(value);
              settings.reload();
              toast.success("Saved");
            } catch (err) {
              toast.error(err instanceof ApiError ? err.message : "Failed to save");
            }
          }} />
          Keep the selection after a payment
        </label>
        <p className="mb-3 mt-4 text-xs text-slate-500">Start the date on Sale, Purchase, Due collection and Supplier payment with today's date. When off, the date starts empty.</p>
        <label className="flex cursor-pointer items-center gap-2 text-sm text-slate-700">
          <input type="checkbox" className="size-4 accent-emerald-600" checked={autoDate ?? settings.data.forms?.auto_date ?? false} onChange={async (e) => {
            const value = e.target.checked;
            try {
              await api("/settings", { method: "PUT", body: { forms: { auto_date: value } } });
              setAutoDate(value);
              settings.reload();
              toast.success("Saved");
            } catch (err) {
              toast.error(err instanceof ApiError ? err.message : "Failed to save");
            }
          }} />
          Fill in today's date automatically
        </label>
        <p className="mb-3 mt-4 text-xs text-slate-500">Show the optional "Memo no." field on the Sale and Due collection forms.</p>
        <label className="flex cursor-pointer items-center gap-2 text-sm text-slate-700">
          <input type="checkbox" className="size-4 accent-emerald-600" checked={memoNo ?? settings.data.forms?.memo_no ?? true} onChange={async (e) => {
            const value = e.target.checked;
            try {
              await api("/settings", { method: "PUT", body: { forms: { memo_no: value } } });
              setMemoNo(value);
              settings.reload();
              toast.success("Saved");
            } catch (err) {
              toast.error(err instanceof ApiError ? err.message : "Failed to save");
            }
          }} />
          Show the memo number field
        </label>
      </Card>
      <Card title="Bookings" className="mb-4">
        <p className="mb-3 text-xs text-slate-500">On cancel or expiry the vehicle returns to stock and the advance follows this rule. Staff can change the refund when cancelling.</p>
        <div className="grid gap-3 sm:grid-cols-3">
          {([
            ["days", "Hold a booking for", settings.data.booking?.days ?? 7, [1, 3, 7, 10, 15, 30, 60].map((d) => [String(d), `${d} days`])],
            ["on_cancel", "When cancelled, the advance is", settings.data.booking?.on_cancel ?? "refund", [["refund", "Refunded in full"], ["keep", "Kept by the shop"]]],
            ["on_expire", "When expired, the advance is", settings.data.booking?.on_expire ?? "refund", [["refund", "Refunded in full"], ["keep", "Kept by the shop"]]],
          ] as [keyof typeof booking, string, string | number, string[][]][]).map(([key, text, current, options]) => (
            <Field key={key} label={text}>
              <NativeSelect className={inputCls} value={String(booking[key] ?? current)} onChange={async (e) => {
                const value = key === "days" ? Number(e.target.value) : e.target.value;
                try {
                  await api("/settings", { method: "PUT", body: { booking: { [key]: value } } });
                  setBooking((b) => ({ ...b, [key]: value }));
                  toast.success("Saved");
                } catch (err) {
                  toast.error(err instanceof ApiError ? err.message : "Failed to save");
                }
              }}>
                {options.map(([v, t]) => <option key={v} value={v}>{t}</option>)}
              </NativeSelect>
            </Field>
          ))}
        </div>
      </Card>
      <Card title="Menu" className="mb-4">
        <p className="mb-3 text-xs text-slate-500">Choose which items show in the side menu.</p>
        <div className="grid gap-x-4 gap-y-2 sm:grid-cols-2 lg:grid-cols-3">
          {menuItems.map(({ href, text, icon: Icon, color }) => (
            <label key={href} className={`flex items-center gap-2 text-sm text-slate-700 ${href === "/settings" ? "opacity-60" : "cursor-pointer"}`}>
              <input type="checkbox" className="size-4 accent-emerald-600" disabled={href === "/settings"} checked={href === "/settings" || !hidden.includes(href)} onChange={(e) => setHidden(e.target.checked ? hidden.filter((h) => h !== href) : [...hidden, href])} />
              <Icon className={`size-4 ${color}`} /> {t(text)}
            </label>
          ))}
        </div>
        {hidden.length > 0 && <Button variant="outline" className="mt-3" onClick={() => setHidden([])}>Show all</Button>}
      </Card>
      <Card title="Dashboard" className="mb-4">
        <p className="mb-3 text-xs text-slate-500">Choose which sections show on the dashboard, on this device.</p>
        <div className="grid gap-x-4 gap-y-2 sm:grid-cols-2 lg:grid-cols-3">
          {DASHBOARD_SECTIONS.map(([key, text]) => (
            <label key={key} className="flex cursor-pointer items-center gap-2 text-sm text-slate-700">
              <input type="checkbox" className="size-4 accent-emerald-600" checked={!dash.hidden.includes(key)} onChange={(e) => dash.setHidden(e.target.checked ? dash.hidden.filter((h) => h !== key) : [...dash.hidden, key])} />
              {t(text)}
            </label>
          ))}
        </div>
        {dash.hidden.length > 0 && <Button variant="outline" className="mt-3" onClick={() => dash.setHidden([])}>Show all</Button>}
      </Card>
    </form>
  );
}
