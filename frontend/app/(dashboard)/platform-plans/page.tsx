"use client";

import Link from "next/link";
import { useState } from "react";
import { Pencil, Plus, Ticket } from "lucide-react";
import { toast } from "sonner";
import { api, ApiError } from "@/lib/api";
import { useFetch } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { featureLabel, INTERVALS, taka, toPaisa } from "@/lib/billing";
import { PlanPrice } from "@/components/PlanPrice";
import type { BillingInterval, FeatureT, PlanT } from "@/lib/types";
import { Badge, Button, ErrorText, Field, inputCls, Modal, PageHeader } from "@/components/app-ui";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { NativeSelect } from "@/components/ui/native-select";
import { DataTable, type Col } from "@/components/DataTable";

type Values = { name: string; slug: string; price: string; original_price: string; billing_interval: BillingInterval; trial_days: string; is_active: boolean; sort_order: string; description: string; features: Record<string, string> };

const fromPlan = (p: PlanT | null, features: FeatureT[]): Values => ({
  name: p?.name ?? "", slug: p?.slug ?? "", price: p ? String(p.price_paisa / 100) : "", original_price: p?.original_price_paisa ? String(p.original_price_paisa / 100) : "", billing_interval: p?.billing_interval ?? "monthly", trial_days: String(p?.trial_days ?? 0),
  is_active: p?.is_active ?? true, sort_order: String(p?.sort_order ?? 0), description: p?.description ?? "",
  // a switch starts as "1"/"0"; a limit as its number, or blank for unlimited
  features: Object.fromEntries(features.map((f) => [f.key, p ? (p.features?.[f.key] ?? (f.type === "boolean" ? "0" : "")) : f.type === "boolean" ? "0" : ""])),
});

/** What the shop sees for the two prices typed in: the discount in taka and percent, and what it pays. Previews only; the server checks it. */
function DiscountSummary({ regular, price }: { regular: string; price: string }) {
  const { t } = useT();
  const reg = toPaisa(regular) ?? 0;
  const pay = toPaisa(price) ?? 0;
  if (reg <= 0 || pay < 0) return null;
  if (reg < pay) return <p role="alert" className="text-sm text-red-600 sm:col-span-2">{t("The regular price cannot be lower than the price after discount.")}</p>;
  const off = reg - pay;
  return (
    <p className="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-slate-700 sm:col-span-2">
      {off === 0 ? t("No discount: both prices are the same.") : <>{t("Discount")}: <strong>৳{taka(off)}</strong> ({Math.round((off * 100) / reg)}%) · {t("Shop pays")}: <strong>৳{taka(pay)}</strong></>}
    </p>
  );
}

