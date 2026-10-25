import Link from "next/link";
import { ArrowDownLeft, ArrowUpRight, Scale } from "lucide-react";
import { money } from "@/lib/format";
import { Card } from "@/components/app-ui";
import { useT } from "@/lib/i18n";

export type TodayFigures = { sales_count: number; sales_total: number; purchases_count: number; purchases_total: number; cash_in: number; cash_out: number; cash_net: number };

/** Today's sales, purchases and cash in one place; opens the full daily report. */
export function TodayCard({ today }: { today: TodayFigures }) {
  const { t } = useT();
  const rows: [string, string, string][] = [
    ["Sold today", `${money(today.sales_total)} (${today.sales_count})`, "text-emerald-700"],
    ["Bought today", `${money(today.purchases_total)} (${today.purchases_count})`, "text-blue-700"],
  ];
  return (
    <Card className="h-full gap-2 py-3" title="Today" actions={<Link className="text-sm font-medium text-emerald-700 underline" href="/daily-report">View all</Link>}>
      <dl className="space-y-2 text-sm">
        {rows.map(([k, v, c]) => (
          <div key={k} className="flex items-center justify-between gap-3"><dt className="text-slate-600">{t(k)}</dt><dd className={`font-bold ${c}`}>{v}</dd></div>
        ))}
        <div className="my-1 border-t border-emerald-100" />
        <div className="flex items-center justify-between gap-3"><dt className="flex items-center gap-1.5 text-slate-600"><ArrowDownLeft className="size-4 text-emerald-600" /> {t("Cash in")}</dt><dd className="font-bold text-emerald-700">{money(today.cash_in)}</dd></div>
        <div className="flex items-center justify-between gap-3"><dt className="flex items-center gap-1.5 text-slate-600"><ArrowUpRight className="size-4 text-amber-600" /> {t("Cash out")}</dt><dd className="font-bold text-amber-700">{money(today.cash_out)}</dd></div>
        <div className="flex items-center justify-between gap-3 rounded-lg bg-emerald-50 px-2 py-1.5"><dt className="flex items-center gap-1.5 font-medium text-slate-700"><Scale className="size-4 text-slate-600" /> {t("Net cash")}</dt><dd className="font-bold text-slate-900">{money(today.cash_net)}</dd></div>
      </dl>
    </Card>
  );
}
