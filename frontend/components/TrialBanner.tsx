"use client";

import { useAuth } from "@/lib/auth";
import { trialState } from "@/lib/trial";

const SHOW_WITHIN_DAYS = 7;

/** Warns a shop on a free trial once 7 days or fewer are left, or that it ended. Nothing for shops without a trial, or with more time left (the profile page always shows it). */
export function TrialBanner() {
  const { user } = useAuth();
  const ends = user?.shop?.trial_ends_at;
  if (!ends) return null;

  const { ended, days } = trialState(ends);
  if (!ended && days > SHOW_WITHIN_DAYS) return null;

  return ended
    ? <p role="alert" className="bg-red-50 px-4 py-2 text-center text-sm font-medium text-red-800 print:hidden">Your free trial has ended. Contact support to continue.</p>
    : <p className="bg-amber-50 px-4 py-2 text-center text-sm font-medium text-amber-800 print:hidden">{days === 1 ? "Free trial: 1 day left." : `Free trial: ${days} days left.`}</p>;
}
