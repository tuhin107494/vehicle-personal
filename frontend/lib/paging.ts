import { useEffect, useState } from "react";
import { useDefaultPerPage } from "@/lib/hooks";
import { perPageParam, setUrlParams, urlParam, validPerPage } from "@/lib/url-state";

const sameRows = <T,>(a: T[] | undefined, b: T[] | undefined) => a === b || (!!a && !!b && a.length === b.length && a.every((x, i) => x === b[i]));

/**
 * Pages through rows that are already loaded; `all` shows every row (used while printing).
 * `size` fixes the rows a page (no choice under the list); without it, the number picked under the list (`setPerPage`)
 * or the shop's default (Settings).
 * With `keep`: `key` is what the rows are filtered by (person, dates), so only a change of it starts again at page 1 and
 * the same list reloaded after an edit keeps its page; `urlKey` keeps the page and rows per page in the address while
 * this list is the one shown. Without it, any change of the rows starts again at page 1.
 */
export function usePaging<T>(rows: T[] | undefined, all: boolean, size?: number, keep?: { key: string; urlKey?: string }) {
  const urlKey = keep?.urlKey;
  const perPageKey = urlKey ? perPageParam(urlKey) : undefined;
  const [state, setState] = useState<{ rows: T[] | undefined; key: string | undefined; page: number }>(() => ({
    rows, key: keep?.key, page: Math.max(1, Math.floor(Number(urlKey ? urlParam(urlKey) : null)) || 1),
  }));
  const { perPage: defaultPerPage } = useDefaultPerPage();
  const [chosenPerPage, setChosenPerPage] = useState<number | null>(() => (perPageKey ? validPerPage(urlParam(perPageKey)) : null));
  const perPage = size ?? chosenPerPage ?? defaultPerPage;

  // new rows or a new filter start again at page 1 - decided while rendering, not in an effect
  // (compared by content without `keep`: some callers hand over a new, equal list on every render)
  const changed = keep ? state.key !== keep.key : !sameRows(state.rows, rows);
  const page = changed ? 1 : state.page;
  if (changed) setState({ rows, key: keep?.key, page: 1 });

  const total = rows?.length ?? 0;
  const last = Math.max(1, Math.ceil(total / perPage));
  const current = Math.min(page, last);
  const items = !rows ? [] : all ? rows : rows.slice((current - 1) * perPage, current * perPage);

  // only the list on screen (its rows loaded) writes the address, so a hidden one cannot take its page away
  const shown = rows !== undefined;
  useEffect(() => {
    if (urlKey && shown) setUrlParams({ [urlKey]: page > 1 ? String(page) : null });
  }, [urlKey, shown, page]);
  useEffect(() => {
    if (perPageKey && shown) setUrlParams({ [perPageKey]: chosenPerPage === null ? null : String(chosenPerPage) });
  }, [perPageKey, shown, chosenPerPage]);

  const setPage = (p: number) => setState({ rows, key: keep?.key, page: p });
  // more or fewer rows a page (only when not fixed): the page holding the first row shown now
  const setPerPage = size === undefined
    ? (n: number) => {
        setPage(Math.floor(((current - 1) * perPage) / n) + 1);
        setChosenPerPage(n === defaultPerPage ? null : n);
      }
    : undefined;

  return { items, setPage, setPerPage, meta: { current_page: current, last_page: last, per_page: perPage, total } };
}
