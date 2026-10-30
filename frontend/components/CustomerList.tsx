"use client";

import { useState } from "react";
import Link from "next/link";
import { Eye, HandCoins, Pencil, Plus } from "lucide-react";
import { usePagedList } from "@/lib/hooks";
import { useAuth } from "@/lib/auth";
import { money } from "@/lib/format";
import type { Customer } from "@/lib/types";
import { Button, inputCls, PageHeader, Pagination } from "@/components/app-ui";
import { Avatar } from "@/components/Avatar";
import { Col, DataTable } from "@/components/DataTable";
import { CustomerForm } from "@/components/forms/CustomerForm";
import { Input } from "@/components/ui/input";
import { PhoneChips } from "@/components/PhoneChips";

/** The customers list: who we sell to, what each still owes, and the shortcut to collect it. */
export function CustomerList() {
  const { can } = useAuth();
  const list = usePagedList<Customer>("/customers");
  const [editing, setEditing] = useState<Customer | null>(null);
  const [open, setOpen] = useState(false);

  const columns: Col<Customer>[] = [
    { header: "Name", cell: ({ row }) => <span className="flex items-center gap-2 font-medium"><Avatar name={row.original.name} url={row.original.photo_url} /> {row.original.name}</span> },
    { header: "Father's name", cell: ({ row }) => row.original.father_name ?? "-" },
    { header: "Phone", cell: ({ row }) => <PhoneChips phone={row.original.phone} alt={row.original.alt_phone} /> },
    { header: "NID", cell: ({ row }) => row.original.nid ?? "-" },
    { header: "Address", cell: ({ row }) => row.original.address ?? "-" },
    {
      header: "Action",
      id: "actions",
      cell: ({ row }) => {
        const c = row.original;
        const owes = c.receivable_due ?? 0;
        return (
          <div className="flex flex-wrap items-center justify-end gap-1.5 md:flex-nowrap md:whitespace-nowrap">
            {owes > 0 && can("payments.collect") && (
              <Link className="inline-flex items-center gap-1.5 rounded-lg border border-sky-200 px-2.5 py-1.5 text-xs font-medium text-sky-700 hover:bg-sky-50" href={`/due-collection?customer_id=${c.id}`}><HandCoins className="size-4" /> Due collection · {money(owes)}</Link>
            )}
            <Link className="inline-flex items-center gap-1.5 rounded-lg border border-violet-200 px-2.5 py-1.5 text-xs font-medium text-violet-700 hover:bg-violet-50" href={`/customers/${c.id}`}><Eye className="size-4" /> View</Link>
            {can("parties.edit") && <Button variant="outline" size="sm" onClick={() => { setEditing(c); setOpen(true); }}><Pencil className="size-4 text-emerald-600" /> Edit</Button>}
          </div>
        );
      },
    },
  ];

  return (
    <>
      <PageHeader title="Customers" actions={can("parties.create") && <Button onClick={() => { setEditing(null); setOpen(true); }}><Plus className="size-4" /> New customer</Button>} />
      <Input className={`${inputCls} mb-3 max-w-xs`} placeholder="Search name, phone, NID" value={list.search} onChange={(e) => list.setSearch(e.target.value)} />
      <DataTable serverPaged columns={columns} data={list.rows} loading={list.loading} empty="No customers yet." />
      <Pagination meta={list.meta} onPage={list.setPage} />
      <CustomerForm open={open} customer={editing} onClose={() => setOpen(false)} onSaved={() => (editing ? list.reload() : list.showFirst())} />
    </>
  );
}
