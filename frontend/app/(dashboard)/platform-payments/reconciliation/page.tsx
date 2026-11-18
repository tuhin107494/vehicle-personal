"use client";

import Link from "next/link";
import { useState } from "react";
import { ArrowLeft, Check } from "lucide-react";
import { toast } from "sonner";
import { api, ApiError } from "@/lib/api";
import { usePagedList } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { taka } from "@/lib/billing";
import type { MismatchT } from "@/lib/types";
import { Badge, Button, ErrorText, Field, inputCls, Modal, PageHeader, Pagination } from "@/components/app-ui";
import { Input } from "@/components/ui/input";
import { DataTable, type Col } from "@/components/DataTable";

const KIND_TEXT: Record<MismatchT["kind"], string> = { amount: "Amount differs", status: "Gateway status", ledger: "Ledger" };

/** What the nightly settlement check could not match, super admin only. Resolving one keeps a note of what was found. */
export default function ReconciliationPage() {
  const { t } = useT();
  const [resolved, setResolved] = useState("");
  const list = usePagedList<MismatchT>("/admin/reconciliation", { resolved });
  const [resolving, setResolving] = useState<MismatchT | null>(null);
  const [note, setNote] = useState("");
  const [busy, setBusy] = useState(false);
  const openCount = (list.meta as { open_count?: number } | null)?.open_count;

  const resolve = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!resolving) return;
    setBusy(true);
    try {
      const res = await api(`/admin/reconciliation/${resolving.id}/resolve`, { method: "POST", body: { note } });
      toast.success(res.message);
      setResolving(null);
      setNote("");
      list.reload();
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : "Failed");
    } finally {
      setBusy(false);
    }
  };

  const columns: Col<MismatchT>[] = [
    { header: "Found", cell: ({ row }) => (row.original.created_at ? new Date(row.original.created_at).toLocaleString() : "-") },
    { header: "Shop", cell: ({ row }) => row.original.shop?.name ?? (row.original.shop_id ? `#${row.original.shop_id}` : "-") },
    { header: "Gateway", cell: ({ row }) => <span className="capitalize">{row.original.gateway}</span> },
    { header: "Kind", cell: ({ row }) => <Badge tone={row.original.kind === "ledger" ? "red" : "yellow"}>{KIND_TEXT[row.original.kind]}</Badge> },
    { header: "Payment", cell: ({ row }) => `${row.original.reference_type.replace(/_/g, " ")} #${row.original.reference_id}` },
    { header: "Expected / actual", cell: ({ row }) => `${taka(row.original.expected_paisa)} / ${taka(row.original.actual_paisa)}` },
    { header: "Details", cell: ({ row }) => <span>{row.original.message}{row.original.note && <span className="block text-xs text-slate-500">{t("Note")}: {row.original.note} ({row.original.resolved_by?.name})</span>}</span> },
    { header: "Action", id: "actions", cell: ({ row }) => row.original.resolved_at ? <Badge tone="green">Resolved</Badge> : <Button size="sm" onClick={() => { setNote(""); setResolving(row.original); }}><Check className="size-3.5" /> Resolve</Button> },
  ];

  return (
    <>
      <PageHeader title="Settlement check" actions={<Link href="/platform-payments"><Button variant="outline"><ArrowLeft className="size-4" /> Payments</Button></Link>} />
      <p className="mb-3 text-sm text-slate-600">{t("Every night, yesterday's payments are compared with the gateway and the ledger. Anything that does not match is listed here until someone looks at it.")}{openCount !== undefined && ` ${t("Open")}: ${openCount}.`}</p>
      <div className="mb-4 flex gap-1.5">
        <Button size="sm" variant={resolved === "" ? "primary" : "outline"} onClick={() => setResolved("")}>Open</Button>
        <Button size="sm" variant={resolved === "yes" ? "primary" : "outline"} onClick={() => setResolved("yes")}>Resolved</Button>
      </div>
      {list.error && <ErrorText message={list.error} />}
      <DataTable columns={columns} data={list.rows} loading={list.loading} empty="Nothing to look at." serverPaged />
      <Pagination meta={list.meta} onPage={list.setPage} />
      <Modal open={!!resolving} onClose={() => setResolving(null)} title="Resolve mismatch">
        <form onSubmit={resolve} className="space-y-3">
          <p className="text-sm text-slate-600">{resolving?.message}</p>
          <Field required label="What was found and done"><Input className={inputCls} value={note} onChange={(e) => setNote(e.target.value)} autoFocus /></Field>
          <div className="flex justify-end gap-2">
            <Button variant="outline" onClick={() => setResolving(null)}>Back</Button>
            <Button type="submit" disabled={busy || !note.trim()}>Resolve</Button>
          </div>
        </form>
      </Modal>
    </>
  );
}
