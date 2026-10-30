"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";

// Bookings are a tab on the Sales page.
export default function BookingsPage() {
  const router = useRouter();

  useEffect(() => {
    router.replace("/sales/new?tab=booking");
  }, [router]);

  return null;
}
