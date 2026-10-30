/** One bordered chip for each phone number (the main one and the alternative), side by side, so the two never run together. */
export function PhoneChips({ phone, alt }: { phone?: string | null; alt?: string | null }) {
  const numbers = [phone, alt].filter(Boolean) as string[];
  if (!numbers.length) return <>-</>;

  return (
    <span className="flex flex-wrap gap-1.5">
      {numbers.map((n, i) => (
        <span key={`${n}-${i}`} className="whitespace-nowrap rounded-md border border-sky-300 bg-sky-50 px-2 py-0.5 text-sm text-slate-800">{n}</span>
      ))}
    </span>
  );
}
