# Phase 5: advanced e-commerce APIs

Phase 5 adds product reviews, customer wishlists, admin category/discount/order management, transactional cancellation with mock refunds, and sales reporting. Existing catalog, pricing, authentication, cart, checkout and legacy `/discount` contracts remain available. No real gateway, frontend or dependency updates are included.

## Authentication and authorization

Use `Accept: application/json` and `Content-Type: application/json`. Register/login using the existing `/api/register` and `/api/login` endpoints, then send `Authorization: Bearer {{token}}`. Public product/review reads need no token. A missing/invalid token on a protected endpoint returns 401. Admin endpoints require the server-managed `is_admin` flag; ordinary customers receive 403, including requests with nonexistent admin resource IDs. There is no HTTP privilege-management endpoint. Use existing trusted internal provisioning (`UserPrivilegeService`) for an admin account; never accept admin/loyalty flags from registration or request bodies.

Existing product POST/PUT/PATCH/DELETE endpoints still require an admin in every environment. Public product reads and reviews exclude inactive/soft-deleted products. Customer order routes still expose only the customer's orders, even to an admin using those customer routes. Admins use `/api/admin/orders` for all orders.

API requests are limited to 300/minute per IP. Additional limits are 60/minute per admin for admin routes, 10/minute per user for review writes, and 30/minute per user for wishlist writes. Existing login/registration limits remain. Exceeding a limit returns 429. Use a shared persistent cache for rate limits across production workers; the array cache used in isolated tests deliberately does not persist between separate HTTP requests. Review-owner failures return 404 to avoid exposing ownership. Admins may explicitly moderate by deleting any review through `ReviewPolicy`; they cannot rewrite another customer's review.

## Endpoints

All new list endpoints accept `page` (positive integer) and `per_page` (1–100, default 15), except the aggregated sales summary. Paginated resources contain `data`, `links`, and `meta`. Deletes return 204 without a response body unless documented otherwise.

| Method | Path | Access / behavior |
| --- | --- | --- |
| GET | `/api/products/{product}/reviews` | Public; active product reviews, newest first, summary |
| POST | `/api/products/{product}/reviews` | Customer; create one review per product, 201 |
| PATCH | `/api/reviews/{review}` | Owner; update rating/comment, 200 |
| DELETE | `/api/reviews/{review}` | Owner or explicitly authorized admin moderator |
| GET | `/api/wishlist` | Customer's items, newest first |
| POST | `/api/wishlist/items` | Customer; add active product, 201; existing item, 200 |
| DELETE | `/api/wishlist/items/{product}` | Remove only customer's item; absent item, 404 |
| GET | `/api/admin/categories` | Admin; paginated categories |
| POST | `/api/admin/categories` | Admin; create, 201 |
| PUT/PATCH | `/api/admin/categories/{category}` | Admin; replace required fields / partial update |
| DELETE | `/api/admin/categories/{category}` | Admin; 409 if category contains any products |
| GET | `/api/admin/discounts` | Admin; paginated discounts including inactive |
| POST | `/api/admin/discounts` | Admin; create, 201 |
| GET | `/api/admin/discounts/{discount}` | Admin; discount and assigned product IDs |
| PUT/PATCH | `/api/admin/discounts/{discount}` | Admin; replace required fields / partial update |
| DELETE | `/api/admin/discounts/{discount}` | Admin; deactivate, retain record and assignments |
| POST | `/api/admin/discounts/{discount}/products` | Admin; add assignments without removing others |
| DELETE | `/api/admin/discounts/{discount}/products/{product}` | Admin; detach assignment, repeat is harmless |
| GET | `/api/admin/orders` | Admin; paginated all-customer orders, newest first |
| GET | `/api/admin/orders/{order}` | Admin; immutable items, payment and refund details |
| PATCH | `/api/admin/orders/{order}/status` | Admin; validated workflow transition |
| GET | `/api/admin/sales-summary` | Admin; exact aggregates and top 10 selling SKUs |

## Reviews

```http
POST /api/products/{{product_id}}/reviews
Authorization: Bearer {{customer_token}}
Content-Type: application/json

{"rating":5,"comment":"Comfortable, well made and delivered as described."}
```

