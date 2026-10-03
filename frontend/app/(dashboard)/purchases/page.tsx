"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";

// Purchases open straight on the buy form; purchase history lives on each vehicle's page.
export default function PurchasesPage() {
  const router = useRouter();

  useEffect(() => {
    router.replace("/purchases/new");
  }, [router]);

  return null;
}
