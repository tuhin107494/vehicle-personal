"use client";

import { savedMsg } from "@/lib/format";
import { useEffect, useState } from "react";
import { Plus, Pencil } from "lucide-react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { toast } from "sonner";
import { api, applyServerErrors } from "@/lib/api";
import { usePagedList } from "@/lib/hooks";
import type { Shop } from "@/lib/types";
import { Badge, Button, Field, inputCls, Modal, PageHeader, Pagination } from "@/components/app-ui";
import { Col, DataTable } from "@/components/DataTable";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";

const schema = z.object({
  name: z.string().min(1, "Shop name is required."),
  phone: z.string(),
  address: z.string(),
  is_active: z.boolean(),
  owner_name: z.string(),
  owner_email: z.string(),
  owner_password: z.string(),
});
type Values = z.infer<typeof schema>;
const blank: Values = { name: "", phone: "", address: "", is_active: true, owner_name: "", owner_email: "", owner_password: "" };

function ShopForm({ open, shop, onClose, onSaved }: { open: boolean; shop: Shop | null; onClose: () => void; onSaved: () => void }) {
  const { register, handleSubmit, reset, setError, formState: { errors, isSubmitting } } = useForm<Values>({ resolver: zodResolver(schema), defaultValues: blank });

  useEffect(() => {
    if (!open) return;
    reset(shop ? { ...blank, name: shop.name, phone: shop.phone ?? "", address: shop.address ?? "", is_active: shop.is_active, owner_name: shop.owner?.name ?? "", owner_email: shop.owner?.email ?? "" } : blank);
  }, [open, shop, reset]);

  const onSubmit = async (v: Values) => {
    if (!shop) {
      let bad = false;
      if (!v.owner_name) { setError("owner_name", { message: "Owner name is required." }); bad = true; }
      if (!v.owner_email) { setError("owner_email", { message: "Owner email is required." }); bad = true; }
      if (v.owner_password.length < 8) { setError("owner_password", { message: "Password must be at least 8 characters." }); bad = true; }
      if (bad) return;
    }
    try {
      await api(shop ? `/shops/${shop.id}` : "/shops", {
        method: shop ? "PUT" : "POST",
        body: { ...v, phone: v.phone || null, address: v.address || null, owner_password: v.owner_password || undefined },
      });
      toast.success(savedMsg("Shop", !!shop));
      onSaved();
      onClose();
    } catch (e) {
      toast.error(applyServerErrors(e, setError));
    }
  };

  return (
    <Modal open={open} onClose={onClose} title={shop ? "Edit shop" : "New shop"}>
      <form onSubmit={handleSubmit(onSubmit)} className="space-y-3">
        <Field required label="Shop name" error={errors.name?.message}><Input className={inputCls} {...register("name")} /></Field>
        <Field label="Phone" error={errors.phone?.message}><Input className={inputCls} {...register("phone")} /></Field>
        <Field label="Address" error={errors.address?.message}><Textarea rows={2} className={inputCls} {...register("address")} /></Field>
        <p className="pt-1 text-sm font-semibold text-emerald-700">Shop owner login</p>
        <Field required label="Owner name" error={errors.owner_name?.message}><Input className={inputCls} {...register("owner_name")} /></Field>
        <Field required label="Owner email" error={errors.owner_email?.message}><Input type="email" className={inputCls} {...register("owner_email")} /></Field>
        <Field required={!shop} label={shop ? "New password (leave blank to keep)" : "Password"} error={errors.owner_password?.message}><Input type="password" className={inputCls} {...register("owner_password")} /></Field>
        {shop && !shop.approved_at && <p className="rounded-lg bg-amber-50 p-2 text-sm text-amber-800">This shop signed up and its owner has not confirmed their email yet. It opens on its own when they do; to open it now anyway, tick &quot;Shop is active&quot; and save.</p>}
        <label className="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" className="accent-emerald-600" {...register("is_active")} /> Shop is active (owner and staff can sign in)</label>
        <div className="flex justify-end gap-2">
          <Button variant="outline" onClick={onClose}>Cancel</Button>
          <Button type="submit" disabled={isSubmitting}>{isSubmitting ? "Saving..." : "Save"}</Button>
        </div>
      </form>
    </Modal>
  );
}

export default function ShopsPage() {
  const list = usePagedList<Shop>("/shops");
  const [editing, setEditing] = useState<Shop | null>(null);
  const [open, setOpen] = useState(false);

  const columns: Col<Shop>[] = [
    { header: "Shop", cell: ({ row }) => <span className="font-medium">{row.original.name}</span> },
    { header: "Owner", cell: ({ row }) => row.original.owner?.name ?? "-" },
    { header: "Owner email", cell: ({ row }) => row.original.owner?.email ?? "-" },
    { header: "Phone", cell: ({ row }) => row.original.phone ?? "-" },
    { header: "Users", cell: ({ row }) => row.original.users_count ?? 0 },
    { header: "Status", cell: ({ row }) => !row.original.approved_at
      ? <Badge tone="yellow">Pending</Badge>
      : <Badge tone={row.original.is_active ? "green" : "gray"}>{row.original.is_active ? "Active" : "Disabled"}</Badge> },
    { header: "Action", id: "actions", cell: ({ row }) => <Button variant="outline" onClick={() => { setEditing(row.original); setOpen(true); }}><Pencil className="size-4 text-sky-600" /> Edit</Button> },
  ];

  return (
    <>
      <PageHeader title="Shops" actions={<Button onClick={() => { setEditing(null); setOpen(true); }}><Plus className="size-4" /> New shop</Button>} />
      <Input className={`${inputCls} mb-3 max-w-xs`} placeholder="Search shop" value={list.search} onChange={(e) => list.setSearch(e.target.value)} />
      <DataTable serverPaged columns={columns} data={list.rows} loading={list.loading} />
      <Pagination meta={list.meta} onPage={list.setPage} />
      <ShopForm open={open} shop={editing} onClose={() => setOpen(false)} onSaved={() => (editing ? list.reload() : list.showFirst())} />
    </>
  );
}
