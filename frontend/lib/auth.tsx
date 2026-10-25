"use client";

import { createContext, useCallback, useContext, useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { api, tokenStore } from "@/lib/api";

export type Role = "super_admin" | "shop_owner" | "admin" | "moderator";
export type User = {
  id: number; name: string; email: string; role: Role; role_label: string; photo_url?: string | null;
  shop: { id: number; name: string } | null; permissions: string[];
};

export type Session = { token: string; user: User };

type AuthContextValue = {
  user: User | null;
  loading: boolean;
  login: (email: string, password: string) => Promise<void>;
  loginWithGoogle: (code: string) => Promise<void>;
  startSession: (session: Session) => void;
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

  // keeps the token from a sign-in (or a Google sign-up) and who it belongs to
  const startSession = useCallback((session: Session) => {
    tokenStore.set(session.token);
    setUser(session.user);
  }, []);

  const login = useCallback(async (email: string, password: string) => {
    const res = await api<Session>("/login", {
      method: "POST",
      body: { email, password },
    });
    startSession(res.data);
  }, [startSession]);

  // the one-time code from Google's popup; the server trades it for the account's email
  const loginWithGoogle = useCallback(async (code: string) => {
    const res = await api<Session>("/auth/google", { method: "POST", body: { code } });
    startSession(res.data);
  }, [startSession]);

  const logout = useCallback(async () => {
    await api("/logout", { method: "POST" }).catch(() => {});
    tokenStore.clear();
    setUser(null);
    router.replace("/login");
  }, [router]);

  const can = useCallback((permission: string) => !!user?.permissions.includes(permission), [user]);

  return (
    <AuthContext.Provider value={{ user, loading, login, loginWithGoogle, startSession, logout, setUser, can }}>{children}</AuthContext.Provider>
  );
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error("useAuth must be used within AuthProvider");
  return ctx;
}
