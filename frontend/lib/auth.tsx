"use client";

import { createContext, useCallback, useContext, useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { api, tokenStore } from "@/lib/api";

export type Role = "super_admin" | "shop_owner" | "admin" | "moderator";
export type User = {
  id: number; name: string; email: string; role: Role; role_label: string; photo_url?: string | null;
  shop: { id: number; name: string } | null; permissions: string[];
};

type AuthContextValue = {
  user: User | null;
  loading: boolean;
  login: (email: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
  setUser: (user: User) => void;
  can: (permission: string) => boolean;
};

const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const [user, setUser] = useState<User | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    // signed in on an earlier visit: fetch who it is; otherwise there is nobody to load
    const restore = tokenStore.get()
      ? api<User>("/me").then((res) => setUser(res.data)).catch(() => tokenStore.clear())
      : Promise.resolve();
    restore.finally(() => setLoading(false));
  }, []);

  const login = useCallback(async (email: string, password: string) => {
    const res = await api<{ token: string; user: User }>("/login", {
      method: "POST",
      body: { email, password },
    });
    tokenStore.set(res.data.token);
    setUser(res.data.user);
  }, []);

  const logout = useCallback(async () => {
    await api("/logout", { method: "POST" }).catch(() => {});
    tokenStore.clear();
    setUser(null);
    router.replace("/login");
  }, [router]);

  const can = useCallback((permission: string) => !!user?.permissions.includes(permission), [user]);

  return (
    <AuthContext.Provider value={{ user, loading, login, logout, setUser, can }}>{children}</AuthContext.Provider>
  );
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error("useAuth must be used within AuthProvider");
  return ctx;
}
