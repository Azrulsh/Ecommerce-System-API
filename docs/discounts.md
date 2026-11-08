# Phase 2: Discount integration

## API contract

Start the local server with `php artisan serve --host=127.0.0.1 --port=8000`. In Postman set `base_url` to `http://127.0.0.1:8000` and add `Accept: application/json`.

| Method | URL | Meaning |
| --- | --- | --- |
| GET | `{{base_url}}/api/products/1/price` | Calculate the best eligible assigned discount |
| GET | `{{base_url}}/api/products/1/discounts` | List eligible assigned discounts only, ordered by ID |
| POST | `{{base_url}}/api/discounts/calculate` | Calculate the same authoritative product price using a JSON product ID |

For POST select Body → raw → JSON, with `Content-Type: application/json`:

```json
{"product_id": 1}
```

Both pricing endpoints return HTTP 200 with this resource shape. Example for a product priced at RM1299.90 with an assigned 10% discount:

```json
{
  "data": {
    "product_id": 1,
    "original_price": "1299.90",
    "discount_amount": "129.99",
    "final_price": "1169.91",
    "applied_discount": {
      "id": 1,
      "name": "10% Electronics Discount",
      "type": "percentage"
    }
  }
}
```

Discount-list example:

```json
{
  "data": [{
    "id": 1,
    "name": "10% Electronics Discount",
    "type": "percentage",
    "value": "10.00",
    "is_active": true,
    "starts_at": "2026-11-08T00:00:00.000000Z",
    "expires_at": null,
    "requires_loyalty": false
  }]
}
```

When no discount qualifies, `discount_amount` is `"0.00"`, `final_price` equals `original_price`, and `applied_discount` is null. The discount list is `{"data":[]}`. Existing development catalog prices have not been changed. The examples above illustrate the resource shape; the actual sample promotions and their development responses are below.

Missing, inactive, or soft-deleted products return HTTP 404 on all three endpoints. Missing/invalid `product_id` returns HTTP 422. Nonempty client `loyalty`, `requires_loyalty`, `is_loyalty_member`, `user_id`, or `customer_id` fields on POST are rejected with 422. Unknown input fields cannot affect pricing: the calculator uses only `product_id` and stored product/discount data, not client prices, percentages, or discount IDs. GET query parameters cannot grant loyalty membership. JSON errors do not require an Accept header.

## Eligibility and money

- Discounts must be assigned, active, and valid at the current UTC instant. Start is inclusive and expiration exclusive. A null boundary is unbounded.
- Fixed discounts subtract their decimal amount. Percentages must be between 0 and 100. Loyalty always subtracts 85%; its stored value must be `85.00` and administration forces `requires_loyalty=true`.
- Discounts never stack. Select the lowest final price; equal prices use the lowest discount ID. An eligible zero-saving discount can be selected.
- Reject negative amounts, excess decimal precision, invalid types and invalid schedules during administration. Fixed discounts cannot exceed any product price when assigned or updated.
- Prices changed through Phase 1 can make an old fixed discount too large. Pricing excludes that discount rather than returning a negative amount or breaking the catalog. Invalid imported percentages are excluded too. Deactivation remains possible.
- New calculations use 64-bit integer minor units (cents), with integer percentage basis points. Round the discount savings half-up to cents, then subtract those savings from the original price. For example, 10% off RM0.05 saves RM0.01, leaving RM0.04. All new money/value fields are two-decimal JSON strings. No new monetary calculations are persisted as floats.
- `DecimalDiscountStrategyInterface` extends the original `DiscountStrategyInterface`. `DecimalDiscountStrategy` implements the exact path; `DiscountService::applyDiscountDecimal()` requires that interface and never falls back to float arithmetic. Its compatibility `calculate(float)` method delegates to the original strategies. `DiscountService::applyDiscount(float)` retains its existing behavior.
- Loyalty requires both a customer identity and a trusted `LoyaltyEligibility` decision. The default provider always denies until Phase 3 implements server-managed authenticated eligibility. No HTTP request can provide that decision, and even a User attribute cannot currently unlock loyalty. Tests exercise loyalty arithmetic and the trusted provider extension point independently.

The original `GET /discount` controller and fixed, percentage, and loyalty strategy calculations are unchanged, including its `original` / `discounted_amount` keys, float output, 85% loyalty reduction, input coercion, and HTTP 400 rules. New API validation uses HTTP 422.

## Internal administration

No publicly writable discount administration endpoints exist. `DiscountAdministrationService` provides `create`, `update`, `deactivate`, and additive/idempotent `assignProducts`. It validates input and uses transactions and row locks for updates/assignments. It never modifies catalog prices, stock, or category records. Future admin controllers must authorize callers before invoking it; the service itself is not an authorization boundary. Direct Eloquent writes bypass its validation and should not be used for administration.

Example for trusted application code or an explicitly authorized local Tinker session (not an HTTP request):

```php
$admin = app(\App\Services\Discount\DiscountAdministrationService::class);
$discount = $admin->create([
    'name' => '10% Electronics Discount',
    'type' => 'percentage',
    'value' => '10.00',
    'is_active' => true,
    'starts_at' => '2026-11-08T00:00:00Z',
    'expires_at' => null,
    'requires_loyalty' => false,
]);
$admin->assignProducts($discount, [1]);
```

Timestamps supplied without an offset are interpreted as UTC; offset-bearing timestamps are converted to UTC. Updating a discount validates the merged state, including all currently assigned, non-deleted products. Assignments are additive and reject missing/deleted products or duplicate input IDs; a repeated assignment call does not duplicate pivot rows.

