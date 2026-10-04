import { money } from "@/lib/format";
import type { Purchase } from "@/lib/types";
import type { VoucherTx } from "@/components/TransactionVoucher";

/** A purchase as the voucher printed from the Buy vehicle page and the Purchases report: PUR-<id>, so the same purchase always prints the same file. */
export function purchaseVoucher(p: Purchase): VoucherTx {
  const seller = p.supplier ?? p.customer ?? p.seller;
  const due = Math.max(0, p.price - p.paid_amount - p.due_adjustment);
  return {
    no: `PUR-${p.id}`, date: p.purchase_date, person: seller?.name ?? "", phone: seller?.phone ?? null, description: "Vehicle purchase", vehicle: p.vehicle?.chassis_no ?? null,
    reference: p.memo_no, charge: p.price, paid: 0,
    details: [["Vehicle", [p.vehicle?.brand, p.vehicle?.model, p.vehicle?.year].filter(Boolean).join(" ")], ["Chassis", p.vehicle?.chassis_no ?? ""], ["Engine", p.vehicle?.engine_no ?? ""], ["Paid", money(p.paid_amount)], ["Due", money(due)]],
    title: "Vehicle purchase voucher", personLabel: p.source === "customer" ? "Bought from customer" : "Supplier", amountLabel: "Purchase price",
  };
}
