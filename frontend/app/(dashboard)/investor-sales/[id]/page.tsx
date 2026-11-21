"use client";

import Link from "next/link";
import { useState } from "react";
import { useParams } from "next/navigation";
import { ArrowRight, Ban, ChevronDown, HandCoins, Pencil, Wallet } from "lucide-react";
import { toast } from "sonner";
import { api, ApiError } from "@/lib/api";
import { InvestorSaleEditModal } from "@/components/forms/InvestorSaleEditModal";
import { useAuth } from "@/lib/auth";
import { useFetch } from "@/lib/hooks";
import type { InvestorSale } from "@/lib/types";
import { Button, Card, ErrorText, KV, Loading, PageHeader } from "@/components/app-ui";
import { InvestorSaleCard } from "@/components/InvestorSaleCard";
import { RegistrationStepper } from "@/components/RegistrationStepper";

/** An investor sale on its own: only what is between the investor and the shop (the vehicle, the amounts, the schedule), not the customer. */
export default function InvestorSaleDetailPage() {
  const { id } = useParams<{ id: string }>();
  const { data: x, loading, error, reload } = useFetch<InvestorSale>(`/investor-sales/${id}`);
  const { can } = useAuth();
  const [paying, setPaying] = useState(false); // the Pay investor form, opened from the page's title bar
  const [receiving, setReceiving] = useState(false); // collecting what the investor still owes, also from the title bar
  const [editing, setEditing] = useState(false);
  const [busy, setBusy] = useState(false);
  const [details, setDetails] = useState(false); // the vehicle and customer stay hidden until asked for

  if (loading && !x) return <Loading />;
  if (error || !x) return <ErrorText message={error ?? "Not found"} />;
  const v = x.sale?.vehicle;
  const active = x.status === "active";
  // it can be cancelled only before any money has moved on it (the server checks this too)
  const moneyMoved = ((x.position?.principal_paid ?? 0) + (x.position?.profit_paid ?? 0)) > 0 || (x.investor_paid ?? 0) > (x.investor_down_payment ?? x.principal) + 0.004;
  const cancel = async () => {
    if (!confirm("Cancel this investor sale? The investor's down payment entry is removed and its schedule is cancelled.")) return;
    setBusy(true);
    try {
      const res = await api(`/investor-sales/${x.id}/cancel`, { method: "POST" });
      toast.success(res.message);
      reload();
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : "Failed");
    } finally {
      setBusy(false);
    }
  };
  const c = x.sale?.customer; // who the vehicle was sold to (their money details stay on the sale's page)

  return (
    <>
      <PageHeader
        title={`Investor sale #${x.id}`}
        // the customer's side (payments, promises, installments) is on the sale's own page
        // the same colours as the actions on the Investors list: each kind of action its own colour
        actions={<>
          {active && can("settlements.manage") && (x.position?.total_due ?? 0) > 0 && <Button variant="outline" className="border-violet-300 bg-violet-50 text-violet-700 hover:bg-violet-100 hover:text-violet-800" onClick={() => setPaying(true)}><Wallet className="size-4" /> Pay investor</Button>}
          {active && can("settlements.manage") && (x.investor_owes ?? 0) > 0 && <Button variant="outline" className="border-sky-300 bg-sky-50 text-sky-700 hover:bg-sky-100 hover:text-sky-800" onClick={() => setReceiving(true)}><HandCoins className="size-4" /> Collect due</Button>}
          {active && can("sales.edit") && <Button variant="outline" className="border-amber-300 bg-amber-50 text-amber-700 hover:bg-amber-100 hover:text-amber-800" onClick={() => setEditing(true)}><Pencil className="size-4" /> Edit</Button>}
          {active && can("sales.cancel") && !moneyMoved && <Button variant="outline" className="border-red-300 bg-red-50 text-red-700 hover:bg-red-100 hover:text-red-800" disabled={busy} onClick={cancel}><Ban className="size-4" /> Cancel</Button>}
          <Link className="inline-flex h-10 items-center gap-1.5 rounded-lg border border-emerald-300 bg-emerald-50 px-4 text-sm font-medium text-emerald-700 hover:bg-emerald-100 hover:text-emerald-800" href={`/sales/${x.sale_id}`}>Related sale #{x.sale_id} <ArrowRight className="size-4" /></Link>
        </>}
      />
      {x.status !== "active" && <div className="mb-4 rounded border border-red-200 bg-red-50 p-3 text-sm text-red-800">This investor sale was cancelled.</div>}
      {(v || c) && (
        <Button variant="outline" className="mb-4" onClick={() => setDetails((d) => !d)}>
          <ChevronDown className={`size-4 transition-transform ${details ? "rotate-180" : ""}`} /> {details ? "Hide vehicle & customer" : "Show vehicle & customer"}
        </Button>
      )}
      {details && v && (
        <Card title="Vehicle" className="mb-4">
          <KV items={[
            ["Chassis no.", v.chassis_no],
            ["Engine no.", v.engine_no],
            ["Vehicle", [v.brand, v.model, v.year].filter(Boolean).join(" ") || "-"],
            ["Registration no.", v.registration_no || "Not registered yet"],
          ]} />
        </Card>
      )}
      {details && c && (
        <Card title="Customer" className="mb-4">
          <KV items={[
            ["Name", <Link key="n" className="underline" href={`/customers/${c.id}`}>{c.name}</Link>],
            ...(c.father_name ? [["Father's name", c.father_name] as [string, React.ReactNode]] : []),
            ["Mobile", c.phone ? <a key="p" className="underline" href={`tel:${c.phone}`}>{c.phone}</a> : "-"],
            ...(c.alt_phone ? [["Alt. mobile", <a key="a" className="underline" href={`tel:${c.alt_phone}`}>{c.alt_phone}</a>] as [string, React.ReactNode]] : []),
            ...(c.nid ? [["NID", c.nid] as [string, React.ReactNode]] : []),
            ...(c.address ? [["Address", c.address] as [string, React.ReactNode]] : []),
            ...(x.customer ? [["Additional customer", <Link key="ac" className="underline" href={`/customers/${x.customer.id}`}>{x.customer.name}{x.customer.phone ? ` · ${x.customer.phone}` : ""}</Link>] as [string, React.ReactNode]] : []),
          ]} />
        </Card>
      )}
      {/* the registration of the vehicle's sale, moved on or back here just as on the sale's page */}
      {x.sale && <Card title="Registration" className="mb-4"><RegistrationStepper sale={x.sale} onChanged={reload} /></Card>}
      {editing && <InvestorSaleEditModal x={x} onClose={() => setEditing(false)} onSaved={reload} />}
      <InvestorSaleCard investorSale={x} onChanged={reload} investorOnly paying={paying} onPayingChange={setPaying} receiving={receiving} onReceivingChange={setReceiving} />
    </>
  );
}
