/* eslint-disable @next/next/no-img-element */
const SIZES = { sm: "size-8 text-xs", md: "size-10 text-sm", lg: "size-24 text-3xl" } as const;

/** A person's photo, or their initial on a green circle when there is none. */
export function Avatar({ name, url, size = "sm", className = "" }: { name: string; url?: string | null; size?: keyof typeof SIZES; className?: string }) {
  const box = `${SIZES[size]} shrink-0 rounded-full ${className}`;
  return url
    ? <img src={url} alt="" className={`${box} object-cover ring-1 ring-emerald-200`} />
    : <span className={`${box} inline-flex items-center justify-center bg-emerald-100 font-bold text-emerald-700`} aria-hidden="true">{name.trim()[0]?.toUpperCase() ?? "?"}</span>;
}
