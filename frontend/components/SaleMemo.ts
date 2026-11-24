import { fmtDate, label, money } from "@/lib/format";
import type { Sale } from "@/lib/types";

const esc = (v: unknown) => String(v ?? "").replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
const cap = (v?: string | null) => { const t = label(v); return t === "-" ? "" : t.charAt(0).toUpperCase() + t.slice(1); };
const pdate = (v?: string | null) => (v ? fmtDate(v) : "-");

/**
 * Prints the sale memo as a clean one-page document from a hidden frame (no new tab, so it works on phones)
 * and starts the print dialog, where "Save as PDF" downloads it. The file is named Sale-<id>.
 */
export function openSaleMemo(sale: Sale, shop: string, options: { installments?: boolean } = {}) {
  const frame = document.createElement("iframe");
  frame.setAttribute("aria-hidden", "true");
  frame.style.cssText = "position:fixed;right:0;bottom:0;width:0;height:0;border:0";
  document.body.appendChild(frame);
  const w = frame.contentWindow;
  if (!w) { frame.remove(); return false; }

  const v = sale.vehicle;
  const p = sale.customer;
  // the memo is the sale as agreed on its day: its terms and what was paid then, never what was collected later
  // (that is the statement). The schedule (dates and amounts only) is added when the caller asks for it.
  const plan = sale.plan;
  const installments = options.installments === false ? [] : (plan?.installments ?? []).filter((i) => i.seq_no > 0 || (plan?.promise_amount ?? 0) > 0);
  const atSale = Number(sale.down_payment ?? 0);
  const payableAtSale = sale.payment_type === "installment" && plan ? Math.round((atSale + Number(plan.total_payable)) * 100) / 100 : Number(sale.sale_price);
  const regular = (plan?.installments ?? []).filter((i) => i.seq_no > 0);
  const perInstallment = regular.length ? regular[0].amount : null;
  const firstPromise = [...(sale.promises ?? [])].sort((a, b) => a.id - b.id)[0];
  const vehicleName = [v?.brand, v?.model, v?.year].filter(Boolean).join(" ");

  const rows = (pairs: [string, unknown][]) => pairs.filter(([, val]) => val !== null && val !== undefined && val !== "").map(([k, val]) => `<tr><td class="k">${esc(k)}</td><td>${esc(val)}</td></tr>`).join("");

  w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>Sale-${esc(sale.id)}</title>
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
<div class="meta"><b>Memo no. ${esc(sale.memo_no || `SALE-${sale.id}`)}</b>Date: ${esc(pdate(sale.sale_date))}</div></div>

<div class="two">
<div><h2>Customer</h2><table>${rows([["Name", p?.name], ["Father's name", p?.father_name || "-"], ["Mobile", [p?.phone, p?.alt_phone].filter(Boolean).join(" / ")], ["NID", p?.nid], ["Address", p?.address], ["Guarantor name", sale.guarantor_name], ["Guarantor mobile", sale.guarantor_phone]])}</table></div>
<div><h2>Vehicle</h2><table>${rows([["Vehicle", vehicleName], ["Type", cap(v?.vehicle_type)], ["Chassis no.", v?.chassis_no], ["Engine no.", v?.engine_no], ["Reg. no.", v?.registration_no], ["Color", v?.color]])}</table></div>
</div>

<h2>Payment</h2>
<table>${rows([
  ["Payment type", cap(sale.payment_type)],
  ["Sale price", money(sale.sale_price)],
  ["Installment cost", sale.payment_type === "installment" && plan && Number(plan.interest_amount) > 0 ? money(plan.interest_amount) : null],
  ["Total payable", money(payableAtSale)],
  [sale.advance?.booking_id ? "Booking advance" : "Advance", sale.advance ? `${money(sale.advance.amount)} received on ${pdate(sale.advance.date)}` : null],
  ["Down payment", money(atSale - Number(sale.advance?.amount ?? 0))],
  // a due sale: the first promise, made at the sale
  ["Promised to pay by", sale.payment_type === "due" && firstPromise ? `${pdate(firstPromise.promised_date)}${firstPromise.note ? ` (${firstPromise.note})` : ""}` : null],
])}
<tr class="total"><td class="k">Due after the sale</td><td>${esc(money(Math.max(payableAtSale - atSale, 0)))}</td></tr></table>

${sale.payment_type === "installment" && plan ? `<h2>Installment plan</h2><table>${rows([
  ["Installments", `${plan.number_of_installments} × ${plan.frequency === "weekly" ? "weekly" : "monthly"}${perInstallment !== null ? ` · ${money(perInstallment)} each` : ""}`],
  ["First installment", pdate(plan.start_date)],
  ["Promised amount", (plan.promise_amount ?? 0) > 0 ? `${money(plan.promise_amount)} by ${pdate(plan.promise_date)}` : null],
])}</table>` : ""}

${installments.length ? `<h2>Installment schedule</h2><table><tr><th>#</th><th>Due date</th><th class="r">Amount</th></tr>${installments.map((i) => `<tr><td>${esc(i.seq_no === 0 ? "Promise" : i.seq_no)}</td><td>${esc(pdate(i.due_date))}</td><td class="r">${esc(money(i.amount))}</td></tr>`).join("")}</table>` : ""}

<div class="sign"><div>Customer signature</div><div>Guarantor signature</div><div>Authorised signature</div></div>
<p class="note">Printed on ${esc(fmtDate(new Date().toISOString()))}</p>
</body></html>`);
  w.document.close();
  printFrame(frame, w, `Sale-${sale.id}`);
  return true;
}

/** Starts the print dialog of a filled hidden frame; the saved PDF is named after the title. */
function printFrame(frame: HTMLIFrameElement, w: Window, title: string) {
  const pageTitle = document.title;
  const restore = () => { document.title = pageTitle; frame.remove(); };
  w.onafterprint = restore;
  setTimeout(() => { document.title = title; w.focus(); w.print(); }, 250);
  setTimeout(restore, 120000);
}

/**
 * Prints one sale's statement: what the customer has to pay, every payment (a payment spread over several installments is
 * one line) with the balance left after it, and for an installment sale the schedule with the date each was paid.
 * The file is named Statement-Sale-<id>.
 */
export function openSaleStatement(sale: Sale, shop: string) {
  const frame = document.createElement("iframe");
  frame.setAttribute("aria-hidden", "true");
  frame.style.cssText = "position:fixed;right:0;bottom:0;width:0;height:0;border:0";
  document.body.appendChild(frame);
  const w = frame.contentWindow;
  if (!w) { frame.remove(); return false; }

  const v = sale.vehicle;
  const p = sale.customer;
  const installments = sale.plan?.installments ?? [];
  const labelOf = new Map(installments.map((i) => [i.id, i.seq_no === 0 ? "Promise" : `#${i.seq_no}`]));
  // the sale's terms as agreed on its day (as the memo prints them)
  const plan = sale.plan;
  const atSale = Number(sale.down_payment ?? 0);
  const payableAtSale = sale.payment_type === "installment" && plan ? Math.round((atSale + Number(plan.total_payable)) * 100) / 100 : Number(sale.sale_price);
  const regular = installments.filter((i) => i.seq_no > 0);
  const perInstallment = regular.length ? regular[0].amount : null;
  const firstPromise = [...(sale.promises ?? [])].sort((a, b) => a.id - b.id)[0];

  // one line per payment: the rows of one collection (same batch) are added together
  type Line = { date: string; method: string; amount: number; what: string[]; ref: string };
  const lines: Line[] = [];
  const byBatch = new Map<string, Line>();
  const paidOn = new Map<number, string>();
  for (const x of [...(sale.payments ?? [])].sort((a, b) => a.paid_at.localeCompare(b.paid_at) || a.id - b.id)) {
    if (x.installment_id && (!paidOn.get(x.installment_id) || x.paid_at > paidOn.get(x.installment_id)!)) paidOn.set(x.installment_id, x.paid_at);
    const what = x.installment_id ? labelOf.get(x.installment_id) ?? "Installment" : x.note || "Payment";
    const line = x.batch_no ? byBatch.get(x.batch_no) : undefined;
    if (line) {
      line.amount = Math.round((line.amount + x.amount) * 100) / 100;
      if (!line.what.includes(what)) line.what.push(what);
      continue;
    }
    const fresh = { date: x.paid_at, method: cap(x.method), amount: x.amount, what: [what], ref: [x.reference && `Ref ${x.reference}`, x.memo_no && `Memo ${x.memo_no}`].filter(Boolean).join(" · ") };
    lines.push(fresh);
    if (x.batch_no) byBatch.set(x.batch_no, fresh);
  }
  let balance = Number(sale.grand_total ?? 0);
  const ledger = lines.map((l) => {
    balance = Math.round((balance - l.amount) * 100) / 100;
    return `<tr><td>${esc(pdate(l.date))}</td><td>${esc(l.what.join(", "))}${l.ref ? `<div class="sub">${esc(l.ref)}</div>` : ""}</td><td>${esc(l.method)}</td><td class="r">${esc(money(l.amount))}</td><td class="r">${esc(money(balance))}</td></tr>`;
  }).join("");

  const rows = (pairs: [string, unknown][]) => pairs.filter(([, val]) => val !== null && val !== undefined && val !== "").map(([k, val]) => `<tr><td class="k">${esc(k)}</td><td>${esc(val)}</td></tr>`).join("");

  w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>Statement-Sale-${esc(sale.id)}</title>
<style>
*{box-sizing:border-box}body{font-family:system-ui,-apple-system,sans-serif;margin:28px;color:#111;font-size:12px}
h1{font-size:20px;margin:0}h2{font-size:12px;margin:16px 0 6px;text-transform:uppercase;letter-spacing:.05em;color:#065f46}
.head{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #065f46;padding-bottom:10px}
.meta{text-align:right;font-size:12px;color:#444}.meta b{display:block;font-size:15px;color:#111}
table{border-collapse:collapse;width:100%}td,th{border:1px solid #bbb;padding:5px 7px;text-align:left;vertical-align:top}
td.k{width:34%;background:#f3f4f6;font-weight:600}th{background:#ecfdf5;font-size:11px}.r{text-align:right}
.two{display:grid;grid-template-columns:1fr 1fr;gap:14px}.total td{font-weight:700;background:#ecfdf5}.sub{font-size:10px;color:#666}
.note{margin-top:14px;font-size:10px;color:#666}${sale.status === "cancelled" ? ".stamp{color:#b91c1c;font-weight:700;border:2px solid #b91c1c;display:inline-block;padding:2px 10px;margin-top:6px}" : ""}
tr{break-inside:avoid}.newpage{break-before:page;page-break-before:always;margin-top:0}@media print{body{margin:12mm}}
</style></head><body>
<div class="head"><div><h1>${esc(shop)}</h1><div>Sale statement</div>${sale.status === "cancelled" ? '<div class="stamp">CANCELLED</div>' : ""}</div>
<div class="meta"><b>Sale #${esc(sale.id)}${sale.memo_no ? ` · Memo ${esc(sale.memo_no)}` : ""}</b>Sold: ${esc(pdate(sale.sale_date))}</div></div>

<div class="two">
<div><h2>Customer</h2><table>${rows([["Name", p?.name], ["Father's name", p?.father_name || "-"], ["Mobile", [p?.phone, p?.alt_phone].filter(Boolean).join(" / ")], ["Address", p?.address]])}</table></div>
<div><h2>Vehicle</h2><table>${rows([["Vehicle", [v?.brand, v?.model, v?.year].filter(Boolean).join(" ")], ["Chassis no.", v?.chassis_no], ["Engine no.", v?.engine_no], ["Reg. no.", v?.registration_no]])}</table></div>
</div>

<h2>Payment (at the sale)</h2>
<table>${rows([
  ["Payment type", cap(sale.payment_type)],
  ["Sale price", money(sale.sale_price)],
  ["Installment cost", sale.payment_type === "installment" && plan && Number(plan.interest_amount) > 0 ? money(plan.interest_amount) : null],
  ["Total payable", money(payableAtSale)],
  [sale.advance?.booking_id ? "Booking advance" : "Advance", sale.advance ? `${money(sale.advance.amount)} received on ${pdate(sale.advance.date)}` : null],
  ["Down payment", money(atSale - Number(sale.advance?.amount ?? 0))],
  ["Promised to pay by", sale.payment_type === "due" && firstPromise ? `${pdate(firstPromise.promised_date)}${firstPromise.note ? ` (${firstPromise.note})` : ""}` : null],
])}
<tr class="total"><td class="k">Due after the sale</td><td>${esc(money(Math.max(payableAtSale - atSale, 0)))}</td></tr></table>

${sale.payment_type === "installment" && plan ? `<h2>Installment plan</h2><table>${rows([
  ["Installments", `${plan.number_of_installments} × ${plan.frequency === "weekly" ? "weekly" : "monthly"}${perInstallment !== null ? ` · ${money(perInstallment)} each` : ""}`],
  ["First installment", pdate(plan.start_date)],
  ["Promised amount", (plan.promise_amount ?? 0) > 0 ? `${money(plan.promise_amount)} by ${pdate(plan.promise_date)}` : null],
])}</table>` : ""}

<h2>Now</h2>
<table>${rows([
  ["Charges added later", Number(sale.grand_total) - payableAtSale > 0.004 ? money(Number(sale.grand_total) - payableAtSale) : null],
  ["Total payable", money(sale.grand_total)],
  ["Paid so far", money(sale.total_paid)],
])}
<tr class="total"><td class="k">Balance due</td><td>${esc(money(sale.due_amount))}</td></tr></table>

<h2 class="newpage">Payments</h2>
${lines.length ? `<table><tr><th>Date</th><th>For</th><th>Method</th><th class="r">Paid</th><th class="r">Balance</th></tr>
<tr><td>${esc(pdate(sale.sale_date))}</td><td colspan="3">Total payable</td><td class="r">${esc(money(sale.grand_total))}</td></tr>${ledger}</table>` : "<p>No payment yet.</p>"}

${installments.length ? `<h2>Installment schedule</h2><table><tr><th>#</th><th>Due date</th><th class="r">Amount</th><th class="r">Paid</th><th class="r">Remaining</th><th>Paid on</th><th>Status</th></tr>${installments.map((i) => `<tr><td>${esc(i.seq_no === 0 ? "Promise" : i.seq_no)}</td><td>${esc(pdate(i.due_date))}</td><td class="r">${esc(money(i.amount))}</td><td class="r">${esc(money(i.paid_amount))}</td><td class="r">${esc(money(i.amount - i.paid_amount))}</td><td>${esc(paidOn.get(i.id) ? pdate(paidOn.get(i.id)) : "-")}</td><td>${esc(cap(i.status))}</td></tr>`).join("")}</table>` : ""}

<p class="note">Printed on ${esc(fmtDate(new Date().toISOString()))}</p>
</body></html>`);
  w.document.close();
  printFrame(frame, w, `Statement-Sale-${sale.id}`);
  return true;
}
