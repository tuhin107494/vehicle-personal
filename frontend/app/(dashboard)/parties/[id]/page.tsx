"use client";

import { viewCol } from "@/components/ViewLink";
import { useState } from "react";
import { useParams, useRouter } from "next/navigation";
import { toast } from "sonner";
import { api, ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { fmtDate, money } from "@/lib/format";
import { useFetch } from "@/lib/hooks";
import type { Party, Purchase, Sale } from "@/lib/types";
import { Button, Card, ErrorText, KV, Loading, PageHeader, StatusBadge } from "@/components/app-ui";
import { Col, DataTable } from "@/components/DataTable";
import { PartyForm } from "@/components/forms/PartyForm";
import { Pencil, Trash2 } from "lucide-react";

type History = { party: Party; purchases: Purchase[]; sales: Sale[] };

export default function PartyDetailPage() {
  const { id } = useParams<{ id: string }>();
  const router = useRouter();
  const { can } = useAuth();
  const { data, loading, error, reload } = useFetch<History>(`/parties/${id}/history`);
  const [editing, setEditing] = useState(false);

  if (loading && !data) return <Loading />;
  if (error || !data) return <ErrorText message={error ?? "Not found"} />;
  const { party, purchases, sales } = data;

  const remove = async () => {
    if (!confirm(`Delete ${party.name}?`)) return;
    try {
      const res = await api(`/parties/${party.id}`, { method: "DELETE" });
      toast.success(res.message);
      router.replace(party.type === "supplier" ? "/suppliers" : "/customers");
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : "Failed");
    }
  };

  const buyCols: Col<Purchase>[] = [
    { header: "Date", cell: ({ row }) => fmtDate(row.original.purchase_date) },
    { header: "Vehicle", cell: ({ row }) => <span className="font-medium">{row.original.vehicle?.chassis_no}</span> },
    { header: "Price", cell: ({ row }) => money(row.original.price) },
    { header: "Paid", cell: ({ row }) => money(row.original.paid_amount) },
    { header: "Status", cell: ({ row }) => <StatusBadge status={row.original.payment_status} /> },
    viewCol<Purchase>((x) => `/vehicles/${x.vehicle_id}`),
  ];
  const saleCols: Col<Sale>[] = [
    { header: "Sale", cell: ({ row }) => <span className="font-medium">#{row.original.id}</span> },
    { header: "Date", cell: ({ row }) => fmtDate(row.original.sale_date) },
    { header: "Vehicle", cell: ({ row }) => row.original.vehicle?.chassis_no },
    { header: "Price", cell: ({ row }) => money(row.original.sale_price) },
    { header: "Due", cell: ({ row }) => money(row.original.due_amount) },
    { header: "Payment", cell: ({ row }) => <StatusBadge status={row.original.payment_status} /> },
    { header: "Sale status", cell: ({ row }) => <StatusBadge status={row.original.status} /> },
    viewCol<Sale>((x) => `/sales/${x.id}`),
  ];

  return (
    <>
      <PageHeader
        title={party.name}
        actions={<>
          {can("parties.edit") && <Button variant="outline" onClick={() => setEditing(true)}><Pencil className="size-4 text-emerald-600" /> Edit</Button>}
          {can("parties.delete") && <Button variant="danger" onClick={remove}><Trash2 className="size-4 " /> Delete</Button>}
        </>}
      />
      <Card className="mb-4">
        <KV items={[...(party.type === "supplier" ? [] : [["Father's name", party.father_name] as [string, React.ReactNode]]), ["Phone", party.phone], ["Alt. phone", party.alt_phone], ["NID", party.nid], ["Type", party.type], ["Address", party.address], ...(party.type === "customer" ? [] : ([["Bank", party.bank_name], ["Branch", party.bank_branch], ["Account name", party.bank_account_name], ["Account no", party.bank_account_no], ["Other bank details", party.bank_info]] as [string, React.ReactNode][])), ["Notes", party.notes]]} />
      </Card>
      <h2 className="mb-2 font-semibold">Bought from this party ({purchases.length})</h2>
      <DataTable columns={buyCols} data={purchases} empty="Nothing bought from this party." />
      <h2 className="mb-2 mt-6 font-semibold">Sold to this party ({sales.length})</h2>
      <DataTable columns={saleCols} data={sales} empty="Nothing sold to this party." />
      <PartyForm open={editing} party={party} onClose={() => setEditing(false)} onSaved={reload} />
    </>
  );
}
