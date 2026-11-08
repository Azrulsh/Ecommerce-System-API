# Phase 3: Customer authentication and shopping carts

## Installation and database safety

Laravel 12.68.0 / PHP 8.4.27, with Sanctum 4.3.3 installed using Laravel's supported command:

Reference: [Laravel 12 Sanctum documentation](https://laravel.com/docs/12.x/sanctum).

```shell
php artisan install:api --without-migration-prompt --no-interaction
```

The existing API routes were preserved: the installer reports that `routes/api.php` already exists and does not overwrite it without `--force`. Do not use `--force` here. Sanctum's provider, configuration and standard token migration are installed; User uses HasApiTokens. Existing routes were extended manually. This is a Bearer-token-only backend: no SPA cookie/session middleware is enabled and Sanctum's session guard list is empty. Tokens expire after 30 days; logout revokes only the current token. Stored tokens are hashed; plaintext tokens are returned only when issued.

Four ordinary pending migrations add `users.is_admin` and `users.is_loyalty_member` (both false by default), Sanctum's personal access tokens table, carts and cart items. There is one cart per user, one item per cart/product, positive quantities, and foreign keys. User deletion cascades to carts/items; referenced products cannot be hard-deleted. Soft-deleted products remain available for cart diagnostics. No persisted cart monetary totals or inventory reservations are added.

Development is local MySQL `ecommerce-api`; PHPUnit uses `ecommerce_api_testing`. Existing credential files remain ignored and unchanged. Before applying migrations, verify both configured targets and actual `SELECT DATABASE()` values, inspect pending migrations, and review SQL. Use only ordinary pending migrations, never resets or fresh migrations on development.

```shell
php artisan migrate:status
php artisan migrate --pretend
php artisan migrate --no-interaction
php artisan migrate:status --env=testing
php artisan migrate --env=testing --pretend
php artisan migrate --env=testing --no-interaction
php vendor/phpunit/phpunit/phpunit --do-not-cache-result
```

The existing Tests\TestCase guard rejects cached/unexpected connections before transaction setup. Tests use rollback and run exclusively on the isolated test database. Existing products, categories, discount rows, assignments, and legacy strategy logic are preserved.

## Registration, login, profile, logout

Start `php artisan serve --host=127.0.0.1 --port=8000`. In Postman set `base_url` to `http://127.0.0.1:8000`. Add `Accept: application/json`; for POST/PATCH choose Body → raw → JSON and `Content-Type: application/json`. All API errors also render JSON without Accept headers.

```http
POST {{base_url}}/api/register
```

```json
{
  "name": "Example Customer",
  "email": "customer@example.test",
  "password": "{{customer_password}}",
  "password_confirmation": "{{customer_password}}"
}
```

Set `customer_password` privately in Postman to a password of 12–72 bytes containing upper- and lower-case letters and numbers. The password values above are variable placeholders, not credentials. Email is normalized to lowercase and must be unique. Registration returns HTTP 201:

```json
{
  "data": {
    "user": {"id": 1, "name": "Example Customer", "email": "customer@example.test", "is_loyalty_member": false, "created_at": "2026-11-08T12:00:00.000000Z"},
    "token_type": "Bearer",
    "token": "<token returned only to the registering customer>"
  }
}
```

POST `{{base_url}}/api/login` with `{"email":"customer@example.test","password":"{{customer_password}}"}` returns the same shape with HTTP 200 and a new token. Incorrect credentials return generic 422 validation errors. Registration is limited to five requests per minute per IP. Login is limited to five requests per minute per email/IP pair and 30 per IP. Exceeding limits returns 429 with Retry-After. Rate limits use the configured cache store.

Save `data.token` to a private Postman variable `access_token`. On protected requests select Authorization → Bearer Token → `{{access_token}}`. Never paste real tokens into documentation, logs, source files, or commits.

GET `{{base_url}}/api/user` returns HTTP 200 with `{"data":{"id":1,"name":"Example Customer","email":"customer@example.test","is_loyalty_member":false,"created_at":"2026-11-08T12:00:00.000000Z"}}`. Password hashes, remember tokens, and the admin flag are not exposed in UserResource. POST `{{base_url}}/api/logout` returns 204 and invalidates only the token used for that request. Other device tokens remain valid. Missing, invalid, expired, or revoked tokens return 401 on protected routes.

## Roles and loyalty

Registration prohibits nonempty `is_admin` and `is_loyalty_member` inputs; neither field is mass assignable. There are no public privilege-management endpoints. Trusted local application code can manage privileges through:

```php
$user = \App\Models\User::where('email', 'customer@example.test')->firstOrFail();
app(\App\Services\UserPrivilegeService::class)->assign($user, false, true);
```

This example grants loyalty only. The arguments explicitly set admin and loyalty flags, respectively; use `true, false` for an explicitly authorized admin. The service is an internal mechanism, not an authorization boundary. Never expose it as a public route.

Product POST, PUT, PATCH, and DELETE require `auth:sanctum` plus ProductPolicy authorization in every environment: guests get 401, customers get 403, admins may proceed to validation. The temporary environment-only middleware was removed. Public catalog/category reads remain public. There are no category or discount administration APIs to extend.

Existing product price/discount routes and POST `/api/discounts/calculate` accept optional Bearer authentication. No token means guest pricing; invalid optional tokens are treated as guests. Valid members receive eligible loyalty discounts based on the stored user record. Client loyalty flags are still prohibited on the calculator and ignored for GET pricing. Loyalty still subtracts 85%, with best-price selection, UTC validity windows, ID tie-breaking and no stacking.

With the original sample catalog and active sample promotions:

| Customer | Laptop unit original | Unit savings | Unit final |
| --- | --- | --- | --- |
| Guest / regular customer | 2499.00 | 249.90 | 2249.10 |
| Authenticated loyalty member | 2499.00 | 2124.15 | 374.85 |

All amounts are MYR strings. Eligibility changes take effect on subsequent requests. The legacy GET `/discount` calculation and response contract are unchanged.

## Cart endpoints

All cart requests require Bearer authentication. A cart is lazily created on first access.

| Method | URL | Body | Status |
| --- | --- | --- | --- |
| GET | `{{base_url}}/api/cart` | none | 200 |
| POST | `{{base_url}}/api/cart/items` | `{"product_id":1,"quantity":2}` | 201 |
| PATCH | `{{base_url}}/api/cart/items/{item}` | `{"quantity":3}` | 200 |
| DELETE | `{{base_url}}/api/cart/items/{item}` | none | 204 |

Use the returned cart-item ID (not product ID) for updates/removal. Adding the same product increases the current quantity and keeps the same row. The combined quantity is validated. One item is limited to 1000 units and a cart to 100 distinct products, keeping totals within exact 64-bit integer arithmetic bounds. Items must be active/non-deleted and quantities positive integers; stock shortages return 409. Missing/hidden products return 404. Invalid quantities or price/ownership claims return 422. Submitted prices, discounts, user IDs and cart IDs never control pricing or ownership. Other customers' item IDs return 404 for updates and deletes, even for admins.

Mutation responses return the current cart, except DELETE which has no body. Every GET recalculates from current catalog prices, discounts, validity windows and stored membership. Products, categories, and discounts are eager loaded; bulk pricing reads membership and scheduling time once per cart. Cart mutation transactions serialize on the cart row and lock products during stock checks. Inventory is never reserved or deducted.

Example regular-customer cart with two sample laptops (product details shortened for readability):

```json
{
  "data": {
    "id": 1,
    "items": [{
      "id": 1,
      "product": {"id": 1, "sku": "SAMPLE-ELEC-001", "name": "14-inch Everyday Laptop", "price": "2499.00"},
      "quantity": 2,
      "availability": "available",
      "available_stock": 12,
      "unit_original_price": "2499.00",
      "unit_final_price": "2249.10",
      "applied_discount": {"id": 1, "name": "Electronics Sale", "type": "percentage"},
      "line_subtotal": "4998.00",
      "line_discount_total": "499.80",
      "line_final_total": "4498.20"
    }],
    "subtotal": "4998.00",
    "total_savings": "499.80",
    "grand_total": "4498.20",
    "is_ready": true
  }
}
```

A loyalty member's same cart has savings `"4248.30"` and grand total `"749.70"`. Empty carts return an empty items array and `"0.00"` totals. Prices and savings are calculated per unit, rounded by the existing discount service, and then multiplied by quantity.

## Availability changes and errors

Cart records are never silently removed because inventory or catalog status changes:

- `available`: active product with sufficient current stock.
- `insufficient_stock`: retain quantity and show current prices/totals, but `is_ready=false`. The customer can reduce quantity or remove the item.
- `inactive` / `deleted`: retain item, quantity, product information and original line subtotal. Unit final price and line discount/final totals are null. Cart savings/grand total are null and `is_ready=false` while any unpriceable item remains. Subtotal includes retained items at their current/last stored original price. The customer can remove those items, but cannot increase or update unavailable products.

This is informational readiness, not checkout or an inventory guarantee. Product foreign keys restrict hard deletion of cart-referenced products; soft deletes are the catalog default.

Examples:

```json
{"message":"Unauthenticated."}
```

```json
{"message":"Requested quantity exceeds available stock."}
```

The first is HTTP 401; the second 409. Validation responses use the existing `message` and `errors` shape with HTTP 422. Cross-user items use 404 to avoid exposing their existence.

## Verification and limits

Run PHPUnit only against the dedicated testing database as above. Coverage includes real token issuance/revocation, login throttling, privilege escalation, admin policies, guest JSON errors, membership/revocation, exact cart calculations and recalculation, unavailable products, quantity/stock checks, ownership, and previous phase regressions. No checkout, order management, payment, password reset or email-verification workflow is added in this phase.

The dependency installation added only Sanctum; unrelated locked versions were preserved. Composer audit reported 13 advisories in seven existing dependencies (Laravel framework, CommonMark, and Symfony components). Sanctum was not listed. Address these in a separate dependency-maintenance change before production deployment; Phase 3 does not silently upgrade unrelated packages.

Recorded from the 8 November 2026 audit for final maintenance; these are the observed results, not a new audit or dependency update:

| Package | Reported advisories |
| --- | ---: |
| `laravel/framework` | 1 |
| `league/commonmark` | 2 |
| `symfony/http-foundation` | 5 |
| `symfony/http-kernel` | 1 |
| `symfony/mime` | 1 |
| `symfony/routing` | 1 |
| `symfony/yaml` | 2 |

Re-audit and review compatible fixes in the final maintenance phase. No Composer or NPM security updates were performed during Phase 3 finalization.

## File inventory

Created:

- `app/Http/Controllers/AuthController.php`
- `app/Http/Controllers/CartController.php`
- `app/Http/Middleware/OptionalSanctumAuthentication.php`
- `app/Http/Requests/LoginRequest.php`
- `app/Http/Requests/RegisterRequest.php`
- `app/Http/Requests/StoreCartItemRequest.php`
- `app/Http/Requests/UpdateCartItemRequest.php`
- `app/Http/Resources/CartItemResource.php`
- `app/Http/Resources/CartResource.php`
- `app/Http/Resources/UserResource.php`
- `app/Models/Cart.php`
- `app/Models/CartItem.php`
- `app/Policies/CartItemPolicy.php`
- `app/Policies/ProductPolicy.php`
- `app/Services/CartPricingService.php`
- `app/Services/CartService.php`
- `app/Services/UserPrivilegeService.php`
- `config/sanctum.php`
- `database/migrations/2026_11_08_000005_add_customer_privileges_to_users_table.php`
- `database/migrations/2026_11_08_000006_create_carts_table.php`
- `database/migrations/2026_11_08_000007_create_cart_items_table.php`
- `database/migrations/2026_11_08_085311_create_personal_access_tokens_table.php`
- `docs/customer-cart.md`
- `tests/Concerns/AuthenticatesCustomers.php`
- `tests/Feature/AuthenticationTest.php`
- `tests/Feature/CustomerLoyaltyTest.php`
- `tests/Feature/ProductAuthorizationTest.php`
- `tests/Feature/ShoppingCartTest.php`

Modified:

- `README.md`
- `app/Http/Requests/StoreProductRequest.php`
- `app/Http/Requests/UpdateProductRequest.php`
- `app/Models/User.php`
- `app/Providers/AppServiceProvider.php`
- `app/Services/Discount/LoyaltyEligibility.php`
- `app/Services/Discount/ProductPricingService.php`
- `bootstrap/app.php`
- `composer.json`
- `composer.lock`
- `docs/discounts.md`
- `routes/api.php`
- `tests/Feature/ProductCatalogTest.php`

Removed: `app/Http/Middleware/EnsureCatalogWritesAllowed.php`, superseded by Sanctum authentication and ProductPolicy.
