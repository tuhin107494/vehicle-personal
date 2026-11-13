import type { BillingInterval, PlanT, SubscriptionStatus } from "@/lib/types";

/** Paisa from the API shown as taka, e.g. 150000 → "1,500". */
export const taka = (paisa: number | null | undefined) => (paisa === null || paisa === undefined ? "-" : (paisa / 100).toLocaleString("en-BD", { minimumFractionDigits: 0, maximumFractionDigits: 2 }));

/** The whole-taka string typed into a form, as paisa for the API; null when blank. */
export const toPaisa = (v: string) => (v.trim() === "" ? null : Math.round(Number(v) * 100));

/** Every period a plan may run for, matching Plan::INTERVALS on the server: the form's choice, and the "per …" unit after a price. */
export const INTERVALS: { value: BillingInterval; label: string; unit: string }[] = [
  { value: "monthly", label: "Monthly", unit: "month" },
  { value: "quarterly", label: "Every 3 months", unit: "3 months" },
  { value: "half_yearly", label: "Every 6 months", unit: "6 months" },
  { value: "yearly", label: "Yearly", unit: "year" },
];
const intervalOf = (value: string) => INTERVALS.find((i) => i.value === value) ?? INTERVALS[0];
export const intervalLabel = (value: string) => intervalOf(value).label;
export const intervalUnit = (value: string) => intervalOf(value).unit;

export const planPrice = (p: PlanT) => (p.price_paisa === 0 ? "Free" : `${taka(p.price_paisa)} / ${intervalUnit(p.billing_interval)}`);

/** What each feature key is called on screen; the keys come from the API's feature catalog. */
export const FEATURE_LABELS: Record<string, string> = {
  max_staff: "Staff accounts", max_vehicles_in_stock: "Vehicles in stock", max_sales_per_month: "Sales per month",
  investors: "Investor funding", installments: "Installment sales", bookings: "Bookings", reports: "Reports", customer_import: "Customer import",
};
export const featureLabel = (key: string) => FEATURE_LABELS[key] ?? key.replace(/_/g, " ");

export const SUBSCRIPTION_TONE: Record<SubscriptionStatus, string> = { trialing: "blue", active: "green", past_due: "yellow", expired: "red", cancelled: "gray" };
export const SUBSCRIPTION_LABEL: Record<SubscriptionStatus, string> = { trialing: "Trial", active: "Active", past_due: "Payment due", expired: "Expired", cancelled: "Cancelled" };

/** One key per click on Pay: letters and digits only, 32 long, as the API asks. A repeat with the same key gets the same attempt back. */
export const newIdempotencyKey = () => (typeof crypto !== "undefined" && "randomUUID" in crypto ? crypto.randomUUID().replace(/-/g, "") : Array.from({ length: 32 }, () => "abcdefghijklmnopqrstuvwxyz0123456789"[Math.floor(Math.random() * 36)]).join(""));
