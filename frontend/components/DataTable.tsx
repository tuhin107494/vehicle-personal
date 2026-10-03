"use client";

import { useEffect, useState } from "react";
import { ColumnDef, flexRender, getCoreRowModel, useReactTable } from "@tanstack/react-table";
import { Pagination } from "@/components/app-ui";
import { useT } from "@/lib/i18n";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";

export type Col<T> = ColumnDef<T, any>;

export function DataTable<T>({
  columns,
  data,
  loading,
  onRowClick,
  empty = "No records found.",
  pageSize = 15,
  serverPaged,
}: {
  columns: Col<T>[];
  data: T[];
  loading?: boolean;
  onRowClick?: (row: T) => void;
  empty?: string;
  pageSize?: number;
  /** the page already pages on the server and shows its own pager */
  serverPaged?: boolean;
}) {
  const { t } = useT();
  const table = useReactTable({ data, columns, getCoreRowModel: getCoreRowModel() });

  // every list pages through its rows, so a long list never becomes one endless page
  const [page, setPage] = useState(1);
  useEffect(() => setPage(1), [data]);
  const all = table.getRowModel().rows;
  const last = Math.max(1, Math.ceil(all.length / pageSize));
  const current = Math.min(page, last);
  const rows = all.slice((current - 1) * pageSize, current * pageSize);

  return (
    <>
      {/* Mobile: one card per row, no sideways scrolling */}
      <div className="space-y-3 md:hidden">
        {rows.map((row) => {
          const [first, ...rest] = row.getVisibleCells();
          const headerOf = (c: typeof first) => (typeof c.column.columnDef.header === "string" ? t(c.column.columnDef.header) : "");
          const fields = rest.filter((c) => headerOf(c));
          const actions = rest.filter((c) => !headerOf(c));
          return (
            <div
              key={row.id}
              onClick={() => onRowClick?.(row.original)}
              className={`rounded-xl bg-white/90 p-3 shadow-sm ring-1 ring-emerald-300 ${onRowClick ? "cursor-pointer active:bg-emerald-50" : ""}`}
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

    <div className="hidden overflow-x-auto rounded-xl bg-white/90 shadow-sm ring-1 ring-emerald-300 md:block">
      <Table>
        <TableHeader className="bg-gradient-to-r from-emerald-50 to-green-50">
          {table.getHeaderGroups().map((hg) => (
            <TableRow key={hg.id} className="border-emerald-300 hover:bg-transparent">
              {hg.headers.map((h) => (
                <TableHead key={h.id} className={`${(h.column.columnDef.meta as { className?: string } | undefined)?.className ?? ""} h-10 whitespace-nowrap border-r border-emerald-200 text-xs font-semibold uppercase tracking-wide text-emerald-700 last:border-r-0`}>
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
              className={`border-emerald-200 hover:bg-emerald-50/60 ${onRowClick ? "cursor-pointer" : ""}`}
            >
              {row.getVisibleCells().map((cell) => (
                <TableCell key={cell.id} className={`${(cell.column.columnDef.meta as { className?: string } | undefined)?.className ?? ""} whitespace-nowrap border-r border-emerald-100 py-2.5 text-slate-800 last:border-r-0`}>
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
    {!serverPaged && <div className="print:hidden"><Pagination meta={{ current_page: current, last_page: last, per_page: pageSize, total: all.length }} onPage={setPage} /></div>}
    </>
  );
}
