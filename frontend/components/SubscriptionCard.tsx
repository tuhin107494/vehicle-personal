"use client";

import { useAuth } from "@/lib/auth";
import { fmtDate } from "@/lib/format";
import { daysLeft, subscriptionState } from "@/lib/subscription";
import { Badge, Card } from "@/components/app-ui";

/** The shop's plan, when it ends and how many days are left. Nothing for users without a shop subscription. */
export function SubscriptionCard() {
  const { user } = useAuth();
  const sub = user?.shop?.subscription;
  if (!sub) return null;

  const { ended, trial, days } = subscriptionState(sub);

  return (
    <Card title="Subscription">
      <dl className="grid grid-cols-3 gap-3 text-sm">
        <div><dt className="text-xs text-slate-500">Plan</dt><dd className="font-semibold text-slate-800">{sub.plan}</dd></div>
        <div><dt className="text-xs text-slate-500">{ended ? "Ended on" : "Ends on"}</dt><dd className="font-semibold text-slate-800">{fmtDate(sub.ends_at)}</dd></div>
        <div>
          <dt className="text-xs text-slate-500">Status</dt>
          <dd>{ended ? <Badge tone="red">Ended</Badge> : <Badge tone={trial ? "yellow" : "green"}>{daysLeft(days)}</Badge>}</dd>
        </div>
      </dl>
      <p className="mt-3 text-xs text-slate-500">{ended ? "Contact support to continue using the app." : "Contact support before it ends to keep using the app."}</p>
    </Card>
  );
}
