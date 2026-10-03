import Link from "next/link";
import { Eye } from "lucide-react";
import type { Col } from "@/components/DataTable";

/** The last column of a list: a View button that opens the row's details page. */
export function viewCol<T>(hrefOf: (row: T) => string): Col<T> {
  return {
    header: "Action",
    id: "view",
    cell: ({ row }) => (
      <Link className="inline-flex items-center gap-1.5 rounded-lg border border-violet-200 px-2.5 py-1.5 text-xs font-medium text-violet-700 hover:bg-violet-50" href={hrefOf(row.original as T)}>
        <Eye className="size-4" /> View
      </Link>
    ),
  };
}
