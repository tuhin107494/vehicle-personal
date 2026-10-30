import * as React from "react"
import { Input as InputPrimitive } from "@base-ui/react/input"
import { cn } from "@/lib/utils"

// Fields that have a picker (calendar or clock): a click on the icon at the right edge opens it; a click elsewhere in the field is for typing the date.
const PICKER_TYPES = ["date", "datetime-local", "month", "week", "time"]

function Input({ className, type, onClick, onWheel, ...props }: React.ComponentProps<"input">) {
  const opensPicker = !!type && PICKER_TYPES.includes(type)

  return (
    <InputPrimitive
      type={type}
      data-slot="input"
      // scrolling over a focused number field (mouse wheel or touchpad) would change its value: leave the field instead, so the page just scrolls
      onWheel={(e) => {
        onWheel?.(e)
        if (type === "number") e.currentTarget.blur()
      }}
      onClick={(e) => {
        onClick?.(e)
        if (!opensPicker || e.defaultPrevented) return
        const box = e.currentTarget.getBoundingClientRect()
        if (e.clientX < box.right - 36) return // inside the field: let the person type
        try {
          e.currentTarget.showPicker()
        } catch {
          // the browser can refuse (disabled field, no user gesture); the field still works by typing
        }
      }}
      className={cn(
        "h-9 w-full min-w-0 rounded-lg border border-input bg-transparent px-2.5 py-1 text-base transition-colors outline-none file:inline-flex file:h-6 file:border-0 file:bg-transparent file:text-sm file:font-medium file:text-foreground placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 disabled:pointer-events-none disabled:cursor-not-allowed disabled:bg-input/50 disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-3 aria-invalid:ring-destructive/20 md:text-sm dark:bg-input/30 dark:disabled:bg-input/80 dark:aria-invalid:border-destructive/50 dark:aria-invalid:ring-destructive/40",
        className
      )}
      {...props}
    />
  )
}

export { Input }