Rating must be an integer from 1 to 5. Comment is nullable and at most 5,000 characters. Product/user IDs come from the route and authenticated identity; forged body IDs return 422. A second review by the same customer for that product returns 409. PATCH accepts either editable field, and DELETE allows resubmission later. Owners may delete reviews even after a product becomes unavailable; creation, public reads and updates require an available product.

```http
GET /api/products/{{product_id}}/reviews?per_page=10&page=1
PATCH /api/reviews/{{review_id}}

{"rating":4,"comment":"Updated after a week of use."}
```

Example listing (pagination links/meta abbreviated here):

```json
{
  "data": [{"id":1,"product_id":42,"rating":5,"comment":"Excellent quality.","reviewer":{"id":7,"name":"Aina"},"created_at":"2026-11-08T10:00:00.000000Z","updated_at":"2026-11-08T10:00:00.000000Z"}],
  "links": {},
  "meta": {"current_page":1,"per_page":10,"total":1},
  "summary": {"average_rating":"5.00","review_count":1}
}
```

The average covers all reviews for the product, independent of the current page. No reviews means `average_rating: null`, count 0. Reviewer data includes only ID/name, never email, password, privilege flags or tokens. A database unique constraint also enforces one review per user/product and a CHECK constraint enforces rating bounds.

## Wishlist

```http
POST /api/wishlist/items
Authorization: Bearer {{customer_token}}
Content-Type: application/json

{"product_id":42}
```

```http
GET /api/wishlist?per_page=10&page=1
DELETE /api/wishlist/items/42
```

Each returned item has `id`, `product_id`, `is_available`, `product` (the existing ProductResource, including category and decimal price), and `created_at`. A unique user/product constraint prevents duplicates. IDs in delete paths identify products, not wishlist-row IDs. Ownership always comes from the token. An item remains visible to its owner with `is_available: false` if the product later becomes inactive or soft-deleted, and can still be removed. These entries do not bypass checkout availability checks. Hard product deletion cascades the wishlist/review rows.

## Admin categories and discounts

```http
POST /api/admin/categories
Authorization: Bearer {{admin_token}}
Content-Type: application/json

{"name":"Outdoor Living","slug":"outdoor-living","description":"Balcony and garden essentials."}
```

Category names/slugs are required on POST/PUT; PATCH validates supplied fields. Slugs are unique, lowercase letters/digits separated by hyphens, at most 255 characters. Descriptions are nullable, at most 10,000 characters. Deleting a category containing active, inactive or soft-deleted products returns 409; the existing restricted product foreign key remains in place.

```http
POST /api/admin/discounts
Authorization: Bearer {{admin_token}}
Content-Type: application/json

{"name":"Weekend Electronics","type":"percentage","value":"10.00","is_active":true,"starts_at":"2026-11-08T00:00:00Z","expires_at":"2026-11-15T00:00:00Z"}
```

Example response:

```json
{"data":{"id":6,"name":"Weekend Electronics","type":"percentage","value":"10.00","is_active":true,"starts_at":"2026-11-08T00:00:00.000000Z","expires_at":"2026-11-15T00:00:00.000000Z","requires_loyalty":false}}
```

Choose validity windows appropriate to your actual testing date. Management accepts inactive/future/expired records intentionally; ProductPricingService still selects only currently eligible promotions. Dates normalize to UTC and expiration must follow activation when both are present. PATCH merges existing dates before business validation, so changing just one endpoint cannot create an invalid interval.

```http
POST /api/admin/discounts/{{discount_id}}/products
Authorization: Bearer {{admin_token}}
Content-Type: application/json

{"product_ids":[42,43]}
```

```http
PATCH /api/admin/discounts/{{discount_id}}

{"value":"15.00"}

DELETE /api/admin/discounts/{{discount_id}}/products/42
DELETE /api/admin/discounts/{{discount_id}}
```

Assignments are additive and idempotent; GET/update/assignment resources expose `product_ids`, including soft-deleted assigned products. Ordinary discount updates never detach products. POST assignment requires 1–1,000 distinct existing, non-deleted product IDs. Removal explicitly detaches only the specified product. DELETE discount only deactivates; historical snapshot references and pivot rows remain.

