"use client";

import { Suspense } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { Button } from "@/components/app-ui";
import { SaleForm } from "@/components/forms/SaleForm";
import { useHiddenMenu } from "@/lib/menu";

/** Sales has two tabs: a normal sale, and a sale financed by an investor. */
function Tabs() {
  const router = useRouter();
  // hiding Investors from the menu also removes investor sales from this page
  const { hidden } = useHiddenMenu();
  const investorOff = hidden.includes("/investors");
  const investor = useSearchParams().get("tab") === "investor" && !investorOff;
  const go = (tab: "" | "investor") => router.replace(tab ? `/sales/new?tab=${tab}` : "/sales/new");

  return (
    <div className={investor ? "-m-4 min-h-screen bg-gradient-to-br from-sky-50/80 via-white/40 to-sky-50/60 p-4 md:-m-6 md:p-6" : ""}>
      {!investorOff && <div className="mb-4 flex gap-2">
        <Button variant={investor ? "outline" : "primary"} onClick={() => go("")}>Normal sale</Button>
        <Button variant="outline" className={investor ? "border-transparent bg-gradient-to-r from-sky-500 to-blue-600 text-white hover:from-sky-400 hover:to-blue-500 hover:text-white" : "border-sky-200 text-sky-700 hover:bg-sky-50 hover:text-sky-800"} onClick={() => go("investor")}>Investor sale</Button>
      </div>}
      <SaleForm key={investor ? "investor" : "normal"} investorMode={investor} />
    </div>
  );
}

export default function NewSalePage() {
  return <Suspense><Tabs /></Suspense>;
}
