"use client";

import { useCallback, useEffect, useState } from "react";
import { api, PageMeta } from "@/lib/api";

export function useFetch<T>(path: string | null) {
  const [data, setData] = useState<T | null>(null);
  const [meta, setMeta] = useState<(PageMeta & { unread_count?: number; total_amount?: number; type_counts?: Record<string, number> }) | null>(null);
  const [loading, setLoading] = useState(path !== null);
  const [error, setError] = useState<string | null>(null);
  const [tick, setTick] = useState(0);

  useEffect(() => {
    if (!path) return;
    let cancelled = false;
    setLoading(true);
    api<T>(path)
      .then((r) => {
        if (cancelled) return;
        setData(r.data);
        setMeta(r.meta ?? null);
        setError(null);
      })
      .catch((e) => !cancelled && setError(e.message))
      .finally(() => !cancelled && setLoading(false));
    return () => {
      cancelled = true;
    };
  }, [path, tick]);

  const reload = useCallback(() => setTick((t) => t + 1), []);
  return { data, meta, loading, error, reload };
}

/** Paginated + searchable list. `filters` are extra query params. */
export function usePagedList<T>(base: string, filters: Record<string, string> = {}) {
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState("");
  const [debounced, setDebounced] = useState("");
  const filterKey = JSON.stringify(filters);

  useEffect(() => {
    const t = setTimeout(() => {
      setDebounced(search);
      setPage(1);
    }, 300);
    return () => clearTimeout(t);
  }, [search]);

  useEffect(() => setPage(1), [filterKey]);

  const qs = new URLSearchParams({ page: String(page), ...(debounced && { search: debounced }) });
  for (const [k, v] of Object.entries(filters)) if (v) qs.set(k, v);

  const result = useFetch<T[]>(`${base}?${qs}`);
  // after creating a record: back to page 1 with no search, so the new one is on the screen (newest first)
  const showFirst = () => {
    setSearch("");
    setDebounced("");
    setPage(1);
    result.reload();
  };
  return { ...result, rows: result.data ?? [], page, setPage, search, setSearch, showFirst };
}