All writes reuse DiscountAdministrationService: percentage 0–100; loyalty exactly 85.00 and membership requirement forced on; fixed discount nonnegative and no greater than each assigned product's price. Monetary inputs accept at most two decimal places within DECIMAL(12,2). Existing best-eligible-discount selection, no stacking, authenticated loyalty checks, and legacy calculator rules are unchanged.

## Order management, cancellation and refunds

```http
GET /api/admin/orders?status=placed&from_date=2026-11-01&to_date=2026-11-30&per_page=10&page=1
GET /api/admin/orders/{{order_id}}
PATCH /api/admin/orders/{{order_id}}/status
Authorization: Bearer {{admin_token}}
Content-Type: application/json

{"status":"processing"}
```

Allowed transitions reuse OrderStatus:

| Current | Allowed next states |
| --- | --- |
| placed | processing, cancelled |
| processing | shipped, cancelled |
| shipped | completed |
| completed / cancelled | No further transition |

Repeating the current state returns 200 unchanged. Other invalid transitions return 409. Unknown status values and supplied financial totals/user/cancellation markers return 422. The controller reads only validated workflow inputs; it never writes arbitrary request attributes. Order/item financial snapshots are never repriced or edited.

Cancel by PATCHing `{"status":"cancelled"}`. The transaction locks the order, inventory products one at a time in ascending product ID, then payment; restores the ordered quantities; creates one mock refund; marks payment refunded and records `stock_restored_at`. Repeated requests lock the same order and return its completed cancellation without restoring stock/refunding again. Transactions retry deadlocks up to three times. Soft-deleted/inactive inventory can be restored; missing/hard-deleted product references or stock overflow return 409 for manual resolution, with no partial changes. Shipment/completion cannot be cancelled.

The refund amount equals the original payment/order total. Payment reference, amount and original `paid_at`, order totals, and all item snapshots are preserved. Refund has a separate unique payment link/reference, exact amount, status and timestamp. No public refund endpoint exists. Owner order/payment reads include the refund resource after cancellation; the workflow itself remains admin-only.

Example cancellation response fields (other order/item fields omitted):

```json
{"data":{"id":12,"status":"cancelled","currency":"MYR","subtotal":"59.97","discount_total":"6.00","grand_total":"53.97","stock_restored_at":"2026-11-08T10:30:00.000000Z","payment":{"id":12,"reference":"MOCK-example","amount":"53.97","status":"refunded","paid_at":"2026-11-08T10:00:00.000000Z","refund":{"id":1,"reference":"MOCK-REFUND-example","amount":"53.97","status":"refunded","refunded_at":"2026-11-08T10:30:00.000000Z"}}}}
```

For local/testing simulation, send `X-Mock-Refund: fail` or `success` on a cancellation request. Failure returns 409 and rolls back status, inventory, marker, payment and refund creation together; a subsequent success may retry. Headers are rejected outside local/testing (422). Without a header, `checkout.mock_refund_outcome` defaults to `success`; invalid configuration returns 503 and rolls back. Failed attempts leave no successful-refund record. No real money moves, no asynchronous gateway callback/reconciliation or partial refunds are implemented. An already successful cancellation remains successful even if retried with a failure simulation.

## Sales summary definitions

```http
GET /api/admin/sales-summary?from_date=2026-11-01&to_date=2026-11-30
Authorization: Bearer {{admin_token}}
```

```json
{"data":{"total_orders":2,"completed_orders":1,"cancelled_orders":1,"orders_by_status":{"placed":0,"processing":0,"shipped":0,"completed":1,"cancelled":1},"gross_sales":"59.97","discounts_given":"6.00","net_sales":"53.97","top_selling_products":[{"sku":"ELEC-EXAMPLE","product_name":"Wireless Headphones","quantity_sold":3,"revenue":"53.97"}]}}
```

