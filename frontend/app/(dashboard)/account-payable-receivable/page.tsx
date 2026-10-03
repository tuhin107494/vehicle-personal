"use client";

import { useState } from "react";
import { useAuth } from "@/lib/auth";
import { Field, PageHeader } from "@/components/app-ui";
import { AccountForm, SIDES, type Side } from "@/components/forms/AccountForm";

const PERM: Record<Side, string> = { supplier: "purchases.edit", customer: "payments.collect" };

export default function Page() {
  const { can } = useAuth();
  const [side, setSide] = useState<Side>(can(PERM.supplier) ? "supplier" : "customer");

  return (
    <>
      <PageHeader title="Account payable / receivable" />
      <div className="mb-3 max-w-2xl">
        <Field required label="Account of">
          <div className="grid grid-cols-2 gap-2">
            {(Object.keys(SIDES) as Side[]).map((s) => (
              <button
                key={s}
                type="button"
                disabled={!can(PERM[s])}
                onClick={() => setSide(s)}
                className={`rounded-xl px-4 py-2 text-left text-sm ring-1 transition disabled:opacity-40 ${side === s ? "bg-gradient-to-r from-emerald-600 to-green-600 text-white shadow ring-transparent" : "bg-white text-emerald-800 ring-emerald-200 hover:bg-emerald-50"}`}
              >
                <span className="block font-semibold">{SIDES[s].label}</span>
                <span className={`block text-xs ${side === s ? "text-white/85" : "text-slate-500"}`}>{SIDES[s].hint}</span>
              </button>
            ))}
          </div>
        </Field>
      </div>
      <AccountForm key={side} side={side} />
    </>
  );
}
