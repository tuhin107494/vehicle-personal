"use client";

import Link from "next/link";
import { useState } from "react";
import { useParams } from "next/navigation";
import { ArrowRight, ChevronDown, Wallet } from "lucide-react";
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
  const [details, setDetails] = useState(false); // the vehicle and customer stay hidden until asked for

  if (loading && !x) return <Loading />;
  if (error || !x) return <ErrorText message={error ?? "Not found"} />;
  const v = x.sale?.vehicle;
  const c = x.sale?.customer; // who the vehicle was sold to (their money details stay on the sale's page)

  return (
    <>
      <PageHeader
        title={`Investor sale #${x.id}`}
        // the customer's side (payments, promises, installments) is on the sale's own page
        actions={<>
          {x.status === "active" && can("settlements.manage") && (x.position?.total_due ?? 0) > 0 && <Button onClick={() => setPaying(true)}><Wallet className="size-4" /> Pay investor</Button>}
          <Link className="inline-flex h-10 items-center gap-1.5 rounded-lg border border-emerald-200 bg-white px-4 text-sm font-medium text-emerald-700 hover:bg-emerald-50 hover:text-emerald-800" href={`/sales/${x.sale_id}`}>Related sale #{x.sale_id} <ArrowRight className="size-4" /></Link>
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
      <InvestorSaleCard investorSale={x} onChanged={reload} investorOnly paying={paying} onPayingChange={setPaying} />
    </>
  );
}
