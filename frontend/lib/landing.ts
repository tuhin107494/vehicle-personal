/** The landing page's card look, shared by the server-rendered sections and the client parts (plans), so every card matches. */

/** One look for every card on the page, so they are all the same size. */
export const CARD = "h-full rounded-2xl p-6 shadow-sm ring-1 transition duration-200 hover:-translate-y-1 hover:shadow-lg";

/** Each card has a slight tint of its icon's colour; the sections behind them are plain. */
const TINT: Record<string, string> = {
  emerald: "bg-emerald-50/40 ring-emerald-200 hover:ring-emerald-400",
  sky: "bg-sky-50/40 ring-sky-200 hover:ring-sky-400",
  amber: "bg-amber-50/40 ring-amber-200 hover:ring-amber-400",
  rose: "bg-rose-50/40 ring-rose-200 hover:ring-rose-400",
  teal: "bg-teal-50/40 ring-teal-200 hover:ring-teal-400",
};
export const tint = (tone: string) => TINT[tone.match(/text-(\w+)-/)?.[1] ?? "emerald"];

/** The icon square at the top of a card. */
export const TILE = "mb-4 flex size-12 items-center justify-center rounded-xl";

/** The tones cards take in turn, the same order the Features section uses. */
export const TONES = ["bg-emerald-100 text-emerald-600", "bg-sky-100 text-sky-600", "bg-amber-100 text-amber-600", "bg-teal-100 text-teal-600", "bg-rose-100 text-rose-600"];
