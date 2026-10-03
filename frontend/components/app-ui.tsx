"use client";

import { ReactNode } from "react";
import type { LucideIcon } from "lucide-react";
import { PageMeta } from "@/lib/api";
import { label } from "@/lib/format";
import { cn } from "@/lib/utils";
import { Badge as ShadBadge } from "@/components/ui/badge";
import { Button as ShadButton } from "@/components/ui/button";
import { Card as ShadCard, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Label } from "@/components/ui/label";
import { useRouter, usePathname } from "next/navigation";
import { Children, isValidElement } from "react";
import { toBnDigits as toBn, useT } from "@/lib/i18n";
import { ArrowLeft, ChevronLeft, ChevronRight } from "lucide-react";

/** Translates plain-text children (keeps icons and other elements as they are). */
function useTx() {
  const { t } = useT();
  return (node: ReactNode): ReactNode => Children.map(node, (c) => (typeof c === "string" ? t(c) : c));
}

/** Applied to shadcn Input / Textarea / NativeSelect so they fill their container. */
export const inputCls = "w-full";

export function Button({
  variant = "primary",
  className,
  ...props
}: Omit<React.ComponentProps<typeof ShadButton>, "variant"> & { variant?: "primary" | "outline" | "danger" }) {
  const map = {
    primary: { variant: "default" as const, cls: "bg-gradient-to-r from-emerald-600 to-green-600 text-white shadow-sm hover:from-emerald-500 hover:to-green-500" },
    outline: { variant: "outline" as const, cls: "border-emerald-200 text-emerald-700 hover:bg-emerald-50 hover:text-emerald-800" },
    danger: { variant: "default" as const, cls: "bg-gradient-to-r from-rose-600 to-red-600 text-white hover:from-rose-500 hover:to-red-500" },
  }[variant];
  const tx = useTx();
  const { children, ...rest } = props;
  return <ShadButton type="button" size="lg" variant={map.variant} className={cn(map.cls, className)} {...rest}>{tx(children)}</ShadButton>;
}

export function Field({ label: text, error, required, children }: { label: string; error?: string; required?: boolean; children: ReactNode }) {
  const { t } = useT();
  return (
    <div className="space-y-1.5">
      <Label className="text-slate-700">
        <span>
          {t(text)}
          {required && (
            <>
              <span className="ml-0.5 text-red-600" aria-hidden="true">*</span>
              <span className="sr-only"> (required)</span>
            </>
          )}
        </span>
      </Label>
      {children}
      {error && <p className="text-sm text-red-600">{error}</p>}
    </div>
  );
}

export function Modal({ open, title: rawTitle, onClose, children, wide, compact }: { open: boolean; title: string; onClose: () => void; children: ReactNode; wide?: boolean; compact?: boolean }) {
  const { t } = useT();
  const title = t(rawTitle);
  return (
    <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
      <DialogContent className={cn("max-h-[90vh] gap-4 overflow-y-auto p-6 sm:max-w-lg", compact && "max-sm:gap-2 max-sm:p-3", wide && "sm:max-w-2xl")}>
        <DialogHeader>
          <DialogTitle className={cn("bg-gradient-to-r from-emerald-600 to-green-600 bg-clip-text text-lg font-semibold text-transparent", compact && "max-sm:text-sm")}>{title}</DialogTitle>
        </DialogHeader>
        {children}
      </DialogContent>
    </Dialog>
  );
}

const TONES: Record<string, string> = {
  green: "bg-green-100 text-green-800",
  yellow: "bg-amber-100 text-amber-800",
  orange: "bg-orange-100 text-orange-800",
  red: "bg-red-100 text-red-700",
  blue: "bg-sky-100 text-sky-800",
  slate: "bg-slate-200 text-slate-700",
  gray: "bg-slate-100 text-slate-600",
};

const STATUS_TONE: Record<string, string> = {
  paid: "green", kept: "green", active: "green", in_stock: "blue", document_provided: "green", registration_complete: "green",
  overdue: "red", broken: "red", cancelled: "gray", unpaid: "red", partial: "yellow", open: "yellow", pending: "gray",
  sold: "slate", booked: "yellow", installment_overdue: "red", promise_broken: "red",
  installment_upcoming: "yellow", promise_upcoming: "yellow",
  bank_chalan_done: "blue", file_received: "blue", file_processing: "yellow",
};

export function Badge({ children, tone }: { children: ReactNode; tone?: string }) {
  const tx = useTx();
  return (
    <ShadBadge variant="secondary" className={cn("border-transparent capitalize", TONES[tone ?? "gray"])}>
      {tx(children)}
    </ShadBadge>
  );
}

export const StatusBadge = ({ status }: { status: string }) => (
  <Badge tone={STATUS_TONE[status] ?? "gray"}>{label(status)}</Badge>
);

export function Card({ title, actions, children, className = "" }: { title?: string; actions?: ReactNode; children: ReactNode; className?: string }) {
  const { t } = useT();
  return (
    <ShadCard className={cn("overflow-visible border-0 bg-white/90 shadow-sm ring-1 ring-emerald-300", className)}>
      {(title || actions) && (
        <CardHeader className="flex flex-row items-center justify-between">
          <CardTitle className="text-base text-slate-800">{title ? t(title) : title}</CardTitle>
          {actions}
        </CardHeader>
      )}
      <CardContent>{children}</CardContent>
    </ShadCard>
  );
}

