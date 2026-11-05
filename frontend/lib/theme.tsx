"use client";

import { createContext, useContext, useEffect, useState } from "react";

export type Theme = "light" | "dark";

const KEY = "vm_theme";
const Ctx = createContext<{ theme: Theme; toggle: () => void }>({ theme: "light", toggle: () => {} });

export const useTheme = () => useContext(Ctx);

/** What was chosen last time, else the device's setting. Only called on the client: the dashboard renders nothing until the user has loaded. */
function initialTheme(): Theme {
  if (typeof window === "undefined") return "light";
  try {
    const saved = localStorage.getItem(KEY);
    if (saved === "dark" || saved === "light") return saved;
  } catch {
    // storage can be blocked; fall back to the device setting
  }
  return window.matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light";
}

/**
 * Light or dark for the dashboard. The first visit follows the device's setting, after that the choice is remembered.
 * The `dark` class is on <html> only while the dashboard is open (so the landing and sign-in pages stay light), and
 * on <html> rather than a wrapper so dialogs, which are drawn outside the page, are dark too.
 */
export function ThemeProvider({ children }: { children: React.ReactNode }) {
  const [theme, setTheme] = useState<Theme>(initialTheme);

  useEffect(() => {
    document.documentElement.classList.toggle("dark", theme === "dark");
    return () => document.documentElement.classList.remove("dark");
  }, [theme]);

  const toggle = () => {
    const next: Theme = theme === "dark" ? "light" : "dark";
    setTheme(next);
    try {
      localStorage.setItem(KEY, next);
    } catch {
      // not remembered, but it still switches for this visit
    }
  };

  return <Ctx.Provider value={{ theme, toggle }}>{children}</Ctx.Provider>;
}
