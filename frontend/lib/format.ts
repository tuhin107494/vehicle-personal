export const money = (v: number | string | null | undefined) =>
  v === null || v === undefined || v === ""
    ? "-"
    : Number(v).toLocaleString("en-BD", { minimumFractionDigits: 0, maximumFractionDigits: 2 });

export const fmtDate = (v?: string | null) =>
  v ? new Date(v).toLocaleDateString("en-GB", { day: "2-digit", month: "short", year: "numeric" }) : "-";

export const label = (v?: string | null) => (v ? v.replace(/_/g, " ") : "-");

// the shop's own calendar day (the server runs on the same time zone), not UTC: between midnight and 6am in Dhaka UTC is still yesterday
const dayOf = (d: Date) => d.toLocaleDateString("en-CA");
export const todayStr = () => dayOf(new Date());
/** The same day a year ago: the reports open on the last year (from here to today). */
export const yearAgoStr = () => {
  const d = new Date();
  d.setFullYear(d.getFullYear() - 1);
  return dayOf(d);
};

export const addDays = (days: number) => {
  const d = new Date();
  d.setDate(d.getDate() + days);
  return dayOf(d);
};

/** The same day next month (the last day when that month is shorter: 31 Jan → 28 Feb), from a "YYYY-MM-DD" day or from today. */
export const nextMonth = (from?: string) => {
  const d = from ? new Date(from + "T00:00:00") : new Date();
  const day = d.getDate();
  d.setDate(1);
  d.setMonth(d.getMonth() + 1);
  d.setDate(Math.min(day, new Date(d.getFullYear(), d.getMonth() + 1, 0).getDate()));
  return dayOf(d);
};

/** Turn form strings into API payload: "" becomes undefined, numeric fields become numbers. */
export function toPayload(values: Record<string, unknown>, numeric: string[] = []) {
  const out: Record<string, unknown> = {};
  for (const [k, v] of Object.entries(values)) {
    if (v === "" || v === undefined || v === null) continue;
    out[k] = numeric.includes(k) ? Number(v) : v;
  }
  return out;
}

/** A mobile number: optional +, then 7 to 20 digits, spaces or dashes. The server checks the same rule. */
export const MOBILE_RX = /^\+?[0-9\s-]{7,20}$/;
export const MOBILE_MSG = "Enter a valid mobile number (7 to 20 digits).";

/** The one wording for every save message: "Customer added successfully", "Vehicle updated successfully". */
export const savedMsg = (thing: string, edited: boolean) => `${thing} ${edited ? "updated" : "added"} successfully`;