/** On a details page: goes back to the list the user came from; opens the dashboard when there is nowhere to go back to. */
function BackButton() {
  const { t } = useT();
  const router = useRouter();
  const pathname = usePathname();
  // only on a details page (vehicles/4, sales/9, investors/1 ...), reached from a list with View; list pages and menu pages have no Back
  const parts = pathname.split("/").filter(Boolean);
  if (parts.length < 2 || parts[1] === "new") return null;
  return (
    <button
      type="button"
      aria-label="Back"
      onClick={() => (window.history.length > 1 ? router.back() : router.push("/"))}
      className="inline-flex h-8 shrink-0 items-center gap-1 rounded-lg border border-emerald-200 bg-white px-2 text-xs font-medium text-emerald-700 hover:bg-emerald-50 print:hidden sm:px-3 sm:text-sm"
    >
      <ArrowLeft className="size-4" /> <span className="hidden sm:inline">{t("Back")}</span>
    </button>
  );
}

export function PageHeader({ title, actions, titleClass }: { title: string; actions?: ReactNode; titleClass?: string }) {
  const { t } = useT();
  // back button, title and action buttons share one row when they fit; several buttons drop to their own row
  return (
    <div className="mb-5 flex flex-wrap items-center justify-between gap-2">
      <div className="flex min-w-0 items-center gap-2">
        <BackButton />
        <h1 className={cn("min-w-0 bg-gradient-to-r from-emerald-700 via-green-600 to-green-500 bg-clip-text text-2xl font-bold tracking-tight text-transparent sm:text-3xl", titleClass)}>{t(title)}</h1>
      </div>
      <div className="ml-auto flex max-w-full flex-wrap justify-end gap-1.5 max-sm:[&_a]:px-2.5 max-sm:[&_a]:py-1.5 max-sm:[&_a]:text-xs max-sm:[&_button]:h-8 max-sm:[&_button]:gap-1 max-sm:[&_button]:px-2.5 max-sm:[&_button]:text-xs sm:gap-2">{actions}</div>
    </div>
  );
}

const STAT_COLORS = {
  green: "from-emerald-500 to-green-600",
  blue: "from-sky-500 to-blue-600",
  amber: "from-amber-400 to-orange-500",
  red: "from-rose-500 to-red-600",
  orange: "from-orange-400 to-orange-600",
  yellow: "from-yellow-400 to-amber-500",
  slate: "from-slate-500 to-slate-700",
  // calmer shades of the same colours; the three sit at the same lightness so cards side by side look even
  "amber-soft": "from-amber-400 to-amber-500",
  "blue-soft": "from-blue-400 to-blue-500",
  "green-soft": "from-emerald-400 to-emerald-500",
};

export function Stat({ label: text, value, tone, color = "green", icon: Icon }: { label: string; value: ReactNode; tone?: "red"; color?: keyof typeof STAT_COLORS; icon?: LucideIcon }) {
  const { t } = useT();
  const gradient = STAT_COLORS[tone === "red" ? "red" : color];
  return (
    <div className={`min-w-0 rounded-xl bg-gradient-to-br ${gradient} px-2 py-1.5 text-white shadow sm:p-4 sm:shadow-md`}>
      <div className="flex items-center justify-between">
        <p className="text-[10px] text-white/85 sm:text-sm">{t(text)}</p>
        {Icon && <span className="flex size-8 items-center justify-center rounded-lg bg-white/25"><Icon className="size-4" /></span>}
      </div>
      <p className="break-words text-sm font-bold sm:mt-1 sm:text-2xl">{value}</p>
    </div>
  );
}

export function KV({ items }: { items: [string, ReactNode][] }) {
  const { t } = useT();
  return (
    <dl className="grid grid-cols-2 gap-x-4 gap-y-3 text-sm sm:grid-cols-3">
      {items.map(([k, v]) => (
        <div key={k}>
          <dt className="text-xs font-medium uppercase tracking-wide text-emerald-400">{t(k)}</dt>
          <dd className="font-medium text-slate-800">{v ?? "-"}</dd>
        </div>
      ))}
    </dl>
  );
}

export function Pagination({ meta, onPage }: { meta: PageMeta | null; onPage: (p: number) => void }) {
  const { lang } = useT();
  // shown on every list, even when it fits on one page, so the footer is always the same
  if (!meta || meta.total === 0) return null;
  return (
    <div className="mt-3 flex items-center justify-between gap-2 text-xs text-slate-600 sm:text-sm">
      <span className="min-w-0">
        {lang === "bn" ? `পৃষ্ঠা ${toBn(String(meta.current_page))} / ${toBn(String(meta.last_page))} · ${toBn(String(meta.total))}টি তথ্য` : `Page ${meta.current_page} of ${meta.last_page} · ${meta.total} record${meta.total === 1 ? "" : "s"}`}
      </span>
      <div className="flex shrink-0 gap-1.5 sm:gap-2">
        <Button className="h-8 px-2.5 text-xs sm:h-10 sm:px-4 sm:text-sm" variant="outline" disabled={meta.current_page <= 1} onClick={() => onPage(meta.current_page - 1)}><ChevronLeft className="size-4 " /> Previous</Button>
        <Button className="h-8 px-2.5 text-xs sm:h-10 sm:px-4 sm:text-sm" variant="outline" disabled={meta.current_page >= meta.last_page} onClick={() => onPage(meta.current_page + 1)}>Next <ChevronRight className="size-4" /></Button>
      </div>
    </div>
  );
}

export const Loading = () => {
  const { t } = useT();
  return <p className="py-6 text-sm text-slate-500">{t("Loading...")}</p>;
};
export const ErrorText = ({ message }: { message: string }) => <p className="py-6 text-sm text-red-600">{message}</p>;
