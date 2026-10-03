"use client";

import { Download } from "lucide-react";
import { fmtDate, money } from "@/lib/format";
import { Button, Modal } from "@/components/app-ui";

export type VoucherTx = {
  no: string; date: string; party: string; phone: string | null; description: string; vehicle: string | null; reference: string | null;
  charge: number; paid: number; details: [string, string][];
};

const esc = (v: string) => v.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");

/** Opens just this transaction in a plain window and starts the print dialog, where "Save as PDF" downloads it. */
function download(tx: VoucherTx, side: "customer" | "supplier", shop: string) {
  const w = window.open("", "_blank", "width=800,height=900");
  if (!w) return;
  const amount = tx.charge || tx.paid;
  const rows: [string, string][] = [
    ["Voucher no", tx.no], ["Date", fmtDate(tx.date)], [side === "customer" ? "Customer" : "Supplier", `${tx.party}${tx.phone ? ` · ${tx.phone}` : ""}`],
    ["Details", tx.description], ...(tx.reference ? ([["Reference", tx.reference]] as [string, string][]) : []), ...tx.details,
    [tx.charge ? (side === "customer" ? "Amount sold" : "Amount bought") : side === "customer" ? "Amount received" : "Amount paid", money(amount)],
  ];
  w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>${esc(tx.no)} - ${esc(tx.party)}</title>
<style>body{font-family:system-ui,sans-serif;margin:32px;color:#111}h1{font-size:20px;margin:0}h2{font-size:15px;margin:4px 0 18px;font-weight:600}
table{border-collapse:collapse;width:100%}td{border:1px solid #999;padding:8px 10px;font-size:14px}td:first-child{width:35%;background:#f3f4f6;font-weight:600}
.amount td{font-size:16px;font-weight:700}.foot{margin-top:40px;display:flex;justify-content:space-between;font-size:12px;color:#444}.foot div{border-top:1px solid #999;padding-top:6px;width:40%;text-align:center}</style></head><body>
<h1>${esc(shop)}</h1><h2>${side === "customer" ? "Customer transaction voucher" : "Supplier transaction voucher"}</h2>
<table>${rows.map(([k, v], i) => `<tr${i === rows.length - 1 ? ' class="amount"' : ""}><td>${esc(k)}</td><td>${esc(v)}</td></tr>`).join("")}</table>
<div class="foot"><div>${side === "customer" ? "Customer" : "Supplier"} signature</div><div>Authorised signature</div></div>
<script>window.onload=function(){window.print()}</script></body></html>`);
  w.document.close();
}

export function TransactionVoucher({ tx, side, shop, onClose }: { tx: VoucherTx | null; side: "customer" | "supplier"; shop: string; onClose: () => void }) {
  return (
    <Modal open={!!tx} title={tx ? `${tx.description}` : ""} onClose={onClose}>
      {tx && (
        <div className="space-y-3">
          <p className="text-xs text-slate-500">Voucher {tx.no} · {fmtDate(tx.date)}</p>
          <dl className="divide-y divide-emerald-100 rounded-lg border border-emerald-200 text-sm">
            {([[side === "customer" ? "Customer" : "Supplier", `${tx.party}${tx.phone ? ` · ${tx.phone}` : ""}`], ...(tx.reference ? ([["Reference", tx.reference]] as [string, string][]) : []), ...tx.details] as [string, string][]).map(([k, v]) => (
              <div key={k} className="flex justify-between gap-4 px-3 py-2"><dt className="text-slate-500">{k}</dt><dd className="min-w-0 break-words text-right font-medium text-slate-800">{v}</dd></div>
            ))}
            <div className="flex justify-between gap-4 bg-emerald-50 px-3 py-2 font-semibold"><dt>{tx.charge ? (side === "customer" ? "Amount sold" : "Amount bought") : side === "customer" ? "Amount received" : "Amount paid"}</dt><dd>{money(tx.charge || tx.paid)}</dd></div>
          </dl>
          <div className="flex justify-end gap-2">
            <Button variant="outline" onClick={onClose}>Close</Button>
            <Button onClick={() => download(tx, side, shop)}><Download className="size-4" /> Download PDF</Button>
          </div>
        </div>
      )}
    </Modal>
  );
}
