"use client";

import { useState } from "react";
import { ChevronDown } from "lucide-react";
import { fmtDate, money } from "@/lib/format";
import { useFetch } from "@/lib/hooks";
import { Modal } from "@/components/app-ui";

type Deletion = {
  id: number; vehicle_id: number; chassis_no: string; engine_no: string | null; registration_no: string | null; vehicle_name: string | null;
  created_at: string; user: { id: number; name: string } | null;
  summary: {
    sales: { id: number; date: string; customer: string | null; payment_type: string; status: string; total: number; paid: number; due: number }[];
    purchases: { id: number; date: string; from: string | null; price: number; paid: number }[];
    investor_sales: { id: number; investor: string | null; principal: number; received: number; paid_back: number }[];
    totals: { customer_paid: number; supplier_paid: number; investor_received: number; investor_paid_back: number };
  };
};

const when = (v: string) => new Date(v).toLocaleString("en-GB", { day: "2-digit", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" });

/** The vehicles deleted with everything on them: who deleted each and when, and the sales, purchases and money removed with it. */
export function DeletedVehicles({ onClose }: { onClose: () => void }) {
  const { data, loading, error } = useFetch<Deletion[]>("/vehicle-deletions");
  const [open, setOpen] = useState<number | null>(null);

  return (
    <Modal open wide title="Deleted vehicles" onClose={onClose}>
      <p className="mb-3 text-sm text-slate-600">Each vehicle deleted with its sales, purchases and payments. The records are gone; this is what was removed.</p>
      {loading && !data && <p className="text-sm text-slate-500">Loading...</p>}
      {error && <p className="text-sm text-red-600">{error}</p>}
      {data && !data.length && <p className="py-6 text-center text-sm text-slate-500">No vehicle has been deleted.</p>}
      <ul className="space-y-2">
        {(data ?? []).map((d) => {
          const t = d.summary.totals;
          const isOpen = open === d.id;
          return (
            <li key={d.id} className="rounded-lg border border-red-200 bg-red-50/40 text-sm">
              <button type="button" aria-expanded={isOpen} onClick={() => setOpen(isOpen ? null : d.id)} className="flex w-full items-start justify-between gap-2 p-3 text-left">
                <div className="min-w-0">
                  <p className="font-semibold">Chassis: {d.chassis_no}{d.engine_no ? ` · Engine: ${d.engine_no}` : ""}</p>
                  <p className="text-xs text-slate-500">{[d.vehicle_name, d.registration_no].filter(Boolean).join(" · ")}</p>
                  <p className="mt-1 text-xs text-slate-600">Deleted {when(d.created_at)}{d.user ? ` by ${d.user.name}` : ""} · {d.summary.sales.length} sale{d.summary.sales.length === 1 ? "" : "s"}, {d.summary.purchases.length} purchase{d.summary.purchases.length === 1 ? "" : "s"}</p>
                </div>
                <ChevronDown className={`mt-1 size-4 shrink-0 text-slate-400 transition-transform ${isOpen ? "rotate-180" : ""}`} />
              </button>
              {isOpen && (
                <div className="space-y-3 border-t border-red-200 p-3">
                  <dl className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    {([["Customer paid", t.customer_paid], ["Paid to supplier", t.supplier_paid], ["From investor", t.investor_received], ["Paid to investor", t.investor_paid_back]] as const).map(([k, v]) => (
                      <div key={k}><dt className="text-xs text-slate-500">{k}</dt><dd className="font-semibold">{money(v)}</dd></div>
                    ))}
                  </dl>
                  {d.summary.purchases.length > 0 && (
                    <div>
                      <p className="mb-1 text-xs font-semibold uppercase text-slate-500">Purchases</p>
                      {d.summary.purchases.map((p) => <p key={p.id} className="text-xs">#{p.id} · {fmtDate(p.date)} · from {p.from ?? "-"} · price {money(p.price)} · paid {money(p.paid)}</p>)}
                    </div>
                  )}
                  {d.summary.sales.length > 0 && (
                    <div>
                      <p className="mb-1 text-xs font-semibold uppercase text-slate-500">Sales</p>
                      {d.summary.sales.map((s) => <p key={s.id} className="text-xs">#{s.id} · {fmtDate(s.date)} · {s.customer ?? "-"} · <span className="capitalize">{s.payment_type}</span> · total {money(s.total)} · paid {money(s.paid)} · due {money(s.due)}{s.status !== "active" ? ` · ${s.status}` : ""}</p>)}
                    </div>
                  )}
                  {d.summary.investor_sales.length > 0 && (
                    <div>
                      <p className="mb-1 text-xs font-semibold uppercase text-slate-500">Investor sales</p>
                      {d.summary.investor_sales.map((x) => <p key={x.id} className="text-xs">#{x.id} · {x.investor ?? "-"} · money {money(x.principal)} · received {money(x.received)} · paid back {money(x.paid_back)}</p>)}
                    </div>
                  )}
                </div>
              )}
            </li>
          );
        })}
      </ul>
    </Modal>
  );
}
