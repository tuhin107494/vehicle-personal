"use client";

import Link from "next/link";
import { useState } from "react";
import { ArrowLeft, Check, X } from "lucide-react";
import { toast } from "sonner";
import { api, ApiError } from "@/lib/api";
import { useFetch } from "@/lib/hooks";
import { money } from "@/lib/format";
import type { PaymentAttemptT } from "@/lib/types";
import { Badge, Button, ErrorText, Field, inputCls, Modal, PageHeader, Pagination, StatusBadge } from "@/components/app-ui";
import { Input } from "@/components/ui/input";
import { NativeSelect } from "@/components/ui/native-select";
import { DataTable, type Col } from "@/components/DataTable";

/** Bank transfers shops say they have made. Approving one is applied exactly like a gateway's confirmation: the invoice is paid and the plan runs. */
export default function BankTransfersPage() {
  const [status, setStatus] = useState("");
  const [page, setPage] = useState(1);
  const list = useFetch<PaymentAttemptT[]>(`/admin/manual-payments?page=${page}${status ? `&status=${status}` : ""}`);
  const [rejecting, setRejecting] = useState<PaymentAttemptT | null>(null);
  const [reason, setReason] = useState("");
  const [busy, setBusy] = useState<number | null>(null);

  const act = async (a: PaymentAttemptT, action: "approve" | "reject") => {
    setBusy(a.id);
    try {
      const res = await api(`/admin/manual-payments/${a.id}/${action}`, { method: "POST", body: action === "reject" ? { reason } : undefined });
      toast.success(res.message);
      setRejecting(null);
      setReason("");
      list.reload();
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : "Failed");
    } finally {
      setBusy(null);
    }
  };

  const columns: Col<PaymentAttemptT>[] = [
    { header: "Submitted", cell: ({ row }) => (row.original.submitted_at ? new Date(row.original.submitted_at).toLocaleString() : "-") },
    { header: "Shop", cell: ({ row }) => row.original.shop?.name ?? `#${row.original.shop_id}` },
    { header: "Invoice", cell: ({ row }) => row.original.invoice?.invoice_no ?? `#${row.original.invoice_id}` },
    { header: "Amount", cell: ({ row }) => <span className="font-semibold">{money(row.original.amount_paisa / 100)}</span> },
    { header: "Reference", cell: ({ row }) => <code className="text-xs">{row.original.reference ?? "-"}</code> },
    { header: "Proof", cell: ({ row }) => (row.original.proof_url ? <a href={row.original.proof_url} target="_blank" rel="noreferrer" className="text-sm text-sky-700 underline">Open</a> : "-") },
    { header: "Status", cell: ({ row }) => (row.original.status === "pending" ? <Badge tone="yellow">Waiting for approval</Badge> : <StatusBadge status={row.original.status} />) },
    {
      header: "Action",
      id: "actions",
      cell: ({ row }) => row.original.status !== "pending" ? (row.original.failure_reason ? <span className="text-xs text-slate-500">{row.original.failure_reason}</span> : null) : (
        <div className="flex gap-1.5">
          <Button size="sm" disabled={busy === row.original.id} onClick={() => act(row.original, "approve")}><Check className="size-3.5" /> Approve</Button>
          <Button size="sm" variant="danger" disabled={busy === row.original.id} onClick={() => { setRejecting(row.original); setReason(""); }}><X className="size-3.5" /> Reject</Button>
        </div>
      ),
    },
  ];

  return (
    <>
      <PageHeader title="Bank transfers" actions={<Link href="/platform-gateways"><Button variant="outline"><ArrowLeft className="size-4" /> Payment gateways</Button></Link>} />
      <div className="mb-4 max-w-xs">
        <Field label="Status">
          <NativeSelect className={inputCls} value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }}>
            <option value="">Waiting for approval</option>
            <option value="success">Approved</option>
            <option value="failed">Rejected</option>
          </NativeSelect>
        </Field>
      </div>
      {list.error && <ErrorText message={list.error} />}
      <DataTable columns={columns} data={list.data ?? []} loading={list.loading} empty="No transfers waiting." />
      <Pagination meta={list.meta} onPage={setPage} />

      <Modal open={!!rejecting} onClose={() => setRejecting(null)} title="Reject">
        <form onSubmit={(e) => { e.preventDefault(); if (rejecting) act(rejecting, "reject"); }} className="space-y-3">
          <p className="text-sm text-slate-600">The shop will see this reason on its payment page.</p>
          <Field required label="Reason for rejecting"><Input className={inputCls} value={reason} onChange={(e) => setReason(e.target.value)} autoFocus /></Field>
          <div className="flex justify-end gap-2">
            <Button variant="outline" onClick={() => setRejecting(null)}>Back</Button>
            <Button type="submit" variant="danger" disabled={!reason.trim() || busy !== null}>Reject</Button>
          </div>
        </form>
      </Modal>
    </>
  );
}
