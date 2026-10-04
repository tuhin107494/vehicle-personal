import Link from "next/link";
import { Eye, Printer } from "lucide-react";
import type { Col } from "@/components/DataTable";

/** The last column of a list: a View button that opens the row's details page, and a Print button when `onPrint` is given. */
export function viewCol<T>(hrefOf: (row: T) => string, onPrint?: (row: T) => void): Col<T> {
  return {
    header: "Action",
    id: "view",
    cell: ({ row }) => (
      <div className="flex flex-wrap items-center justify-end gap-1.5 md:flex-nowrap">
        <Link className="inline-flex items-center gap-1.5 rounded-lg border border-violet-200 px-2.5 py-1.5 text-xs font-medium text-violet-700 hover:bg-violet-50" href={hrefOf(row.original as T)}>
          <Eye className="size-4" /> View
        </Link>
        {onPrint && (
          <button type="button" onClick={() => onPrint(row.original as T)} className="inline-flex items-center gap-1.5 rounded-lg border border-sky-200 px-2.5 py-1.5 text-xs font-medium text-sky-700 hover:bg-sky-50">
            <Printer className="size-4" /> Print
          </button>
        )}
      </div>
    ),
  };
}
