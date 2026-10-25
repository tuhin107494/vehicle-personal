"use client";

import { createContext, useCallback, useContext, useEffect, useSyncExternalStore, type ReactNode } from "react";
import { BN } from "@/lib/bn";
import { startDomTranslate, stopDomTranslate } from "@/lib/domTranslate";

export type Lang = "en" | "bn";
const KEY = "vm_lang";
const SWITCH_KEY = "vm_lang_switch";

type Ctx = { lang: Lang; setLang: (l: Lang) => void; t: (text: string) => string; showSwitch: boolean; setShowSwitch: (v: boolean) => void };
const I18n = createContext<Ctx>({ lang: "en", setLang: () => {}, t: (s) => s, showSwitch: true, setShowSwitch: () => {} });

/** Bangla digits for numbers shown in text. */
const BN_DIGITS = "০১২৩৪৫৬৭৮৯";

// The chosen language lives in localStorage (kept in memory too, for browsers that block storage).
const langListeners = new Set<() => void>();
let memoryLang: Lang | null = null;
let memorySwitch: boolean | null = null; // whether the EN / বাং switch is shown in the menu

function readLang(): Lang {
  if (memoryLang) return memoryLang;
  try {
    if (localStorage.getItem(KEY) === "bn") return "bn";
  } catch {}
  return "en";
}

function readSwitch(): boolean {
  if (memorySwitch !== null) return memorySwitch;
  try {
    if (localStorage.getItem(SWITCH_KEY) === "0") return false;
  } catch {}
  return true;
}

function subscribeLang(onChange: () => void) {
  langListeners.add(onChange);
  window.addEventListener("storage", onChange); // another tab switched language
  return () => {
    langListeners.delete(onChange);
    window.removeEventListener("storage", onChange);
  };
}

export function LangProvider({ children }: { children: ReactNode }) {
  const lang = useSyncExternalStore(subscribeLang, readLang, () => "en" as Lang); // the server always renders English
  const showSwitch = useSyncExternalStore(subscribeLang, readSwitch, () => true);

  useEffect(() => {
    document.documentElement.lang = lang;
  }, [lang]);

  // everything on the page - messages, hints, dropdown choices - is translated as it appears
  useEffect(() => {
    if (lang === "bn") startDomTranslate();
    else stopDomTranslate();
    return () => stopDomTranslate();
  }, [lang]);

  const setLang = useCallback((l: Lang) => {
    memoryLang = l;
    try {
      localStorage.setItem(KEY, l);
    } catch {}
    langListeners.forEach((notify) => notify());
  }, []);

  const setShowSwitch = useCallback((v: boolean) => {
    memorySwitch = v;
    try {
      localStorage.setItem(SWITCH_KEY, v ? "1" : "0");
    } catch {}
    langListeners.forEach((notify) => notify());
  }, []);

  /** English text in, the chosen language out; anything without a translation stays as written. */
  const t = useCallback((text: string) => {
    if (lang === "en" || !text) return text;
    const key = text.trim();
    const hit = BN[key];
    if (!hit) return text;
    return text.replace(key, hit);
  }, [lang]);

  return <I18n.Provider value={{ lang, setLang, t, showSwitch, setShowSwitch }}>{children}</I18n.Provider>;
}

export const useT = () => useContext(I18n);
export const toBnDigits = (s: string) => s.replace(/\d/g, (d) => BN_DIGITS[Number(d)]);
