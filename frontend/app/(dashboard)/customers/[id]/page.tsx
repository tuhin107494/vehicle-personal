"use client";

import { useState } from "react";
import { useParams, useRouter } from "next/navigation";
import { Pencil, Trash2 } from "lucide-react";
import { toast } from "sonner";
import { api, ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { fmtDate, money } from "@/lib/format";
import { useFetch } from "@/lib/hooks";
import type { Customer, Purchase, Sale } from "@/lib/types";
import { Button, Card, ErrorText, KV, Loading, PageHeader, StatusBadge } from "@/components/app-ui";
import { Avatar } from "@/components/Avatar";
import { Col, DataTable } from "@/components/DataTable";
import { CustomerForm } from "@/components/forms/CustomerForm";
import { viewCol } from "@/components/ViewLink";

type History = { customer: Customer; purchases: Purchase[]; sales: Sale[] };

export default function CustomerDetailPage() {
  const { id } = useParams<{ id: string }>();
  const router = useRouter();
  const { can } = useAuth();
  const { data, loading, error, reload } = useFetch<History>(`/customers/${id}/history`);
  const [editing, setEditing] = useState(false);

  if (loading && !data) return <Loading />;
  if (error || !data) return <ErrorText message={error ?? "Not found"} />;
  const { customer, purchases, sales } = data;

  const remove = async () => {
    if (!confirm(`Delete ${customer.name}?`)) return;
    try {
      await api(`/customers/${customer.id}`, { method: "DELETE" });
      toast.success("Customer deleted successfully");
      router.replace("/customers");
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : "Failed");
    }
  };

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
  const buyBackCols: Col<Purchase>[] = [
    { header: "Date", cell: ({ row }) => fmtDate(row.original.purchase_date) },
    { header: "Vehicle", cell: ({ row }) => <span className="font-medium">{row.original.vehicle?.chassis_no}</span> },
    { header: "Price", cell: ({ row }) => money(row.original.price) },
    { header: "Paid", cell: ({ row }) => money(row.original.paid_amount) },
    { header: "Status", cell: ({ row }) => <StatusBadge status={row.original.payment_status} /> },
    viewCol<Purchase>((x) => `/vehicles/${x.vehicle_id}`),
  ];

  return (
    <>
      <PageHeader
        title={customer.name}
        actions={<>
          {can("parties.edit") && <Button variant="outline" onClick={() => setEditing(true)}><Pencil className="size-4 text-emerald-600" /> Edit</Button>}
          {can("parties.delete") && <Button variant="danger" onClick={remove}><Trash2 className="size-4 " /> Delete</Button>}
        </>}
      />
      <Card className="mb-4">
        <div className="mb-3"><Avatar name={customer.name} url={customer.photo_url} size="lg" /></div>
        <KV items={[["Father's name", customer.father_name], ["Phone", customer.phone], ["Alt. phone", customer.alt_phone], ["NID", customer.nid], ["Address", customer.address], ["Notes", customer.notes]]} />
      </Card>
      <h2 className="mb-2 font-semibold">Sold to this customer ({sales.length})</h2>
      <DataTable columns={saleCols} data={sales} empty="Nothing sold to this customer." />
      {purchases.length > 0 && (
        <>
          <h2 className="mb-2 mt-6 font-semibold">Bought back from this customer ({purchases.length})</h2>
          <DataTable columns={buyBackCols} data={purchases} />
        </>
      )}
      <CustomerForm open={editing} customer={customer} onClose={() => setEditing(false)} onSaved={reload} />
    </>
  );
}
