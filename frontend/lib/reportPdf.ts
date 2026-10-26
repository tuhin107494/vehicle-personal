import { jsPDF } from "jspdf";
import autoTable from "jspdf-autotable";

export type ReportMeta = { shop: string; title: string; period?: string; filters?: string[]; totals?: [string, string | number][] };

/** Builds an A4 landscape PDF: heading, totals as plain text lines, then the report table read from the screen (without its Action column). */
export function downloadReportPdf(meta: ReportMeta, source: HTMLTableElement | null) {
  const doc = new jsPDF({ orientation: "landscape", unit: "mm", format: "a4" });
  const margin = 12;
  const width = doc.internal.pageSize.getWidth();
  let y = 14;

  const mid = width / 2;
  doc.setFont("helvetica", "bold").setFontSize(13).text(meta.shop, mid, y, { align: "center" });
  y += 9;
  doc.setFontSize(22).text(meta.title, mid, y, { align: "center" });
  doc.setFont("helvetica", "normal").setFontSize(9);
  doc.text(`Generated: ${new Date().toLocaleString("en-GB")}`, width - margin, 14, { align: "right" });
  for (const line of [meta.period, meta.filters?.length ? `Filters: ${meta.filters.join("  |  ")}` : ""]) {
    if (!line) continue;
    y += 6;
    doc.text(line, mid, y, { align: "center" });
  }
  y += 3;
  doc.setDrawColor(120).setLineWidth(0.3).line(margin, y, width - margin, y);

  if (meta.totals?.length) {
    y += 5.5;
    doc.setFontSize(10);
    let x = margin;
    for (const [label, value] of meta.totals) {
      doc.setFont("helvetica", "normal").text(`${label}: `, x, y);
      x += doc.getTextWidth(`${label}: `);
      doc.setFont("helvetica", "bold").text(String(value), x, y);
      x += doc.getTextWidth(String(value)) + 10;
    }
    y += 3;
    doc.line(margin, y, width - margin, y);
  }

  if (source) {
    // work on a copy with the button column taken out, kept off screen so its styles still resolve
    const copy = source.cloneNode(true) as HTMLTableElement;
    copy.querySelectorAll("[data-print-hide]").forEach((el) => el.remove());
    copy.style.cssText = "position:absolute;left:-9999px;top:0";
    document.body.appendChild(copy);
    try {
      autoTable(doc, {
        html: copy,
        startY: y + 4,
        margin: { left: margin, right: margin, bottom: 14 },
        theme: "grid",
        styles: { font: "helvetica", fontSize: 8.5, cellPadding: 1.8, textColor: 20, lineColor: [150, 150, 150], lineWidth: 0.2, overflow: "linebreak" },
        headStyles: { fillColor: [235, 235, 235], textColor: 20, fontStyle: "bold" },
        alternateRowStyles: { fillColor: [248, 248, 248] },
        rowPageBreak: "avoid",
      });
    } finally {
      copy.remove();
    }
  }

  const pages = doc.getNumberOfPages();
  for (let i = 1; i <= pages; i++) {
    doc.setPage(i).setFont("helvetica", "normal").setFontSize(8).setTextColor(90);
    doc.text(`Page ${i} of ${pages}`, width - margin, doc.internal.pageSize.getHeight() - 7, { align: "right" });
  }
  doc.save(`${meta.title}.pdf`);
}
