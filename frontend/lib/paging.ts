import { useState } from "react";

const sameRows = <T,>(a: T[] | undefined, b: T[] | undefined) => a === b || (!!a && !!b && a.length === b.length && a.every((x, i) => x === b[i]));

/** Pages through rows that are already loaded; `all` shows every row (used while printing). */
export function usePaging<T>(rows: T[] | undefined, all: boolean, size = 15) {
  const [state, setState] = useState<{ rows: T[] | undefined; page: number }>({ rows, page: 1 });

  // new rows (a new search, date or person) start again at page 1 - decided while rendering, not in an effect
  // (compared by content: some callers hand over a new, equal list on every render)
  const same = sameRows(state.rows, rows);
  const page = same ? state.page : 1;
  if (!same) setState({ rows, page: 1 });

  const total = rows?.length ?? 0;
  const last = Math.max(1, Math.ceil(total / size));
  const current = Math.min(page, last);
  const items = !rows ? [] : all ? rows : rows.slice((current - 1) * size, current * size);

  return { items, setPage: (p: number) => setState({ rows, page: p }), meta: { current_page: current, last_page: last, per_page: size, total } };
}