/** Add or edit a plan: its price and interval, and what it switches on or caps. */
function PlanForm({ open, plan, features, onClose, onSaved }: { open: boolean; plan: PlanT | null; features: FeatureT[]; onClose: () => void; onSaved: () => void }) {
  const { t } = useT();
  const [v, setV] = useState<Values>(() => fromPlan(plan, features));
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [busy, setBusy] = useState(false);
  // a new plan (or another one) starts the form again from what is saved
  const [openedFor, setOpenedFor] = useState<{ open: boolean; plan: PlanT | null }>({ open, plan });
  if (openedFor.open !== open || openedFor.plan !== plan) {
    setOpenedFor({ open, plan });
    setV(fromPlan(plan, features));
    setErrors({});
  }
  const set = (patch: Partial<Values>) => setV((s) => ({ ...s, ...patch }));
  const err = (field: string) => errors[field]?.[0];

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    const body = {
      name: v.name, slug: v.slug || null, price_paisa: toPaisa(v.price) ?? 0, original_price_paisa: toPaisa(v.original_price), billing_interval: v.billing_interval, trial_days: Number(v.trial_days || 0), is_active: v.is_active, sort_order: Number(v.sort_order || 0), description: v.description || null,
      features: Object.fromEntries(features.map((f) => [f.key, f.type === "boolean" ? v.features[f.key] === "1" : v.features[f.key] === "" ? null : Number(v.features[f.key])])),
    };
    setBusy(true);
    try {
      const res = await api(plan ? `/admin/plans/${plan.id}` : "/admin/plans", { method: plan ? "PUT" : "POST", body });
      toast.success(res.message);
      onSaved();
      onClose();
    } catch (e) {
      if (e instanceof ApiError && e.errors) setErrors(e.errors);
      toast.error(e instanceof ApiError ? e.message : "Failed to save");
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal open={open} onClose={onClose} title={plan ? "Edit plan" : "New plan"} wide>
      <form onSubmit={submit} className="space-y-3">
        <div className="grid gap-3 sm:grid-cols-2">
          <Field required label="Name" error={err("name")}><Input className={inputCls} value={v.name} onChange={(e) => set({ name: e.target.value })} /></Field>
          <Field label="Slug" error={err("slug")}><Input className={inputCls} value={v.slug} placeholder={t("from the name")} onChange={(e) => set({ slug: e.target.value })} /></Field>
          <Field label="Regular price before discount (taka)" error={err("original_price_paisa")}><Input type="number" min={0} step="0.01" className={inputCls} value={v.original_price} placeholder={t("Blank = no discount")} onChange={(e) => set({ original_price: e.target.value })} /></Field>
          <Field required label="Price after discount (taka)" error={err("price_paisa")}><Input type="number" min={0} step="0.01" className={inputCls} value={v.price} onChange={(e) => set({ price: e.target.value })} /></Field>
          <DiscountSummary regular={v.original_price} price={v.price} />
          <Field required label="Billing interval" error={err("billing_interval")}>
            <NativeSelect className={inputCls} value={v.billing_interval} onChange={(e) => set({ billing_interval: e.target.value as Values["billing_interval"] })}>
              {INTERVALS.map((i) => <option key={i.value} value={i.value}>{t(i.label)}</option>)}
            </NativeSelect>
          </Field>
          <Field label="Trial days" error={err("trial_days")}><Input type="number" min={0} max={365} className={inputCls} value={v.trial_days} onChange={(e) => set({ trial_days: e.target.value })} /></Field>
          <Field label="Sort order" error={err("sort_order")}><Input type="number" min={0} className={inputCls} value={v.sort_order} onChange={(e) => set({ sort_order: e.target.value })} /></Field>
        </div>
        <Field label="Description" error={err("description")}><Textarea className={inputCls} rows={2} value={v.description} onChange={(e) => set({ description: e.target.value })} /></Field>
        <label className="flex cursor-pointer items-center gap-2 text-sm text-slate-700"><input type="checkbox" className="size-4 accent-emerald-600" checked={v.is_active} onChange={(e) => set({ is_active: e.target.checked })} /> {t("Offered to shops")}</label>
        <fieldset className="rounded-lg border border-emerald-200 p-3">
          <legend className="px-1 text-sm font-medium text-emerald-700">{t("Features")}</legend>
          <div className="grid gap-3 sm:grid-cols-2">
            {features.map((f) => f.type === "boolean" ? (
              <label key={f.key} className="flex cursor-pointer items-center gap-2 text-sm text-slate-700"><input type="checkbox" className="size-4 accent-emerald-600" checked={v.features[f.key] === "1"} onChange={(e) => set({ features: { ...v.features, [f.key]: e.target.checked ? "1" : "0" } })} /> {t(featureLabel(f.key))}</label>
            ) : (
              <Field key={f.key} label={featureLabel(f.key)} error={err(`features.${f.key}`)}><Input type="number" min={0} className={inputCls} value={v.features[f.key]} placeholder={t("Unlimited")} onChange={(e) => set({ features: { ...v.features, [f.key]: e.target.value } })} /></Field>
            ))}
          </div>
          <p className="mt-2 text-xs text-slate-500">{t("A blank limit means unlimited; 0 switches it off.")}</p>
        </fieldset>
        <div className="flex justify-end gap-2">
          <Button variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={busy}>Save</Button>
        </div>
      </form>
    </Modal>
  );
}

/** Every plan the platform offers, super admin only. A plan is never deleted: switch it off instead. */
export default function PlatformPlansPage() {
  const { t } = useT();
  const list = useFetch<PlanT[]>("/admin/plans");
  const features = ((list.meta as { features?: FeatureT[] } | null)?.features) ?? [];
  const [editing, setEditing] = useState<{ open: boolean; plan: PlanT | null }>({ open: false, plan: null });

  const columns: Col<PlanT>[] = [
    { header: "Plan", cell: ({ row }) => <div><p className="font-medium">{row.original.name}</p><code className="text-xs text-slate-500">{row.original.slug}</code></div> },
    { header: "Price", cell: ({ row }) => <PlanPrice plan={row.original} /> },
    { header: "Trial", cell: ({ row }) => (row.original.trial_days ? `${row.original.trial_days} ${t("days")}` : "-") },
    { header: "Shops", cell: ({ row }) => row.original.subscriptions_count ?? 0 },
    { header: "Order", cell: ({ row }) => row.original.sort_order },
    { header: "Status", cell: ({ row }) => <Badge tone={row.original.is_active ? "green" : "gray"}>{row.original.is_active ? "Offered" : "Hidden"}</Badge> },
    { header: "Action", id: "actions", cell: ({ row }) => <Button size="sm" variant="outline" onClick={() => setEditing({ open: true, plan: row.original })}><Pencil className="size-3.5" /> Edit</Button> },
  ];

  return (
    <>
      <PageHeader title="Plans" actions={<><Link href="/platform-plans/coupons"><Button variant="outline"><Ticket className="size-4" /> Coupons</Button></Link><Button onClick={() => setEditing({ open: true, plan: null })}><Plus className="size-4" /> New plan</Button></>} />
      {list.error && <ErrorText message={list.error} />}
      <DataTable columns={columns} data={list.data ?? []} loading={list.loading} empty="No plans yet." />
      <PlanForm open={editing.open} plan={editing.plan} features={features} onClose={() => setEditing((s) => ({ ...s, open: false }))} onSaved={list.reload} />
    </>
  );
}