- Filters use **order creation time in UTC**, inclusive calendar dates: `>= from_date 00:00:00`, `< day after to_date 00:00:00`. Either bound is optional; both require `to_date >= from_date`. Invalid dates return 422.
- `total_orders` and `orders_by_status` count every order in that range once, including cancelled or unpaid orders. `completed_orders`/`cancelled_orders` are the corresponding status counts.
- Recognized sales require a non-cancelled order, its payment status `paid`, and no refund record. This includes paid placed/processing/shipped/completed orders, rather than only completed orders. Cancelled/refunded/failed/unpaid orders contribute nothing to financial metrics or top sellers.
- `gross_sales` is SUM of recognized order subtotals before discounts; `discounts_given` is SUM of their discount totals; `net_sales` is SUM of their grand totals. SQL DECIMAL aggregation and string formatting preserve cents, including totals larger than a single DECIMAL(12,2) order. Empty totals are `"0.00"`.
- Top 10 products group immutable item snapshots by SKU, sum quantity and line-total revenue, sort descending by quantity then SKU. A deterministic MAX of historical name snapshots labels each SKU; current catalog price/name changes cannot alter revenue. This treats a historically reused SKU as one reporting group.
- Queries execute within one database transaction for a consistent InnoDB repeatable-read snapshot. Order totals are aggregated without item joins, avoiding duplicate order totals for multi-item orders. This is an operational paid-sales summary, not accounting settlement or a refund-event cash-flow ledger.

## Database safety and verification

Development remains `mysql`, `127.0.0.1:3306`, database `ecommerce-api`; tests use only `ecommerce_api_testing`. Existing ignored environment files/credentials are unchanged. Inspect actual `SELECT DATABASE()`, pending migrations and SQL before applying ordinary migrations. Never reset/wipe/fresh/refresh the development database.

Phase 5 adds only:

- `reviews`: product/user cascading foreign keys, unique pair, rating CHECK 1–5, comment and timestamps.
- `wishlist_items`: user/product cascading foreign keys, unique pair and timestamps.
- Nullable `orders.stock_restored_at`.
- `refunds`: unique payment foreign key (cascade), unique reference, unsigned DECIMAL(12,2) amount, status, refund timestamp and timestamps.

Existing development rows are compared to pre-migration count/SHA-256 snapshots; no development fixtures are inserted for HTTP verification. The base TestCase and standalone workers fail closed before mutation if their configuration or actual database is not the isolated local testing target. Standard feature tests roll back transactions. Contention/HTTP checks create committed, uniquely owned fixtures and remove only those IDs and associated tokens in `finally`; no database reset is used. Run these checks **sequentially**, never alongside another suite sharing the testing database.

```shell
php artisan migrate:status
php artisan migrate:status --env=testing
php vendor/phpunit/phpunit/phpunit --do-not-cache-result
php tests/Support/advanced-http-smoke.php
php artisan route:list --path=api
```

`CancellationConcurrencyTest` starts two independent PHP/MySQL workers and holds a product barrier lock. It requires observing both worker transactions waiting on row locks simultaneously before releasing the barrier, then checks one stock restoration/refund per order. It covers simultaneous retries of one order and different orders sharing one product. Existing checkout contention tests also remain. The observer uses the local MariaDB/XAMPP `information_schema.INNODB_TRX` and `INNODB_LOCK_WAITS`, requiring appropriate metadata privileges; MySQL 8 servers exposing performance_schema lock metadata instead need an adapted observer. Unobserved contention fails rather than silently skipping. These tests verify the covered races, not every possible future integration.

`advanced-http-smoke.php` starts a temporary loopback PHP server with the verified testing environment, exercises actual TCP requests, and stops its own process. It tests guest/customer/admin access, reviewer privacy, wishlist isolation/duplicates, category restrictions, discount administration/pricing, checkout, declined-refund rollback, repeat cancellation, inventory restoration, reporting and the legacy calculator. Tokens are never printed or retained. Do not run HTTP smoke and PHPUnit concurrently.

Run Pint on new/modified PHP files only, then `--test` those same files. Composer/NPM dependencies and lockfiles remain unchanged; previously recorded advisories in [customer-cart.md](customer-cart.md) remain deferred to final maintenance.

Verified Phase 5 results: **199 tests passed, 0 failed, 1,195 assertions**, including all 173 earlier regression tests and two genuine cancellation contention tests. **35 real HTTP checks passed**. Pint passed on all 52 new/modified PHP files. Three additive migrations ran successfully in each database; neither has pending migrations. All 11 existing development-table fingerprints matched before and after migration, including 40 products, four categories, five discounts, 67 discount assignments, three users and two carts. HTTP fixtures/tokens were removed from testing; development sample records were unchanged.

