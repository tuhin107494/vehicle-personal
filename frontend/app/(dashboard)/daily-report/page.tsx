"use client";

import { createContext, useContext, useState } from "react";
import { ChevronLeft, ChevronRight, Printer } from "lucide-react";
import { fmtDate, label, money } from "@/lib/format";
import { useFetch } from "@/lib/hooks";
import { useAuth } from "@/lib/auth";
import { Button, ErrorText, inputCls, PageHeader, Pagination } from "@/components/app-ui";
import { Input } from "@/components/ui/input";
import { MobileCards, type CardItem } from "@/components/MobileCards";
import { usePaging } from "@/lib/paging";

type Sale = { id: number; vehicle: string; vehicle_name: string; name: string; phone: string; payment_type: string; total: number; received: number; due: number };
type Purchase = { id: number; vehicle: string; vehicle_name: string; name: string; phone: string; memo_no: string | null; total: number; paid: number; due: number };
type Collection = { id: number; sale_id: number; name: string; phone: string; vehicle: string; method: string; amount: number; note: string | null };
type SupplierPay = { name: string; phone: string; vehicle: string | null; kind: string; method: string; amount: number; reference: string | null };
type InvestorTx = { id: number; investor: string; phone: string | null; type: string; amount: number; direction: "in" | "out"; note: string | null };
type Daily = {
  date: string;
  sales: { rows: Sale[]; count: number; total: number; received: number; due: number };
  purchases: { rows: Purchase[]; count: number; total: number; paid: number; due: number };
  collections: { rows: Collection[]; count: number; total: number };
  booking_advances: { count: number; total: number };
  booking_refunds: { count: number; total: number };
  supplier_payments: { rows: SupplierPay[]; count: number; total: number };
  investor_received: { rows: InvestorTx[]; count: number; total: number };
  investor_paid: { rows: InvestorTx[]; count: number; total: number };
  cash: { in: number; out: number; net: number };
};

const MODULES: Tone[] = ["sales", "purchases", "collection", "supplier", "investor", "investor_paid"];

const todayStr = () => new Date().toLocaleDateString("en-CA"); // the shop's local day, not UTC

const shift = (date: string, days: number) => {
  const d = new Date(`${date}T00:00:00`);
  d.setDate(d.getDate() + days);
  return d.toLocaleDateString("en-CA");
};

// One clearly different colour per module (no red).
const TONE = {
  sales: { ring: "ring-emerald-300", head: "bg-emerald-50 text-emerald-800", foot: "border-emerald-300 bg-emerald-50", rule: "border-emerald-200", cell: "border-emerald-100", dot: "bg-emerald-500", title: "text-emerald-800" },
  purchases: { ring: "ring-blue-300", head: "bg-blue-50 text-blue-800", foot: "border-blue-300 bg-blue-50", rule: "border-blue-200", cell: "border-blue-100", dot: "bg-blue-500", title: "text-blue-800" },
  collection: { ring: "ring-violet-300", head: "bg-violet-50 text-violet-800", foot: "border-violet-300 bg-violet-50", rule: "border-violet-200", cell: "border-violet-100", dot: "bg-violet-500", title: "text-violet-800" },
  investor_paid: { ring: "ring-yellow-400", head: "bg-yellow-50 text-yellow-800", foot: "border-yellow-400 bg-yellow-50", rule: "border-yellow-300", cell: "border-yellow-100", dot: "bg-yellow-500", title: "text-yellow-800" },
  investor: { ring: "ring-cyan-300", head: "bg-cyan-50 text-cyan-800", foot: "border-cyan-300 bg-cyan-50", rule: "border-cyan-200", cell: "border-cyan-100", dot: "bg-cyan-500", title: "text-cyan-800" },
  supplier: { ring: "ring-orange-300", head: "bg-orange-50 text-orange-800", foot: "border-orange-300 bg-orange-50", rule: "border-orange-200", cell: "border-orange-100", dot: "bg-orange-500", title: "text-orange-800" },
  cash: { ring: "ring-slate-300", head: "bg-slate-50 text-slate-800", foot: "border-slate-300 bg-slate-50", rule: "border-slate-200", cell: "border-slate-100", dot: "bg-slate-500", title: "text-slate-800" },
};
type Tone = keyof typeof TONE;

