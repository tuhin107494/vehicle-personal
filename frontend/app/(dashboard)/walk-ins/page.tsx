"use client";

import { useState } from "react";
import { Pencil, Plus, Trash2, UserPlus } from "lucide-react";
import { toast } from "sonner";
import { api, ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { fmtDate } from "@/lib/format";
import { usePagedList } from "@/lib/hooks";
import type { WalkIn } from "@/lib/types";
import { Button, inputCls, PageHeader, Pagination } from "@/components/app-ui";
import { Col, DataTable } from "@/components/DataTable";
import { CustomerForm } from "@/components/forms/CustomerForm";
import { WalkInForm } from "@/components/forms/WalkInForm";
import { PhoneChips } from "@/components/PhoneChips";
import { Input } from "@/components/ui/input";

/** People who visited the shop but have not bought yet: their number, address and a note. */
export default function WalkInsPage() {
  const { can } = useAuth();
  const list = usePagedList<WalkIn>("/walk-ins");
  const [editing, setEditing] = useState<WalkIn | null>(null);
  const [open, setOpen] = useState(false);
  const [converting, setConverting] = useState<WalkIn | null>(null);

  const remove = async (w: WalkIn) => {
    if (!confirm("Delete this walk-in customer?")) return;
    try {
      const res = await api(`/walk-ins/${w.id}`, { method: "DELETE" });
      toast.success(res.message);
      list.reload();
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : "Failed to delete");
    }
  };

  const columns: Col<WalkIn>[] = [
    { header: "Date", cell: ({ row }) => fmtDate(row.original.created_at) },
    { header: "Phone", cell: ({ row }) => <PhoneChips phone={row.original.phone} /> },
    { header: "Name", cell: ({ row }) => row.original.name ?? "-" },
    { header: "Address", cell: ({ row }) => row.original.address ?? "-" },
    { header: "Note", cell: ({ row }) => row.original.note ?? "-" },
    {
      header: "Action",
      id: "actions",
      cell: ({ row }) => (
        <div className="flex items-center justify-end gap-1.5 md:whitespace-nowrap">
          {can("walkins.edit") && can("parties.create") && <Button variant="outline" size="sm" className="border-sky-300 bg-sky-50 text-sky-700 hover:bg-sky-100" onClick={() => setConverting(row.original)}><UserPlus className="size-4" /> Make customer</Button>}
          {can("walkins.edit") && <Button variant="outline" size="sm" onClick={() => { setEditing(row.original); setOpen(true); }}><Pencil className="size-4 text-emerald-600" /> Edit</Button>}
          {can("walkins.delete") && <Button variant="outline" size="sm" className="border-rose-300 text-rose-700 hover:bg-rose-50 hover:text-rose-800" onClick={() => remove(row.original)}><Trash2 className="size-4" /> Delete</Button>}
        </div>
      ),
    },
  ];

  return (
    <>
      <PageHeader title="Walk-in customers" actions={can("walkins.create") && <Button onClick={() => { setEditing(null); setOpen(true); }}><Plus className="size-4" /> New walk-in</Button>} />
      <Input className={`${inputCls} mb-3 max-w-xs`} placeholder="Search phone, name, address, note" value={list.search} onChange={(e) => list.setSearch(e.target.value)} />
      <DataTable serverPaged columns={columns} data={list.rows} loading={list.loading} empty="No walk-in customers yet." />
      <Pagination meta={list.meta} onPage={list.setPage} />
      <CustomerForm open={!!converting} convertFrom={converting} onClose={() => setConverting(null)} onSaved={() => list.reload()} />
      <WalkInForm open={open} walkIn={editing} onClose={() => setOpen(false)} onSaved={() => (editing ? list.reload() : list.showFirst())} />
    </>
  );
}
