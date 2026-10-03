export const money = (v: number | string | null | undefined) =>
  v === null || v === undefined || v === ""
    ? "-"
    : Number(v).toLocaleString("en-BD", { minimumFractionDigits: 0, maximumFractionDigits: 2 });

export const fmtDate = (v?: string | null) =>
  v ? new Date(v).toLocaleDateString("en-GB", { day: "2-digit", month: "short", year: "numeric" }) : "-";

export const label = (v?: string | null) => (v ? v.replace(/_/g, " ") : "-");

export const todayStr = () => new Date().toISOString().slice(0, 10);

export const addDays = (days: number) => {
  const d = new Date();
  d.setDate(d.getDate() + days);
  return d.toISOString().slice(0, 10);
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
