"use client";

import { useState } from "react";
import { ColumnDef, flexRender, getCoreRowModel, useReactTable } from "@tanstack/react-table";
import { Pagination } from "@/components/app-ui";
import { useT } from "@/lib/i18n";
import { useDefaultPerPage } from "@/lib/hooks";
import { perPageParam, useUrlPage, useUrlPerPage } from "@/lib/url-state";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";

const ACTION_IDS = ["actions", "view", "pay"]; // columns shown as buttons on a phone card, not as a labelled field

export type Col<T> = ColumnDef<T, unknown>;

export function DataTable<T>({
  columns,
  data,
  loading,
  onRowClick,
  empty = "No records found.",
  pageSize,
  serverPaged,
  pager = true,
  compact,
  cards,
  rowClass,
  urlKey,
  pageKey = "",
}: {
  columns: Col<T>[];
  data: T[];
  loading?: boolean;
  onRowClick?: (row: T) => void;
  empty?: string;
  /** a fixed number of rows a page (a short list, or every row while printing); without it: the number picked under the list, or the shop's default (Settings) */
  pageSize?: number;
  /** the page already pages on the server and shows its own pager */
  serverPaged?: boolean;
  /** false: no page controls, for a short fixed list such as a dashboard card */
  pager?: boolean;
  /** tighter rows, for a small table inside a card */
  compact?: boolean;
  /** always one card per row, never the wide table: for a narrow card on a dashboard */
  cards?: boolean;
  /** extra classes for a row, e.g. to tint it */
  rowClass?: (row: T) => string;
  /** keeps the page number in the address under this name (?page=3), so a refresh or coming Back shows the same page: for a page's main list */
  urlKey?: string;
  /** what the rows are filtered by (the page's filters and search): a change starts again from page 1, a reload of the same list (after an edit) keeps the page */
  pageKey?: string;
}) {
  const { t } = useT();
  const table = useReactTable({ data, columns, getCoreRowModel: getCoreRowModel() });

  // every list pages through its rows, so a long list never becomes one endless page
  const [page, setPage] = useUrlPage(urlKey);
  const { perPage: defaultPerPage } = useDefaultPerPage();
  const [chosenPerPage, setChosenPerPage] = useUrlPerPage(urlKey ? perPageParam(urlKey) : undefined);
  const size = pageSize ?? chosenPerPage ?? defaultPerPage;
  const [pagedFor, setPagedFor] = useState(pageKey);
  if (pagedFor !== pageKey) {
    setPagedFor(pageKey);
    setPage(1);
  }
  const all = table.getRowModel().rows;
  const last = Math.max(1, Math.ceil(all.length / size));
  const current = Math.min(page, last);
  // a list paged on the server shows the page it was sent whole (the booking list adds its saved drafts on top of it)
  const rows = serverPaged ? all : all.slice((current - 1) * size, current * size);
  // more or fewer rows a page: the page holding the first row shown now
  const setPerPage = (n: number) => {
    setPage(Math.floor(((current - 1) * size) / n) + 1);
    setChosenPerPage(n === defaultPerPage ? null : n);
  };

  return (
    <>
      {/* Mobile: one card per row, no sideways scrolling */}
      <div className={`space-y-3 ${cards ? "" : "md:hidden"}`}>
        {rows.map((row) => {
          const [first, ...rest] = row.getVisibleCells();
          const headerOf = (c: typeof first) => (typeof c.column.columnDef.header === "string" ? t(c.column.columnDef.header) : "");
          const fields = rest.filter((c) => headerOf(c) && !ACTION_IDS.includes(c.column.id));
          const actions = rest.filter((c) => !headerOf(c) || ACTION_IDS.includes(c.column.id));
          return (
            <div
              key={row.id}
              onClick={() => onRowClick?.(row.original)}
              className={`rounded-xl bg-white/90 p-3 shadow-sm ring-1 ring-emerald-300 ${onRowClick ? "cursor-pointer active:bg-emerald-50" : ""} ${rowClass?.(row.original) ?? ""}`}
            >
              {first && <div className="mb-2 border-b border-emerald-50 pb-2 font-semibold text-slate-900">{flexRender(first.column.columnDef.cell, first.getContext())}</div>}
              <dl className="space-y-1.5 text-sm">
                {fields.map((c) => (
                  <div key={c.id} className="flex items-start justify-between gap-3">
                    <dt className="shrink-0 text-xs font-medium uppercase tracking-wide text-emerald-700">{headerOf(c)}</dt>
                    <dd className="min-w-0 break-words text-right text-slate-800">{flexRender(c.column.columnDef.cell, c.getContext())}</dd>
                  </div>
                ))}
              </dl>
              {actions.length > 0 && (
                <div className="mt-3 flex min-w-0 justify-end gap-2">
                  {actions.map((c) => <div key={c.id} className="min-w-0 flex-1">{flexRender(c.column.columnDef.cell, c.getContext())}</div>)}
                </div>
              )}
            </div>
          );
        })}
        {!data.length && <p className="rounded-xl bg-white/90 py-8 text-center text-sm text-slate-500 ring-1 ring-emerald-300">{loading ? t("Loading...") : t(empty)}</p>}
      </div>

    <div data-report-table className={`hidden overflow-hidden [&_[data-slot=table-container]]:overflow-x-visible [&_th]:!whitespace-normal [&_td]:!whitespace-normal rounded-xl bg-white/90 shadow-sm ring-1 ring-emerald-300 ${cards ? "" : "md:block"}`}>
      <Table>
        <TableHeader className="bg-gradient-to-r from-emerald-50 to-green-50">
          {table.getHeaderGroups().map((hg) => (
            <TableRow key={hg.id} className="border-emerald-300 hover:bg-transparent">
              {hg.headers.map((h) => (
                <TableHead key={h.id} data-print-hide={ACTION_IDS.includes(h.column.id) || undefined} className={`${h.column.columnDef.header === "Action" ? "text-center " : ""}${(h.column.columnDef.meta as { className?: string } | undefined)?.className ?? ""} ${compact ? "h-8" : "h-10"} whitespace-nowrap border-r border-emerald-200 text-xs font-semibold uppercase tracking-wide text-emerald-700 last:border-r-0`}>
                  {typeof h.column.columnDef.header === "string" ? t(h.column.columnDef.header) : flexRender(h.column.columnDef.header, h.getContext())}
                </TableHead>
              ))}
            </TableRow>
          ))}
        </TableHeader>
        <TableBody>
          {rows.map((row) => (
            <TableRow
              key={row.id}
              onClick={() => onRowClick?.(row.original)}
              className={`border-emerald-200 hover:bg-emerald-50/60 ${onRowClick ? "cursor-pointer" : ""} ${rowClass?.(row.original) ?? ""}`}
            >
              {row.getVisibleCells().map((cell) => (
                <TableCell key={cell.id} data-print-hide={ACTION_IDS.includes(cell.column.id) || undefined} className={`${(cell.column.columnDef.meta as { className?: string } | undefined)?.className ?? ""} whitespace-nowrap border-r border-emerald-100 ${compact ? "py-1.5" : "py-2.5"} text-slate-800 last:border-r-0`}>
                  {flexRender(cell.column.columnDef.cell, cell.getContext())}
                </TableCell>
              ))}
            </TableRow>
          ))}
          {!data.length && (
            <TableRow>
              <TableCell colSpan={columns.length} className="py-8 text-center text-slate-500">
                {loading ? t("Loading...") : t(empty)}
              </TableCell>
            </TableRow>
          )}
        </TableBody>
      </Table>
    </div>
    {!serverPaged && pager && <div className="print:hidden"><Pagination meta={{ current_page: current, last_page: last, per_page: size, total: all.length }} onPage={setPage} onPerPage={pageSize === undefined ? setPerPage : undefined} /></div>}
    </>
  );
}
