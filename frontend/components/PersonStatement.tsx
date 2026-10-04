"use client";

import { useState } from "react";
import { fmtDate, money } from "@/lib/format";
import { useFetch } from "@/lib/hooks";
import { useAuth } from "@/lib/auth";
import { Button, ErrorText, Field, inputCls, Pagination, Stat } from "@/components/app-ui";
import { usePaging } from "@/lib/paging";
import { CustomerSelect, SupplierSelect } from "@/components/Selects";
import { Input } from "@/components/ui/input";
import { PrintButton } from "@/components/PrintButton";
import { ResponsiveFilters } from "@/components/ResponsiveFilters";
import { MobileCards } from "@/components/MobileCards";
import { Printer } from "lucide-react";
import { printVoucher, TransactionVoucher, type VoucherTx } from "@/components/TransactionVoucher";

type Row = { no: string; details: [string, string][]; date: string; kind: string; description: string; vehicle: string | null; reference: string | null; charge: number; paid: number; balance: number };
type Statement = {
  person: { id: number; name: string; phone: string; alt_phone: string | null; address: string | null };
  side: "customer" | "supplier"; from: string | null; to: string | null;
  opening_balance: number; rows: Row[]; total_charge: number; total_paid: number; closing_balance: number;
};

type ListRow = { person: { id: number; name: string; phone: string; alt_phone: string | null }; opening_balance: number; charge: number; paid: number; closing_balance: number; transactions: number; last_date: string | null };
type TxRow = { no: string; phone: string | null; details: [string, string][]; date: string; person_id: number; person: string; kind: string; description: string; vehicle: string | null; reference: string | null; charge: number; paid: number };
type Txs = { rows: TxRow[]; total_charge: number; total_paid: number };
type Listing = { rows: ListRow[]; total_opening: number; total_charge: number; total_paid: number; total_closing: number };

const CARD_TONE = { ring: "ring-emerald-300", rule: "border-emerald-200", foot: "border-emerald-300 bg-emerald-50" };

const TEXT = {
  customer: { title: "Customer transactions", party: "Customer", charge: "Sold (debit)", paid: "Received (credit)", balance: "Balance (they owe)", owed: "Customer owes us", ahead: "We owe the customer" },
  supplier: { title: "Supplier transactions", party: "Supplier", charge: "Bought (credit)", paid: "Paid (debit)", balance: "Balance (we owe)", owed: "We owe the supplier", ahead: "Paid ahead to supplier" },
} as const;

