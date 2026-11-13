const DAY = 24 * 60 * 60 * 1000;

/** A trial end time as the YYYY-MM-DD a date input holds, in the browser's own time zone. */
export function trialDateInput(endsAt: string | null): string {
  if (!endsAt) return "";
  const d = new Date(endsAt);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
}

/** Where a free trial stands, from its end time: whether it ended, and the whole days left (rounded up). */
export function trialState(endsAt: string): { ended: boolean; days: number } {
  const left = new Date(endsAt).getTime() - Date.now();
  return { ended: left <= 0, days: Math.ceil(left / DAY) };
}
