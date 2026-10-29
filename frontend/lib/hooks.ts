"use client";

import { useCallback, useEffect, useState, useSyncExternalStore } from "react";
import { api, PageMeta } from "@/lib/api";
import type { VehicleCategory } from "@/lib/categories";
import type { PermissionT } from "@/lib/types";

type Meta = PageMeta & { unread_count?: number; total_amount?: number; type_counts?: Record<string, number>; due_count?: number; status_counts?: Record<string, number>; drafts?: import("@/lib/types").DraftT[]; permissions?: PermissionT[]; categories?: VehicleCategory[] };

/** The value, but only after it has stopped changing for `delay` ms, so typing in a search box does not fire a request per key. */
export function useDebounced<T>(value: T, delay = 300): T {
  const [settled, setSettled] = useState(value);
  useEffect(() => {
    const t = setTimeout(() => setSettled(value), delay);
    return () => clearTimeout(t);
  }, [value, delay]);
  return settled;
}

export function useFetch<T>(path: string | null) {
  // what the last finished request returned, and which request (path + reload count) that was
  const [result, setResult] = useState<{ key: string | null; data: T | null; meta: Meta | null; error: string | null }>({ key: null, data: null, meta: null, error: null });
  const [tick, setTick] = useState(0);
  const key = path === null ? null : `${tick}:${path}`;

  useEffect(() => {
    if (!path) return;
    let cancelled = false;
    api<T>(path)
      .then((r) => !cancelled && setResult({ key, data: r.data, meta: r.meta ?? null, error: null }))
      .catch((e) => !cancelled && setResult((prev) => ({ ...prev, key, error: e.message })));
    return () => {
      cancelled = true;
    };
  }, [key, path]);

  const reload = useCallback(() => setTick((t) => t + 1), []);
  // change what is on screen without a request (a row that was just saved, for example); the next reload brings the server's own answer
  const mutate = useCallback((change: (data: T | null, meta: Meta | null) => { data: T | null; meta: Meta | null }) => setResult((prev) => ({ ...prev, ...change(prev.data, prev.meta) })), []);
  // loading until the request for the current path has answered; the previous data stays on screen meanwhile
  const loading = key !== null && result.key !== key;
  // no path means nothing is wanted (a selection was cleared): show nothing, not the last answer
  if (path === null) return { data: null, meta: null, loading: false, error: null, reload, mutate };
  return { data: result.data, meta: result.meta, loading, error: result.error, reload, mutate };
}

const noSubscribe = () => () => {};

/** An element some other part of the page renders (a slot to portal into), or null when it is not there. */
export function useElementById(id: string): HTMLElement | null {
  return useSyncExternalStore(noSubscribe, () => document.getElementById(id), () => null);
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

  // a new filter starts again from page 1
  const [pagedFor, setPagedFor] = useState(filterKey);
  if (pagedFor !== filterKey) {
    setPagedFor(filterKey);
    setPage(1);
  }

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
