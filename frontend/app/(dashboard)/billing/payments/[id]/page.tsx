"use client";

import Link from "next/link";
import { Suspense, useEffect, useState } from "react";
import { useParams, useSearchParams } from "next/navigation";
import { CircleCheck, CircleX, Clock } from "lucide-react";
import { useFetch } from "@/lib/hooks";
import { useT } from "@/lib/i18n";
import { taka } from "@/lib/billing";
import type { PaymentAttemptT } from "@/lib/types";
import { Button, Card, ErrorText, Loading, PageHeader } from "@/components/app-ui";

const POLL_MS = 3000;
const POLL_FOR_MS = 3 * 60_000;

/** Where the payer lands after the gateway: the attempt is asked about until the gateway's answer has been applied. */
function PaymentResult() {
  const { t } = useT();
  const { id } = useParams<{ id: string }>();
  const params = useSearchParams();
  const result = params.get("result");
  const attempt = useFetch<PaymentAttemptT>(`/billing/payments/${id}`);
  const a = attempt.data;
  const open = !!a && (a.status === "initiated" || a.status === "pending");
  const [startedAt] = useState(() => Date.now());
  const [timedOut, setTimedOut] = useState(false);

  // while the gateway's answer is still on its way, ask again every few seconds, for a few minutes at most
  useEffect(() => {
    if (!open || timedOut) return;
    const timer = setInterval(() => {
      if (Date.now() - startedAt > POLL_FOR_MS) setTimedOut(true);
      else attempt.reload();
    }, POLL_MS);
    return () => clearInterval(timer);
  }, [open, timedOut, startedAt, attempt]);

  if (attempt.error) return <ErrorText message={attempt.error} />;
  if (!a) return <Loading />;

  const manualWaiting = a.gateway === "manual" && a.status === "pending" && a.submitted_at;
  const icon = a.status === "success" ? <CircleCheck className="size-12 text-emerald-600" /> : open ? <Clock className="size-12 text-amber-500" /> : <CircleX className="size-12 text-red-600" />;
  const title = a.status === "success" ? "Payment received" : manualWaiting ? "Transfer submitted" : open ? (result === "cancel" ? "Payment not completed" : "Checking the payment") : a.status === "cancelled" ? "Payment cancelled" : a.status === "expired" ? "Payment window closed" : "Payment failed";
  const text = a.status === "success"
    ? t("Thank you. Your plan is active and the invoice is marked paid.")
    : manualWaiting ? t("We will confirm the transfer soon. You will see the invoice marked paid once it is approved.")
      : open ? (timedOut ? t("The gateway has not answered yet. Refresh this page in a few minutes, or contact support if the money left your account.") : t("Waiting for the gateway to confirm. This usually takes a few seconds."))
        : a.failure_reason ?? t("The payment did not go through. You can try again with another method.");

  return (
    <>
      <PageHeader title="Payment" />
      <Card>
        <div className="flex flex-col items-center gap-3 py-4 text-center">
          {icon}
          <h2 className="text-xl font-semibold text-slate-900">{t(title)}</h2>
          <p className="max-w-md text-sm text-slate-600">{text}</p>
          <p className="text-sm text-slate-700">{a.invoice?.invoice_no} · <strong>{a.currency} {taka(a.amount_paisa)}</strong>{a.reference && <> · <code className="text-xs">{a.reference}</code></>}</p>
          <div className="mt-2 flex flex-wrap justify-center gap-2">
            <Link href="/billing"><Button variant="outline">Billing</Button></Link>
            {!open && a.status !== "success" && a.invoice?.status !== "paid" && <Link href={`/billing/pay/${a.invoice_id}`}><Button>Try again</Button></Link>}
          </div>
        </div>
      </Card>
    </>
  );
}

export default function PaymentResultPage() {
  return <Suspense><PaymentResult /></Suspense>;
}
