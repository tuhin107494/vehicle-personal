"use client";

import Link from "next/link";
import { ArrowLeft } from "lucide-react";
import { usePagedList } from "@/lib/hooks";
import { taka } from "@/lib/billing";
import type { GatewayRefundT } from "@/lib/types";
import { Button, ErrorText, PageHeader, Pagination, StatusBadge } from "@/components/app-ui";
import { DataTable, type Col } from "@/components/DataTable";

/** Every refund asked for, super admin only, with the gateway's answer. */
export default function RefundsPage() {
  const list = usePagedList<GatewayRefundT>("/admin/refunds");

  const columns: Col<GatewayRefundT>[] = [
    { header: "Requested", cell: ({ row }) => (row.original.created_at ? new Date(row.original.created_at).toLocaleString() : "-") },
    { header: "Shop", cell: ({ row }) => row.original.shop?.name ?? `#${row.original.shop_id}` },
    { header: "Payment", cell: ({ row }) => <span><span className="capitalize">{row.original.payment?.gateway_code}</span> <code className="text-xs">{row.original.payment?.gateway_trx_id}</code></span> },
    { header: "Amount", cell: ({ row }) => <span className="font-semibold">{taka(row.original.amount_paisa)}</span> },
    { header: "Reason", cell: ({ row }) => row.original.reason },
    { header: "By", cell: ({ row }) => row.original.requested_by?.name ?? "-" },
    { header: "Status", cell: ({ row }) => <span><StatusBadge status={row.original.status} />{row.original.failure_reason && <span className="block text-xs text-red-600">{row.original.failure_reason}</span>}{row.original.gateway_refund_id && <code className="block text-xs text-slate-500">{row.original.gateway_refund_id}</code>}</span> },
  ];

  return (
    <>
      <PageHeader title="Refunds" actions={<Link href="/platform-payments"><Button variant="outline"><ArrowLeft className="size-4" /> Payments</Button></Link>} />
      {list.error && <ErrorText message={list.error} />}
      <DataTable columns={columns} data={list.rows} loading={list.loading} empty="No refunds yet." serverPaged />
      <Pagination meta={list.meta} onPage={list.setPage} />
    </>
  );
}