## Postman walkthrough

1. Start the development API using `php artisan serve`. Set collection variables `base_url` (`http://127.0.0.1:8000`), `customer_token`, `other_customer_token`, `admin_token`, `product_id`, `review_id`, `discount_id`, and `order_id`. Store tokens locally, never in a committed/exported collection.
2. Register/login two ordinary users and a separately provisioned admin. Copy login tokens into their variables. Use Bearer Auth per request; disable it for public reads.
3. Select an active product with sufficient stock from `GET {{base_url}}/api/products`. Create/read/update a review and verify the other customer's update/delete returns 404. Check an admin can delete the review but cannot alter another author's rating.
4. Add the product twice to the wishlist (201 then 200). Read as each customer to confirm isolation; remove by product ID.
5. As admin, create a category and a currently valid discount, assign existing product IDs, inspect public `/price`, update/deactivate the discount, and verify the category containing products cannot be deleted. Regular customers must receive 403 on the same admin paths.
6. Add stock to a cart and checkout with a fresh `Idempotency-Key`. Record original stock, totals, payment reference and snapshots. As admin, try cancellation with `X-Mock-Refund: fail` and confirm 409 with unchanged stock/order/payment. Retry success, repeat cancellation, and verify stock restored exactly once and one refund equals the paid amount. Do not use manually valuable orders for experiments.
7. Read customer order/payment and admin summary. Verify cancelled sales do not contribute recognized revenue. Try invalid date ranges/status transitions and unauthorized access. Use the isolated HTTP script above for repeatable checks that leave development data untouched.

## Phase 5 file inventory

Created:

- Controllers: `app/Http/Controllers/ReviewController.php`, `WishlistController.php`; `Admin/CategoryController.php`, `Admin/DiscountController.php`, `Admin/OrderController.php`, `Admin/SalesSummaryController.php`.
- Middleware/policy: `app/Http/Middleware/EnsureAdmin.php`, `app/Policies/ReviewPolicy.php`.
- Requests (`app/Http/Requests/`): `PaginationRequest.php`, `StoreReviewRequest.php`, `UpdateReviewRequest.php`, `StoreWishlistItemRequest.php`, `StoreCategoryRequest.php`, `UpdateCategoryRequest.php`, `StoreDiscountRequest.php`, `UpdateDiscountRequest.php`, `AssignDiscountProductsRequest.php`, `DateRangeRequest.php`, `IndexAdminOrderRequest.php`, `UpdateOrderStatusRequest.php`.
- Resources (`app/Http/Resources/`): `ReviewResource.php`, `WishlistItemResource.php`, `RefundResource.php`, `SalesSummaryResource.php`.
- Models: `app/Models/Review.php`, `WishlistItem.php`, `Refund.php`.
- Services: `app/Services/OrderWorkflowService.php`, `SalesSummaryService.php`.
- Migrations: `database/migrations/2026_11_08_100001_create_reviews_table.php`, `2026_11_08_100002_create_wishlist_items_table.php`, `2026_11_08_100003_add_cancellation_tracking_and_refunds.php`.
- Tests: `tests/Feature/ReviewsAndWishlistTest.php`, `AdminCatalogTest.php`, `AdminOrdersTest.php`, `CancellationConcurrencyTest.php`; `tests/Support/cancellation-worker.php`, `advanced-http-smoke.php`.
- Documentation: `docs/advanced-ecommerce.md`.

Modified:

- `app/Models/Product.php`, `User.php`, `Order.php`, `Payment.php` (relationships/cancellation cast).
- `app/Http/Controllers/OrderController.php`; `app/Http/Resources/DiscountResource.php`, `OrderResource.php`, `PaymentResource.php` (assignment IDs/cancellation/refund visibility).
- `app/Services/Discount/DiscountAdministrationService.php`, `app/Services/MockPaymentService.php`.
- `app/Providers/AppServiceProvider.php`, `bootstrap/app.php`, `config/checkout.php`, `routes/api.php`.
- `README.md`, `docs/checkout-orders.md`, `docs/discounts.md`.

No environment files, dependency manifests/lockfiles, legacy discount strategies/controller, generated artifacts or database dumps are part of Phase 5 changes.