## Database and verification

New migrations only add:

- `discounts`: name, enum type, unsigned `DECIMAL(12,2)` value, active/loyalty flags, nullable UTC schedule columns, timestamps and schedule index.
- `discount_product`: foreign keys, timestamps, a unique discount/product pair. Hard deletion of a discount or product cleans up its assignments; soft-deleting a product does not delete its pivot records, and pricing rejects the product.

No environment credentials, Phase 1 sample products, categories, or catalog seeders are changed. Development uses `ecommerce-api`; tests use `ecommerce_api_testing` with the existing fail-closed test guard and transaction rollback. Review the selected database and pending SQL before running only ordinary pending migrations.

## Sample discounts and HTTP examples

The dedicated seeder creates missing sample promotions through `DiscountAdministrationService` and resolves existing catalog products through their category slugs and sample SKU prefixes, never fixed product IDs. Only active, non-deleted, stocked sample products qualify for assignment. Fixed promotions additionally require the stored product price to cover the voucher amount.

```shell
php artisan db:seed --class=DiscountSampleSeeder --no-interaction
```

The seeder verifies both the configured and actual database: local MySQL `ecommerce-api`, or `ecommerce_api_testing` when running tests. It refuses other environments, unexpected host/port, and connection URL/read/write overrides. It does not invoke the catalog or default database seeders.

| Promotion | Type/value | Assigned products in the original sample catalog |
| --- | --- | --- |
| Electronics Sale | percentage / 10.00 | 8 Electronics products |
| Fashion Promotion | percentage / 20.00 | 9 Fashion products |
| Gaming Voucher | fixed / 50.00 | 7 Gaming products priced at least RM50 |
| Home Living Deal | fixed / 30.00 | 9 Home & Living products |
| VIP Loyalty Discount | loyalty / 85.00 | 34 qualifying products across all four categories |

This creates five promotions and 67 unique assignments. The RM49.90 gaming mouse mat is eligible only for the stored VIP promotion, which public requests cannot use. Active out-of-stock products and inactive products receive no sample promotions.

On first creation, each promotion starts at the current UTC day's midnight and expires 30 days later (exclusive). The development run created a window of `2026-11-08T00:00:00Z` through `2026-12-08T00:00:00Z`. Rerunning never refreshes dates, reactivates promotions, changes values, or restores manually removed assignments. Expired or deactivated promotions therefore remain ineligible; a trusted manual update is needed to extend an expired sample window.

Sample names are the stable identities. If a record already has a sample name, the entire record and its assignments are skipped, even if manually modified. Preserve those names when editing sample values or schedules; renaming a sample makes its old name available for recreation on a later run. Run sample setup serially. All five new promotions and their assignments are created in one transaction, so a missing suitable category/product rolls back the run rather than leaving partial samples. Existing products and categories are never updated.

The following values were verified with real HTTP GET and POST requests against the development database. IDs reflect that database; on another installation, resolve products by searching the catalog and use the returned ID.

| Request | Original | Savings | Final | Applied promotion |
| --- | --- | --- | --- | --- |
| `GET {{base_url}}/api/products/1/price` | 2499.00 | 249.90 | 2249.10 | Electronics Sale |
| `GET {{base_url}}/api/products/11/price` | 39.90 | 7.98 | 31.92 | Fashion Promotion |
| `GET {{base_url}}/api/products/31/price` | 219.00 | 50.00 | 169.00 | Gaming Voucher |
| `GET {{base_url}}/api/products/21/price` | 79.00 | 30.00 | 49.00 | Home Living Deal |
| `GET {{base_url}}/api/products/35/price` | 49.90 | 0.00 | 49.90 | null (loyalty unavailable publicly) |

Example Electronics response, HTTP 200:

```json
{
  "data": {
    "product_id": 1,
    "original_price": "2499.00",
    "discount_amount": "249.90",
    "final_price": "2249.10",
    "applied_discount": {"id": 1, "name": "Electronics Sale", "type": "percentage"}
  }
}
```

In Postman, POST `{{base_url}}/api/discounts/calculate` with JSON `{"product_id":1}` returns the same response. GET `{{base_url}}/api/products/1/discounts` lists Electronics Sale with value `"10.00"`, but does not expose VIP Loyalty Discount. GET `{{base_url}}/api/products/35/discounts` returns `{"data":[]}`. Adding `?is_loyalty_member=1` cannot change the price. POSTing `{"product_id":35,"is_loyalty_member":true}` returns HTTP 422; loyalty eligibility cannot be supplied by the client.

The original `GET {{base_url}}/discount?type=loyalty&original=100` still returns `{"original":100,"discounted_amount":15}`. That legacy arithmetic endpoint is independent of product eligibility.

```shell
php artisan migrate:status
php artisan migrate --pretend
php artisan migrate --no-interaction
php artisan migrate:status --env=testing
php artisan migrate --env=testing --pretend
php artisan migrate --env=testing --no-interaction
php vendor/phpunit/phpunit/phpunit --do-not-cache-result
php artisan route:list --path=api
```

Tests cover precise arithmetic (including maximum money values and rounding), invalid inputs, legacy compatibility, UTC boundaries, inactive/expired/future discounts, no stacking, tie-breaking, assignment relationships, administration validation/rollback, hidden products, and attempted client loyalty claims. No authentication or later-phase functionality is implemented.
