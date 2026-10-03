"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";

// Customers and suppliers have their own pages now.
export default function PartiesPage() {
  const router = useRouter();

  useEffect(() => {
    router.replace("/customers");
  }, [router]);

  return null;
}
