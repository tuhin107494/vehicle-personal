---
paths:
  - "frontend/**"
---
# Frontend

Before using any Next.js API you are unsure of, read its guide in `frontend/node_modules/next/dist/docs/` (this Next.js 16 differs from older versions).

## Structure
- Screens: `app/(dashboard)/<feature>/page.tsx`. Forms: `components/forms/<Name>Form.tsx`. Shared pieces: `components/`. Primitives: `components/ui/`.
- MUST reuse `DataTable`, `SearchSelect`, `Selects`, `MobileCards`, `ResponsiveFilters` and the `ui/` primitives before creating a new component.
- Components render and collect input. Business rules (amounts due, allowed transitions) come from the API. The UI MAY preview a calculation but MUST NOT be the only check.

## Data and state
- All API calls MUST go through `api()` in `lib/api.ts`. MUST NOT call `fetch` directly or build the API URL by hand.
- API data types live in `lib/types.ts` and MUST match the backend Resource (see [api-design.md](api-design.md)).
- Shared state uses only the existing contexts (`lib/auth.tsx`, `lib/i18n.tsx`). Screen state stays local (`useState`, react-hook-form). MUST NOT add a state library without an ADR.
- After a save, rely on `api()`'s cache reset (any non-GET clears it). MUST NOT add a separate cache.

## Every data screen MUST show four states
1. **Loading:** pass `loading` to `DataTable`, or show the existing `Loading...` text.
2. **Empty:** `DataTable`'s `empty` text, or an equivalent message.
3. **Error:** a `toast.error(...)` with the `ApiError` message. The screen stays usable.
4. **Success:** the data. Writes confirm with `toast.success(...)`.

Submit buttons MUST be disabled while their request is pending, so they can't double-submit.

## Forms and validation
- Use react-hook-form + `zodResolver`, like `CustomerForm.tsx`.
- The zod schema MUST mirror the FormRequest: the same required fields, max lengths, number ranges and regexes (for example the mobile pattern). The server is the authority.
- Show server errors with `applyServerErrors(error, setError)`, which maps 422 field errors onto inputs.

## Accessibility (WCAG 2.1 AA)
- Every input has a visible `<Label htmlFor>`, or an `aria-label` when no visible label fits.
- Buttons that show only an icon MUST have an `aria-label`.
- Everything works by keyboard. Use `ui/dialog`, `ui/button` and the other base-ui primitives, not clickable `div`s.
- Status MUST NOT be shown by color alone. Pair it with text (for example a badge label).
- Text contrast at least 4.5:1. Use the existing Tailwind color tokens.

## Internationalization
- Every new user-visible English string MUST be added to `lib/bn.ts`, and rendered through `t()` from `useT()` (`lib/i18n.tsx`), or left for `lib/domTranslate.ts` to translate the way nearby code does.

## Security
- MUST NOT use `dangerouslySetInnerHTML`, `innerHTML` or `eval` with API or user data (there are none today).
- MUST NOT put secrets in `NEXT_PUBLIC_*` vars or client code. The only public var is `NEXT_PUBLIC_API_URL`.
- MUST NOT log tokens or API responses with `console.log`.
- MUST NOT edit the CSP or headers in `next.config.ts` without the approval required by [security.md](security.md).
