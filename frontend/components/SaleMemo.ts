import { fmtDate, label, money } from "@/lib/format";
import type { Sale } from "@/lib/types";

const esc = (v: unknown) => String(v ?? "").replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
const cap = (v?: string | null) => { const t = label(v); return t === "-" ? "" : t.charAt(0).toUpperCase() + t.slice(1); };
const pdate = (v?: string | null) => (v ? fmtDate(v) : "-");

/**
 * Opens the sale memo as a clean one-page document in a new window and starts the print dialog,
 * where "Save as PDF" downloads it. Pass `print: false` to only show it.
 */
export function openSaleMemo(sale: Sale, shop: string) {
  const w = window.open("", "_blank", "width=900,height=1000");
  if (!w) return false;

  const v = sale.vehicle;
  const p = sale.party;
  const installments = sale.plan?.installments ?? [];
  const payments = (sale.payments ?? []).filter((x) => x.amount > 0);
  const vehicleName = [v?.brand, v?.model, v?.year].filter(Boolean).join(" ");

  const rows = (pairs: [string, unknown][]) => pairs.filter(([, val]) => val !== null && val !== undefined && val !== "").map(([k, val]) => `<tr><td class="k">${esc(k)}</td><td>${esc(val)}</td></tr>`).join("");

  w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>Sale memo ${esc(sale.id)} - ${esc(p?.name)}</title>
<style>
*{box-sizing:border-box}body{font-family:system-ui,-apple-system,sans-serif;margin:28px;color:#111;font-size:13px}
h1{font-size:20px;margin:0}h2{font-size:13px;margin:18px 0 6px;text-transform:uppercase;letter-spacing:.05em;color:#065f46}
.head{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #065f46;padding-bottom:10px}
.meta{text-align:right;font-size:12px;color:#444}.meta b{display:block;font-size:15px;color:#111}
table{border-collapse:collapse;width:100%}td,th{border:1px solid #bbb;padding:6px 8px;text-align:left}
td.k{width:30%;background:#f3f4f6;font-weight:600}th{background:#ecfdf5;font-size:12px}.r{text-align:right}
.two{display:grid;grid-template-columns:1fr 1fr;gap:14px}.total td{font-weight:700;background:#ecfdf5}
.sign{margin-top:48px;display:flex;justify-content:space-between;font-size:12px;color:#444}.sign div{border-top:1px solid #888;padding-top:6px;width:30%;text-align:center}
.note{margin-top:14px;font-size:11px;color:#666}${sale.status === "cancelled" ? ".stamp{color:#b91c1c;font-weight:700;border:2px solid #b91c1c;display:inline-block;padding:2px 10px;margin-top:6px}" : ""}
@media print{body{margin:14mm}}
</style></head><body>
<div class="head"><div><h1>${esc(shop)}</h1><div>Sales memo</div>${sale.status === "cancelled" ? '<div class="stamp">CANCELLED</div>' : ""}</div>
<div class="meta"><b>Memo no. SALE-${esc(sale.id)}</b>Date: ${esc(pdate(sale.sale_date))}</div></div>

<div class="two">
<div><h2>Customer</h2><table>${rows([["Name", p?.name], ["Father's name", p?.father_name], ["Mobile", [p?.phone, p?.alt_phone].filter(Boolean).join(" / ")], ["NID", p?.nid], ["Address", p?.address], ["Guarantor mobile", sale.guarantor_phone]])}</table></div>
<div><h2>Vehicle</h2><table>${rows([["Vehicle", vehicleName], ["Type", cap(v?.vehicle_type)], ["Chassis no.", v?.chassis_no], ["Engine no.", v?.engine_no], ["Reg. no.", v?.registration_no], ["Color", v?.color]])}</table></div>
</div>

<h2>Payment</h2>
<table>${rows([["Payment type", cap(sale.payment_type)], ["Sale price", money(sale.sale_price)], ["Down payment", money(sale.down_payment)], ["Total payable", money(sale.grand_total)], ["Paid so far", money(sale.total_paid)]])}
<tr class="total"><td class="k">Balance due</td><td>${esc(money(sale.due_amount))}</td></tr></table>

${installments.length ? `<h2>Installment schedule</h2><table><tr><th>#</th><th>Due date</th><th class="r">Amount</th><th class="r">Paid</th><th class="r">Remaining</th></tr>${installments.map((i) => `<tr><td>${esc(i.seq_no)}</td><td>${esc(pdate(i.due_date))}</td><td class="r">${esc(money(i.amount))}</td><td class="r">${esc(money(i.paid_amount))}</td><td class="r">${esc(money(i.amount - i.paid_amount))}</td></tr>`).join("")}</table>` : ""}

${payments.length ? `<h2>Payments received</h2><table><tr><th>Date</th><th>Method</th><th class="r">Amount</th></tr>${payments.map((x) => `<tr><td>${esc(pdate(x.paid_at))}</td><td>${esc(cap(x.method))}</td><td class="r">${esc(money(x.amount))}</td></tr>`).join("")}</table>` : ""}

<div class="sign"><div>Customer signature</div><div>Guarantor signature</div><div>Authorised signature</div></div>
<p class="note">Printed on ${esc(fmtDate(new Date().toISOString()))}</p>
<script>window.onload=function(){window.print()}</script></body></html>`);
  w.document.close();
  return true;
}
