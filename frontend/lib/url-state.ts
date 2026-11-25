"use client";

import { useEffect, useState } from "react";

/**
 * A list's page and filters kept in the page's address (?page=5&status=sold): a refresh, an edit, or coming Back from a
 * record keeps the same page and filters. The dashboard renders only in the browser (after sign-in), so these read the
 * address when a list first appears.
 */

/** One value of the address's query, or null when it has none. */
export function urlParam(key: string): string | null {
  return typeof window === "undefined" ? null : new URLSearchParams(window.location.search).get(key);
}

/**
 * Puts these values in the address in place of the current ones; null takes one out. It replaces the history entry
 * (Back still leaves the page), and Next.js keeps its router (useSearchParams) in step with the browser's own call.
 */
export function setUrlParams(values: Record<string, string | null>) {
  if (typeof window === "undefined") return;
  const query = new URLSearchParams(window.location.search);
  for (const [key, value] of Object.entries(values)) {
    if (value === null) query.delete(key);
    else query.set(key, value);
  }
  const qs = query.toString();
  const url = `${window.location.pathname}${qs ? `?${qs}` : ""}${window.location.hash}`;
  if (url !== `${window.location.pathname}${window.location.search}${window.location.hash}`) window.history.replaceState(null, "", url);
}

/** Like useState, kept in the address as ?key=value. The default is left out of the address, so a plain link shows it. */
export function useUrlState(key: string, initial: string): [string, (value: string) => void] {
  const [value, setValue] = useState(() => urlParam(key) ?? initial);
  useEffect(() => setUrlParams({ [key]: value === initial ? null : value }), [key, value, initial]);
  return [value, setValue];
}

/** A page number kept in the address as ?key=3 (page 1 is left out); no key keeps it only on screen. */
export function useUrlPage(key?: string): [number, (page: number) => void] {
  const [page, setPage] = useState(() => Math.max(1, Math.floor(Number(key ? urlParam(key) : null)) || 1));
  useEffect(() => {
    if (key) setUrlParams({ [key]: page > 1 ? String(page) : null });
  }, [key, page]);
  return [page, setPage];
}

/** A rows-per-page number from the address: a whole number from 1 to 100, else null (the shop's default is used). */
export function validPerPage(value: string | null): number | null {
  const n = Number(value);
  return value && Number.isInteger(n) && n >= 1 && n <= 100 ? n : null;
}

/** Where a list keeps its rows per page in the address, beside its page number (?page=2&per_page=50). */
export const perPageParam = (pageKey: string) => (pageKey === "page" ? "per_page" : `${pageKey}_per_page`);

/** The rows per page picked under a list, kept in the address under `key` (left out while it is the shop's default); null until one is picked. */
export function useUrlPerPage(key?: string): [number | null, (n: number | null) => void] {
  const [perPage, setPerPage] = useState<number | null>(() => (key ? validPerPage(urlParam(key)) : null));
  useEffect(() => {
    if (key) setUrlParams({ [key]: perPage === null ? null : String(perPage) });
  }, [key, perPage]);
  return [perPage, setPerPage];
}
