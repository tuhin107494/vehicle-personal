@AGENTS.md

# Frontend (Next.js dashboard)

Next.js 16.3 App Router, React 19, TypeScript `strict`, Tailwind CSS 4, shadcn/ui on `@base-ui/react`, react-hook-form + zod, npm. Rules are in `/.claude/rules/`, especially [frontend.md](../.claude/rules/frontend.md). The lint, typecheck and build commands are defined only in the `/verify` skill.

## Everyday commands (run inside `frontend/`)

| Command | Purpose |
| --- | --- |
| `npm run dev` | Dev server on http://localhost:3000 (it also rewrites the Next.js block in `AGENTS.md`) |
| `npm start` | Serve a production build |

Package installs (`npm install`, `npx shadcn`) ask for approval first.

## Where things go

| Concern | Location |
| --- | --- |
| Screens | `app/(dashboard)/<feature>/page.tsx`; sign-in screens in `app/(auth)/` |
| Shared components | `components/` (`DataTable`, `SearchSelect`, `AppShell`, …) |
| Forms | `components/forms/<Name>Form.tsx` |
| UI primitives | `components/ui/` (shadcn: button, card, checkbox, dialog, input, label, …) |
| API calls, token, error mapping | `lib/api.ts` (`api()`, `ApiError`, `applyServerErrors`) |
| Signed-in user and permissions | `lib/auth.tsx` |
| API data types | `lib/types.ts` |
| Bangla strings | `lib/bn.ts` (the English text is the key) and `lib/i18n.tsx` |
| Security headers and CSP | `next.config.ts` |

## Facts to know before you change code

- The only env vars are `NEXT_PUBLIC_API_URL` (inlined into the browser bundle) and `NODE_ENV`.
- The bearer token is kept in `localStorage` under `vm_token` by `tokenStore` in `lib/api.ts`.
- There is no Prettier and no formatter hook for the frontend. Style is enforced by ESLint (`eslint.config.mjs`: next core-web-vitals + typescript).
- The frontend has no test runner. Behavior is covered by backend feature tests.
