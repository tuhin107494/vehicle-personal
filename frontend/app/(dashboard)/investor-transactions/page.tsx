"use client";

import { PageHeader } from "@/components/app-ui";
import { InvestorTransactions } from "@/components/InvestorTransactions";

export default function InvestorTransactionsPage() {
  return (
    <>
      <div className="print:hidden"><PageHeader title="Investor transactions" actions={<span id="print-slot" />} /></div>
      <InvestorTransactions />
    </>
  );
}
