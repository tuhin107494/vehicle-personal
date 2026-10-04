"use client";

import { Download } from "lucide-react";
import { fmtDate, money } from "@/lib/format";
import { Button, Modal } from "@/components/app-ui";

export type VoucherTx = {
  no: string; date: string; person: string; phone: string | null; description: string; vehicle: string | null; reference: string | null;
  charge: number; paid: number; details: [string, string][];
  /** Wording overrides, for a voucher that is not a plain customer/supplier transaction (e.g. a vehicle purchase). */
  title?: string; personLabel?: string; amountLabel?: string;
};

const esc = (v: string) => v.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");

/** Prints just this transaction from a hidden frame (no new tab, so it works on phones too); "Save as PDF" in the print dialog downloads it. */
export function printVoucher(tx: VoucherTx, side: "customer" | "supplier", shop: string) {
  const frame = document.createElement("iframe");
  frame.setAttribute("aria-hidden", "true");
  frame.style.cssText = "position:fixed;right:0;bottom:0;width:0;height:0;border:0";
  document.body.appendChild(frame);
  const w = frame.contentWindow;
  if (!w) { frame.remove(); return; }
  const amount = tx.charge || tx.paid;
  const rows: [string, string][] = [
    ["Voucher no", tx.no], ["Date", fmtDate(tx.date)], [tx.personLabel ?? (side === "customer" ? "Customer" : "Supplier"), `${tx.person}${tx.phone ? ` · ${tx.phone}` : ""}`],
    ["Details", tx.description], ...(tx.reference ? ([["Reference", tx.reference]] as [string, string][]) : []), ...tx.details,
    [tx.amountLabel ?? (tx.charge ? (side === "customer" ? "Amount sold" : "Amount bought") : side === "customer" ? "Amount received" : "Amount paid"), money(amount)],
  ];
  w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>${esc(tx.no)}</title>
<style>body{font-family:system-ui,sans-serif;margin:32px;color:#111}h1{font-size:20px;margin:0}h2{font-size:15px;margin:4px 0 18px;font-weight:600}
table{border-collapse:collapse;width:100%}td{border:1px solid #999;padding:8px 10px;font-size:14px}td:first-child{width:35%;background:#f3f4f6;font-weight:600}
.amount td{font-size:16px;font-weight:700}.foot{margin-top:40px;display:flex;justify-content:space-between;font-size:12px;color:#444}.foot div{border-top:1px solid #999;padding-top:6px;width:40%;text-align:center}</style></head><body>
<h1>${esc(shop)}</h1><h2>${esc(tx.title ?? (side === "customer" ? "Customer transaction voucher" : "Supplier transaction voucher"))}</h2>
<table>${rows.map(([k, v], i) => `<tr${i === rows.length - 1 ? ' class="amount"' : ""}><td>${esc(k)}</td><td>${esc(v)}</td></tr>`).join("")}</table>
<div class="foot"><div>${esc(tx.personLabel ?? (side === "customer" ? "Customer" : "Supplier"))} signature</div><div>Authorised signature</div></div>
</body></html>`);
  w.document.close();
  // Browsers name the saved PDF after the page title, so title it with the voucher no: it is unique per transaction.
  const pageTitle = document.title;
  const fileName = tx.no.replace(/[\\/:*?"<>|\s]+/g, "");
  const restore = () => { document.title = pageTitle; frame.remove(); };
  w.onafterprint = restore;
  setTimeout(() => { document.title = fileName; w.focus(); w.print(); }, 250);
  setTimeout(restore, 120000); // in case the browser never reports the print as finished
}

/** `count` > 1 means several vouchers are queued: Download moves on to the next one, Close drops them all. */
export function TransactionVoucher({ tx, side, shop, onClose, onDownloaded, count = 1 }: { tx: VoucherTx | null; side: "customer" | "supplier"; shop: string; onClose: () => void; onDownloaded?: () => void; count?: number }) {
  return (
    <Modal open={!!tx} title={tx ? `${tx.description}` : ""} onClose={onClose}>
      {tx && (
        <div className="space-y-3">
          <p className="text-xs text-slate-500">Voucher {tx.no} · {fmtDate(tx.date)}{count > 1 ? ` · ${count} vouchers for this payment` : ""}</p>
          <dl className="divide-y divide-emerald-100 rounded-lg border border-emerald-200 text-sm">
            {([[side === "customer" ? "Customer" : "Supplier", `${tx.person}${tx.phone ? ` · ${tx.phone}` : ""}`], ...(tx.reference ? ([["Reference", tx.reference]] as [string, string][]) : []), ...tx.details] as [string, string][]).map(([k, v]) => (
              <div key={k} className="flex justify-between gap-4 px-3 py-2"><dt className="text-slate-500">{k}</dt><dd className="min-w-0 break-words text-right font-medium text-slate-800">{v}</dd></div>
            ))}
            <div className="flex justify-between gap-4 bg-emerald-50 px-3 py-2 font-semibold"><dt>{tx.charge ? (side === "customer" ? "Amount sold" : "Amount bought") : side === "customer" ? "Amount received" : "Amount paid"}</dt><dd>{money(tx.charge || tx.paid)}</dd></div>
          </dl>
          <div className="flex justify-end gap-2">
            <Button variant="outline" onClick={onClose}>Close</Button>
            <Button onClick={() => { printVoucher(tx, side, shop); (onDownloaded ?? onClose)(); }}><Download className="size-4" /> Download PDF</Button>
          </div>
        </div>
      )}
    </Modal>
  );
}
