import type { ReactNode } from "react";
import { useT } from "@/lib/i18n";

export type CardItem = { key: string | number; title: ReactNode; sub?: ReactNode; amount?: ReactNode; fields: [string, ReactNode][]; action?: ReactNode };
export type CardTone = { ring: string; rule: string; foot: string };

/** Phone layout for a report table: one card per row and a totals card, so nothing scrolls sideways. */
export function MobileCards({ items, tone, empty, totals }: { items: CardItem[]; tone: CardTone; empty: string; totals?: [string, ReactNode][] }) {
  const { t } = useT();
  return (
    <div className="space-y-2 p-2 md:hidden print:hidden">
      {items.map((it) => (
        <div key={it.key} className={`rounded-lg bg-white p-3 ring-1 ${tone.ring}`}>
          <div className="flex items-start justify-between gap-3">
            <div className="min-w-0">
              <div className="font-semibold text-slate-900">{it.title}</div>
              {it.sub && <div className="text-xs text-slate-500">{it.sub}</div>}
            </div>
            {it.amount && <div className="shrink-0 text-right font-semibold text-slate-900">{it.amount}</div>}
          </div>
          {it.fields.length > 0 && (
            <dl className={`mt-2 space-y-1 border-t ${tone.rule} pt-2 text-sm`}>
              {it.fields.map(([k, v]) => (
                <div key={k} className="flex justify-between gap-3">
                  <dt className="text-xs uppercase tracking-wide text-slate-500">{t(k)}</dt>
                  <dd className="min-w-0 break-words text-right text-slate-800">{v}</dd>
                </div>
              ))}
            </dl>
          )}
          {it.action && <div className="mt-2 flex justify-end">{it.action}</div>}
        </div>
      ))}
      {!items.length && <p className="py-4 text-center text-sm text-slate-500">{t(empty)}</p>}
      {totals && (
        <div className={`rounded-lg border-2 ${tone.foot} p-3 text-sm font-semibold`}>
          {totals.map(([k, v]) => (
            <div key={k} className="flex justify-between gap-3"><span>{t(k)}</span><span>{v}</span></div>
          ))}
        </div>
      )}
    </div>
  );
}
