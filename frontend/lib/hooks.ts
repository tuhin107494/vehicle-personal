"use client";

import { useCallback, useEffect, useState, useSyncExternalStore } from "react";
import { api, PageMeta } from "@/lib/api";
import type { VehicleCategory } from "@/lib/categories";
import type { PermissionT } from "@/lib/types";
import { useAuth } from "@/lib/auth";
import { setUrlParams, urlParam, useUrlPage, useUrlPerPage } from "@/lib/url-state";

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

/**
 * A paginated list that grows: page 1 first, then each "load more" adds the next page under it. A new `base` (a new search) starts again from page 1.
 * `base` is the path with its filters and search; paging is added here. Null fetches nothing.
 */
export function useLoadMore<T>(base: string | null, perPage = 20) {
  const [page, setPage] = useState(1);
  const [pagedFor, setPagedFor] = useState(base);
  if (pagedFor !== base) {
    setPagedFor(base);
    setPage(1);
  }

  const result = useFetch<T[]>(base ? `${base}${base.includes("?") ? "&" : "?"}per_page=${perPage}&page=${page}` : null);

  // the pages that have arrived, kept together; set while rendering, only when a page just arrived (it has no effect to wait for)
  const [kept, setKept] = useState<{ base: string | null; pages: T[][] }>({ base: null, pages: [] });
  const arrived = base && !result.loading ? result.data : null;
  if (arrived && (kept.base !== base || kept.pages[page - 1] !== arrived)) {
    setKept({ base, pages: kept.base === base ? [...kept.pages.slice(0, page - 1), arrived] : [arrived] });
  }

  const rows = kept.base === base ? kept.pages.flat() : [];
  const hasMore = !!arrived && !!result.meta && result.meta.current_page < result.meta.last_page;
  const loadMore = () => setPage((p) => p + 1);
  const reload = () => {
    setPage(1);
    result.reload();
  };

  return { data: base ? rows : null, loading: result.loading, hasMore, loadMore, reload };
}

/**
 * The rows per page the shop chose in Settings (1 to 100), or 15. `ready` is false until it is known, so a list waits
 * for it instead of first loading the wrong number of rows. The super admin has no shop: 15.
 */
export function useDefaultPerPage(): { perPage: number; ready: boolean } {
  const { user } = useAuth();
  const settings = useFetch<{ lists?: { per_page?: number } }>(user?.shop ? "/settings" : null);
  const n = settings.data?.lists?.per_page;
  return { perPage: n && n >= 1 && n <= 100 ? n : 15, ready: !settings.loading };
}

const noSubscribe = () => () => {};

/** An element some other part of the page renders (a slot to portal into), or null when it is not there. */
export function useElementById(id: string): HTMLElement | null {
  return useSyncExternalStore(noSubscribe, () => document.getElementById(id), () => null);
}

/**
 * Paginated + searchable list. `filters` are extra query params. The page and search are kept in the page's address
 * (?page=5&search=...), so a refresh, an edit, or coming Back from a record shows the same page; `urlKey` names them
 * apart (urlKey_page) when one page has two lists.
 */
export function usePagedList<T>(base: string, filters: Record<string, string> = {}, urlKey = "") {
  const pageKey = urlKey ? `${urlKey}_page` : "page";
  const searchKey = urlKey ? `${urlKey}_search` : "search";
  const [page, setPage] = useUrlPage(pageKey);
  // rows per page: what was picked under the list (kept in the address), else the shop's default
  const { perPage: defaultPerPage, ready } = useDefaultPerPage();
  const [chosenPerPage, setChosenPerPage] = useUrlPerPage(urlKey ? `${urlKey}_per_page` : "per_page");
  const perPage = chosenPerPage ?? defaultPerPage;
  const [search, setSearch] = useState(() => urlParam(searchKey) ?? "");
  const [debounced, setDebounced] = useState(search);
  const filterKey = JSON.stringify(filters);

  // a new search starts again from page 1; nothing typed since (a search kept in the address) keeps the page
  useEffect(() => {
    if (search === debounced) return;
    const t = setTimeout(() => {
      setDebounced(search);
      setPage(1);
    }, 300);
    return () => clearTimeout(t);
  }, [search, debounced, setPage]);
  useEffect(() => setUrlParams({ [searchKey]: debounced || null }), [searchKey, debounced]);

  // a new filter starts again from page 1
  const [pagedFor, setPagedFor] = useState(filterKey);
  if (pagedFor !== filterKey) {
    setPagedFor(filterKey);
    setPage(1);
  }

  const qs = new URLSearchParams({ page: String(page), per_page: String(perPage), ...(debounced && { search: debounced }) });
  for (const [k, v] of Object.entries(filters)) if (v) qs.set(k, v);

  const result = useFetch<T[]>(ready ? `${base}?${qs}` : null);
  // a page that is no longer there (its last row was deleted, or an old address): the last page there is
  const lastPage = result.meta?.last_page;
  if (!result.loading && lastPage && page > lastPage) setPage(lastPage);
  // after creating a record: back to page 1 with no search, so the new one is on the screen (newest first)
  const showFirst = () => {
    setSearch("");
    setDebounced("");
    setPage(1);
    result.reload();
  };
  // more or fewer rows a page: the page holding the first row shown now
  const setPerPage = (n: number) => {
    setPage(Math.floor(((page - 1) * perPage) / n) + 1);
    setChosenPerPage(n === defaultPerPage ? null : n);
  };
  return { ...result, loading: !ready || result.loading, rows: result.data ?? [], page, setPage, perPage, setPerPage, search, setSearch, showFirst };
}
