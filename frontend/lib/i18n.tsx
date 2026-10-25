"use client";

import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from "react";
import { BN } from "@/lib/bn";
import { startDomTranslate, stopDomTranslate } from "@/lib/domTranslate";

export type Lang = "en" | "bn";
const KEY = "vm_lang";
const SWITCH_KEY = "vm_lang_switch";

type Ctx = { lang: Lang; setLang: (l: Lang) => void; t: (text: string) => string; showSwitch: boolean; setShowSwitch: (v: boolean) => void };
const I18n = createContext<Ctx>({ lang: "en", setLang: () => {}, t: (s) => s, showSwitch: true, setShowSwitch: () => {} });

/** Bangla digits for numbers shown in text. */
const BN_DIGITS = "০১২৩৪৫৬৭৮৯";

export function LangProvider({ children }: { children: ReactNode }) {
  const [lang, setLangState] = useState<Lang>("en");
  const [showSwitch, setShowSwitchState] = useState(true); // whether the EN / বাং switch is shown in the menu

  useEffect(() => {
    try {
      const saved = localStorage.getItem(KEY);
      if (saved === "bn" || saved === "en") setLangState(saved);
      if (localStorage.getItem(SWITCH_KEY) === "0") setShowSwitchState(false);
    } catch {}
  }, []);

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
    setLangState(l);
    try {
      localStorage.setItem(KEY, l);
    } catch {}
  }, []);

  const setShowSwitch = useCallback((v: boolean) => {
    setShowSwitchState(v);
    try {
      localStorage.setItem(SWITCH_KEY, v ? "1" : "0");
    } catch {}
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
