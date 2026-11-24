"use client"

import * as React from "react"
import { Input as InputPrimitive } from "@base-ui/react/input"
import { CalendarDays, Eye, EyeOff } from "lucide-react"
import { cn } from "@/lib/utils"

// Fields that have a picker (calendar or clock): a click on the icon at the right edge opens it; a click elsewhere in the field is for typing the date.
const PICKER_TYPES = ["date", "datetime-local", "month", "week", "time"]

const subscribeTouch = (notify: () => void) => {
  const query = window.matchMedia("(pointer: coarse)")
  query.addEventListener("change", notify)
  return () => query.removeEventListener("change", notify)
}
const isTouch = () => window.matchMedia("(pointer: coarse)").matches

const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/

function isIsoDate(text: string): boolean {
  return ISO_DATE.test(text) && new Date(`${text}T00:00:00Z`).toISOString().startsWith(text)
}

const toDisplay = (iso: string) => (isIsoDate(iso) ? `${iso.slice(8)}/${iso.slice(5, 7)}/${iso.slice(0, 4)}` : "")

/** Digits typed on a phone keyboard become DD/MM/YYYY (the slashes are added for the person). */
function formatDate(text: string): string {
  const d = text.replace(/\D/g, "").slice(0, 8)
  return [d.slice(0, 2), d.slice(2, 4), d.slice(4, 8)].filter(Boolean).join("/")
}

const toIso = (shown: string) => {
  const [d, m, y] = shown.split("/")
  return y?.length === 4 ? `${y}-${m}-${d}` : ""
}

/**
 * Phones have no way to type into a native date field, so there it is a text field (DD/MM/YYYY) with a calendar button on top of
 * the browser's own picker. The form keeps the date as YYYY-MM-DD in a hidden field, and it is passed on only once the typed date
 * is complete and real, so a half-typed one never reaches the form.
 */
function TouchDateInput({ className, onChange, onBlur, onFocus, min, max, ref, id, value, defaultValue, placeholder, ...props }: React.ComponentProps<"input">) {
  void defaultValue
  void placeholder
  const hidden = React.useRef<HTMLInputElement | null>(null)
  const shown = React.useRef<HTMLInputElement>(null)
  const picker = React.useRef<HTMLInputElement>(null)
  const setHidden = (el: HTMLInputElement | null) => {
    hidden.current = el
    if (typeof ref === "function") ref(el)
    else if (ref) ref.current = el
  }
  // the form can set or reset the value without an event (a controlled value, reset()): show it
  React.useEffect(() => {
    if (!hidden.current || !shown.current) return
    if (value !== undefined && hidden.current.value !== String(value)) hidden.current.value = String(value)
    if (document.activeElement !== shown.current) shown.current.value = toDisplay(hidden.current.value)
  })
  // react-hook-form and the screens read event.target.value, so the event they get points at the hidden YYYY-MM-DD field
  const pass = (handler: React.ChangeEventHandler<HTMLInputElement> | undefined, iso: string) => {
    if (!hidden.current) return
    hidden.current.value = iso
    handler?.({ target: hidden.current, currentTarget: hidden.current, type: "change" } as unknown as React.ChangeEvent<HTMLInputElement>)
  }

  return (
    <div className="relative w-full">
      <input {...props} ref={setHidden} type="hidden" />
      <input
        ref={shown}
        id={id}
        type="text"
        data-slot="input"
        inputMode="numeric"
        autoComplete="off"
        placeholder="DD/MM/YYYY"
        maxLength={10}
        disabled={props.disabled}
        readOnly={props.readOnly}
        required={props.required}
        aria-invalid={props["aria-invalid"]}
        onFocus={onFocus}
        onChange={(e) => {
          const el = e.currentTarget
          const formatted = formatDate(el.value)
          if (formatted !== el.value) el.value = formatted
          if (formatted === "") return pass(onChange, "")
          if (isIsoDate(toIso(formatted))) pass(onChange, toIso(formatted))
        }}
        onBlur={(e) => {
          // an unfinished date is put back to the last good one, so what shows is what the form holds
          e.currentTarget.value = toDisplay(hidden.current?.value ?? "")
          onBlur?.({ target: hidden.current, currentTarget: hidden.current, type: "blur" } as unknown as React.FocusEvent<HTMLInputElement>)
        }}
        className={cn(
          "h-9 w-full min-w-0 rounded-lg border border-input bg-transparent py-1 pr-10 pl-2.5 text-base transition-colors outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 disabled:pointer-events-none disabled:cursor-not-allowed disabled:bg-input/50 disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-3 aria-invalid:ring-destructive/20 dark:bg-input/30 dark:disabled:bg-input/80",
          className
        )}
      />
      <span
        className="absolute inset-y-0 right-0 flex w-10 items-center justify-center text-slate-500"
        onPointerDown={() => {
          // open the calendar on the date already in the field
          if (picker.current && hidden.current) picker.current.value = hidden.current.value
        }}
      >
        <CalendarDays className="size-4" aria-hidden />
        <input
          ref={picker}
          type="date"
          aria-label="Choose date"
          min={min}
          max={max}
          disabled={props.disabled || props.readOnly}
          tabIndex={-1}
          className="absolute inset-0 size-full cursor-pointer opacity-0"
          onChange={(e) => {
            if (shown.current) shown.current.value = toDisplay(e.target.value)
            pass(onChange, e.target.value)
          }}
        />
      </span>
    </div>
  )
}

function PlainInput({ className, type, onClick, onWheel, ...props }: React.ComponentProps<"input">) {
  const touch = React.useSyncExternalStore(subscribeTouch, isTouch, () => false)
  const opensPicker = !!type && PICKER_TYPES.includes(type)

  if (touch && type === "date") return <TouchDateInput className={className} {...props} />

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
