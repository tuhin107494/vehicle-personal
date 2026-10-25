"use client";

import { useState } from "react";
import { useParams, useRouter } from "next/navigation";
import Link from "next/link";
import { Eye, HandCoins, Pencil, Trash2 } from "lucide-react";
import { toast } from "sonner";
import { api, ApiError, applyServerErrors } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { fmtDate, money, todayStr } from "@/lib/format";
import { useFetch } from "@/lib/hooks";
import type { Purchase, Supplier } from "@/lib/types";
import { Button, Card, ErrorText, Field, inputCls, KV, Loading, Modal, PageHeader, StatusBadge } from "@/components/app-ui";
import { Input } from "@/components/ui/input";
import { NativeSelect } from "@/components/ui/native-select";
import { Avatar } from "@/components/Avatar";
import { Col, DataTable } from "@/components/DataTable";
import { SupplierForm } from "@/components/forms/SupplierForm";

type History = { supplier: Supplier; purchases: Purchase[] };

export default function SupplierDetailPage() {
  const { id } = useParams<{ id: string }>();
  const router = useRouter();
  const { can } = useAuth();
  const { data, loading, error, reload } = useFetch<History>(`/suppliers/${id}/history`);
  const [editing, setEditing] = useState(false);
  const [paying, setPaying] = useState<Purchase | null>(null);
  const [amount, setAmount] = useState("");
  const [method, setMethod] = useState("cash");
  const [saving, setSaving] = useState(false);

  if (loading && !data) return <Loading />;
  if (error || !data) return <ErrorText message={error ?? "Not found"} />;
  const { supplier, purchases } = data;

  const owed = purchases.reduce((n, p) => n + Math.max(0, p.price - p.paid_amount - p.due_adjustment), 0);

  const dueOf = (p: Purchase) => Math.max(0, p.price - p.paid_amount - p.due_adjustment);

  const pay = async () => {
    if (!paying) return;
    setSaving(true);
    try {
      await api(`/purchases/${paying.id}/payments`, { method: "POST", body: { amount: Number(amount), method, paid_at: todayStr() } });
      toast.success("Payment recorded successfully");
      setPaying(null);
      reload();
    } catch (e) {
      toast.error(applyServerErrors(e, () => {}));
    } finally {
      setSaving(false);
    }
  };

  const remove = async () => {
    if (!confirm(`Delete ${supplier.name}?`)) return;
    try {
      await api(`/suppliers/${supplier.id}`, { method: "DELETE" });
      toast.success("Supplier deleted successfully");
      router.replace("/suppliers");
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : "Failed");
    }
  };

  const buyCols: Col<Purchase>[] = [
    { header: "Date", cell: ({ row }) => fmtDate(row.original.purchase_date) },
    { header: "Vehicle", cell: ({ row }) => <span className="font-medium">{row.original.vehicle?.chassis_no}</span> },
    { header: "Price", cell: ({ row }) => money(row.original.price) },
    { header: "Paid", cell: ({ row }) => money(row.original.paid_amount) },
    { header: "Due", cell: ({ row }) => { const d = dueOf(row.original); return d > 0 ? <b className="text-red-700">{money(d)}</b> : "-"; } },
    { header: "Status", cell: ({ row }) => <StatusBadge status={row.original.payment_status} /> },
    {
      header: "Action",
      id: "actions",
      cell: ({ row }) => {
        const p = row.original;
        const d = dueOf(p);
        return (
          <div className="flex flex-wrap items-center justify-end gap-1.5 md:flex-nowrap">
            {d > 0 && can("purchases.edit") && <Button variant="outline" size="sm" onClick={() => { setPaying(p); setAmount(String(d)); setMethod("cash"); }}><HandCoins className="size-4 text-sky-600" /> Pay due</Button>}
            <Link className="inline-flex items-center gap-1.5 rounded-lg border border-violet-200 px-2.5 py-1.5 text-xs font-medium text-violet-700 hover:bg-violet-50" href={`/vehicles/${p.vehicle_id}`}><Eye className="size-4" /> View</Link>
          </div>
        );
      },
    },
  ];

  return (
    <>
      <PageHeader
        title={supplier.name}
        actions={<>
          {owed > 0 && can("purchases.edit") && <Link className="inline-flex items-center gap-1.5 rounded-lg border border-sky-200 bg-white px-3 py-2 text-sm font-medium text-sky-700 hover:bg-sky-50" href={`/supplier-payment?supplier_id=${supplier.id}`}><HandCoins className="size-4" /> Pay due · {money(owed)}</Link>}
          {can("parties.edit") && <Button variant="outline" onClick={() => setEditing(true)}><Pencil className="size-4 text-emerald-600" /> Edit</Button>}
          {can("parties.delete") && <Button variant="danger" onClick={remove}><Trash2 className="size-4 " /> Delete</Button>}
        </>}
      />
      <Card className="mb-4">
        <div className="mb-3"><Avatar name={supplier.name} url={supplier.photo_url} size="lg" /></div>
        <KV items={[["Phone", supplier.phone], ["Alt. phone", supplier.alt_phone], ["NID", supplier.nid], ["Address", supplier.address], ["Bank", supplier.bank_name], ["Branch", supplier.bank_branch], ["Account name", supplier.bank_account_name], ["Account no", supplier.bank_account_no], ["Other bank details", supplier.bank_info], ["Notes", supplier.notes]]} />
      </Card>
      <h2 className="mb-2 font-semibold">Bought from this supplier ({purchases.length})</h2>
      <DataTable columns={buyCols} data={purchases} empty="Nothing bought from this supplier." />
      <Modal open={!!paying} onClose={() => setPaying(null)} title="Pay due">
        {paying && (
          <div className="space-y-3">
            <p className="text-sm text-slate-600">{paying.vehicle?.chassis_no} · Due {money(dueOf(paying))}</p>
            <Field label="Amount"><Input type="number" min="1" max={dueOf(paying)} className={inputCls} value={amount} onChange={(e) => setAmount(e.target.value)} /></Field>
            <Field label="Method"><NativeSelect className={inputCls} value={method} onChange={(e) => setMethod(e.target.value)}><option value="cash">Cash</option><option value="bank">Bank</option><option value="mobile">Mobile</option></NativeSelect></Field>
            <div className="flex justify-end gap-2">
              <Button variant="outline" onClick={() => setPaying(null)}>Cancel</Button>
              <Button disabled={saving || !(Number(amount) > 0) || Number(amount) > dueOf(paying)} onClick={pay}>{saving ? "Saving..." : "Pay"}</Button>
            </div>
          </div>
        )}
      </Modal>
      <SupplierForm open={editing} supplier={supplier} onClose={() => setEditing(false)} onSaved={reload} />
    </>
  );
}
