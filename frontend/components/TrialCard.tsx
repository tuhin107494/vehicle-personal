"use client";

import { useAuth } from "@/lib/auth";
import { fmtDate } from "@/lib/format";
import { trialState } from "@/lib/trial";
import { Badge, Card } from "@/components/app-ui";

/** A shop on a free trial sees when it ends and how many days are left. Nothing for shops without a trial. */
export function TrialCard() {
  const { user } = useAuth();
  const ends = user?.shop?.trial_ends_at;
  if (!ends) return null;

  const { ended, days } = trialState(ends);

  return (
    <Card title="Free trial">
      <dl className="grid grid-cols-2 gap-3 text-sm">
        <div><dt className="text-xs text-slate-500">{ended ? "Ended on" : "Ends on"}</dt><dd className="font-semibold text-slate-800">{fmtDate(ends)}</dd></div>
        <div>
          <dt className="text-xs text-slate-500">Status</dt>
          <dd>{ended ? <Badge tone="red">Ended</Badge> : <Badge tone="yellow">{days === 1 ? "1 day left" : `${days} days left`}</Badge>}</dd>
        </div>
      </dl>
      <p className="mt-3 text-xs text-slate-500">{ended ? "Contact support to continue using the app." : "Contact support before it ends to keep using the app."}</p>
    </Card>
  );
}
