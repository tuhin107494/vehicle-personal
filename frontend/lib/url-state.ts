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
