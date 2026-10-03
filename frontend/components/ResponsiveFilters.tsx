"use client";

import { useEffect, useState, type ReactNode } from "react";
import { createPortal } from "react-dom";
import { SlidersHorizontal } from "lucide-react";
import { Button, Modal } from "@/components/app-ui";

/**
 * Report filters. On a desktop they sit in the page as given; on a phone or tablet they stay hidden behind one
 * "Filters" button that opens them in a window. The button goes into the `#report-filters-slot` row when the page
 * has one (next to the report picker), otherwise it sits right here.
 */
export function ResponsiveFilters({ children, desktopClass }: { children: ReactNode; desktopClass: string }) {
  const [open, setOpen] = useState(false);
  const [slot, setSlot] = useState<HTMLElement | null>(null);
  useEffect(() => setSlot(document.getElementById("report-filters-slot")), []);

  const button = <Button variant="outline" className="w-full px-2 lg:w-auto" onClick={() => setOpen(true)}><SlidersHorizontal className="size-4" /> Filters</Button>;

  return (
    <>
      <div className={`${desktopClass} print:hidden`}>{children}</div>
      {slot ? createPortal(button, slot) : <div className="mb-4 lg:hidden print:hidden">{button}</div>}
      <div className="lg:hidden">
        <Modal open={open} title="Filters" onClose={() => setOpen(false)}>
          <div className="space-y-3">{children}</div>
          <div className="flex justify-end"><Button onClick={() => setOpen(false)}>Show results</Button></div>
        </Modal>
      </div>
    </>
  );
}
