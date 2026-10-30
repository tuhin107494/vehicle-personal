"use client";

import { Suspense } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { useFetch } from "@/lib/hooks";
import type { Booking } from "@/lib/types";
import { ErrorText, Loading } from "@/components/app-ui";
import { Button } from "@/components/app-ui";
import { SaleForm } from "@/components/forms/SaleForm";
import { BookingList } from "@/components/BookingList";
import { useAuth } from "@/lib/auth";
import { useHiddenMenu } from "@/lib/menu";

/** Sales has three tabs: a normal sale, a sale financed by an investor, and the bookings (vehicles held for an advance). */
function Tabs() {
  const { can } = useAuth();
  // hiding Investors from the menu also removes investor sales from this page
  const { hidden } = useHiddenMenu();
  const investorOff = hidden.includes("/investors");
  const router = useRouter();
  const params = useSearchParams();
  const wanted = params.get("tab");
  // /sales/new?booking=12: the sale is made from that booking (its vehicle, buyer, price and advance)
  const bookingId = params.get("booking");
  const fromBooking = useFetch<Booking>(bookingId ? `/bookings/${bookingId}` : null);
  const booking = wanted === "booking" && can("sales.view");
  const investor = wanted === "investor" && !investorOff;
  // only the address changes (no request for the page again): useSearchParams follows history.replaceState
  const go = (tab: "" | "investor" | "booking") => window.history.replaceState(null, "", tab ? `/sales/new?tab=${tab}` : "/sales/new");

  return (
    <div>
      <div className="mb-4 flex flex-wrap gap-2">
        <Button variant={investor || booking ? "outline" : "primary"} className={investor || booking ? "" : "bg-none bg-emerald-600 hover:bg-emerald-500"} onClick={() => go("")}>Normal sale</Button>
        {!investorOff && <Button variant="outline" className={investor ? "border-transparent bg-sky-600 text-white hover:bg-sky-500 hover:text-white" : "border-sky-400 text-sky-700 hover:bg-sky-50 hover:text-sky-800"} onClick={() => go("investor")}>Investor sale</Button>}
        {can("sales.view") && <Button variant="outline" className={`${booking ? "border-transparent bg-yellow-400 text-slate-900 hover:bg-yellow-300 hover:text-slate-900" : "border-yellow-400 text-yellow-700 hover:bg-yellow-50 hover:text-yellow-800"}`} onClick={() => go("booking")}>Booking</Button>}
      </div>
      {bookingId
        ? fromBooking.loading && !fromBooking.data ? <Loading />
          : fromBooking.data?.status === "active" ? <SaleForm key={`booking-${bookingId}`} booking={fromBooking.data} onSold={(sale) => router.push(`/sales/${sale.id}`)} />
          : <ErrorText message={fromBooking.error ?? `This booking is ${fromBooking.data?.status ?? "not available"}, so it cannot be turned into a sale.`} />
        : booking ? <BookingList /> : <SaleForm key={investor ? "investor" : "normal"} investorMode={investor} />}
    </div>
  );
}

export default function NewSalePage() {
  return <Suspense><Tabs /></Suspense>;
}
