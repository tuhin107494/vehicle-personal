"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";

// Sales open straight on the sale form; the sales list lives in Reports and on each vehicle or party page.
export default function SalesPage() {
  const router = useRouter();

  useEffect(() => {
    router.replace("/sales/new");
  }, [router]);

  return null;
}
