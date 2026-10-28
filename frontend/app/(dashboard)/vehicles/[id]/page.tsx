"use client";

import Link from "next/link";
import { useState } from "react";
import { useParams, useSearchParams } from "next/navigation";
import { ChevronDown } from "lucide-react";
import { fmtDate, money } from "@/lib/format";
import { useCategories } from "@/lib/categories";
import { useFetch } from "@/lib/hooks";
import { useAuth } from "@/lib/auth";
import type { Payment, Purchase, Sale, Vehicle } from "@/lib/types";
import { DataTable, type Col } from "@/components/DataTable";
import { Badge, Button, Card, ErrorText, KV, Loading, PageHeader, StatusBadge } from "@/components/app-ui";

type Event = { type: string; date: string; title: string; amount: number | null; ref_id: number; how?: string | null };

export default function VehicleDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { can } = useAuth();
  const { nameOf } = useCategories();
  // View opens the vehicle's details; History (?tab=history) opens its purchase and sale chain
  const history = useSearchParams().get("tab") === "history";
  const [open, setOpen] = useState<Record<string, boolean>>({}); // which chain steps are expanded
  const { data, loading, error } = useFetch<{ vehicle: Vehicle; sales: Sale[]; purchases: Purchase[]; timeline: Event[] }>(`/vehicles/${id}/history`);

  if (loading && !data) return <Loading />;
  if (error || !data) return <ErrorText message={error ?? "Not found"} />;
  const { vehicle: v, timeline, sales, purchases } = data;

  // Purchases and sales in the order they happened: bought, sold, returned (bought back), sold again...
  type Step = { kind: "purchase"; at: string; id: number; p: Purchase } | { kind: "sale"; at: string; id: number; s: Sale };
  const steps: Step[] = [
    ...purchases.map((p): Step => ({ kind: "purchase", at: p.purchase_date, id: p.id, p })),
    ...sales.map((x): Step => ({ kind: "sale", at: x.sale_date, id: x.id, s: x })),
  ].sort((a, b) => a.at.localeCompare(b.at) || (a.kind === b.kind ? a.id - b.id : a.kind === "purchase" ? -1 : 1));

  const paymentCols: Col<Payment>[] = [
    { header: "Date", cell: ({ row }) => fmtDate(row.original.paid_at) },
    { header: "Method", cell: ({ row }) => <Badge tone="gray">{row.original.method}</Badge> },
    { header: "Note", cell: ({ row }) => [row.original.reference && `Ref ${row.original.reference}`, row.original.note].filter(Boolean).join(" · ") || "-" },
    { header: "Amount", cell: ({ row }) => <span className="font-semibold">{money(row.original.amount)}</span> },
  ];

  const Facts = ({ items }: { items: [string, React.ReactNode][] }) => {
    const shown = items.filter(([, v]) => v !== null && v !== undefined && v !== "");
    if (!shown.length) return null;
    return (
    <dl className="grid gap-x-4 gap-y-1 sm:grid-cols-2">
      {shown.map(([k, v]) => (
        <div key={k} className="flex gap-2"><dt className="w-28 shrink-0 text-gray-500">{k}</dt><dd className="font-medium text-slate-800">{v}</dd></div>
      ))}
    </dl>
    );
  };

  return (
    <>
      <PageHeader
        title={[v.brand, v.model, v.year].filter(Boolean).join(" ") || v.chassis_no}
        actions={v.status === "sold" ? !can("purchases.create") ? null : <Link className="inline-flex items-center gap-1.5 rounded-lg bg-gradient-to-r from-emerald-600 to-green-600 px-3 py-2 text-sm font-medium text-white shadow-sm hover:from-emerald-500 hover:to-green-500" href={`/purchases/new?chassis=${encodeURIComponent(v.chassis_no)}`}>Repurchase</Link> : v.status === "in_stock" ? !can("sales.create") ? null : <Link className="inline-flex items-center gap-1.5 rounded-lg bg-gradient-to-r from-emerald-600 to-green-600 px-3 py-2 text-sm font-medium text-white shadow-sm hover:from-emerald-500 hover:to-green-500" href={`/sales/new?vehicle=${v.id}`}>Sell</Link> : null}
      />
      <div className="mb-4 flex gap-2">
        <Button variant={history ? "outline" : "primary"} onClick={() => window.history.replaceState(null, "", `/vehicles/${id}`)}>Details</Button>
        <Button variant={history ? "primary" : "outline"} onClick={() => window.history.replaceState(null, "", `/vehicles/${id}?tab=history`)}>History</Button>
      </div>
      <Card className="mb-4">
        <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
          <span className="font-semibold text-slate-900">{v.chassis_no}</span>
          <span className="text-gray-600">{nameOf(v.vehicle_type)}</span>
          <StatusBadge status={v.status} />
        </div>
      </Card>
      {!history && (
        <>
      <Card className="mb-4">
        <KV items={[
          ["Type", nameOf(v.vehicle_type)], ["Chassis no.", v.chassis_no], ["Engine no.", v.engine_no], ["Registration no.", v.registration_no],
          ["Color", v.color], ["CC", v.cc], ["Fuel", v.fuel_type], ["Status", <StatusBadge key="s" status={v.status} />],
        ]} />
      </Card>
      <Card title="Papers & condition" className="mb-4">
        <KV items={[
          ["Registration date", fmtDate(v.registration_date)], ["Tax token expiry", fmtDate(v.tax_token_expiry)], ["Fitness expiry", fmtDate(v.fitness_expiry)],
          ["Insurance expiry", fmtDate(v.insurance_expiry)], ["Mileage", v.mileage == null ? "-" : `${v.mileage.toLocaleString()} km`],
          ["Papers received", v.papers_received ? "Yes" : "No"], ["Condition", v.condition_notes],
        ]} />
      </Card>
        </>
      )}
      {history && <Card title={`Purchase and sale chain (${steps.length})`} className="mb-4">
        {!steps.length && <p className="text-sm text-gray-500">No purchases or sales yet.</p>}
        <ol>
          {steps.map((st, i) => {
            const key = `${st.kind}-${st.id}`;
            const isOpen = !!open[key];
            const last = i === steps.length - 1;
            const isReturn = st.kind === "purchase" && st.p.source === "customer";
            const tone = st.kind === "sale" ? "bg-green-600" : isReturn ? "bg-amber-500" : "bg-blue-500";
            const label = st.kind === "sale" ? "Sale" : isReturn ? "Return" : "Purchase";
            // one colour per kind of step: purchase blue, return amber, sale green (never red)
            const box = st.kind === "sale" ? "border-green-500 bg-green-50/40" : isReturn ? "border-amber-400 bg-amber-50/40" : "border-sky-500 bg-sky-50/40";
            const line = st.kind === "sale" ? "border-green-200" : isReturn ? "border-amber-200" : "border-sky-200";
            const person = st.kind === "sale" ? st.s.customer : st.p.seller;
            const personHref = st.kind === "sale" || st.p.source === "customer" ? `/customers/${person?.id}` : `/suppliers/${person?.id}`;
            const amount = st.kind === "sale" ? st.s.sale_price : st.p.price;
            return (
              <li key={key} className="relative flex gap-3 pb-4">
                {!last && <span className="absolute left-[13px] top-7 h-[calc(100%-1.75rem)] w-0.5 bg-emerald-200" />}
                <span className={`z-10 flex size-7 shrink-0 items-center justify-center rounded-full text-xs font-bold text-white ${tone}`}>{i + 1}</span>
                <div className={`min-w-0 flex-1 rounded-lg border-2 text-sm ${box}`}>
                  <button type="button" aria-expanded={isOpen} onClick={() => setOpen((o) => ({ ...o, [key]: !o[key] }))} className="block w-full p-3 text-left">
                    <div className="flex items-center gap-2">
                    <div className="min-w-0 flex-1">
                      <div className="flex flex-wrap items-center gap-2">
                        <Badge tone={st.kind === "sale" ? "green" : isReturn ? "yellow" : "blue"}>{label}</Badge>
                        <span className="text-gray-500">{fmtDate(st.at)}</span>
                        {st.kind === "sale" && <StatusBadge status={st.s.status} />}
                      </div>
                      <div className="mt-1 truncate">
                        {st.kind === "sale" ? "Sold to " : isReturn ? "Returned by " : "Bought from "}<b>{person?.name ?? "-"}</b>
                      </div>
                    </div>
                    <b className="shrink-0 text-base">{money(amount)}</b>
                    <ChevronDown className={`size-4 shrink-0 text-slate-400 transition-transform ${isOpen ? "rotate-180" : ""}`} />
                    </div>
                    <dl className={`mt-2 grid grid-cols-2 gap-x-4 gap-y-1 border-t pt-2 sm:grid-cols-5 ${line}`}>
                      {(st.kind === "sale"
                        ? [["Sale price", money(st.s.sale_price)], ["Down payment", money(st.s.down_payment)], ["Paid", money(st.s.total_paid)], ["Due", money(st.s.status === "active" ? st.s.due_amount : 0)], ["Type", <span key="t" className="capitalize">{st.s.payment_type}</span>]]
                        : [["Price", money(st.p.price)], ["Paid", money(st.p.paid_amount)], ["Due set off", money(st.p.due_adjustment)], ["Method", <span key="m" className="capitalize">{st.p.payment_method || "-"}</span>]]
                      ).map(([k, val]) => (
                        <div key={String(k)}><dt className="text-xs text-gray-500">{k}</dt><dd className={`font-semibold ${k === "Due" && st.kind === "sale" && st.s.status === "active" && st.s.due_amount > 0 ? "text-amber-700" : "text-slate-800"}`}>{val}</dd></div>
                      ))}
                    </dl>
                  </button>
                  {isOpen && (
                    <div className={`space-y-3 border-t-2 p-3 ${line}`}>
                      {person && (
                        <div className="text-xs text-gray-600">
                          <Link className="font-semibold underline" href={personHref}>{person.name}</Link> · {[person.phone, person.alt_phone].filter(Boolean).join(" / ")}{person.nid ? ` · NID ${person.nid}` : ""}
                        </div>
                      )}
                      {st.kind === "purchase" ? (
                        <>
                          <Facts items={[
                            ["Extra cost", st.p.cost_extra > 0 ? money(st.p.cost_extra) : null],
                            ["Payment status", <span key="ps" className="capitalize">{st.p.payment_status}</span>], ["Memo no.", st.p.memo_no], ["Reference", st.p.payment_reference], ["Notes", st.p.notes],
                          ]} />
                          {st.p.adjusted_sale_id && <Link className="text-xs underline" href={`/sales/${st.p.adjusted_sale_id}`}>Due set off against sale #{st.p.adjusted_sale_id}</Link>}
                        </>
                      ) : (
                        <>
                          <Facts items={[
                            ["Guarantor", st.s.guarantor_phone],
                            ["Cancelled", st.s.status === "cancelled" ? st.s.cancel_reason ?? "Yes" : null],
                          ]} />
                          <div>
                            <p className="mb-2 text-xs font-semibold uppercase text-emerald-700">Payments</p>
                            <DataTable columns={paymentCols} data={st.s.payments ?? []} empty="No payments." />
                            {(st.s.payments ?? []).length > 0 && (
                              <p className="mt-2 text-right text-sm text-gray-600">Total paid <b className="text-slate-900">{money(st.s.total_paid)}</b></p>
                            )}
                          </div>
                          {timeline.filter((e) => e.type === "registration" && e.ref_id === st.id).length > 0 && (
                            <div>
                              <p className="mb-1 text-xs font-semibold uppercase text-emerald-700">Registration</p>
                              <ul className="space-y-1">
                                {timeline.filter((e) => e.type === "registration" && e.ref_id === st.id).map((e, k) => (
                                  <li key={k} className="flex gap-3"><span className="w-28 text-gray-500">{fmtDate(e.date)}</span><span>{e.title.replace("Registration: ", "")}</span></li>
                                ))}
                              </ul>
                            </div>
                          )}
                          <Link className="inline-block text-xs underline" href={`/sales/${st.id}`}>Sales details #{st.id}</Link>
                        </>
                      )}
                    </div>
                  )}
                </div>
              </li>
            );
          })}
        </ol>
      </Card>}
    </>
  );
}
