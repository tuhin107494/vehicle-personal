"use client";

import Link from "next/link";
import { useRef, useState } from "react";
import { useParams, useRouter } from "next/navigation";
import { ArrowLeft, Landmark } from "lucide-react";
import { toast } from "sonner";
import { api, ApiError } from "@/lib/api";
import { useFetch } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { fmtDate } from "@/lib/format";
import { newIdempotencyKey, taka } from "@/lib/billing";
import type { GatewayOptionT, InvoiceT, PaymentAttemptT } from "@/lib/types";
import { Badge, Button, Card, ErrorText, Field, inputCls, Loading, PageHeader } from "@/components/app-ui";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";

/** Bank transfer: the details to pay to, then the form for the reference (and the receipt photo). */
function TransferForm({ attempt, onDone }: { attempt: PaymentAttemptT; onDone: () => void }) {
  const { t } = useT();
  const [reference, setReference] = useState("");
  const [note, setNote] = useState("");
  const [proof, setProof] = useState<File | null>(null);
  const [busy, setBusy] = useState(false);
  const details = attempt.initiation?.details ?? {};

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    const body = new FormData();
    body.set("reference", reference);
    if (note) body.set("note", note);
    if (proof) body.set("proof", proof);
    setBusy(true);
    try {
      const res = await api(`/billing/payments/${attempt.id}/reference`, { method: "POST", body });
      toast.success(res.message);
      onDone();
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : "Failed");
    } finally {
      setBusy(false);
    }
  };

  return (
    <Card title="Bank transfer">
      {attempt.initiation?.instructions && <p className="mb-3 whitespace-pre-line text-sm text-slate-700">{attempt.initiation.instructions}</p>}
      <dl className="mb-4 grid gap-2 text-sm sm:grid-cols-2">
        {Object.entries(details).map(([k, v]) => <div key={k} className="rounded-lg bg-slate-50 p-2"><dt className="text-xs uppercase tracking-wide text-slate-500">{k}</dt><dd className="font-medium text-slate-800">{v}</dd></div>)}
      </dl>
      <form onSubmit={submit} className="space-y-3">
        <Field required label="Transfer reference or transaction number"><Input className={inputCls} value={reference} onChange={(e) => setReference(e.target.value)} /></Field>
        <Field label="Receipt photo (JPG, PNG or WebP, up to 4 MB)"><input type="file" accept="image/jpeg,image/png,image/webp" aria-label={t("Receipt photo")} className="block w-full text-sm" onChange={(e) => setProof(e.target.files?.[0] ?? null)} /></Field>
        <Field label="Note"><Textarea className={inputCls} rows={2} value={note} onChange={(e) => setNote(e.target.value)} /></Field>
        <Button type="submit" disabled={busy || !reference.trim()}>I have paid</Button>
      </form>
    </Card>
  );
}

/** Pay one invoice: pick a gateway, then either go to the gateway or follow the bank transfer instructions. Owners only. */
export default function PayInvoicePage() {
  const { t } = useT();
  const router = useRouter();
  const { invoice: id } = useParams<{ invoice: string }>();
  const invoice = useFetch<InvoiceT>(`/billing/invoices/${id}`);
  const gateways = useFetch<GatewayOptionT[]>(`/billing/invoices/${id}/gateways`);
  // one key per gateway per page open: a double click or a retry gets the same attempt back instead of a second charge
  const keys = useRef<Record<string, string>>({}).current;
  const [busy, setBusy] = useState<string | null>(null);
  const [attempt, setAttempt] = useState<PaymentAttemptT | null>(null);

  const pay = async (code: string) => {
    keys[code] ??= newIdempotencyKey();
    setBusy(code);
    try {
      const res = await api<PaymentAttemptT>(`/billing/invoices/${id}/pay`, { method: "POST", body: { gateway: code, idempotency_key: keys[code] } });
      const a = res.data;
      if (a.initiation?.kind === "redirect" && a.initiation.redirect_url) {
        window.location.assign(a.initiation.redirect_url);
        return;
      }
      if (a.initiation?.kind === "instructions") setAttempt(a);
      else router.push(`/billing/payments/${a.id}`);
    } catch (e) {
      toast.error(e instanceof ApiError ? e.message : "Failed");
    } finally {
      setBusy(null);
    }
  };

  if (invoice.error) return <ErrorText message={invoice.error} />;
  if (!invoice.data) return <Loading />;
  const inv = invoice.data;

  return (
    <>
      <PageHeader title="Pay invoice" actions={<Link href="/billing"><Button variant="outline"><ArrowLeft className="size-4" /> Billing</Button></Link>} />
      <Card className="mb-4">
        <div className="flex flex-wrap items-center justify-between gap-3 text-sm">
          <div>
            <p className="font-semibold text-slate-900">{inv.invoice_no}</p>
            <p className="text-slate-600">{inv.plan?.name} · {fmtDate(inv.period_starts_at)} – {fmtDate(inv.period_ends_at)}</p>
          </div>
          <p className="text-2xl font-bold text-emerald-700">{inv.currency} {taka(inv.total_paisa)}</p>
        </div>
        {!inv.payable && <p className="mt-2 text-sm text-slate-600">{inv.status === "paid" ? t("This invoice is paid.") : t("This invoice is not open for payment.")}</p>}
      </Card>

      {attempt ? <TransferForm attempt={attempt} onDone={() => router.push(`/billing/payments/${attempt.id}`)} /> : inv.payable && (
        <>
          <h2 className="mb-3 text-lg font-semibold text-emerald-700">{t("Choose a payment method")}</h2>
          {gateways.error && <ErrorText message={gateways.error} />}
          {gateways.loading && !gateways.data ? <Loading /> : (gateways.data ?? []).length === 0 ? <p className="text-sm text-slate-600">{t("No payment method is available right now. Please try again later.")}</p> : (
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
              {(gateways.data ?? []).map((g) => (
                <button key={g.code} type="button" disabled={busy !== null} onClick={() => pay(g.code)} className="flex items-center gap-3 rounded-xl bg-white/90 p-4 text-left shadow-sm ring-1 ring-emerald-300 transition hover:ring-emerald-500 disabled:opacity-60">
                  {g.logo_url ? <img src={g.logo_url} alt="" className="size-12 rounded-lg object-contain ring-1 ring-slate-200" /> : <span className="flex size-12 items-center justify-center rounded-lg bg-slate-100 text-slate-500"><Landmark className="size-5" /></span>}
                  <span className="min-w-0">
                    <span className="flex items-center gap-2 font-semibold text-slate-900">{g.display_name} {g.mode === "sandbox" && <Badge tone="yellow">Test</Badge>}</span>
                    {g.description && <span className="block text-xs text-slate-600">{g.description}</span>}
                    {busy === g.code && <span className="block text-xs text-emerald-700">{t("Please wait...")}</span>}
                  </span>
                </button>
              ))}
            </div>
          )}
        </>
      )}
    </>
  );
}
