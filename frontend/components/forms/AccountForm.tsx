"use client";

import { useEffect, useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { toast } from "sonner";
import { Phone } from "lucide-react";
import { api, applyServerErrors } from "@/lib/api";
import { fmtDate, money, todayStr, toPayload } from "@/lib/format";
import { useDebounced, useFetch } from "@/lib/hooks";
import type { AccountSummary } from "@/lib/types";
import { Button, Card, Field, inputCls, Pagination } from "@/components/app-ui";
import { usePaging } from "@/lib/paging";
import { CustomerSelect, SupplierSelect } from "@/components/Selects";
import { useAuth } from "@/lib/auth";
import { TransactionVoucher, type VoucherTx } from "@/components/TransactionVoucher";
import { SearchSelect } from "@/components/SearchSelect";
import type { Sale } from "@/lib/types";
import { Input } from "@/components/ui/input";
import { NativeSelect } from "@/components/ui/native-select";

export const SIDES = {
  supplier: { label: "Supplier", hint: "Account payable · we pay", verb: "Pay", amount: "Amount paid", method: "Paid by", done: "Record payment" },
  customer: { label: "Customer", hint: "Account receivable · we receive", verb: "Receive", amount: "Amount received", method: "Received by", done: "Receive payment" },
} as const;
export type Side = keyof typeof SIDES;

const schema = z.object({
  side: z.enum(["supplier", "customer"]),
  party_id: z.string().min(1, "Select the supplier or customer."),
  amount: z.string().min(1, "Enter the amount.").refine((v) => Number(v) > 0, "Amount must be greater than zero."),
  method: z.enum(["cash", "bank", "mobile"]),
  paid_at: z.string().refine((v) => v <= todayStr(), "Date cannot be in the future."),
  reference: z.string(),
  note: z.string(),
});
type Values = z.infer<typeof schema>;
const blank: Values = { side: "supplier", party_id: "", amount: "", method: "cash", paid_at: todayStr(), reference: "", note: "" };

export function AccountForm({ side, vehicleId, partyId }: { side: Side; vehicleId?: string | null; partyId?: string | null }) {
  const { register, handleSubmit, reset, setValue, setError, watch, formState: { errors, isSubmitting } } = useForm<Values>({ resolver: zodResolver(schema), defaultValues: { ...blank, side } });
  const w = watch();
  const text = SIDES[side];
  const { user } = useAuth();
  const [vouchers, setVouchers] = useState<VoucherTx[]>([]); // the receipts shown after a payment (one per purchase or installment it paid)

  const summary = useFetch<AccountSummary>(w.party_id ? `/accounts?side=${side}&${side === "customer" ? "customer_id" : "supplier_id"}=${w.party_id}` : null);
  // Customers can pay one vehicle's due; empty means spread over all, oldest first.
  const [saleId, setSaleId] = useState("");
  // Or start from the vehicle: choosing one fills in its customer.
  const [found, setFound] = useState<{ party: string } | null>(null);
  const [vq, setVq] = useState("");
  const vqD = useDebounced(vq);
  // Opened from a vehicle row: fill in its customer and select it.
  const fromVehicle = useFetch<Sale[]>(side === "customer" && vehicleId ? `/sales?due=1&vehicle_id=${vehicleId}` : null);
  useEffect(() => {
    const x = fromVehicle.data?.[0];
    if (!x) return;
    setFound({ party: x.customer?.name ?? "" });
    setValue("party_id", String(x.customer_id), { shouldValidate: true });
    setSaleId(String(x.id));
  }, [fromVehicle.data, setValue]);
  // Opened from a customer/supplier row.
  const fromParty = useFetch<{ id: number; name: string }>(partyId ? `/${side === "customer" ? "customers" : "suppliers"}/${partyId}` : null);
  useEffect(() => {
    if (!fromParty.data) return;
    setFound({ party: fromParty.data.name });
    setValue("party_id", String(fromParty.data.id), { shouldValidate: true });
  }, [fromParty.data, setValue]);
  const [vehicleListOpened, setVehicleListOpened] = useState(false); // the due list loads when the "find by vehicle" field is first used
  const dueSales = useFetch<Sale[]>(side === "customer" && !w.party_id && vehicleListOpened ? `/sales?due=1&per_page=30${vqD ? `&search=${encodeURIComponent(vqD)}` : ""}` : null);
  const itemsPg = usePaging(summary.data?.items, false, 5);
  const picked = summary.data?.items.find((i) => String(i.id) === saleId);
  // A due is collected per vehicle: the oldest one is chosen until another is picked.
  const firstId = side === "customer" ? summary.data?.items[0]?.id : undefined;
  useEffect(() => {
    if (firstId !== undefined && !summary.data?.items.some((i) => String(i.id) === saleId)) setSaleId(String(firstId));
  }, [firstId, saleId, summary.data]);
  const due = picked ? picked.due : summary.data?.total_due ?? 0;
  const current = picked ? picked.current_due ?? 0 : summary.data?.current_due ?? 0;
  const late = picked ? picked.days_late ?? 0 : summary.data?.days_late ?? 0;
  const advance = summary.data?.advance_available ?? 0;
  const amount = Number(w.amount || 0);
  const tooMuch = side === "customer" && !!summary.data && amount > due;

  const onSubmit = async (v: Values) => {
    if (tooMuch) {
      setError("amount", { message: `${picked ? "This vehicle has" : "This customer owes"} ${money(due)}${picked ? " due" : ""}.` });
      return;
    }
    try {
      const res = await api("/accounts", { method: "POST", body: { ...toPayload({ ...v, [side === "customer" ? "customer_id" : "supplier_id"]: v.party_id }, [side === "customer" ? "customer_id" : "supplier_id", "amount"]), sale_id: side === "customer" && saleId ? Number(saleId) : null } });
      toast.success(`${res.message} successfully`);
      {
        // the same vouchers the reports print, so a later download has the same number and file name
        const nos: string[] = (res.data as { vouchers?: string[] }).vouchers ?? [];
        api<{ rows: (VoucherTx & { person_id: number })[] }>(`/reports/transactions?side=${side}&from=${v.paid_at}&to=${v.paid_at}`)
          .then((r) => setVouchers(r.data.rows.filter((x) => nos.includes(x.no) && x.person_id === Number(v.party_id))))
          .catch(() => {});
      }
      reset({ ...blank, side: v.side }); // the whole form clears, including the person
      setSaleId("");
      setFound(null);
      setVq("");
    } catch (e) {
      toast.error(applyServerErrors(e, setError));
    }
  };

  return (
    <>
      <form onSubmit={handleSubmit(onSubmit)} noValidate className="max-w-2xl">
        <Card>
          <div className="space-y-4">

            {(() => {
              const Pick = side === "customer" ? CustomerSelect : SupplierSelect;
              return <Pick label={SIDES[side].label} allowCreate={false} value={w.party_id} selectedLabel={found?.party} onChange={(id: string) => { setSaleId(""); setFound(null); setValue("party_id", id, { shouldValidate: true }); }} error={errors.party_id?.message} />;
            })()}

            {side === "customer" && !w.party_id && (
              <Field label="Or find by vehicle">
                <SearchSelect
                  options={(dueSales.data ?? []).map((x) => ({ value: String(x.id), label: x.vehicle?.chassis_no ?? `Sale #${x.id}`, hint: `${x.customer?.name} · due ${money(x.due_amount)}` }))}
                  value=""
                  onChange={(id) => {
                    const x = dueSales.data?.find((r) => String(r.id) === id);
                    if (!x) return;
                    setFound({ party: x.customer?.name ?? "" });
                    setValue("party_id", String(x.customer_id), { shouldValidate: true });
                    setSaleId(id);
                  }}
                  onQueryChange={setVq}
                  onOpen={() => setVehicleListOpened(true)}
                  loading={dueSales.loading}
                  placeholder="Search chassis, model or customer..."
                />
              </Field>
            )}

            {summary.data && (
              <div className="space-y-3 rounded-md border border-emerald-100 bg-emerald-50/50 p-3 text-sm">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <div className="font-semibold text-slate-800">{summary.data.person.name}</div>
                  <div className="flex flex-wrap gap-2">
                    {[summary.data.person.phone, summary.data.person.alt_phone].filter(Boolean).map((n) => (
                      <a key={n} href={`tel:${n}`} className="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-2.5 py-1 text-sm font-medium text-emerald-800 hover:bg-emerald-200"><Phone className="size-3.5" /> {n}</a>
                    ))}
                  </div>
                </div>

                <div className={`grid gap-2 ${side === "customer" ? "grid-cols-3" : "grid-cols-1"}`}>
                  <div className="rounded-md border border-emerald-200 bg-white p-2">
                    <div className="text-xs text-slate-500">{side === "customer" ? "Total due" : "We owe the supplier"}</div>
                    <div className="text-lg font-bold text-emerald-700">{money(due)}</div>
                  </div>
                  {side === "customer" && (
                    <>
                      <div className="rounded-md border border-emerald-200 bg-white p-2">
                        <div className="text-xs text-slate-500">Current due (now)</div>
                        <div className="text-lg font-bold text-emerald-700">{money(current)}</div>
                      </div>
                      <div className="rounded-md border border-emerald-200 bg-white p-2">
                        <div className="text-xs text-slate-500">Delay</div>
                        <div className="text-lg font-bold text-emerald-700">{late > 0 ? `${late} day${late === 1 ? "" : "s"}` : "On time"}</div>
                      </div>
                    </>
                  )}
                </div>
                {side === "supplier" && (() => {
                  const b = summary.data.person;
                  const rows: [string, string | null | undefined][] = [["Bank", b.bank_name], ["Branch", b.bank_branch], ["Account name", b.bank_account_name], ["Account no", b.bank_account_no], ["Other", b.bank_info]];
                  return rows.some(([, v]) => v) ? (
                    <div className="rounded-md border border-emerald-200 bg-white p-2 text-xs">
                      <div className="mb-1 font-semibold uppercase tracking-wide text-emerald-700">Bank details</div>
                      <dl className="grid grid-cols-2 gap-x-4 gap-y-1 sm:grid-cols-3">
                        {rows.filter(([, v]) => v).map(([k, v]) => <div key={k}><dt className="text-slate-500">{k}</dt><dd className="break-all font-medium text-slate-800">{v}</dd></div>)}
                      </dl>
                    </div>
                  ) : null;
                })()}
                {side === "supplier" && advance > 0 && <p className="text-xs text-slate-600">Advance already paid to them: <b>{money(advance)}</b></p>}

                {/* the customer picks which vehicle to collect for; a supplier is simply paid, oldest first, so no vehicle list there */}
                {side === "customer" && (
                  <>
                    {itemsPg.items.map((i) => {
                      const on = side === "customer" ? String(i.id) === saleId : true;
                      const facts: [string, string | null | undefined][] = [["Chassis", i.vehicle], ["Engine", i.engine_no], ["Memo no", i.memo_no], [side === "customer" ? "Sold" : "Bought", fmtDate(i.date)]];
                      return (
                        <div
                          key={`${i.kind}-${i.id}`}
                          onClick={() => side === "customer" && setSaleId(String(i.id))}
                          className={`rounded-md border bg-white p-3 ${side === "customer" ? "cursor-pointer" : ""} ${on ? "border-emerald-400 ring-1 ring-emerald-300" : "border-slate-200"}`}
                        >
                          <div className="mb-2 flex flex-wrap items-center gap-2">
                            <b className="text-slate-800">{i.vehicle_name || i.vehicle}</b>
                            {i.payment_type && <span className="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] uppercase text-slate-600">{i.payment_type}</span>}
                          </div>
                          <dl className="grid grid-cols-2 gap-x-4 gap-y-1 text-xs sm:grid-cols-4">
                            {facts.filter(([, v]) => v).map(([k, v]) => (
                              <div key={k}><dt className="text-slate-500">{k}</dt><dd className="break-all font-medium text-slate-800">{v}</dd></div>
                            ))}
                          </dl>
                          <div className="mt-2 flex flex-wrap gap-x-5 gap-y-1 border-t border-slate-100 pt-2 text-xs">
                            <span className="text-slate-500">Due <b className="text-emerald-700">{money(i.due)}</b></span>
                            {i.current_due !== undefined && <span className="text-slate-500">Now <b className="text-emerald-700">{money(i.current_due)}</b></span>}
                            {i.days_late !== undefined && (i.days_late > 0 ? <span className="font-semibold text-emerald-700">{i.days_late} days late</span> : i.next_date ? <span className="text-slate-500">Next date <b className="text-slate-800">{fmtDate(i.next_date)}</b></span> : null)}
                          </div>
                        </div>
                      );
                    })}
                    <Pagination meta={itemsPg.meta} onPage={itemsPg.setPage} />
                  </>
                )}
                {side === "supplier" && summary.data.items.length > 1 && <p className="text-xs text-slate-500">A payment goes to the oldest first.</p>}
              </div>
            )}

            {side === "customer" && summary.data && summary.data.items.length > 0 && (
              <Field required label="Vehicle">
                <NativeSelect className={inputCls} value={saleId} onChange={(e) => setSaleId(e.target.value)}>
                  {summary.data.items.map((i) => <option key={i.id} value={String(i.id)}>{i.vehicle} · due {money(i.due)}{i.days_late ? ` · ${i.days_late}d late` : ""}</option>)}
                </NativeSelect>
              </Field>
            )}

            <div className="grid gap-3 sm:grid-cols-2">
              <Field required label={text.amount} error={errors.amount?.message}>
                <Input type="number" step="0.01" className={inputCls} {...register("amount")} />
                {summary.data && (
                  <p className="mt-1 text-xs text-slate-500">
                    {due > 0 && <button type="button" className="font-medium text-emerald-700 hover:underline" onClick={() => setValue("amount", String(due), { shouldValidate: true })}>Full due {money(due)}</button>}
                    {side === "supplier" && amount > due && <> {due > 0 && "· "}{money(amount - due)} will be saved as advance</>}
                    {tooMuch && <span className="text-amber-700"> · more than the customer owes</span>}
                  </p>
                )}
              </Field>
              <Field required label="Date" error={errors.paid_at?.message}><Input type="date" className={inputCls} {...register("paid_at")} /></Field>
              <Field required label={text.method} error={errors.method?.message}>
                <NativeSelect className={inputCls} {...register("method")}><option value="cash">Cash</option><option value="bank">Bank</option><option value="mobile">Mobile</option></NativeSelect>
              </Field>
              <Field label="Reference" error={errors.reference?.message}><Input className={inputCls} {...register("reference")} /></Field>
            </div>
            <Field label="Note" error={errors.note?.message}><Input className={inputCls} {...register("note")} /></Field>

            <div className="flex justify-end">
              <Button type="submit" disabled={isSubmitting}>{isSubmitting ? "Saving..." : text.done}</Button>
            </div>
          </div>
        </Card>
      </form>
      <TransactionVoucher tx={vouchers[0] ?? null} count={vouchers.length} side={side} shop={user?.shop?.name ?? "Vehicle Hub"} onClose={() => setVouchers([])} onDownloaded={() => setVouchers((q) => q.slice(1))} />
    </>
  );
}
