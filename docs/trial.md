# Free trial for signed-up shops

A shop that signs itself up gets **7 days** from the moment it opens (the owner confirms their email, signs up with Google, or the super admin approves it). When the trial ends, the shop's sign-in and shop screens are locked until the super admin extends it. Data is kept. Shops the super admin creates, and every shop that existed before, have no limit (`shops.trial_ends_at` is empty).

All trial rules are in `TrialService`. The super admin extends a trial from **Shops → Extend** (`POST /api/shops/{shop}/extend-trial`, `days` 1 to 365).

## Files that exist only for the trial
- `backend/app/Services/TrialService.php`
- `backend/app/Http/Middleware/EnsureTrialActive.php`
- `backend/app/Http/Controllers/Api/TrialController.php`
- `backend/app/Http/Requests/ExtendTrialRequest.php`
- `backend/database/migrations/2026_11_13_100000_add_trial_ends_at_to_shops_table.php`
- `backend/tests/Feature/TrialTest.php`
- `frontend/components/TrialBanner.tsx`, `frontend/components/TrialCard.tsx`, `frontend/components/forms/ExtendTrialModal.tsx`, `frontend/lib/trial.ts`

## One-line hooks into other files (remove these to drop trials)
- `backend/bootstrap/app.php`: the `trial` middleware alias
- `backend/routes/api.php`: `'trial'` in the two shop route groups and around the vehicle-categories routes, and the `extend-trial` route
- `backend/app/Http/Controllers/Api/AuthController.php`: the trial check in `signIn()`
- `backend/app/Http/Controllers/Api/SignupController.php` and `ShopController.php`: the `$trial->start(...)` calls
- `backend/app/Models/Shop.php`: `trial_ends_at` in `$fillable` and `casts()`
- `backend/app/Models/User.php`: `trial_ends_at` in `authData()`
- `frontend/lib/types.ts` and `frontend/lib/auth.tsx`: the `trial_ends_at` field
- `frontend/components/AppShell.tsx`: `<TrialBanner />`
- `frontend/app/(dashboard)/profile/page.tsx`: `<TrialCard />`
- `frontend/app/(dashboard)/shops/page.tsx`: the Trial column and Extend button
- `frontend/lib/bn.ts`: the trial strings

## To remove it
Delete the files above, undo the hooks, and add a new migration that drops `shops.trial_ends_at`. Do not edit the existing migration once it is merged.
