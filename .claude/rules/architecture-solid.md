# Architecture and SOLID

The full picture is in [docs/architecture.md](../../docs/architecture.md).

## Layers and dependency direction (backend)
```
routes/api.php → middleware → Controller → FormRequest (validation)
                                      ↘ Service (business rules, transactions)
                                           ↘ Eloquent Model (data access + scopes)
Controller → Resource → ApiResponse envelope
```
- Dependencies point down only. A Model MUST NOT call a Service. A Service MUST NOT use `Request`, `response()` or Resources.
- **There is no repository layer.** Eloquent models are the data-access layer. MUST NOT add repositories unless an ADR in `docs/adr/` decides to.
- Controllers MAY do single-model CRUD directly (`$customer->update($request->validated())`, as `CustomerController` does). Anything that writes more than one row or table, touches money, or enforces a business rule MUST be a Service method.
- Frontend: `page.tsx` → form/components → `lib/api.ts` → API. Components MUST NOT call `fetch` directly or hold business rules beyond display logic. The server stays the authority.

## SOLID, with this repo's code
**S: single responsibility.** One reason to change per class.
```php
// Controller: HTTP only. Validation lives in StorePaymentRequest, rules in PaymentService.
public function store(StorePaymentRequest $request, Sale $sale, PaymentService $payments): JsonResponse
{
    $created = $payments->collect($sale, $request->validated(), $request->user());

    return $this->success(['payments' => PaymentResource::collection(collect($created))], 'Payment collected', 201);
}
```

**O: open/closed.** Extend by adding, not editing branches. A new permission is one entry in `PermissionCatalog::all()`, not an `if` in a controller. A new validation helper is a protected method on `ApiRequest` that every request inherits.

**L: Liskov substitution.** Every FormRequest extends `ApiRequest` and MUST keep its contract (`authorize()` returns `true`; access control is middleware). A subclass MUST NOT override `authorize()` to return a different answer.

**I: interface segregation.** Service methods take only what they use:
```php
public function collect(Sale $sale, array $data, User $user): array
```
MUST NOT pass a whole `Request` into a Service.

**D: dependency inversion.** Get Services through method or constructor injection (`PaymentService $payments`), never `new PaymentService()` in a controller. That lets tests and the container swap them.

## Design principles
- **DRY:** extract on the **third** repeat, or at once if the logic is a business rule (rules MUST live in one place). Two similar UI blocks MAY stay duplicated.
- **KISS:** prefer the plain Laravel or React feature (`DB::transaction`, FormRequest, `useState`) over a custom abstraction.
- **YAGNI:** MUST NOT add options, interfaces, config flags or generic base classes that no current caller uses.
- **Composition over inheritance:** share model behavior with traits (`BelongsToShop`), and UI behavior with components and hooks. Inheritance is used only for framework bases (`ApiRequest`, `Controller`, `Model`, `JsonResource`).
- A new layer, package or cross-cutting pattern MUST get an ADR (`docs/adr/NNNN-title.md`).
