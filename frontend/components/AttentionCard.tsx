import Link from "next/link";
import { AlertTriangle, ClipboardCheck, Clock, HandCoins } from "lucide-react";
import { money } from "@/lib/format";
import { Card } from "@/components/app-ui";
import { useT } from "@/lib/i18n";

type Item = { href: string; label: string; value: string | number; hot: boolean; icon: typeof Clock };

/** The few things on the dashboard worth a look today. A row turns amber/red when there is something to do. */
export function AttentionCard({ payable, overdue, registrationPending, oldStock }: { payable: number; overdue: number; registrationPending: number; oldStock: number }) {
  const { t } = useT();
  const items: Item[] = [
    { href: "/collections", label: "Overdue installments", value: overdue, hot: overdue > 0, icon: AlertTriangle },
    { href: "/supplier-payment", label: "Supplier due", value: money(payable), hot: payable > 0, icon: HandCoins },
    { href: "/vehicles?status=sold&registration=pending", label: "Registration pending", value: registrationPending, hot: registrationPending > 0, icon: ClipboardCheck },
    { href: "/vehicles?status=in_stock", label: "In stock over 30 days", value: oldStock, hot: oldStock > 0, icon: Clock },
  ];
  return (
    <Card title="Needs attention" className="h-full gap-2 py-3">
      <ul className="space-y-1.5">
        {items.map(({ href, label, value, hot, icon: Icon }) => (
          <li key={label}>
            <Link href={href} className={`flex items-center justify-between gap-3 rounded-lg px-3 py-1.5 text-sm ring-1 transition ${hot ? "bg-amber-50 ring-amber-200 hover:bg-amber-100" : "bg-white ring-emerald-100 hover:bg-emerald-50"}`}>
              <span className="flex items-center gap-2 text-slate-700"><Icon className={`size-4 ${hot ? "text-amber-600" : "text-emerald-600"}`} /> {t(label)}</span>
              <b className={hot ? "text-amber-800" : "text-slate-800"}>{value}</b>
            </Link>
          </li>
        ))}
      </ul>
    </Card>
  );
}
