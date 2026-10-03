"use client";

import { useState } from "react";
import Link from "next/link";
import { Eye, HandCoins, Pencil, Plus } from "lucide-react";
import { usePagedList } from "@/lib/hooks";
import { useAuth } from "@/lib/auth";
import { useT } from "@/lib/i18n";
import { money } from "@/lib/format";
import type { Party } from "@/lib/types";
import { Button, inputCls, PageHeader, Pagination } from "@/components/app-ui";
import { Col, DataTable } from "@/components/DataTable";
import { PartyForm } from "@/components/forms/PartyForm";
import { Input } from "@/components/ui/input";

/** The customers list and the suppliers list: same screen, each showing (and creating) its own kind. */
export function PartyList({ kind }: { kind: "customer" | "supplier" }) {
  const { can } = useAuth();
  const { t } = useT();
  const list = usePagedList<Party>("/parties", { type: kind });
  const [editing, setEditing] = useState<Party | null>(null);
  const [open, setOpen] = useState(false);
  const plural = kind === "customer" ? "Customers" : "Suppliers";

  const columns: Col<Party>[] = [
    { header: "Name", cell: ({ row }) => <span className="font-medium">{row.original.name}</span> },
    ...(kind === "customer" ? [{ header: "Father's name", cell: ({ row }: { row: { original: Party } }) => row.original.father_name ?? "-" }] : []),
    { header: "Phone", cell: ({ row }) => [row.original.phone, row.original.alt_phone].filter(Boolean).join(" / ") },
    { header: "NID", cell: ({ row }) => row.original.nid ?? "-" },
    { header: "Address", cell: ({ row }) => row.original.address ?? "-" },
    {
      header: "",
      id: "actions",
      cell: ({ row }) => {
        const p = row.original;
        const owes = p.receivable_due ?? 0; // customer owes us
        const owed = p.payable_due ?? 0; // we owe the supplier
        return (
          <div className="flex flex-wrap items-center justify-end gap-1.5 md:flex-nowrap md:whitespace-nowrap">
            {kind === "customer" && owes > 0 && can("payments.collect") && (
              <Link className="inline-flex items-center gap-1.5 rounded-lg border border-sky-200 px-2.5 py-1.5 text-xs font-medium text-sky-700 hover:bg-sky-50" href={`/due-collection?party_id=${p.id}`}><HandCoins className="size-4" /> Due collection · {money(owes)}</Link>
            )}
            {kind === "supplier" && owed > 0 && can("purchases.edit") && (
              <Link className="inline-flex items-center gap-1.5 rounded-lg border border-sky-200 px-2.5 py-1.5 text-xs font-medium text-sky-700 hover:bg-sky-50" href={`/supplier-payment?party_id=${p.id}`}><HandCoins className="size-4" /> Pay · {money(owed)}</Link>
            )}
            <Link className="inline-flex items-center gap-1.5 rounded-lg border border-violet-200 px-2.5 py-1.5 text-xs font-medium text-violet-700 hover:bg-violet-50" href={`/parties/${p.id}`}><Eye className="size-4" /> View</Link>
            {can("parties.edit") && <Button variant="outline" size="sm" onClick={() => { setEditing(p); setOpen(true); }}><Pencil className="size-4 text-emerald-600" /> Edit</Button>}
          </div>
        );
      },
    },
  ];

  return (
    <>
      <PageHeader title={plural} actions={can("parties.create") && <Button onClick={() => { setEditing(null); setOpen(true); }}><Plus className="size-4" /> {t(`New ${kind}`)}</Button>} />
      <Input className={`${inputCls} mb-3 max-w-xs`} placeholder="Search name, phone, NID" value={list.search} onChange={(e) => list.setSearch(e.target.value)} />
      <DataTable serverPaged columns={columns} data={list.rows} loading={list.loading} empty={`No ${plural.toLowerCase()} yet.`} />
      <Pagination meta={list.meta} onPage={list.setPage} />
      <PartyForm open={open} party={editing} kind={kind} onClose={() => setOpen(false)} onSaved={() => (editing ? list.reload() : list.showFirst())} />
    </>
  );
}
