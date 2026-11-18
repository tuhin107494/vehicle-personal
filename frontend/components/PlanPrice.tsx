"use client";

import { useT } from "@/lib/i18n";
import { intervalUnit, taka } from "@/lib/billing";
import type { PlanT } from "@/lib/types";

/**
 * A plan's price: with a discount, the regular price struck through, the discount in taka and percent, then the price
 * after discount that the shop pays. `large` is for the landing page cards, the default for lists and the plan picker.
 */
export function PlanPrice({ plan, large = false }: { plan: PlanT; large?: boolean }) {
  const { t } = useT();
  const unit = t(intervalUnit(plan.billing_interval));

  if (plan.price_paisa === 0 && !plan.discount_paisa) {
    return <span className={large ? "text-3xl font-extrabold text-slate-900" : "font-semibold text-slate-900"}>{t("Free")}</span>;
  }

  return (
    <span className="inline-flex flex-col gap-0.5">
      {plan.discount_paisa > 0 && plan.original_price_paisa !== null && (
        <span className={`flex flex-wrap items-center gap-2 ${large ? "text-sm" : "text-xs"}`}>
          <span className="text-slate-500 line-through" aria-label={`${t("Regular price")} ৳${taka(plan.original_price_paisa)}`}>৳{taka(plan.original_price_paisa)}</span>
          <span className="rounded-full bg-rose-100 px-2 py-0.5 font-semibold text-rose-700">{`${plan.discount_percent}% ${t("off")}`}</span>
        </span>
      )}
      <span>
        <span className={large ? "text-3xl font-extrabold text-slate-900" : "font-semibold text-slate-900"}>{plan.price_paisa === 0 ? t("Free") : `৳${taka(plan.price_paisa)}`}</span>
        {plan.price_paisa > 0 && <span className={`${large ? "text-sm" : "text-xs"} text-slate-500`}> / {unit}</span>}
      </span>
      {plan.discount_paisa > 0 && <span className={`${large ? "text-sm" : "text-xs"} font-medium text-emerald-700`}>{`${t("You save")} ৳${taka(plan.discount_paisa)}`}</span>}
    </span>
  );
}
