"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";

// Investor sales are a tab on the Sales page.
export default function InvestorSalesPage() {
  const router = useRouter();

  useEffect(() => {
    router.replace("/sales/new?tab=investor");
  }, [router]);

  return null;
}