// which modules have their details open; everything starts as an overview
const OpenCtx = createContext<Partial<Record<Tone, boolean>>>({});

function Section({ title, count, tone, cards, pager, children }: { title: string; count: number; tone: Tone; pager: { meta: { current_page: number; last_page: number; per_page: number; total: number }; setPage: (p: number) => void }; cards: { items: CardItem[]; empty: string; totals: [string, React.ReactNode][] }; children: React.ReactNode }) {
  const t = TONE[tone];
  const open = useContext(OpenCtx);
  if (!open[tone]) return null;
  return (
    <section className="mb-5 break-inside-avoid">
      <h2 className={`mb-2 flex items-center gap-2 text-base font-semibold ${t.title}`}><span className={`size-2.5 rounded-full ${t.dot}`} />{title} <span className="text-sm font-normal text-slate-500">({count})</span></h2>
      <div className={`rounded-xl bg-white/90 ring-1 ${t.ring} print:ring-slate-400`}>
        <MobileCards tone={t} {...cards} />
        <div className="hidden md:block print:block">{children}</div>
      </div>
      <div className="print:hidden"><Pagination meta={pager.meta} onPage={pager.setPage} /></div>
    </section>
  );
}

const th = (t: Tone) => `whitespace-nowrap border-r ${TONE[t].rule} px-3 py-2 last:border-r-0`;
const td = (t: Tone) => `border-r ${TONE[t].cell} px-3 py-2 last:border-r-0`;
const head = (t: Tone) => `${TONE[t].head} text-left text-xs uppercase tracking-wide print:bg-slate-100 print:text-slate-700`;
const foot = (t: Tone) => `border-t-2 ${TONE[t].foot} font-semibold print:bg-slate-100`;
const empty = (t: Tone, cols: number, text: string) => <tr className={`border-t ${TONE[t].rule}`}><td colSpan={cols} className="px-3 py-5 text-center text-slate-500">{text}</td></tr>;

