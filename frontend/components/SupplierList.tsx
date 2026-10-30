"use client";

import { useState } from "react";
import Link from "next/link";
import { Eye, HandCoins, Pencil, Plus } from "lucide-react";
import { usePagedList } from "@/lib/hooks";
import { useAuth } from "@/lib/auth";
import { money } from "@/lib/format";
import type { Supplier } from "@/lib/types";
import { Button, inputCls, PageHeader, Pagination } from "@/components/app-ui";
import { Avatar } from "@/components/Avatar";
import { Col, DataTable } from "@/components/DataTable";
import { SupplierForm } from "@/components/forms/SupplierForm";
import { Input } from "@/components/ui/input";
import { PhoneChips } from "@/components/PhoneChips";

/** The suppliers list: who we buy from, what we still owe each, and the shortcut to pay it. */
export function SupplierList() {
  const { can } = useAuth();
  const list = usePagedList<Supplier>("/suppliers");
  const [editing, setEditing] = useState<Supplier | null>(null);
  const [open, setOpen] = useState(false);

  const columns: Col<Supplier>[] = [
    { header: "Name", cell: ({ row }) => <span className="flex items-center gap-2 font-medium"><Avatar name={row.original.name} url={row.original.photo_url} /> {row.original.name}</span> },
    { header: "Phone", cell: ({ row }) => <PhoneChips phone={row.original.phone} alt={row.original.alt_phone} /> },
    { header: "NID", cell: ({ row }) => row.original.nid ?? "-" },
    { header: "Address", cell: ({ row }) => row.original.address ?? "-" },
    {
      header: "Action",
      id: "actions",
      cell: ({ row }) => {
        const s = row.original;
        const owed = s.payable_due ?? 0;
        return (
          <div className="flex flex-wrap items-center justify-end gap-1.5 md:flex-nowrap md:whitespace-nowrap">
            {owed > 0 && can("purchases.edit") && (
              <Link className="inline-flex items-center gap-1.5 rounded-lg border border-sky-200 px-2.5 py-1.5 text-xs font-medium text-sky-700 hover:bg-sky-50" href={`/supplier-payment?supplier_id=${s.id}`}><HandCoins className="size-4" /> Pay · {money(owed)}</Link>
            )}
            <Link className="inline-flex items-center gap-1.5 rounded-lg border border-violet-200 px-2.5 py-1.5 text-xs font-medium text-violet-700 hover:bg-violet-50" href={`/suppliers/${s.id}`}><Eye className="size-4" /> View</Link>
            {can("parties.edit") && <Button variant="outline" size="sm" onClick={() => { setEditing(s); setOpen(true); }}><Pencil className="size-4 text-emerald-600" /> Edit</Button>}
          </div>
        );
      },
    },
  ];

  return (
    <>
      <PageHeader title="Suppliers" actions={can("parties.create") && <Button onClick={() => { setEditing(null); setOpen(true); }}><Plus className="size-4" /> New supplier</Button>} />
      <Input className={`${inputCls} mb-3 max-w-xs`} placeholder="Search name, phone, NID" value={list.search} onChange={(e) => list.setSearch(e.target.value)} />
      <DataTable serverPaged columns={columns} data={list.rows} loading={list.loading} empty="No suppliers yet." />
      <Pagination meta={list.meta} onPage={list.setPage} />
      <SupplierForm open={open} supplier={editing} onClose={() => setOpen(false)} onSaved={() => (editing ? list.reload() : list.showFirst())} />
    </>
  );
}
