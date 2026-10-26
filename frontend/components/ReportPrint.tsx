"use client";

import { useState } from "react";
import { useAuth } from "@/lib/auth";
import { PrintButton } from "@/components/PrintButton";
import { downloadReportPdf, type ReportMeta } from "@/lib/reportPdf";

/** PDF for a report. While it builds, the table shows every row (not just this page) so the PDF holds them all. */
export function useReportPrint(title: string) {
  const { user } = useAuth();
  const [building, setBuilding] = useState(false);
  const run = (meta: Omit<ReportMeta, "shop" | "title"> = {}) => {
    setBuilding(true);
    setTimeout(() => {
      try {
        downloadReportPdf({ shop: user?.shop?.name ?? "Vehicle Hub", title, ...meta }, document.querySelector<HTMLTableElement>("[data-report-table] table"));
      } finally {
        setBuilding(false);
      }
    }, 150);
  };
  return { run, pageSize: building ? 100000 : 15 };
}

export function ReportPrint({ pr, meta, disabled }: { pr: ReturnType<typeof useReportPrint>; meta?: Omit<ReportMeta, "shop" | "title">; disabled?: boolean }) {
  return <PrintButton disabled={disabled} onClick={() => pr.run(meta)} />;
}