export default function DailyReportPage() {
  const { user } = useAuth();
  const [date, setDate] = useState(todayStr());
  const [open, setOpen] = useState<Partial<Record<Tone, boolean>>>({});
  const { data, loading, error } = useFetch<Daily>(`/reports/daily?date=${date}`);
  const [printing, setPrinting] = useState(false); // while printing every row shows, not just this page
  const pg = {
    sales: usePaging(data?.sales.rows, printing),
    purchases: usePaging(data?.purchases.rows, printing),
    collections: usePaging(data?.collections.rows, printing),
    supplier: usePaging(data?.supplier_payments.rows, printing),
    received: usePaging(data?.investor_received.rows, printing),
    paid: usePaging(data?.investor_paid.rows, printing),
  };

  const allOpen = MODULES.every((m) => open[m]);

  const print = () => {
    const old = document.title;
    document.title = `Daily report - ${date}`;
    setPrinting(true);
    setTimeout(() => {
      window.print();
      document.title = old;
      setPrinting(false);
    }, 150);
  };

  return (
    <>
      <div className="print:hidden"><PageHeader title="Daily report" /></div>
      <div className="mb-4 flex flex-wrap items-end gap-2 print:hidden">
        <Button variant="outline" onClick={() => setDate(shift(date, -1))}><ChevronLeft className="size-4" /> Previous</Button>
        <Input type="date" className={`${inputCls} w-44`} value={date} max={todayStr()} onChange={(e) => e.target.value && setDate(e.target.value)} />
        <Button variant="outline" disabled={date >= todayStr()} onClick={() => setDate(shift(date, 1))}>Next <ChevronRight className="size-4" /></Button>
        {date !== todayStr() && <Button variant="outline" onClick={() => setDate(todayStr())}>Today</Button>}
        <Button variant="outline" disabled={!data} onClick={() => setOpen(allOpen ? {} : Object.fromEntries(MODULES.map((m) => [m, true])))}>{allOpen ? "Hide all details" : "Show all details"}</Button>
        <Button disabled={!data} onClick={print}><Printer className="size-4" /> Print / PDF</Button>
      </div>

      {error && <ErrorText message={error} />}
      {loading && !data && <p className="text-sm text-slate-500">Loading...</p>}

      {data && (
        <OpenCtx.Provider value={open}>
        <div>
          <div className="mb-4 hidden border-b border-slate-300 pb-3 print:block">
            <p className="text-lg font-bold">{user?.shop?.name ?? "Vehicle Hub"}</p>
            <p className="text-base font-semibold">Daily report · {fmtDate(data.date)}</p>
          </div>
          <p className="mb-3 text-sm text-slate-600 print:hidden">{fmtDate(data.date)}</p>

          <div className="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 print:hidden">
            {([
              { tone: "sales", title: "Sales", count: data.sales.count, main: money(data.sales.total), lines: [["Received", money(data.sales.received)], ["Due", money(data.sales.due)]] },
              { tone: "purchases", title: "Purchases", count: data.purchases.count, main: money(data.purchases.total), lines: [["Paid", money(data.purchases.paid)], ["Unpaid now", money(data.purchases.due)]] },
              { tone: "collection", title: "Due collection", count: data.collections.count, main: money(data.collections.total), lines: [...(data.booking_advances.count ? [["Booking advance received", money(data.booking_advances.total)]] : []), ...(data.booking_refunds.count ? [["Booking advance refunded", money(data.booking_refunds.total)]] : [])] as [string, string][] },
              { tone: "supplier", title: "Supplier transactions (paid)", count: data.supplier_payments.count, main: money(data.supplier_payments.total), lines: [] },
              { tone: "investor", title: "Received from investor", count: data.investor_received.count, main: money(data.investor_received.total), lines: [] },
              { tone: "investor_paid", title: "Paid to investor", count: data.investor_paid.count, main: money(data.investor_paid.total), lines: [] },
            ] as { tone: Tone; title: string; count: number; main: string; lines: [string, string][] }[]).map((m) => {
              const t = TONE[m.tone];
              const isOpen = !!open[m.tone];
              return (
                <div key={m.tone} className={`rounded-xl bg-white/90 p-4 ring-1 ${t.ring}`}>
                  <div className={`flex items-center gap-2 text-sm font-semibold ${t.title}`}><span className={`size-2.5 rounded-full ${t.dot}`} />{m.title} <span className="font-normal text-slate-500">({m.count})</span></div>
                  <p className="mt-1 text-2xl font-bold text-slate-900">{m.main}</p>
                  {m.lines.length > 0 && <p className="mt-1 text-xs text-slate-600">{m.lines.map(([k, v]) => `${k} ${v}`).join(" · ")}</p>}
                  <button type="button" disabled={m.count === 0} onClick={() => setOpen({ ...open, [m.tone]: !isOpen })} className={`mt-3 rounded-lg border px-3 py-1 text-xs font-medium ${t.rule} ${t.title} disabled:opacity-40`}>
                    {m.count === 0 ? "No entries" : isOpen ? "Hide details" : "Show details"}
                  </button>
                </div>
              );
            })}
          </div>

          <table className="mb-4 hidden w-full text-sm print:table">
            <thead><tr className="border-b border-slate-400 text-left text-xs uppercase"><th className="py-1">Module</th><th className="py-1 text-right">Entries</th><th className="py-1 text-right">Amount</th></tr></thead>
            <tbody>
              {[["Sales", data.sales.count, data.sales.total], ["Purchases", data.purchases.count, data.purchases.total], ["Due collection", data.collections.count, data.collections.total], ["Supplier transactions (paid)", data.supplier_payments.count, data.supplier_payments.total], ["Received from investor", data.investor_received.count, data.investor_received.total], ["Paid to investor", data.investor_paid.count, data.investor_paid.total]].map(([k, c, a]) => (
                <tr key={k as string} className="border-b border-slate-200"><td className="py-1">{k}</td><td className="py-1 text-right">{c}</td><td className="py-1 text-right">{money(a as number)}</td></tr>
              ))}
            </tbody>
          </table>

          <Section tone="sales" pager={pg.sales} cards={{
              items: pg.sales.items.map((r) => ({ key: r.id, title: r.vehicle_name || r.vehicle, sub: `#${r.id} · ${r.vehicle}`, amount: money(r.total), fields: [["Customer", `${r.name} · ${r.phone}`], ["Type", <span key="t" className="capitalize">{r.payment_type}</span>], ["Received", money(r.received)], ["Due", money(r.due)]] })),
              empty: "No sales on this day.",
              totals: [["Total", money(data.sales.total)], ["Received", money(data.sales.received)], ["Due", money(data.sales.due)]],
            }} title="Sales" count={data.sales.count}>
            <table className="w-full text-sm">
              <thead className={head("sales")}><tr><th className={th("sales")}>Sale</th><th className={th("sales")}>Vehicle</th><th className={th("sales")}>Customer</th><th className={th("sales")}>Type</th><th className={`${th("sales")} text-right`}>Total</th><th className={`${th("sales")} text-right`}>Received</th><th className={`${th("sales")} text-right`}>Due</th></tr></thead>
              <tbody>
                {pg.sales.items.map((r) => (
                  <tr key={r.id} className="border-t border-emerald-200">
                    <td className={td("sales")}>#{r.id}</td>
                    <td className={td("sales")}>{r.vehicle_name || r.vehicle}<div className="text-xs text-slate-500">{r.vehicle}</div></td>
                    <td className={td("sales")}>{r.name}<div className="text-xs text-slate-500">{r.phone}</div></td>
                    <td className={`${td("sales")} capitalize`}>{r.payment_type}</td>
                    <td className={`${td("sales")} text-right`}>{money(r.total)}</td>
                    <td className={`${td("sales")} text-right`}>{money(r.received)}</td>
                    <td className={`${td("sales")} text-right font-medium`}>{money(r.due)}</td>
                  </tr>
                ))}
                {!data.sales.rows.length && empty("sales", 7, "No sales on this day.")}
              </tbody>
              <tfoot><tr className={foot("sales")}><td className="px-3 py-2" colSpan={4}>Total</td><td className="px-3 py-2 text-right">{money(data.sales.total)}</td><td className="px-3 py-2 text-right">{money(data.sales.received)}</td><td className="px-3 py-2 text-right">{money(data.sales.due)}</td></tr></tfoot>
            </table>
          </Section>

          <Section tone="purchases" pager={pg.purchases} cards={{
              items: pg.purchases.items.map((r) => ({ key: r.id, title: r.vehicle_name || r.vehicle, sub: r.vehicle, amount: money(r.total), fields: [["Bought from", `${r.name} · ${r.phone}`], ...(r.memo_no ? ([["Memo", r.memo_no]] as [string, React.ReactNode][]) : []), ["Paid", money(r.paid)], ["Unpaid now", money(r.due)]] })),
              empty: "No purchases on this day.",
              totals: [["Total", money(data.purchases.total)], ["Paid", money(data.purchases.paid)], ["Unpaid now", money(data.purchases.due)]],
            }} title="Purchases" count={data.purchases.count}>
            <table className="w-full text-sm">
              <thead className={head("purchases")}><tr><th className={th("purchases")}>Vehicle</th><th className={th("purchases")}>Bought from</th><th className={th("purchases")}>Memo</th><th className={`${th("purchases")} text-right`}>Price</th><th className={`${th("purchases")} text-right`}>Paid</th><th className={`${th("purchases")} text-right`}>Unpaid now</th></tr></thead>
              <tbody>
                {pg.purchases.items.map((r) => (
                  <tr key={r.id} className="border-t border-emerald-200">
                    <td className={td("purchases")}>{r.vehicle_name || r.vehicle}<div className="text-xs text-slate-500">{r.vehicle}</div></td>
                    <td className={td("purchases")}>{r.name}<div className="text-xs text-slate-500">{r.phone}</div></td>
                    <td className={td("purchases")}>{r.memo_no ?? "-"}</td>
                    <td className={`${td("purchases")} text-right`}>{money(r.total)}</td>
                    <td className={`${td("purchases")} text-right`}>{money(r.paid)}</td>
                    <td className={`${td("purchases")} text-right font-medium`}>{money(r.due)}</td>
                  </tr>
                ))}
                {!data.purchases.rows.length && empty("purchases", 6, "No purchases on this day.")}
              </tbody>
              <tfoot><tr className={foot("purchases")}><td className="px-3 py-2" colSpan={3}>Total</td><td className="px-3 py-2 text-right">{money(data.purchases.total)}</td><td className="px-3 py-2 text-right">{money(data.purchases.paid)}</td><td className="px-3 py-2 text-right">{money(data.purchases.due)}</td></tr></tfoot>
            </table>
          </Section>

          <Section tone="collection" pager={pg.collections} cards={{
              items: pg.collections.items.map((r) => ({ key: r.id, title: r.name, sub: `${r.phone} · ${r.vehicle}`, amount: money(r.amount), fields: [["Method", <span key="m" className="capitalize">{r.method}</span>], ...(r.note ? ([["Note", r.note]] as [string, React.ReactNode][]) : [])] })),
              empty: "No due collected on this day.",
              totals: [["Total", money(data.collections.total)]],
            }} title="Due collection (from customers)" count={data.collections.count}>
            <table className="w-full text-sm">
              <thead className={head("collection")}><tr><th className={th("collection")}>Customer</th><th className={th("collection")}>Vehicle</th><th className={th("collection")}>Method</th><th className={th("collection")}>Note</th><th className={`${th("collection")} text-right`}>Amount</th></tr></thead>
              <tbody>
                {pg.collections.items.map((r) => (
                  <tr key={r.id} className="border-t border-emerald-200">
                    <td className={td("collection")}>{r.name}<div className="text-xs text-slate-500">{r.phone}</div></td>
                    <td className={td("collection")}>{r.vehicle}</td>
                    <td className={`${td("collection")} capitalize`}>{r.method}</td>
                    <td className={td("collection")}>{r.note ?? ""}</td>
                    <td className={`${td("collection")} text-right font-medium`}>{money(r.amount)}</td>
                  </tr>
                ))}
                {!data.collections.rows.length && empty("collection", 5, "No due collected on this day.")}
              </tbody>
              <tfoot><tr className={foot("collection")}><td className="px-3 py-2" colSpan={4}>Total</td><td className="px-3 py-2 text-right">{money(data.collections.total)}</td></tr></tfoot>
            </table>
          </Section>

          <Section tone="supplier" pager={pg.supplier} cards={{
              items: pg.supplier.items.map((r, i) => ({ key: i, title: r.name, sub: `${r.phone}${r.vehicle ? ` · ${r.vehicle}` : ""}`, amount: money(r.amount), fields: [["Kind", r.kind], ["Method", <span key="m" className="capitalize">{r.method}</span>], ...(r.reference ? ([["Ref.", r.reference]] as [string, React.ReactNode][]) : [])] })),
              empty: "No supplier payments on this day.",
              totals: [["Total", money(data.supplier_payments.total)]],
            }} title="Supplier transactions (paid)" count={data.supplier_payments.count}>
            <table className="w-full text-sm">
              <thead className={head("supplier")}><tr><th className={th("supplier")}>Supplier</th><th className={th("supplier")}>Vehicle</th><th className={th("supplier")}>Kind</th><th className={th("supplier")}>Method</th><th className={th("supplier")}>Ref.</th><th className={`${th("supplier")} text-right`}>Amount</th></tr></thead>
              <tbody>
                {pg.supplier.items.map((r, i) => (
                  <tr key={i} className="border-t border-emerald-200">
                    <td className={td("supplier")}>{r.name}<div className="text-xs text-slate-500">{r.phone}</div></td>
                    <td className={td("supplier")}>{r.vehicle ?? "-"}</td>
                    <td className={td("supplier")}>{r.kind}</td>
                    <td className={`${td("supplier")} capitalize`}>{r.method}</td>
                    <td className={td("supplier")}>{r.reference ?? ""}</td>
                    <td className={`${td("supplier")} text-right font-medium`}>{money(r.amount)}</td>
                  </tr>
                ))}
                {!data.supplier_payments.rows.length && empty("supplier", 6, "No supplier payments on this day.")}
              </tbody>
              <tfoot><tr className={foot("supplier")}><td className="px-3 py-2" colSpan={5}>Total</td><td className="px-3 py-2 text-right">{money(data.supplier_payments.total)}</td></tr></tfoot>
            </table>
          </Section>

          <Section
            tone="investor"
            pager={pg.received}
            title="Received from investor (investment)"
            count={data.investor_received.count}
            cards={{
              items: pg.received.items.map((r) => ({ key: r.id, title: r.investor, sub: r.phone ?? undefined, amount: money(r.amount), fields: [["Type", <span key="t" className="capitalize">{label(r.type)}</span>], ...(r.note ? ([["Note", r.note]] as [string, React.ReactNode][]) : [])] })),
              empty: "No money received from investors on this day.",
              totals: [["Total received", money(data.investor_received.total)]],
            }}
          >
            <table className="w-full text-sm">
              <thead className={head("investor")}><tr><th className={th("investor")}>Investor</th><th className={th("investor")}>Type</th><th className={th("investor")}>Note</th><th className={`${th("investor")} text-right`}>Amount</th></tr></thead>
              <tbody>
                {pg.received.items.map((r) => (
                  <tr key={r.id} className={`border-t ${TONE.investor.rule}`}>
                    <td className={td("investor")}>{r.investor}<div className="text-xs text-slate-500">{r.phone}</div></td>
                    <td className={`${td("investor")} capitalize`}>{label(r.type)}</td>
                    <td className={td("investor")}>{r.note ?? ""}</td>
                    <td className={`${td("investor")} text-right font-medium`}>{money(r.amount)}</td>
                  </tr>
                ))}
                {!data.investor_received.rows.length && empty("investor", 4, "No money received from investors on this day.")}
              </tbody>
              <tfoot><tr className={foot("investor")}><td className="px-3 py-2" colSpan={3}>Total</td><td className="px-3 py-2 text-right">{money(data.investor_received.total)}</td></tr></tfoot>
            </table>
          </Section>

          <Section
            tone="investor_paid"
            pager={pg.paid}
            title="Paid to investor (installment)"
            count={data.investor_paid.count}
            cards={{
              items: pg.paid.items.map((r) => ({ key: r.id, title: r.investor, sub: r.phone ?? undefined, amount: money(r.amount), fields: [["Type", <span key="t" className="capitalize">{label(r.type)}</span>], ...(r.note ? ([["Note", r.note]] as [string, React.ReactNode][]) : [])] })),
              empty: "No money paid to investors on this day.",
              totals: [["Total paid", money(data.investor_paid.total)]],
            }}
          >
            <table className="w-full text-sm">
              <thead className={head("investor_paid")}><tr><th className={th("investor_paid")}>Investor</th><th className={th("investor_paid")}>Type</th><th className={th("investor_paid")}>Note</th><th className={`${th("investor_paid")} text-right`}>Amount</th></tr></thead>
              <tbody>
                {pg.paid.items.map((r) => (
                  <tr key={r.id} className={`border-t ${TONE.investor_paid.rule}`}>
                    <td className={td("investor_paid")}>{r.investor}<div className="text-xs text-slate-500">{r.phone}</div></td>
                    <td className={`${td("investor_paid")} capitalize`}>{label(r.type)}</td>
                    <td className={td("investor_paid")}>{r.note ?? ""}</td>
                    <td className={`${td("investor_paid")} text-right font-medium`}>{money(r.amount)}</td>
                  </tr>
                ))}
                {!data.investor_paid.rows.length && empty("investor_paid", 4, "No money paid to investors on this day.")}
              </tbody>
              <tfoot><tr className={foot("investor_paid")}><td className="px-3 py-2" colSpan={3}>Total</td><td className="px-3 py-2 text-right">{money(data.investor_paid.total)}</td></tr></tfoot>
            </table>
          </Section>

          <section className={`rounded-xl bg-white/90 p-4 ring-1 ${TONE.cash.ring} print:ring-slate-400`}>
            <h2 className={`mb-2 flex items-center gap-2 text-base font-semibold ${TONE.cash.title}`}><span className={`size-2.5 rounded-full ${TONE.cash.dot}`} />Cash for the day</h2>
            <dl className="grid gap-3 text-sm sm:grid-cols-3">
              <div><dt className="text-slate-500">In (sale + due collected + investment)</dt><dd className="text-lg font-bold text-emerald-700 print:text-black">{money(data.cash.in)}</dd></div>
              <div><dt className="text-slate-500">Out (purchase + suppliers + investors)</dt><dd className="text-lg font-bold text-amber-700 print:text-black">{money(data.cash.out)}</dd></div>
              <div><dt className="text-slate-500">Net</dt><dd className="text-lg font-bold">{money(data.cash.net)}</dd></div>
            </dl>
          </section>
          <p className="mt-2 hidden text-xs text-slate-500 print:block">Printed on {fmtDate(new Date().toISOString())}</p>
        </div>
        </OpenCtx.Provider>
      )}
    </>
  );
}
