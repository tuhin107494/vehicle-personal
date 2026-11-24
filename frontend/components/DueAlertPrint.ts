import { fmtDate, money } from "@/lib/format";
import type { CallRow } from "@/lib/types";

const esc = (v: unknown) => String(v ?? "").replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
const plural = (n: number, word: string) => `${n} ${word}${n === 1 ? "" : "s"}`;

/** A Due alert row: one vehicle, with every installment or due of it in the list. */
export type DueAlertRow = CallRow & { items: CallRow[] };

/** "3 months 2 days over", "in 4 days", "Today" */
function whenText(r: CallRow): string {
  if (r.overdue_by) {
    const o = r.overdue_by;
    return `${[o.months ? plural(o.months, "month") : "", o.days || !o.months ? plural(o.days, "day") : ""].filter(Boolean).join(" ")} over`;
  }
  return r.days_left === 0 ? "Today" : `in ${plural(r.days_left, "day")}`;
}

/**
 * Prints the Due alert list as it is shown (its tab, filters and search): one row per vehicle with the customer and
 * their numbers, the vehicle, what is due and since when, the amount and the customer's total due, and the call mark.
 * From a hidden frame, so it works on phones; "Save as PDF" names the file after the tab.
 */
export function printDueAlert(rows: DueAlertRow[], shop: string, title: string, filters: string[]) {
  const frame = document.createElement("iframe");
  frame.setAttribute("aria-hidden", "true");
  frame.style.cssText = "position:fixed;right:0;bottom:0;width:0;height:0;border:0";
  document.body.appendChild(frame);
  const w = frame.contentWindow;
  if (!w) { frame.remove(); return false; }

  const total = Math.round(rows.reduce((t, r) => t + r.amount, 0) * 100) / 100;
  const customers = new Set(rows.map((r) => r.customer.id)).size;
  const body = rows.map((r, i) => {
    const v = r.vehicle;
    const phones = [r.customer.phone, r.customer.alt_phone].filter(Boolean).join(" / ");
    const called = r.called_until ? `Called · again ${fmtDate(r.called_until)}${r.called_meet_date ? ` · meet ${fmtDate(r.called_meet_date)}` : ""}${r.called_note ? ` · ${r.called_note}` : ""}` : "";
    return `<tr${r.called_until ? ' class="called"' : ""}>
<td>${i + 1}</td>
<td><b>${esc(r.customer.name)}</b>${r.customer.father_name ? `<div class="sub">Father: ${esc(r.customer.father_name)}</div>` : ""}<div class="sub">${esc(phones)}</div>${r.guarantor_phone ? `<div class="sub">Guarantor: ${esc(r.guarantor_phone)}</div>` : ""}</td>
<td>${esc([v.brand, v.model].filter(Boolean).join(" ") || "-")}<div class="sub">Chassis: ${esc(v.chassis_no)}</div>${v.engine_no ? `<div class="sub">Engine: ${esc(v.engine_no)}</div>` : ""}</td>
<td>${esc(r.label)}${r.items.length > 1 ? `<div class="sub">${r.items.length} ${r.items.every((x) => x.type === "installment") ? "installments" : "dues"}</div>` : ""}</td>
<td>${r.items.length > 1 ? '<span class="sub">from </span>' : ""}${esc(fmtDate(r.due_date))}<div class="${r.overdue_by ? "late" : "sub"}">${esc(whenText(r))}</div></td>
<td class="r"><b>${esc(money(r.amount))}</b></td>
<td class="r">${esc(money(r.customer_total_due))}</td>
<td class="sub">${esc(called)}</td>
</tr>`;
  }).join("");

  w.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>${esc(`Due-alert-${title}`.replace(/[^\w-]+/g, "-"))}</title>
<style>
*{box-sizing:border-box}body{font-family:system-ui,-apple-system,sans-serif;margin:24px;color:#111;font-size:11px}
h1{font-size:18px;margin:0}.head{display:flex;justify-content:space-between;align-items:flex-end;border-bottom:2px solid #065f46;padding-bottom:8px;margin-bottom:8px}
.meta{text-align:right;font-size:11px;color:#444}.meta b{display:block;font-size:14px;color:#111}
.sum{display:flex;gap:18px;margin:6px 0 10px;font-size:12px}.sum b{font-size:13px}
table{border-collapse:collapse;width:100%}td,th{border:1px solid #bbb;padding:4px 6px;text-align:left;vertical-align:top}
th{background:#ecfdf5;font-size:10px;text-transform:uppercase;letter-spacing:.03em}.r{text-align:right}
.sub{font-size:10px;color:#555}.late{font-size:10px;color:#b91c1c;font-weight:600}tr.called td{background:#fef3c7}
tr{break-inside:avoid}thead{display:table-header-group}.note{margin-top:10px;font-size:10px;color:#666}
@page{size:A4 landscape;margin:0}@media print{body{margin:10mm 12mm}}
</style></head><body>
<div class="head"><div><h1>${esc(shop)}</h1><div>Due alert · ${esc(title)}</div></div>
<div class="meta"><b>${esc(fmtDate(new Date().toISOString()))}</b>${esc(filters.join(" · "))}</div></div>
<div class="sum"><span>Vehicles: <b>${rows.length}</b></span><span>Customers: <b>${customers}</b></span><span>Amount: <b>${esc(money(total))}</b></span></div>
${rows.length ? `<table><thead><tr><th>#</th><th>Customer</th><th>Vehicle</th><th>For</th><th>Due date</th><th class="r">Amount</th><th class="r">Total due</th><th>Call</th></tr></thead><tbody>${body}</tbody></table>` : "<p>Nothing to show in this list.</p>"}
<p class="note">Printed on ${esc(fmtDate(new Date().toISOString()))}</p>
</body></html>`);
  w.document.close();

  const pageTitle = document.title;
  const restore = () => { document.title = pageTitle; frame.remove(); };
  w.onafterprint = restore;
  setTimeout(() => { document.title = `Due-alert-${title}`.replace(/[^\w-]+/g, "-"); w.focus(); w.print(); }, 250);
  setTimeout(restore, 120000);
  return true;
}
