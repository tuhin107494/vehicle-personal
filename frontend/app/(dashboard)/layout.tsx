"use client";

import { useEffect } from "react";
import { usePathname, useRouter } from "next/navigation";
import { useAuth } from "@/lib/auth";
import { AppShell } from "@/components/AppShell";

export default function DashboardLayout({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const { user, loading } = useAuth();

  const pathname = usePathname();

  useEffect(() => {
    if (!loading && !user) router.replace("/login");
    else if (user?.role === "super_admin" && pathname === "/") router.replace("/shops");
  }, [loading, user, router, pathname]);

  if (loading || !user) return null;

  return <AppShell>{children}</AppShell>;
}
