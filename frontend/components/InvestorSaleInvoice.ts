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
  const p = x.position;
  const vehicleName = [v?.brand, v?.model, v?.year].filter(Boolean).join(" ");
  const afterCustomer = x.sale_price !== null && x.down_payment !== null ? r2(x.sale_price - x.down_payment) : x.principal;
  const investorDown = x.investor_down_payment ?? afterCustomer;
  const paidBack = p ? r2(p.principal_paid + p.profit_paid) : 0;
  const plan = x.number_of_installments > 0;


  const rows = (pairs: [string, unknown][]) => pairs.filter(([, val]) => val !== null && val !== undefined && val !== "").map(([k, val]) => `<tr><td class="k">${esc(k)}</td><td>${esc(val)}</td></tr>`).join("");

  w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>InvestorSale-${esc(x.id)}</title>
<style>
*{box-sizing:border-box}body{font-family:system-ui,-apple-system,sans-serif;margin:28px;color:#111;font-size:13px}
h1{font-size:20px;margin:0}h2{font-size:13px;margin:18px 0 6px;text-transform:uppercase;letter-spacing:.05em;color:#065f46}
.head{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #065f46;padding-bottom:10px}
.meta{text-align:right;font-size:12px;color:#444}.meta b{display:block;font-size:15px;color:#111}
table{border-collapse:collapse;width:100%}td,th{border:1px solid #bbb;padding:6px 8px;text-align:left}
td.k{width:38%;background:#f3f4f6;font-weight:600}th{background:#ecfdf5;font-size:12px}.r{text-align:right}
.two{display:grid;grid-template-columns:1fr 1fr;gap:14px}.total td{font-weight:700;background:#ecfdf5}
.sign{margin-top:48px;display:flex;justify-content:space-between;font-size:12px;color:#444}.sign div{border-top:1px solid #888;padding-top:6px;width:30%;text-align:center}
.note{margin-top:14px;font-size:11px;color:#666}${x.status === "cancelled" ? ".stamp{color:#b91c1c;font-weight:700;border:2px solid #b91c1c;display:inline-block;padding:2px 10px;margin-top:6px}" : ""}
@media print{body{margin:14mm}}
</style></head><body>
<div class="head"><div><h1>${esc(shop)}</h1><div>Investor sale invoice</div>${x.status === "cancelled" ? '<div class="stamp">CANCELLED</div>' : ""}</div>
<div class="meta"><b>${esc(x.memo_no ? `Memo no. ${x.memo_no}` : `Investor sale #${x.id}`)}</b>Date: ${esc(pdate(x.date))}<br>Sale #${esc(x.sale_id)}</div></div>

<div class="two">
<div><h2>Investor</h2><table>${rows([["Name", inv?.name], ["Mobile", [inv?.phone, inv?.alt_phone].filter(Boolean).join(" / ")], ["NID", inv?.nid], ["Address", inv?.address]])}</table></div>
<div><h2>Vehicle</h2><table>${rows([["Vehicle", vehicleName], ["Type", cap(v?.vehicle_type)], ["Chassis no.", v?.chassis_no], ["Engine no.", v?.engine_no], ["Reg. no.", v?.registration_no]])}</table></div>
</div>

${c ? `<h2>Customer</h2><table>${rows([["Name", c.name], ["Mobile", [c.phone, c.alt_phone].filter(Boolean).join(" / ")], ["Address", c.address], ["Guarantor", [x.guarantor_name, x.guarantor_phone].filter(Boolean).join(" · ")]])}</table>` : ""}

<h2>Amounts</h2>
<table>${rows([
    ["Sale price", x.sale_price !== null ? money(x.sale_price) : null],
    ["Down payment (customer)", x.down_payment !== null ? `${money(x.down_payment)}${x.method ? ` (${cap(x.method)})` : ""}` : null],
    ["Down payment (investor)", money(investorDown)],
    ["Investor due", money(x.investor_owes ?? Math.max(r2(afterCustomer - investorDown), 0))],
    ["Investor's money (on the plan)", money(x.principal)],
    ...(plan ? [
      ["Investor's profit", `${money(x.profit_amount)} (${x.profit_rate}% a year)`],
      ["Installments", `${x.number_of_installments} ${x.frequency === "weekly" ? "weeks" : "months"}, from ${pdate(x.start_date)}`],
      ["Paid back so far", money(paidBack)],
    ] as [string, unknown][] : [["Plan", "None: the investor collects from the customer"]] as [string, unknown][]),
  ])}
${plan ? `<tr class="total"><td class="k">We pay back in total</td><td>${esc(money(x.total_payable))}</td></tr>` : ""}</table>


<div class="sign"><div>Investor signature</div><div>Customer signature</div><div>Authorised signature</div></div>
<p class="note">Printed on ${esc(fmtDate(new Date().toISOString()))}</p>
</body></html>`);
  w.document.close();
  // browsers name the saved PDF after the page title
  const pageTitle = document.title;
  const restore = () => { document.title = pageTitle; frame.remove(); };
  w.onafterprint = restore;
  setTimeout(() => { document.title = `InvestorSale-${x.id}`; w.focus(); w.print(); }, 250);
  setTimeout(restore, 120000);
  return true;
}
