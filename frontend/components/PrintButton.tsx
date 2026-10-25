"use client";

import { createPortal } from "react-dom";
import { Printer } from "lucide-react";
import { Button } from "@/components/app-ui";
import { useElementById } from "@/lib/hooks";

/** The page title row holds `<span id="print-slot" />`; the Print / PDF button moves up into it, or stays where it is without one. */
export function PrintButton({ disabled, onClick }: { disabled?: boolean; onClick: () => void }) {
  const slot = useElementById("print-slot");
  const button = <Button className="h-8 gap-1 px-2.5 text-xs md:h-10 md:gap-1.5 md:px-4 md:text-sm" disabled={disabled} onClick={onClick}><Printer className="size-3.5 md:size-4" /> Print / PDF</Button>;
  return slot ? createPortal(button, slot) : <div className="print:hidden">{button}</div>;
}
