"use client";

import { useState } from "react";
import Link from "next/link";
import { money } from "@/lib/format";
import { useFetch } from "@/lib/hooks";
import type { InvestorSale } from "@/lib/types";
import { Button, ErrorText, Loading, Modal } from "@/components/app-ui";
import { ReceiveModal } from "@/components/InvestorSaleCard";

/** One investor's dues to the shop: each investor sale they still have to give money on, with a Collect button. */
export function InvestorDuesModal({ investorId, name, onClose, onSaved }: { investorId: number; name: string; onClose: () => void; onSaved?: () => void }) {
  const { data, loading, error, reload } = useFetch<InvestorSale[]>(`/investor-sales/dues?investor_id=${investorId}`);
  const [collect, setCollect] = useState<InvestorSale | null>(null);
  const rows = data ?? [];
  const total = rows.reduce((t, x) => t + (x.investor_owes ?? 0), 0);

  return (
    <>
      <Modal open title={`Collect due · ${name}`} onClose={onClose}>
        {loading && !data ? <Loading /> : error ? <ErrorText message={error} /> : (
          <>
            <p className="mb-3 text-sm text-slate-600">{name} still has to give the shop: <b className="text-amber-700">{money(total)}</b></p>
            <ul className="space-y-2">
              {rows.map((x) => (
                <li key={x.id} className="flex items-center justify-between gap-2 rounded-lg border border-emerald-200 p-3 text-sm">
                  <div className="min-w-0">
                    <p className="font-medium"><Link className="underline" href={`/investor-sales/${x.id}`}>Investor sale #{x.id}</Link> · {x.sale?.vehicle?.chassis_no ?? "-"}</p>
                    <p className="text-xs text-slate-500">Gave {money(x.investor_paid ?? 0)} of {money(x.principal)}</p>
                  </div>
                  <div className="flex shrink-0 items-center gap-2">
                    <b className="text-amber-700">{money(x.investor_owes ?? 0)}</b>
                    <Button onClick={() => setCollect(x)}>Collect</Button>
                  </div>
                </li>
              ))}
              {!rows.length && <li className="py-6 text-center text-sm text-slate-500">Nothing is due from this investor.</li>}
            </ul>
          </>
        )}
      </Modal>
      {collect && <ReceiveModal x={collect} owed={collect.investor_owes ?? 0} onClose={() => setCollect(null)} onSaved={() => { reload(); onSaved?.(); }} />}
    </>
  );
}
