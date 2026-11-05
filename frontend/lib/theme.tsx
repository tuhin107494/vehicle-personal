"use client";

import { createContext, useContext, useEffect, useState } from "react";

export type Theme = "light" | "dark";

const KEY = "vm_theme";
const SWITCH_KEY = "vm_theme_switch";

type Ctx = { theme: Theme; setTheme: (t: Theme) => void; toggle: () => void; showSwitch: boolean; setShowSwitch: (v: boolean) => void };
const Noop: Ctx = { theme: "light", setTheme: () => {}, toggle: () => {}, showSwitch: true, setShowSwitch: () => {} };
const ThemeCtx = createContext<Ctx>(Noop);

export const useTheme = () => useContext(ThemeCtx);

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

/** Whether the light/dark button is shown in the menu (Settings can turn it off, like the language switch). */
function initialShowSwitch(): boolean {
  if (typeof window === "undefined") return true;
  try {
    return localStorage.getItem(SWITCH_KEY) !== "0";
  } catch {
    return true;
  }
}

function remember(key: string, value: string) {
  try {
    localStorage.setItem(key, value);
  } catch {
    // not remembered, but it still applies for this visit
  }
}

/**
 * Light or dark for the dashboard. The first visit follows the device's setting, after that the choice is remembered.
 * The `dark` class is on <html> only while the dashboard is open (so the landing and sign-in pages stay light), and
 * on <html> rather than a wrapper so dialogs, which are drawn outside the page, are dark too.
 */
export function ThemeProvider({ children }: { children: React.ReactNode }) {
  const [theme, setThemeState] = useState<Theme>(initialTheme);
  const [showSwitch, setShowSwitchState] = useState<boolean>(initialShowSwitch);

  useEffect(() => {
    document.documentElement.classList.toggle("dark", theme === "dark");
    return () => document.documentElement.classList.remove("dark");
  }, [theme]);

  const setTheme = (next: Theme) => {
    setThemeState(next);
    remember(KEY, next);
  };
  const setShowSwitch = (v: boolean) => {
    setShowSwitchState(v);
    remember(SWITCH_KEY, v ? "1" : "0");
  };

  return <ThemeCtx.Provider value={{ theme, setTheme, toggle: () => setTheme(theme === "dark" ? "light" : "dark"), showSwitch, setShowSwitch }}>{children}</ThemeCtx.Provider>;
}
