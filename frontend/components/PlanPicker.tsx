"use client";

import { Check, Minus } from "lucide-react";
import { useT } from "@/lib/i18n";
import { featureLabel } from "@/lib/billing";
import { PlanPrice } from "@/components/PlanPrice";
import type { PlanT, SubscriptionT } from "@/lib/types";
import { Badge, Button } from "@/components/app-ui";

export type PlanAction = "subscribe" | "upgrade" | "downgrade";

/** What pressing a plan's button does for this shop, or null when the plan is the one it is on. */
export function actionFor(plan: PlanT, current: SubscriptionT | null): PlanAction | null {
  const usable = current && ["trialing", "active", "past_due"].includes(current.status);
  if (!usable || current.status === "trialing") return "subscribe";
  if (current.plan && plan.id === current.plan.id) return null;
  return plan.price_paisa > (current.plan?.price_paisa ?? 0) ? "upgrade" : "downgrade";
}

const ACTION_TEXT: Record<PlanAction, string> = { subscribe: "Choose plan", upgrade: "Upgrade", downgrade: "Downgrade" };

/** The plans on offer side by side, each with what it includes and the one button that fits this shop. */
export function PlanPicker({ plans, current, busy, onPick }: { plans: PlanT[]; current: SubscriptionT | null; busy: boolean; onPick: (plan: PlanT, action: PlanAction) => void }) {
  const { t } = useT();
  const pendingId = current?.pending_plan?.id;
  return (
    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
      {plans.map((plan) => {
        const action = actionFor(plan, current);
        const isCurrent = action === null;
        return (
          <div key={plan.id} className={`flex flex-col rounded-xl bg-white/90 p-4 shadow-sm ring-1 ${isCurrent ? "ring-2 ring-emerald-500" : "ring-emerald-300"}`}>
            <div className="flex items-start justify-between gap-2">
              <div>
                <h3 className="text-lg font-semibold text-slate-900">{plan.name}</h3>
                <p className="mt-1 text-sm"><PlanPrice plan={plan} /></p>
              </div>
              {isCurrent && <Badge tone="green">Current plan</Badge>}
              {pendingId === plan.id && <Badge tone="yellow">Next period</Badge>}
            </div>
            {plan.description && <p className="mt-2 text-sm text-slate-600">{plan.description}</p>}
            {plan.trial_days > 0 && <p className="mt-1 text-xs text-emerald-700">{`${plan.trial_days} ${t("days free trial")}`}</p>}
            <ul className="mt-3 flex-1 space-y-1 text-sm">
              {Object.entries(plan.features ?? {}).map(([key, value]) => {
                const off = value === "0";
                const limit = value !== null && value !== "1" && value !== "0";
                return (
                  <li key={key} className={`flex items-center gap-2 ${off ? "text-slate-400" : "text-slate-700"}`}>
                    {off ? <Minus className="size-3.5" /> : <Check className="size-3.5 text-emerald-600" />}
                    <span>{t(featureLabel(key))}{limit ? `: ${value}` : value === null ? `: ${t("Unlimited")}` : ""}</span>
                  </li>
                );
              })}
            </ul>
            <div className="mt-4">
              {action && <Button className="w-full" variant={action === "downgrade" ? "outline" : "primary"} disabled={busy} onClick={() => onPick(plan, action)}>{ACTION_TEXT[action]}</Button>}
            </div>
          </div>
        );
      })}
    </div>
  );
}
