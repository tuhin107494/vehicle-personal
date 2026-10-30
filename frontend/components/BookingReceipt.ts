import { fmtDate, label, money } from "@/lib/format";
import type { Booking } from "@/lib/types";

const esc = (v: unknown) => String(v ?? "").replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
const pdate = (v?: string | null) => (v ? fmtDate(v) : "-");

/**
 * Prints the booking receipt (the advance a customer paid to hold a vehicle) from a hidden frame and starts the print dialog,
 * where "Save as PDF" downloads it. The file is named Booking-<id>.
 */
export function openBookingReceipt(booking: Booking, shop: string) {
  const frame = document.createElement("iframe");
  frame.setAttribute("aria-hidden", "true");
  frame.style.cssText = "position:fixed;right:0;bottom:0;width:0;height:0;border:0";
  document.body.appendChild(frame);
  const w = frame.contentWindow;
  if (!w) { frame.remove(); return false; }

  const v = booking.vehicle;
  const p = booking.customer;
  const rows = (pairs: [string, unknown][]) => pairs.filter(([, val]) => val !== null && val !== undefined && val !== "").map(([k, val]) => `<tr><td class="k">${esc(k)}</td><td>${esc(val)}</td></tr>`).join("");
  const closed = booking.status !== "active";

  w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>Booking-${esc(booking.id)}</title>
<style>
*{box-sizing:border-box}body{font-family:system-ui,-apple-system,sans-serif;margin:28px;color:#111;font-size:13px}
h1{font-size:20px;margin:0}h2{font-size:13px;margin:18px 0 6px;text-transform:uppercase;letter-spacing:.05em;color:#065f46}
.head{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #065f46;padding-bottom:10px}
.meta{text-align:right;font-size:12px;color:#444}.meta b{display:block;font-size:15px;color:#111}
table{border-collapse:collapse;width:100%}td,th{border:1px solid #bbb;padding:6px 8px;text-align:left}
td.k{width:30%;background:#f3f4f6;font-weight:600}.two{display:grid;grid-template-columns:1fr 1fr;gap:14px}.total td{font-weight:700;background:#ecfdf5}
.sign{margin-top:48px;display:flex;justify-content:space-between;font-size:12px;color:#444}.sign div{border-top:1px solid #888;padding-top:6px;width:40%;text-align:center}
.note{margin-top:14px;font-size:11px;color:#666}.stamp{color:#b91c1c;font-weight:700;border:2px solid #b91c1c;display:inline-block;padding:2px 10px;margin-top:6px;text-transform:uppercase}
@media print{body{margin:14mm}}
</style></head><body>
<div class="head"><div><h1>${esc(shop)}</h1><div>Booking receipt</div>${closed ? `<div class="stamp">${esc(booking.status)}</div>` : ""}</div>
<div class="meta"><b>Booking no. BOOK-${esc(booking.id)}</b>Date: ${esc(pdate(booking.booked_on))}</div></div>

<div class="two">
<div><h2>Customer</h2><table>${rows([["Name", p?.name], ["Mobile", [p?.phone, p?.alt_phone].filter(Boolean).join(" / ")]])}</table></div>
<div><h2>Vehicle</h2><table>${v ? rows([["Vehicle", [v.brand, v.model, v.year].filter(Boolean).join(" ")], ["Chassis no.", v.chassis_no], ["Engine no.", v.engine_no]]) : rows([["Vehicle", "Not chosen yet"]])}</table></div>
</div>

<h2>Advance</h2>
<table>${rows([["Sale price", booking.agreed_price ? money(booking.agreed_price) : "Not fixed yet"], ["Advance received", Number(booking.advance_amount) > 0 ? money(booking.advance_amount) : "No advance"], ["Paid by", Number(booking.advance_amount) > 0 ? label(booking.advance_method) : null], ["Held until", booking.expires_on ? pdate(booking.expires_on) : "No deadline"], ["Note", booking.note]])}
${booking.balance !== null ? `<tr class="total"><td class="k">Balance to pay</td><td>${esc(money(booking.balance))}</td></tr>` : ""}</table>
${closed && booking.status !== "completed" ? `<h2>Closed</h2><table>${rows([["Date", pdate(booking.closed_on)], ["Refunded", money(booking.refund_amount)], ["Kept by shop", money(booking.kept_amount)]])}</table>` : ""}

<div class="sign"><div>Customer signature</div><div>Authorised signature</div></div>
<p class="note">Printed on ${esc(fmtDate(new Date().toISOString()))}</p>
</body></html>`);
  w.document.close();
  const pageTitle = document.title;
  const restore = () => { document.title = pageTitle; frame.remove(); };
  w.onafterprint = restore;
  setTimeout(() => { document.title = `Booking-${booking.id}`; w.focus(); w.print(); }, 250);
  setTimeout(restore, 120000);
  return true;
}
