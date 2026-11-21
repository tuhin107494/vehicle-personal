import Link from "next/link";
import { Eye, Pencil, Printer } from "lucide-react";
import type { Col } from "@/components/DataTable";

/**
 * The last column of a list: a View button that opens the row's details page, a Print button when `onPrint` is given,
 * and an Edit button when `editOf` gives the row an edit action (it returns nothing for a row that cannot be edited).
 */
export function viewCol<T>(hrefOf: (row: T) => string, onPrint?: (row: T) => void, editOf?: (row: T) => (() => void) | undefined): Col<T> {
  return {
    header: "Action",
    id: "view",
    cell: ({ row }) => (
      <div className="flex flex-wrap items-center justify-end gap-1.5 md:flex-nowrap">
        <Link className="inline-flex items-center gap-1.5 rounded-lg border border-violet-200 px-2.5 py-1.5 text-xs font-medium text-violet-700 hover:bg-violet-50" href={hrefOf(row.original as T)}>
          <Eye className="size-4" /> View
        </Link>
        {(() => {
          const edit = editOf?.(row.original as T);
          return edit && (
            <button type="button" onClick={edit} className="inline-flex items-center gap-1.5 rounded-lg border border-amber-200 px-2.5 py-1.5 text-xs font-medium text-amber-700 hover:bg-amber-50">
              <Pencil className="size-4" /> Edit
            </button>
          );
        })()}
        {onPrint && (
          <button type="button" onClick={() => onPrint(row.original as T)} className="inline-flex items-center gap-1.5 rounded-lg border border-sky-200 px-2.5 py-1.5 text-xs font-medium text-sky-700 hover:bg-sky-50">
            <Printer className="size-4" /> Print
          </button>
        )}
      </div>
    ),
  };
}
