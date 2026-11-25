import { useEffect, useState } from "react";
import { setUrlParams, urlParam } from "@/lib/url-state";

const sameRows = <T,>(a: T[] | undefined, b: T[] | undefined) => a === b || (!!a && !!b && a.length === b.length && a.every((x, i) => x === b[i]));

/**
 * Pages through rows that are already loaded; `all` shows every row (used while printing).
 * With `keep`: `key` is what the rows are filtered by (person, dates), so only a change of it starts again at page 1 and
 * the same list reloaded after an edit keeps its page; `urlKey` keeps the page in the address (?page=3) while this list
 * is the one shown. Without it, any change of the rows starts again at page 1.
 */
export function usePaging<T>(rows: T[] | undefined, all: boolean, size = 15, keep?: { key: string; urlKey?: string }) {
  const [state, setState] = useState<{ rows: T[] | undefined; key: string | undefined; page: number }>(() => ({
    rows, key: keep?.key, page: Math.max(1, Math.floor(Number(keep?.urlKey ? urlParam(keep.urlKey) : null)) || 1),
  }));

  // new rows or a new filter start again at page 1 - decided while rendering, not in an effect
  // (compared by content without `keep`: some callers hand over a new, equal list on every render)
  const changed = keep ? state.key !== keep.key : !sameRows(state.rows, rows);
  const page = changed ? 1 : state.page;
  if (changed) setState({ rows, key: keep?.key, page: 1 });

  const total = rows?.length ?? 0;
  const last = Math.max(1, Math.ceil(total / size));
  const current = Math.min(page, last);
  const items = !rows ? [] : all ? rows : rows.slice((current - 1) * size, current * size);

  // only the list on screen (its rows loaded) writes the address, so a hidden one cannot take its page away
  const shown = rows !== undefined;
  const urlKey = keep?.urlKey;
  useEffect(() => {
    if (urlKey && shown) setUrlParams({ [urlKey]: page > 1 ? String(page) : null });
  }, [urlKey, shown, page]);

  return { items, setPage: (p: number) => setState({ rows, key: keep?.key, page: p }), meta: { current_page: current, last_page: last, per_page: size, total } };
}
