// Each vehicle type gets its own colour. The pick comes from the type's id, so it looks random, never
// changes for a type, and neighbouring types never share a colour. No reds, pinks or oranges. Class names are written out in
// full so Tailwind can see them. The palette is ordered so consecutive picks are clearly different.
const PALETTE = [
  { ring: "ring-sky-400", title: "text-sky-700", badge: "bg-sky-100 text-sky-800" },
  { ring: "ring-amber-400", title: "text-amber-700", badge: "bg-amber-100 text-amber-800" },
  { ring: "ring-violet-400", title: "text-violet-700", badge: "bg-violet-100 text-violet-800" },
  { ring: "ring-lime-500", title: "text-lime-700", badge: "bg-lime-100 text-lime-800" },
  { ring: "ring-blue-400", title: "text-blue-700", badge: "bg-blue-100 text-blue-800" },
  { ring: "ring-teal-400", title: "text-teal-700", badge: "bg-teal-100 text-teal-800" },
  { ring: "ring-purple-400", title: "text-purple-700", badge: "bg-purple-100 text-purple-800" },
  { ring: "ring-cyan-500", title: "text-cyan-700", badge: "bg-cyan-100 text-cyan-800" },
  { ring: "ring-indigo-400", title: "text-indigo-700", badge: "bg-indigo-100 text-indigo-800" },
  { ring: "ring-emerald-400", title: "text-emerald-700", badge: "bg-emerald-100 text-emerald-800" },
];

export function typeColor(id: number) {
  return PALETTE[Math.abs(id) % PALETTE.length];
}
