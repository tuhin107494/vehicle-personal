"use client";

import { useMemo, useState } from "react";
import { Wallet } from "lucide-react";
import { fmtDate, money } from "@/lib/format";
import { useFetch } from "@/lib/hooks";
import { Button, ErrorText, inputCls, Modal } from "@/components/app-ui";
import { Input } from "@/components/ui/input";
import { SettlementForm } from "@/components/forms/SettlementForm";

type Row = {
  plan_id: number; sale_id: number; vehicle: string; vehicle_name: string; buyer: string;
  principal: number; principal_paid: number; principal_due: number; profit: number; profit_paid: number; profit_due: number;
  installments: number; installments_left: number; next_date: string | null; monthly: number; current_due: number; total_due: number;
};
type Data = { rows: Row[]; totals: { principal_due: number; profit_due: number; paid: number; monthly: number; current_due: number; total_due: number } };

/** Opens on "Pay": the vehicles this investor financed, each with what it still owes and its own Pay button. */
export function InvestorVehiclePay({ investorId, name, onClose, onSaved }: { investorId: number; name: string; onClose: () => void; onSaved?: () => void }) {
  const { data, loading, error, reload } = useFetch<Data>(`/investors/${investorId}/vehicle-payments`);
  const [pay, setPay] = useState<Row | null>(null);
  const [q, setQ] = useState("");
  const [tab, setTab] = useState<"all" | "over">("all");
  const today = new Date().toLocaleDateString("en-CA");
  const isOver = (r: Row) => !!r.next_date && r.next_date < today && r.total_due > 0; // its installment date has passed
  const overCount = (data?.rows ?? []).filter(isOver).length;
  const shown = (data?.rows ?? []).filter((r) => (tab === "all" || isOver(r)) && `${r.vehicle} ${r.vehicle_name} ${r.buyer}`.toLowerCase().includes(q.trim().toLowerCase()));
  const defaults = useMemo(() => ({ principal: pay?.principal_due, profit: pay?.profit_due }), [pay]);

  return (
    <>
      <Modal open title={`Pay investor · ${name}`} onClose={onClose} wide>
        {error && <ErrorText message={error} />}
        {loading && !data && <p className="text-sm text-slate-500">Loading...</p>}
        {data && (
          <>
            <div className="grid grid-cols-3 gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-center text-sm">
              <div><p className="text-xs text-emerald-700">Monthly installment</p><p className="font-bold text-blue-700">{money(data.totals.monthly)}</p></div>
              <div><p className="text-xs text-emerald-700">Current due</p><p className="font-bold text-amber-700">{money(data.totals.current_due)}</p></div>
              <div><p className="text-xs text-emerald-700">Total due</p><p className="font-bold text-rose-700">{money(data.totals.total_due)}</p></div>
            </div>
            <div className="flex gap-2">
              <Button variant={tab === "all" ? "primary" : "outline"} onClick={() => setTab("all")}>All <span className="ml-1 rounded-full bg-white/30 px-1.5 text-xs">{data.rows.length}</span></Button>
              <Button variant="outline" className={tab === "over" ? "border-transparent bg-gradient-to-r from-amber-500 to-orange-500 text-white hover:from-amber-400 hover:to-orange-400 hover:text-white" : "border-amber-300 text-amber-700 hover:bg-amber-50 hover:text-amber-800"} onClick={() => setTab("over")}>Date over <span className="ml-1 rounded-full bg-black/10 px-1.5 text-xs">{overCount}</span></Button>
            </div>
            <Input className={inputCls} placeholder="Search chassis, vehicle or buyer" value={q} onChange={(e) => setQ(e.target.value)} />
            <ul className="max-h-[50vh] space-y-2 overflow-y-auto">
              {shown.map((r) => {
                const owes = r.total_due > 0;
                return (
                  <li key={r.plan_id} className="rounded-lg border border-emerald-200 bg-white p-3">
                    <div className="flex items-start justify-between gap-2">
                      <div className="min-w-0">
                        <p className="font-semibold text-slate-900">{r.vehicle} <span className="text-xs font-normal text-slate-500">{r.vehicle_name}</span></p>
                        <p className="text-xs text-slate-500">Buyer {r.buyer}</p>
                      </div>
                      {owes ? (
                        <Button variant="outline" size="sm" className="shrink-0 border-violet-300 bg-violet-50 text-violet-700 hover:bg-violet-100 hover:text-violet-800" onClick={() => setPay(r)}><Wallet className="size-4" /> Pay</Button>
                      ) : <span className="shrink-0 rounded-full bg-emerald-100 px-2 py-0.5 text-xs text-emerald-700">Paid</span>}
                    </div>
                    <dl className="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 border-t border-emerald-100 pt-2 text-xs">
                      <div className="flex justify-between"><dt className="text-slate-500">Monthly installment</dt><dd className="font-medium text-blue-700">{money(r.monthly)}</dd></div>
                      <div className="flex justify-between"><dt className="text-slate-500">Current due</dt><dd className="font-medium text-amber-700">{money(r.current_due)}</dd></div>
                      <div className="flex justify-between"><dt className="text-slate-500">Total due</dt><dd className="font-medium text-rose-700">{money(r.total_due)}</dd></div>
                      <div className="flex justify-between"><dt className="text-slate-500">Installments left</dt><dd className="font-medium text-slate-800">{r.installments_left} of {r.installments}</dd></div>
                      <div className="flex justify-between"><dt className="text-slate-500">Next installment date</dt><dd className="font-medium text-slate-800">{r.next_date ? fmtDate(r.next_date) : "-"}</dd></div>
                    </dl>
                  </li>
                );
              })}
              {!shown.length && <li className="py-6 text-center text-sm text-slate-500">{data.rows.length ? (tab === "over" && !q ? "No vehicle has a passed installment date." : "No vehicle matches your search.") : "This investor has not financed any active sale."}</li>}
            </ul>
          </>
        )}
      </Modal>
      {pay && (
        <SettlementForm open mode="pay" name={pay.vehicle} investorId={investorId} planId={pay.plan_id} outstanding={pay.principal_due} defaults={defaults}
          onClose={() => setPay(null)} onSaved={() => { reload(); onSaved?.(); }} />
      )}
    </>
  );
}
