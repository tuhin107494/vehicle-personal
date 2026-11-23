import { fmtDate, label, money } from "@/lib/format";
import type { InvestorSale } from "@/lib/types";

const esc = (v: unknown) => String(v ?? "").replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
const cap = (v?: string | null) => { const t = label(v); return t === "-" ? "" : t.charAt(0).toUpperCase() + t.slice(1); };
const pdate = (v?: string | null) => (v ? fmtDate(v) : "-");
const r2 = (n: number) => Math.round((n + Number.EPSILON) * 100) / 100;

/**
 * Prints an investor sale invoice (one page, like the sales memo) from a hidden frame and opens the print dialog,
 * where "Save as PDF" downloads it as InvestorSale-<id>: the investor, the vehicle and its buyer, and the money between
 * the investor and the shop.
 */
export function printInvestorSaleInvoice(x: InvestorSale, shop: string) {
  const frame = document.createElement("iframe");
  frame.setAttribute("aria-hidden", "true");
  frame.style.cssText = "position:fixed;right:0;bottom:0;width:0;height:0;border:0";
  document.body.appendChild(frame);
  const w = frame.contentWindow;
  if (!w) { frame.remove(); return false; }

  const inv = x.investor;
  const v = x.sale?.vehicle;
  const c = x.sale?.customer;
  const vehicleName = [v?.brand, v?.model, v?.year].filter(Boolean).join(" ");
  const afterCustomer = x.sale_price !== null && x.down_payment !== null ? r2(x.sale_price - x.down_payment) : x.principal;
  const investorDown = x.investor_down_payment ?? afterCustomer;
  const plan = x.number_of_installments > 0;


  const rows = (pairs: [string, unknown][]) => pairs.filter(([, val]) => val !== null && val !== undefined && val !== "").map(([k, val]) => `<tr><td class="k">${esc(k)}</td><td>${esc(val)}</td></tr>`).join("");

  w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>InvestorSale-${esc(x.id)}</title>
<style>
*{box-sizing:border-box}body{font-family:system-ui,-apple-system,sans-serif;margin:24px;color:#111;font-size:12px}
h1{font-size:20px;margin:0}h2{font-size:12px;margin:12px 0 4px;text-transform:uppercase;letter-spacing:.05em;color:#065f46}
.head{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #065f46;padding-bottom:8px}
.meta{text-align:right;font-size:12px;color:#444}.meta b{display:block;font-size:15px;color:#111}
table{border-collapse:collapse;width:100%}td,th{border:1px solid #bbb;padding:4px 8px;text-align:left}
td.k{width:38%;background:#f3f4f6;font-weight:600}th{background:#ecfdf5;font-size:12px}.r{text-align:right}
.two{display:grid;grid-template-columns:1fr 1fr;gap:14px}.total td{font-weight:700;background:#ecfdf5}
.sign{margin-top:36px;break-inside:avoid;display:flex;justify-content:space-between;font-size:12px;color:#444}.sign div{border-top:1px solid #888;padding-top:6px;width:30%;text-align:center}
.note{margin-top:8px;font-size:11px;color:#666}${x.status === "cancelled" ? ".stamp{color:#b91c1c;font-weight:700;border:2px solid #b91c1c;display:inline-block;padding:2px 10px;margin-top:6px}" : ""}
@page{size:A4;margin:0}@media print{body{margin:12mm 14mm}}
</style></head><body>
<div class="head"><div><h1>${esc(shop)}</h1><div>Investor sale invoice</div>${x.status === "cancelled" ? '<div class="stamp">CANCELLED</div>' : ""}</div>
<div class="meta"><b>${esc(x.memo_no ? `Memo no. ${x.memo_no}` : `Investor sale #${x.id}`)}</b>Date: ${esc(pdate(x.date))}<br>Sale #${esc(x.sale_id)}</div></div>

<div class="two">
<div><h2>Investor</h2><table>${rows([["Name", inv?.name], ["Father's name", inv?.father_name], ["Mobile", [inv?.phone, inv?.alt_phone].filter(Boolean).join(" / ")], ["NID", inv?.nid], ["Address", inv?.address]])}</table></div>
<div><h2>Vehicle</h2><table>${rows([["Vehicle", vehicleName], ["Type", cap(v?.vehicle_type)], ["Chassis no.", v?.chassis_no], ["Engine no.", v?.engine_no], ["Reg. no.", v?.registration_no]])}</table></div>
</div>

${c ? `<h2>Customer</h2><table>${rows([["Name", c.name], ["Mobile", [c.phone, c.alt_phone].filter(Boolean).join(" / ")], ["Address", c.address], ["Guarantor", [x.guarantor_name, x.guarantor_phone].filter(Boolean).join(" · ")]])}</table>` : ""}

<h2>Amounts</h2>
<table>${rows([
    ["Sale price", x.sale_price !== null ? money(x.sale_price) : null],
    ["Down payment (customer)", x.down_payment !== null ? `${money(x.down_payment)}${x.method ? ` (${cap(x.method)})` : ""}` : null],
    ["Down payment (investor)", money(investorDown)],
    ["Investor due", money(Math.max(r2(afterCustomer - investorDown), 0))],
    ["Investor's money (on the plan)", money(x.principal)],
    ...(plan ? [
      ["Investor's profit", money(x.profit_amount)],
      ["Installments", `${x.number_of_installments} ${x.frequency === "weekly" ? "weeks" : "months"}, from ${pdate(x.start_date)}`],
    ] as [string, unknown][] : [["Plan", "None: the investor collects from the customer"]] as [string, unknown][]),
  ])}
${plan ? `<tr class="total"><td class="k">We pay back in total</td><td>${esc(money(x.total_payable))}</td></tr>` : ""}</table>


<div class="sign"><div>Investor signature</div><div>Customer signature</div><div>Authorised signature</div></div>
<p class="note">Printed on ${esc(fmtDate(new Date().toISOString()))}</p>
</body></html>`);
  w.document.close();
  printFrame(frame, w, `InvestorSale-${x.id}`);
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
 * Prints an investor sale's statement, laid out as the sale statement: the investor and the vehicle, the terms as agreed,
 * the plan, where it stands now, then on a new page every payment to the investor (its principal and profit as one line)
 * with what it paid and the balance left after it, and the schedule with the date each installment was paid.
 * Needs the investor sale with its settlements loaded. The file is named Statement-InvestorSale-<id>.
 */
export function printInvestorSaleStatement(x: InvestorSale, shop: string) {
  const frame = document.createElement("iframe");
  frame.setAttribute("aria-hidden", "true");
  frame.style.cssText = "position:fixed;right:0;bottom:0;width:0;height:0;border:0";
  document.body.appendChild(frame);
  const w = frame.contentWindow;
  if (!w) { frame.remove(); return false; }

  const inv = x.investor;
  const v = x.sale?.vehicle;
  const c = x.sale?.customer;
  const p = x.position;
  const afterCustomer = x.sale_price !== null && x.down_payment !== null ? r2(x.sale_price - x.down_payment) : x.principal;
  const investorDown = x.investor_down_payment ?? afterCustomer;
  const plan = x.number_of_installments > 0;
  const paidBack = p ? r2(p.principal_paid + p.profit_paid) : 0;
  const schedule = x.schedule ?? [];

  // payments to the investor in date order, the principal and profit of one payment as one line
  type Line = { date: string; amount: number; note: string; for: string[] };
  const lines: Line[] = [];
  const byPayment = new Map<string, Line>();
  const received = [...(x.settlements ?? [])].filter((t) => t.type === "investment").sort((a, b) => a.date.localeCompare(b.date) || a.id - b.id);
  for (const t of [...(x.settlements ?? [])].sort((a, b) => a.date.localeCompare(b.date) || a.id - b.id)) {
    if (t.type !== "principal_return" && t.type !== "profit_share") continue;
    const key = `${t.date}|${t.note ?? ""}`;
    const line = byPayment.get(key);
    if (line) { line.amount = r2(line.amount + t.amount); continue; }
    const fresh: Line = { date: t.date, amount: t.amount, note: t.note ?? "", for: [] };
    byPayment.set(key, fresh);
    lines.push(fresh);
  }

  // the schedule is filled in order by what was paid back: each payment names the installments it went to, and each
  // installment the date the running total reached it
  const ends: number[] = [];
  schedule.reduce((t, r) => { ends.push(r2(t + r.amount)); return r2(t + r.amount); }, 0);
  let before = 0;
  const reached: { date: string; total: number }[] = [];
  for (const l of lines) {
    const after = r2(before + l.amount);
    schedule.forEach((r, k) => { const s0 = k ? ends[k - 1] : 0; if (ends[k] > before + 0.004 && s0 < after - 0.004) l.for.push(`#${r.seq_no}`); });
    reached.push({ date: l.date, total: after });
    before = after;
  }
  let balance = x.total_payable;
  const ledger = lines.map((l) => {
    balance = r2(balance - l.amount);
    return `<tr><td>${esc(pdate(l.date))}</td><td>${esc(l.for.join(", ") || "Payment")}${l.note ? `<div class="sub">${esc(l.note)}</div>` : ""}</td><td class="r">${esc(money(l.amount))}</td><td class="r">${esc(money(balance))}</td></tr>`;
  }).join("");

  let rest = paidBack;
  let start = 0;
  const scheduleRows = schedule.map((r) => {
    const paid = r2(Math.min(rest, r.amount));
    rest = Math.max(r2(rest - paid), 0);
    const end = start + paid;
    const on = paid > 0 ? reached.find((t) => t.total >= end - 0.004)?.date : undefined;
    start = end;
    const remaining = r2(r.amount - paid);
    return `<tr><td>${esc(r.seq_no)}</td><td>${esc(pdate(r.due_date))}</td><td class="r">${esc(money(r.amount))}</td><td class="r">${esc(money(paid))}</td><td class="r">${esc(money(remaining))}</td><td>${esc(on ? pdate(on) : "-")}</td><td>${remaining <= 0.004 ? "Paid" : paid > 0 ? "Partial" : "Pending"}</td></tr>`;
  }).join("");

  const rows = (pairs: [string, unknown][]) => pairs.filter(([, val]) => val !== null && val !== undefined && val !== "").map(([k, val]) => `<tr><td class="k">${esc(k)}</td><td>${esc(val)}</td></tr>`).join("");

  w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>Statement-InvestorSale-${esc(x.id)}</title>
<style>
*{box-sizing:border-box}body{font-family:system-ui,-apple-system,sans-serif;margin:28px;color:#111;font-size:12px}
h1{font-size:20px;margin:0}h2{font-size:12px;margin:16px 0 6px;text-transform:uppercase;letter-spacing:.05em;color:#065f46}
.head{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #065f46;padding-bottom:10px}
.meta{text-align:right;font-size:12px;color:#444}.meta b{display:block;font-size:15px;color:#111}
table{border-collapse:collapse;width:100%}td,th{border:1px solid #bbb;padding:5px 7px;text-align:left;vertical-align:top}
td.k{width:34%;background:#f3f4f6;font-weight:600}th{background:#ecfdf5;font-size:11px}.r{text-align:right}
.two{display:grid;grid-template-columns:1fr 1fr;gap:14px}.total td{font-weight:700;background:#ecfdf5}.sub{font-size:10px;color:#666}
.note{margin-top:14px;font-size:10px;color:#666}${x.status === "cancelled" ? ".stamp{color:#b91c1c;font-weight:700;border:2px solid #b91c1c;display:inline-block;padding:2px 10px;margin-top:6px}" : ""}
tr{break-inside:avoid}.newpage{break-before:page;page-break-before:always;margin-top:0}@media print{body{margin:12mm}}
</style></head><body>
<div class="head"><div><h1>${esc(shop)}</h1><div>Investor sale statement</div>${x.status === "cancelled" ? '<div class="stamp">CANCELLED</div>' : ""}</div>
<div class="meta"><b>Investor sale #${esc(x.id)}${x.memo_no ? ` · Memo ${esc(x.memo_no)}` : ""}</b>Date: ${esc(pdate(x.date))} · Sale #${esc(x.sale_id)}</div></div>

<div class="two">
<div><h2>Investor</h2><table>${rows([["Name", inv?.name], ["Father's name", inv?.father_name || "-"], ["Mobile", [inv?.phone, inv?.alt_phone].filter(Boolean).join(" / ")], ["Address", inv?.address]])}</table></div>
<div><h2>Vehicle</h2><table>${rows([["Vehicle", [v?.brand, v?.model, v?.year].filter(Boolean).join(" ")], ["Chassis no.", v?.chassis_no], ["Engine no.", v?.engine_no], ["Reg. no.", v?.registration_no], ["Customer", c ? [c.name, c.phone].filter(Boolean).join(" · ") : null]])}</table></div>
</div>

<h2>Payment (at the investor sale)</h2>
<table>${rows([
    ["Sale price", x.sale_price !== null ? money(x.sale_price) : null],
    ["Down payment (customer)", x.down_payment !== null ? money(x.down_payment) : null],
    ["Investor's money (on the plan)", money(x.principal)],
    ["Investor's profit", plan ? money(x.profit_amount) : null],
    ["Down payment (investor)", money(investorDown)],
    ["Investor due", money(Math.max(r2(afterCustomer - investorDown), 0))],
  ])}
<tr class="total"><td class="k">${plan ? "We pay back in total" : "Plan"}</td><td>${plan ? esc(money(x.total_payable)) : "None: the investor collects from the customer"}</td></tr></table>

${plan ? `<h2>Installment plan</h2><table>${rows([
    ["Installments", `${x.number_of_installments} × ${x.frequency === "weekly" ? "weekly" : "monthly"}${schedule.length ? ` · ${money(schedule[0].amount)} each` : ""}`],
    ["First installment", pdate(x.start_date)],
  ])}</table>` : ""}

<h2>Now</h2>
<table>${rows([
    ["Received from the investor", x.investor_paid !== undefined ? money(x.investor_paid) : null],
    ["Investor still owes the shop", (x.investor_owes ?? 0) > 0 ? money(x.investor_owes) : null],
    ["We pay back in total", plan ? money(x.total_payable) : null],
    ["Paid back so far", plan ? money(paidBack) : null],
  ])}
${plan ? `<tr class="total"><td class="k">Balance to pay back</td><td>${esc(money(p?.total_due ?? r2(x.total_payable - paidBack)))}</td></tr>` : ""}</table>

<h2 class="newpage">Payments</h2>
${lines.length ? `<table><tr><th>Date</th><th>For</th><th class="r">Paid</th><th class="r">Balance</th></tr>
<tr><td>${esc(pdate(x.date))}</td><td colspan="2">We pay back in total</td><td class="r">${esc(money(x.total_payable))}</td></tr>${ledger}</table>` : "<p>No payment yet.</p>"}

${received.length ? `<h2>Received from the investor</h2><table><tr><th>Date</th><th>Note</th><th class="r">Amount</th></tr>${received.map((t) => `<tr><td>${esc(pdate(t.date))}</td><td>${esc(t.note ?? "")}</td><td class="r">${esc(money(t.amount))}</td></tr>`).join("")}</table>` : ""}

${scheduleRows ? `<h2>Installment schedule</h2><table><tr><th>#</th><th>Due date</th><th class="r">Amount</th><th class="r">Paid</th><th class="r">Remaining</th><th>Paid on</th><th>Status</th></tr>${scheduleRows}</table>` : ""}

<p class="note">Printed on ${esc(fmtDate(new Date().toISOString()))}</p>
</body></html>`);
  w.document.close();
  printFrame(frame, w, `Statement-InvestorSale-${x.id}`);
  return true;
}
