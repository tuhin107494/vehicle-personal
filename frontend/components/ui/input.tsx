"use client"

import * as React from "react"
import { Input as InputPrimitive } from "@base-ui/react/input"
import { Eye, EyeOff } from "lucide-react"
import { cn } from "@/lib/utils"

// Fields that have a picker (calendar or clock): a click on the icon at the right edge opens it; a click elsewhere in the field is for typing the date.
const PICKER_TYPES = ["date", "datetime-local", "month", "week", "time"]

function PlainInput({ className, type, onClick, onWheel, ...props }: React.ComponentProps<"input">) {
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

/** A password field with an eye button: one click shows what was typed, another hides it again. */
function PasswordInput({ className, ...props }: React.ComponentProps<"input">) {
  const [visible, setVisible] = React.useState(false)
  const box = React.useRef<HTMLDivElement>(null)

  // submitting hides it again, so a password is not left on screen after the form has gone
  React.useEffect(() => {
    const form = box.current?.closest("form")
    const hide = () => setVisible(false)
    form?.addEventListener("submit", hide)
    return () => form?.removeEventListener("submit", hide)
  }, [])

  return (
    <div ref={box} className="relative w-full">
      <PlainInput
        {...props}
        type={visible ? "text" : "password"}
        // once shown it is a text field: keep the browser's spellcheck and phone keyboards away from the password
        spellCheck={false}
        autoCapitalize="none"
        autoCorrect="off"
        className={cn("pr-9 [&::-ms-reveal]:hidden", className)}
      />
      <button
        type="button"
        aria-label={visible ? "Hide password" : "Show password"}
        aria-pressed={visible}
        disabled={props.disabled}
        onMouseDown={(e) => e.preventDefault()} // keep the cursor in the field
        onClick={() => setVisible((v) => !v)}
        className="absolute inset-y-0 right-0 flex w-9 items-center justify-center rounded-r-lg text-slate-500 outline-none hover:text-emerald-700 focus-visible:text-emerald-700 focus-visible:ring-3 focus-visible:ring-ring/50 disabled:pointer-events-none disabled:opacity-50"
      >
        {visible ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
      </button>
    </div>
  )
}

function Input({ type, ...props }: React.ComponentProps<"input">) {
  return type === "password" ? <PasswordInput {...props} /> : <PlainInput type={type} {...props} />
}

export { Input }
