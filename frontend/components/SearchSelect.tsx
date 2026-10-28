"use client";

import { useEffect, useRef, useState } from "react";
import { ChevronsUpDown, X } from "lucide-react";
import { Input } from "@/components/ui/input";

export type Option = { value: string; label: string; hint?: string };

/**
 * One field that both searches and selects. Type to filter, click (or press Enter) to choose.
 * Pass `onQueryChange` to search on the server; otherwise options are filtered locally.
 */
export function SearchSelect({
  options,
  value,
  onChange,
  placeholder = "Search and select...",
  onQueryChange,
  loading,
  fallbackLabel,
  onCreate,
  createLabel = "Add new",
  onOpen,
}: {
  options: Option[];
  value: string;
  onChange: (value: string, option: Option) => void;
  placeholder?: string;
  onQueryChange?: (query: string) => void;
  loading?: boolean;
  /** Shown when the value was set from outside and is not in the current options. */
  fallbackLabel?: string;
  /** Adds a "+ Add new" choice at the end of the list; it receives what was typed so far. */
  onCreate?: (typed: string) => void;
  createLabel?: string;
  /** Called each time the field is focused, so a list can be fetched only once someone reaches for it. */
  onOpen?: () => void;
}) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");
  const [selected, setSelected] = useState<Option | null>(null);
  const ref = useRef<HTMLDivElement>(null);

  // The chosen option may fall out of a server-filtered list, so remember it.
  const current = options.find((o) => o.value === value) ?? (selected?.value === value ? selected : null) ?? (value && fallbackLabel ? { value, label: fallbackLabel } : null);

  useEffect(() => {
    const close = (e: MouseEvent) => {
      if (!ref.current?.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener("mousedown", close);
    return () => document.removeEventListener("mousedown", close);
  }, []);

  const q = query.trim().toLowerCase();
  const shown = onQueryChange || !q ? options : options.filter((o) => `${o.label} ${o.hint ?? ""}`.toLowerCase().includes(q));

  const clear = () => {
    setSelected(null);
    setQuery("");
    onChange("", { value: "", label: "" });
    onQueryChange?.("");
  };

  const choose = (o: Option) => {
    setSelected(o);
    onChange(o.value, o);
    setOpen(false);
    setQuery("");
    onQueryChange?.("");
  };

  return (
    <div ref={ref} className="relative w-full">
      <Input
        className={`w-full ${current ? "pr-14" : "pr-9"}`}
        placeholder={current ? current.label : placeholder}
        value={open ? query : current?.label ?? ""}
        onFocus={() => { setOpen(true); onOpen?.(); }}
        onChange={(e) => {
          setQuery(e.target.value);
          setOpen(true);
          onQueryChange?.(e.target.value);
        }}
        onKeyDown={(e) => {
          if (e.key === "Escape") setOpen(false);
          if (e.key === "Enter" && open) {
            e.preventDefault();
            if (shown[0]) choose(shown[0]);
          }
        }}
        autoComplete="off"
      />
      {current && (
        <button type="button" aria-label="Clear selection" title="Clear" onMouseDown={(e) => e.preventDefault()} onClick={clear} className="absolute right-8 top-1/2 flex size-5 -translate-y-1/2 items-center justify-center rounded-full text-slate-400 hover:bg-slate-100 hover:text-red-600">
          <X className="size-3.5" />
        </button>
      )}
      <ChevronsUpDown className="pointer-events-none absolute right-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
      {open && (
        <ul className="absolute z-30 mt-1 max-h-60 w-full overflow-y-auto rounded-lg bg-white py-1 text-sm shadow-lg ring-1 ring-emerald-100">
          {shown.map((o) => (
            <li key={o.value}>
              <button
                type="button"
                onMouseDown={(e) => e.preventDefault()}
                onClick={() => choose(o)}
                className={`flex w-full flex-col px-3 py-2 text-left hover:bg-emerald-50 ${o.value === value ? "bg-emerald-50 font-medium text-emerald-800" : "text-slate-800"}`}
              >
                <span>{o.label}</span>
                {o.hint && <span className="text-xs text-slate-500">{o.hint}</span>}
              </button>
            </li>
          ))}
          {!shown.length && <li className="px-3 py-2 text-slate-500">{loading ? "Searching..." : "No matches"}</li>}
          {onCreate && (
            <li className="border-t border-emerald-100">
              <button
                type="button"
                onMouseDown={(e) => e.preventDefault()}
                onClick={() => { setOpen(false); onCreate(query.trim()); }}
                className="w-full px-3 py-2 text-left font-medium text-emerald-700 hover:bg-emerald-50"
              >
                + {createLabel}{query.trim() ? ` "${query.trim()}"` : ""}
              </button>
            </li>
          )}
        </ul>
      )}
    </div>
  );
}
