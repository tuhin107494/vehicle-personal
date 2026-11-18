"use client";

import Link from "next/link";
import { useState } from "react";
import { ArrowLeft, Pencil, Plus } from "lucide-react";
import { toast } from "sonner";
import { api, ApiError } from "@/lib/api";
import { usePagedList } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { fmtDate } from "@/lib/format";
import { taka, toPaisa } from "@/lib/billing";
import type { CouponT } from "@/lib/types";
import { Badge, Button, ErrorText, Field, inputCls, Modal, PageHeader, Pagination } from "@/components/app-ui";
import { Input } from "@/components/ui/input";
import { NativeSelect } from "@/components/ui/native-select";
import { DataTable, type Col } from "@/components/DataTable";

type Values = { code: string; type: "percent" | "fixed"; value: string; max_uses: string; expires_at: string; is_active: boolean };
const fromCoupon = (c: CouponT | null): Values => ({
  code: c?.code ?? "", type: c?.type ?? "percent", value: c ? String(c.type === "fixed" ? c.value / 100 : c.value) : "", max_uses: c?.max_uses ? String(c.max_uses) : "",
  expires_at: c?.expires_at ? c.expires_at.slice(0, 10) : "", is_active: c?.is_active ?? true,
});

function CouponForm({ open, coupon, onClose, onSaved }: { open: boolean; coupon: CouponT | null; onClose: () => void; onSaved: () => void }) {
  const { t } = useT();
  const [v, setV] = useState<Values>(() => fromCoupon(coupon));
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [busy, setBusy] = useState(false);
  const [openedFor, setOpenedFor] = useState<{ open: boolean; coupon: CouponT | null }>({ open, coupon });
  if (openedFor.open !== open || openedFor.coupon !== coupon) {
    setOpenedFor({ open, coupon });
    setV(fromCoupon(coupon));
    setErrors({});
  }
  const set = (patch: Partial<Values>) => setV((s) => ({ ...s, ...patch }));
  const err = (field: string) => errors[field]?.[0];

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    const body = { code: v.code.trim(), type: v.type, value: v.type === "fixed" ? toPaisa(v.value) : Number(v.value || 0), max_uses: v.max_uses ? Number(v.max_uses) : null, expires_at: v.expires_at || null, is_active: v.is_active };
    setBusy(true);
    try {
      const res = await api(coupon ? `/admin/coupons/${coupon.id}` : "/admin/coupons", { method: coupon ? "PUT" : "POST", body });
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
    <Modal open={open} onClose={onClose} title={coupon ? "Edit coupon" : "New coupon"}>
      <form onSubmit={submit} className="space-y-3">
        <Field required label="Code" error={err("code")}><Input className={`${inputCls} uppercase`} value={v.code} onChange={(e) => set({ code: e.target.value })} autoComplete="off" /></Field>
        <div className="grid gap-3 sm:grid-cols-2">
          <Field required label="Type" error={err("type")}>
            <NativeSelect className={inputCls} value={v.type} onChange={(e) => set({ type: e.target.value as Values["type"] })}>
              <option value="percent">{t("Percent off")}</option>
              <option value="fixed">{t("Fixed amount off (taka)")}</option>
            </NativeSelect>
          </Field>
          <Field required label={v.type === "percent" ? "Percent" : "Amount (taka)"} error={err("value")}><Input type="number" min={1} max={v.type === "percent" ? 100 : undefined} step={v.type === "percent" ? 1 : "0.01"} className={inputCls} value={v.value} onChange={(e) => set({ value: e.target.value })} /></Field>
          <Field label="Maximum uses" error={err("max_uses")}><Input type="number" min={1} className={inputCls} value={v.max_uses} placeholder={t("Unlimited")} onChange={(e) => set({ max_uses: e.target.value })} /></Field>
          <Field label="Expires on" error={err("expires_at")}><Input type="date" className={inputCls} value={v.expires_at} onChange={(e) => set({ expires_at: e.target.value })} /></Field>
        </div>
        <label className="flex cursor-pointer items-center gap-2 text-sm text-slate-700"><input type="checkbox" className="size-4 accent-emerald-600" checked={v.is_active} onChange={(e) => set({ is_active: e.target.checked })} /> {t("Active")}</label>
        <div className="flex justify-end gap-2">
          <Button variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={busy}>Save</Button>
        </div>
      </form>
    </Modal>
  );
}

/** Discount codes shops can type when choosing a plan. Super admin only; a used coupon is switched off, never deleted. */
export default function CouponsPage() {
  const { t } = useT();
  const list = usePagedList<CouponT>("/admin/coupons");
  const [editing, setEditing] = useState<{ open: boolean; coupon: CouponT | null }>({ open: false, coupon: null });

  const columns: Col<CouponT>[] = [
    { header: "Code", cell: ({ row }) => <code className="font-semibold">{row.original.code}</code> },
    { header: "Discount", cell: ({ row }) => (row.original.type === "percent" ? `${row.original.value}%` : `${taka(row.original.value)} ${t("taka")}`) },
    { header: "Used", cell: ({ row }) => `${row.original.used_count} / ${row.original.max_uses ?? "∞"}` },
    { header: "Expires", cell: ({ row }) => fmtDate(row.original.expires_at) },
    { header: "Status", cell: ({ row }) => <Badge tone={row.original.redeemable ? "green" : "gray"}>{row.original.redeemable ? "Redeemable" : row.original.is_active ? "Used up or expired" : "Inactive"}</Badge> },
    { header: "Action", id: "actions", cell: ({ row }) => <Button size="sm" variant="outline" onClick={() => setEditing({ open: true, coupon: row.original })}><Pencil className="size-3.5" /> Edit</Button> },
  ];

  return (
    <>
      <PageHeader title="Coupons" actions={<><Link href="/platform-plans"><Button variant="outline"><ArrowLeft className="size-4" /> Plans</Button></Link><Button onClick={() => setEditing({ open: true, coupon: null })}><Plus className="size-4" /> New coupon</Button></>} />
      <div className="mb-4 max-w-xs"><Input className={inputCls} placeholder={t("Search")} aria-label={t("Search")} value={list.search} onChange={(e) => list.setSearch(e.target.value)} /></div>
      {list.error && <ErrorText message={list.error} />}
      <DataTable columns={columns} data={list.rows} loading={list.loading} empty="No coupons yet." serverPaged />
      <Pagination meta={list.meta} onPage={list.setPage} />
      <CouponForm open={editing.open} coupon={editing.coupon} onClose={() => setEditing((s) => ({ ...s, open: false }))} onSaved={list.reload} />
    </>
  );
}