/** A customer's or supplier's full statement, with a print / save-as-PDF button. */
export function PersonStatement({ side }: { side: "customer" | "supplier" }) {
  const { user } = useAuth();
  const t = TEXT[side];
  const [personId, setPersonId] = useState("");
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const qs = new URLSearchParams({ side, [side === "customer" ? "customer_id" : "supplier_id"]: personId, ...(from && { from }), ...(to && { to }) });
  const { data, loading, error } = useFetch<Statement>(personId ? `/reports/person-transactions?${qs}` : null);
  const [pickedName, setPickedName] = useState<string | undefined>();
  const listQs = new URLSearchParams({ side, ...(from && { from }), ...(to && { to }) });
  const [voucher, setVoucher] = useState<VoucherTx | null>(null);
  const shopName = user?.shop?.name ?? "Vehicle Hub";
  // a row of one person's statement, as the voucher the per-transaction report prints for it
  const voucherOf = (r: Row): VoucherTx => ({ no: r.no, date: r.date, person: data?.person.name ?? "", phone: data?.person.phone ?? null, description: r.description, vehicle: r.vehicle, reference: r.reference, charge: r.charge, paid: r.paid, details: r.details });
  const [view, setView] = useState<"party" | "transaction">("transaction");
  const list = useFetch<Listing>(personId || view !== "party" ? null : `/reports/person-summary?${listQs}`);
  const txs = useFetch<Txs>(personId || view !== "transaction" ? null : `/reports/transactions?${listQs}`);
  const [printing, setPrinting] = useState(false); // while printing, every row is shown, not just this page
  const stPg = usePaging(data?.rows, printing);
  const listPg = usePaging(list.data?.rows, printing);
  const txPg = usePaging(txs.data?.rows, printing);

  const period = from || to ? `${from ? fmtDate(from) : "Beginning"} to ${to ? fmtDate(to) : "today"}` : "All dates";
  
  const print = () => {
    // the browser names the saved PDF after the page title
    const old = document.title;
    document.title = personId ? `${t.title} - ${data?.person.name ?? ""}` : view === "transaction" ? `${t.title} per transaction` : `${t.title} list`;
    setPrinting(true);
    setTimeout(() => {
      window.print();
      document.title = old;
      setPrinting(false);
    }, 150);
  };

  const closing = data?.closing_balance ?? 0;

  return (
    <>
      <div className="mb-3 flex flex-wrap items-center gap-2 print:hidden">
        {!personId ? (
          <>
            <Button variant={view === "transaction" ? "primary" : "outline"} onClick={() => setView("transaction")}>Per transaction</Button>
            <Button variant={view === "party" ? "primary" : "outline"} onClick={() => setView("party")}>Per {side}</Button>
          </>
        ) : (
          <Button variant="outline" onClick={() => { setPersonId(""); setPickedName(undefined); }}>← All {side}s</Button>
        )}
        <PrintButton disabled={personId ? !data : view === "party" ? !list.data : !txs.data} onClick={print} />
      </div>
      <ResponsiveFilters desktopClass="mb-4 hidden items-start gap-3 lg:grid lg:grid-cols-3">
        {side === "customer"
          ? <CustomerSelect filter allowCreate={false} value={personId} selectedLabel={pickedName} onChange={(id) => { setPersonId(id); setPickedName(undefined); }} />
          : <SupplierSelect filter allowCreate={false} value={personId} selectedLabel={pickedName} onChange={(id) => { setPersonId(id); setPickedName(undefined); }} />}
        <Field label="From"><Input type="date" className={inputCls} value={from} max={to || undefined} onChange={(e) => setFrom(e.target.value)} /></Field>
        <Field label="To"><Input type="date" className={inputCls} value={to} min={from || undefined} onChange={(e) => setTo(e.target.value)} /></Field>
      </ResponsiveFilters>

      {!personId && view === "party" && (
        <div>
          <div className="mb-4 hidden border-b border-slate-300 pb-3 print:block">
            <p className="text-lg font-bold">{user?.shop?.name ?? "Vehicle Hub"}</p>
            <p className="text-base font-semibold">{t.title} list</p>
            <p className="text-xs text-slate-500">Period: {period}</p>
          </div>
          {list.error && <ErrorText message={list.error} />}
          {list.loading && !list.data && <p className="text-sm text-slate-500">Loading...</p>}
          {list.data && (
            <>
              <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4 print:hidden">
                <Stat color="slate" label="Opening balance" value={money(list.data.total_opening)} />
                <Stat color="blue" label={side === "customer" ? "Sold" : "Bought"} value={money(list.data.total_charge)} />
                <Stat color="green" label={side === "customer" ? "Received" : "Paid"} value={money(list.data.total_paid)} />
                <Stat color="amber" label={side === "customer" ? "Customers owe us" : "We owe suppliers"} value={money(list.data.total_closing)} />
              </div>
              <div className="rounded-xl bg-white/90 ring-1 ring-emerald-300 print:ring-slate-400">
                <div>
                  <MobileCards
                    tone={CARD_TONE}
                    empty="No transactions in this period."
                    items={listPg.items.map((r) => ({ key: r.person.id, title: <button type="button" className="text-left underline" onClick={() => { setPickedName(r.person.name); setPersonId(String(r.person.id)); }}>{r.person.name}</button>, sub: [r.person.phone, r.person.alt_phone].filter(Boolean).join(" / "), amount: money(r.closing_balance), fields: [["Opening", money(r.opening_balance)], [t.charge, money(r.charge)], [t.paid, money(r.paid)], ["Entries", r.transactions]] }))}
                    totals={[["Total " + (side === "customer" ? "sold" : "bought"), money(list.data.total_charge)], [side === "customer" ? "Total received" : "Total paid", money(list.data.total_paid)], ["Balance", money(list.data.total_closing)]]}
                  />
                </div>
                <div className="hidden overflow-x-auto md:block print:block">
                <table className="w-full text-sm">
                  <thead className="bg-gradient-to-r from-emerald-50 to-green-50 text-left text-xs uppercase tracking-wide text-emerald-700 print:bg-slate-100 print:text-slate-700">
                    <tr>
                      {[t.party, "Phone"].map((h) => <th key={h} className="whitespace-nowrap border-r border-emerald-200 px-3 py-2">{h}</th>)}
                      {["Opening", t.charge, t.paid, t.balance].map((h) => <th key={h} className="whitespace-nowrap border-r border-emerald-200 px-3 py-2 text-right">{h}</th>)}
                      <th className="whitespace-nowrap border-r border-emerald-200 px-3 py-2 text-right">Entries</th>
                      <th className="whitespace-nowrap px-3 py-2 print:hidden" />
                    </tr>
                  </thead>
                  <tbody>
                    {listPg.items.map((r) => (
                      <tr key={r.person.id} className="cursor-pointer border-t border-emerald-200 hover:bg-emerald-50/60" onClick={() => { setPickedName(r.person.name); setPersonId(String(r.person.id)); }}>
                        <td className="border-r border-emerald-100 px-3 py-2 font-medium">{r.person.name}</td>
                        <td className="whitespace-nowrap border-r border-emerald-100 px-3 py-2">{[r.person.phone, r.person.alt_phone].filter(Boolean).join(" / ")}</td>
                        <td className="border-r border-emerald-100 px-3 py-2 text-right">{money(r.opening_balance)}</td>
                        <td className="border-r border-emerald-100 px-3 py-2 text-right text-amber-700 print:text-black">{money(r.charge)}</td>
                        <td className="border-r border-emerald-100 px-3 py-2 text-right text-emerald-700 print:text-black">{money(r.paid)}</td>
                        <td className="border-r border-emerald-100 px-3 py-2 text-right font-semibold">{money(r.closing_balance)}</td>
                        <td className="border-r border-emerald-100 px-3 py-2 text-right">{r.transactions}</td>
                        <td className="whitespace-nowrap px-3 py-2 text-right print:hidden"><span className="rounded-lg border border-emerald-200 px-2.5 py-1 text-xs font-medium text-emerald-700">Statement</span></td>
                      </tr>
                    ))}
                    {!list.data.rows.length && <tr className="border-t border-emerald-200"><td colSpan={8} className="px-3 py-6 text-center text-slate-500">No transactions in this period.</td></tr>}
                  </tbody>
                  <tfoot>
                    <tr className="border-t-2 border-emerald-300 bg-emerald-50 font-semibold print:bg-slate-100">
                      <td className="px-3 py-2" colSpan={2}>Total</td>
                      <td className="px-3 py-2 text-right">{money(list.data.total_opening)}</td>
                      <td className="px-3 py-2 text-right">{money(list.data.total_charge)}</td>
                      <td className="px-3 py-2 text-right">{money(list.data.total_paid)}</td>
                      <td className="px-3 py-2 text-right">{money(list.data.total_closing)}</td>
                      <td className="px-3 py-2" />
                      <td className="px-3 py-2 print:hidden" />
                    </tr>
                  </tfoot>
                </table>
                </div>
              </div>
              <div className="print:hidden"><Pagination meta={listPg.meta} onPage={listPg.setPage} /></div>
              <p className="mt-2 hidden text-xs text-slate-500 print:block">Printed on {fmtDate(new Date().toISOString())}</p>
            </>
          )}
        </div>
      )}
      {!personId && view === "transaction" && (
        <div>
          <div className="mb-4 hidden border-b border-slate-300 pb-3 print:block">
            <p className="text-lg font-bold">{user?.shop?.name ?? "Vehicle Hub"}</p>
            <p className="text-base font-semibold">{t.title} · per transaction</p>
            <p className="text-xs text-slate-500">Period: {period}</p>
          </div>
          {txs.error && <ErrorText message={txs.error} />}
          {txs.loading && !txs.data && <p className="text-sm text-slate-500">Loading...</p>}
          {txs.data && (
            <>
              <div className="mb-4 grid grid-cols-2 gap-3 print:hidden">
                <Stat color="blue" label={side === "customer" ? "Sold" : "Bought"} value={money(txs.data.total_charge)} />
                <Stat color="green" label={side === "customer" ? "Received" : "Paid"} value={money(txs.data.total_paid)} />
              </div>
              <div className="rounded-xl bg-white/90 ring-1 ring-emerald-300 print:ring-slate-400">
                <MobileCards
                  tone={CARD_TONE}
                  empty="No transactions in this period."
                  items={txPg.items.map((r, i) => ({ key: i, title: r.description, sub: `${fmtDate(r.date)} · ${r.person}${r.vehicle ? ` · ${r.vehicle}` : ""}`, amount: money(r.charge || r.paid), fields: [[r.charge ? t.charge : t.paid, money(r.charge || r.paid)], ...(r.reference ? ([["Ref.", r.reference]] as [string, React.ReactNode][]) : [])], action: <div className="flex gap-1.5"><button type="button" onClick={() => setVoucher(r)} className="rounded-lg border border-emerald-200 px-3 py-1 text-xs font-medium text-emerald-700">View</button><button type="button" onClick={() => printVoucher(r, side, shopName)} className="inline-flex items-center gap-1 rounded-lg border border-sky-200 px-2.5 py-1 text-xs font-medium text-sky-700 hover:bg-sky-50"><Printer className="size-3.5" /> Print</button></div> }))}
                  totals={[[side === "customer" ? "Total sold" : "Total bought", money(txs.data.total_charge)], [side === "customer" ? "Total received" : "Total paid", money(txs.data.total_paid)]]}
                />
                <div className="hidden overflow-x-auto md:block print:block">
                  <table className="w-full text-sm">
                    <thead className="bg-gradient-to-r from-emerald-50 to-green-50 text-left text-xs uppercase tracking-wide text-emerald-700 print:bg-slate-100 print:text-slate-700">
                      <tr>
                        {["Date", t.party, "Details", "Vehicle", "Ref."].map((h) => <th key={h} className="whitespace-nowrap border-r border-emerald-200 px-3 py-2">{h}</th>)}
                        <th className="whitespace-nowrap border-r border-emerald-200 px-3 py-2 text-right">{t.charge}</th>
                        <th className="whitespace-nowrap border-r border-emerald-200 px-3 py-2 text-right">{t.paid}</th>
                        <th className="whitespace-nowrap px-3 py-2 text-center print:hidden">Action</th>
                      </tr>
                    </thead>
                    <tbody>
                      {txPg.items.map((r, i) => (
                        <tr key={i} className="border-t border-emerald-200">
                          <td className="whitespace-nowrap border-r border-emerald-100 px-3 py-2">{fmtDate(r.date)}</td>
                          <td className="border-r border-emerald-100 px-3 py-2 font-medium">{r.person}</td>
                          <td className="border-r border-emerald-100 px-3 py-2">{r.description}</td>
                          <td className="whitespace-nowrap border-r border-emerald-100 px-3 py-2">{r.vehicle ?? "-"}</td>
                          <td className="border-r border-emerald-100 px-3 py-2">{r.reference ?? ""}</td>
                          <td className="border-r border-emerald-100 px-3 py-2 text-right text-amber-700 print:text-black">{r.charge ? money(r.charge) : ""}</td>
                          <td className="border-r border-emerald-100 px-3 py-2 text-right text-emerald-700 print:text-black">{r.paid ? money(r.paid) : ""}</td>
                          <td className="whitespace-nowrap px-3 py-2 text-right print:hidden"><div className="flex justify-end gap-1.5"><button type="button" onClick={() => setVoucher(r)} className="rounded-lg border border-emerald-200 px-2.5 py-1 text-xs font-medium text-emerald-700 hover:bg-emerald-50">View</button><button type="button" onClick={() => printVoucher(r, side, shopName)} className="inline-flex items-center gap-1 rounded-lg border border-sky-200 px-2.5 py-1 text-xs font-medium text-sky-700 hover:bg-sky-50"><Printer className="size-3.5" /> Print</button></div></td>
                        </tr>
                      ))}
                      {!txs.data.rows.length && <tr className="border-t border-emerald-200"><td colSpan={8} className="px-3 py-6 text-center text-slate-500">No transactions in this period.</td></tr>}
                    </tbody>
                    <tfoot>
                      <tr className="border-t-2 border-emerald-300 bg-emerald-50 font-semibold print:bg-slate-100">
                        <td className="px-3 py-2" colSpan={5}>Total</td>
                        <td className="px-3 py-2 text-right">{money(txs.data.total_charge)}</td>
                        <td className="px-3 py-2 text-right">{money(txs.data.total_paid)}</td>
                        <td className="px-3 py-2 print:hidden" />
                      </tr>
                    </tfoot>
                  </table>
                </div>
              </div>
              <div className="print:hidden"><Pagination meta={txPg.meta} onPage={txPg.setPage} /></div>
              <p className="mt-2 hidden text-xs text-slate-500 print:block">Printed on {fmtDate(new Date().toISOString())}</p>
            </>
          )}
        </div>
      )}
      {error && <ErrorText message={error} />}
      {loading && !data && <p className="text-sm text-slate-500">Loading...</p>}

      {data && (
        <div>
          <div className="mb-4 hidden border-b border-slate-300 pb-3 print:block">
            <p className="text-lg font-bold">{user?.shop?.name ?? "Vehicle Hub"}</p>
            <p className="text-base font-semibold">{t.title}</p>
          </div>

          <div className="mb-4 rounded-xl bg-white/90 p-4 ring-1 ring-emerald-300 print:bg-transparent print:ring-0 print:p-0">
            <p className="text-lg font-semibold text-slate-900">{data.person.name}</p>
            <p className="text-sm text-slate-600">{[data.person.phone, data.person.alt_phone].filter(Boolean).join(" / ")}{data.person.address ? ` · ${data.person.address}` : ""}</p>
            <p className="mt-1 text-xs text-slate-500">Period: {period}</p>
            <p className="mt-1 hidden text-sm font-semibold print:block">{closing < 0 ? t.ahead : t.owed}: {money(Math.abs(closing))}</p>
          </div>

          <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4 print:hidden">
            <Stat color="slate" label="Opening balance" value={money(data.opening_balance)} />
            <Stat color="blue" label={side === "customer" ? "Sold" : "Bought"} value={money(data.total_charge)} />
            <Stat color="green" label={side === "customer" ? "Received" : "Paid"} value={money(data.total_paid)} />
            <Stat color="amber" label={closing < 0 ? t.ahead : t.owed} value={money(Math.abs(closing))} />
          </div>

          <div className="rounded-xl bg-white/90 ring-1 ring-emerald-300 print:ring-1 print:ring-slate-400">
            <MobileCards
              tone={CARD_TONE}
              empty="No transactions in this period."
              items={[
                { key: "open", title: "Opening balance", amount: money(data.opening_balance), fields: [] },
                ...stPg.items.map((r, i) => ({ key: i, title: r.description, sub: `${fmtDate(r.date)}${r.vehicle ? ` · ${r.vehicle}` : ""}`, amount: money(r.balance), fields: [...(r.charge ? ([[t.charge, money(r.charge)]] as [string, React.ReactNode][]) : []), ...(r.paid ? ([[t.paid, money(r.paid)]] as [string, React.ReactNode][]) : []), ...(r.reference ? ([["Ref.", r.reference]] as [string, React.ReactNode][]) : [])] , action: <div className="flex gap-1.5"><button type="button" onClick={() => setVoucher(voucherOf(r))} className="rounded-lg border border-emerald-200 px-2.5 py-1 text-xs font-medium text-emerald-700 hover:bg-emerald-50">View</button><button type="button" onClick={() => printVoucher(voucherOf(r), side, shopName)} className="inline-flex items-center gap-1 rounded-lg border border-sky-200 px-2.5 py-1 text-xs font-medium text-sky-700 hover:bg-sky-50"><Printer className="size-3.5" /> Print</button></div> })),
              ]}
              totals={[[side === "customer" ? "Total sold" : "Total bought", money(data.total_charge)], [side === "customer" ? "Total received" : "Total paid", money(data.total_paid)], ["Balance", money(data.closing_balance)]]}
            />
            <div className="hidden overflow-x-auto md:block print:block">
            <table className="w-full text-sm">
              <thead className="bg-gradient-to-r from-emerald-50 to-green-50 text-left text-xs uppercase tracking-wide text-emerald-700 print:bg-slate-100 print:text-slate-700">
                <tr>
                  {["Date", "Details", "Vehicle", "Ref."].map((h) => <th key={h} className="whitespace-nowrap border-r border-emerald-200 px-3 py-2">{h}</th>)}
                  <th className="whitespace-nowrap border-r border-emerald-200 px-3 py-2 text-right">{t.charge}</th>
                  <th className="whitespace-nowrap border-r border-emerald-200 px-3 py-2 text-right">{t.paid}</th>
                  <th className="whitespace-nowrap border-r border-emerald-200 px-3 py-2 text-right">{t.balance}</th>
                  <th className="whitespace-nowrap px-3 py-2 text-center print:hidden">Action</th>
                </tr>
              </thead>
              <tbody>
                <tr className="border-t border-emerald-200 bg-slate-50 text-slate-600">
                  <td className="border-r border-emerald-100 px-3 py-2" colSpan={4}>Opening balance</td>
                  <td className="border-r border-emerald-100 px-3 py-2" />
                  <td className="border-r border-emerald-100 px-3 py-2" />
                  <td className="border-r border-emerald-100 px-3 py-2 text-right font-medium">{money(data.opening_balance)}</td>
                  <td className="px-3 py-2 print:hidden" />
                </tr>
                {stPg.items.map((r, i) => (
                  <tr key={i} className="border-t border-emerald-200">
                    <td className="whitespace-nowrap border-r border-emerald-100 px-3 py-2">{fmtDate(r.date)}</td>
                    <td className="border-r border-emerald-100 px-3 py-2">{r.description}</td>
                    <td className="whitespace-nowrap border-r border-emerald-100 px-3 py-2">{r.vehicle ?? "-"}</td>
                    <td className="border-r border-emerald-100 px-3 py-2">{r.reference ?? ""}</td>
                    <td className="border-r border-emerald-100 px-3 py-2 text-right text-amber-700 print:text-black">{r.charge ? money(r.charge) : ""}</td>
                    <td className="border-r border-emerald-100 px-3 py-2 text-right text-emerald-700 print:text-black">{r.paid ? money(r.paid) : ""}</td>
                    <td className="border-r border-emerald-100 px-3 py-2 text-right font-medium">{money(r.balance)}</td>
                    <td className="whitespace-nowrap px-3 py-2 text-right print:hidden"><div className="flex justify-end gap-1.5"><button type="button" onClick={() => setVoucher(voucherOf(r))} className="rounded-lg border border-emerald-200 px-2.5 py-1 text-xs font-medium text-emerald-700 hover:bg-emerald-50">View</button><button type="button" onClick={() => printVoucher(voucherOf(r), side, shopName)} className="inline-flex items-center gap-1 rounded-lg border border-sky-200 px-2.5 py-1 text-xs font-medium text-sky-700 hover:bg-sky-50"><Printer className="size-3.5" /> Print</button></div></td>
                  </tr>
                ))}
                {!data.rows.length && <tr className="border-t border-emerald-200"><td colSpan={8} className="px-3 py-6 text-center text-slate-500">No transactions in this period.</td></tr>}
              </tbody>
              <tfoot>
                <tr className="border-t-2 border-emerald-300 bg-emerald-50 font-semibold print:bg-slate-100">
                  <td className="px-3 py-2" colSpan={4}>Total</td>
                  <td className="px-3 py-2 text-right">{money(data.total_charge)}</td>
                  <td className="px-3 py-2 text-right">{money(data.total_paid)}</td>
                  <td className="px-3 py-2 text-right">{money(data.closing_balance)}</td>
                  <td className="px-3 py-2 print:hidden" />
                </tr>
              </tfoot>
            </table>
            </div>
          </div>
          <div className="print:hidden"><Pagination meta={stPg.meta} onPage={stPg.setPage} /></div>
          <p className="mt-2 hidden text-xs text-slate-500 print:block">Printed on {fmtDate(new Date().toISOString())}</p>
        </div>
      )}
    <TransactionVoucher tx={voucher} side={side} shop={shopName} onClose={() => setVoucher(null)} />
    </>
  );
}
