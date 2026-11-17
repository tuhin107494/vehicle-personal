"use client";

import { useMemo, useState } from "react";
import Link from "next/link";
import { toast } from "sonner";
import { Ban, Wallet } from "lucide-react";
import { api, ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { fmtDate, money } from "@/lib/format";
import type { InvestorSale } from "@/lib/types";
import { Button, Card, KV } from "@/components/app-ui";
import { Col, DataTable } from "@/components/DataTable";
import { SettlementForm } from "@/components/forms/SettlementForm";

type Row = InvestorSale["schedule"][number] & { paid: boolean };

/** The investor sale on a sale's page: the investor's terms, where the payments to them stand, and their schedule. */
export function InvestorSaleCard({ investorSale: x, onChanged }: { investorSale: InvestorSale; onChanged: () => void }) {
  const { can } = useAuth();
  const [paying, setPaying] = useState(false);
  const [busy, setBusy] = useState(false);
  const [showSchedule, setShowSchedule] = useState(false);
  const p = x.position;
  const paid = p ? p.principal_paid + p.profit_paid : 0;
  const defaults = useMemo(() => ({ principal: p ? p.principal - p.principal_paid : undefined, profit: p ? p.profit - p.profit_paid : undefined }), [p]);

  // an installment counts as paid once the payments so far cover it and every one before it
  const rows = useMemo(() => {
    const out: Row[] = [];
    let upToHere = 0;
    for (const r of x.schedule) {
      upToHere += r.amount;
      out.push({ ...r, paid: upToHere <= paid + 0.005 });
    }
    return out;
  }, [x.schedule, paid]);
  const cols: Col<Row>[] = [
    { header: "#", accessorKey: "seq_no" },
    { header: "Date", cell: ({ row }) => fmtDate(row.original.due_date) },
    { header: "Amount", cell: ({ row }) => money(row.original.amount) },
    { header: "Status", cell: ({ row }) => row.original.paid ? <span className="text-emerald-700">Paid</span> : row.original.due_date <= new Date().toLocaleDateString("en-CA") ? <span className="font-medium text-amber-700">Due</span> : <span className="text-slate-500">Upcoming</span> },
  ];

  const cancel = async () => {
    if (!confirm("Cancel this investor sale? The investor's investment for it is removed.")) return;
    setBusy(true);
    try {
      const res = await api(`/investor-sales/${x.id}/cancel`, { method: "POST" });
      toast.success(res.message);
      onChanged();
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : "Failed");
    } finally {
      setBusy(false);
    }
  };

  return (
    <Card title="Investor sale" className="mb-4" actions={(
      <div className="flex flex-wrap gap-2">
        {can("settlements.manage") && p && p.total_due > 0 && <Button variant="outline" onClick={() => setPaying(true)}><Wallet className="size-4 text-violet-600" /> Pay investor</Button>}
        {can("sales.cancel") && paid === 0 && <Button variant="danger" disabled={busy} onClick={cancel}><Ban className="size-4" /> Cancel</Button>}
      </div>
    )}>
      <KV items={[
        ["Investor", x.investor ? <Link key="i" className="underline" href={`/investors/${x.investor.id}`}>{x.investor.name}</Link> : "-"],
        ...(x.customer ? [["Additional customer", <Link key="c" className="underline" href={`/customers/${x.customer.id}`}>{x.customer.name}{x.customer.phone ? ` · ${x.customer.phone}` : ""}</Link>] as [string, React.ReactNode]] : []),
        ["Sale date", fmtDate(x.date)],
        ...(x.sale_price !== null ? [["Sale price", money(x.sale_price)] as [string, React.ReactNode]] : []),
        ...(x.down_payment !== null ? [["Down payment", `${money(x.down_payment)}${x.method ? ` (${x.method})` : ""}`] as [string, React.ReactNode]] : []),
        ["Due amount", money(x.principal)],
        ...(x.memo_no ? [["Memo no.", x.memo_no] as [string, React.ReactNode]] : []),
        ...(x.guarantor_name ? [["Guarantor name", x.guarantor_name] as [string, React.ReactNode]] : []),
        ...(x.guarantor_phone ? [["Guarantor", <a key="g" className="underline" href={`tel:${x.guarantor_phone}`}>{x.guarantor_phone}</a>] as [string, React.ReactNode]] : []),
        ["Investor profit", `${money(x.profit_amount)} (${x.profit_rate}%)`],
        ["Pay the investor", `${money(x.total_payable)} in ${x.number_of_installments} ${x.frequency === "weekly" ? "weeks" : "months"} from ${fmtDate(x.start_date)}`],
        ...(p ? [
          ["Paid so far", money(paid)] as [string, React.ReactNode],
          ["Due now", <b key="d" className={p.current_due > 0 ? "text-amber-700" : ""}>{money(p.current_due)}</b>] as [string, React.ReactNode],
          ["Still to pay", money(p.total_due)] as [string, React.ReactNode],
          ["Next date", p.next_date ? fmtDate(p.next_date) : "-"] as [string, React.ReactNode],
        ] : []),
      ]} />
      <Button variant="outline" className="mt-3" onClick={() => setShowSchedule((v) => !v)}>{showSchedule ? "Hide schedule" : "Show schedule"}</Button>
      {showSchedule && <div className="mt-3"><DataTable columns={cols} data={rows} /></div>}
      {paying && x.investor && (
        <SettlementForm open mode="pay" name={x.investor.name} investorId={x.investor.id} investorSaleId={x.id} outstanding={defaults.principal} defaults={defaults}
          onClose={() => setPaying(false)} onSaved={onChanged} />
      )}
    </Card>
  );
}
