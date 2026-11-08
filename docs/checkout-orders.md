# Phase 4: Checkout and order management

## Transaction flow and data safety

Checkout is authenticated through Sanctum and uses the same current product pricing, integer-cent arithmetic, authenticated loyalty eligibility, UTC validity windows, half-up savings rounding and best-discount/no-stacking rules as earlier phases. Client totals never determine persisted financial values.

One transaction performs the following:

1. Lock the authenticated customer row, then check for that customer's existing idempotency key. A replay returns the original order without inspecting or consuming a new cart.
2. Obtain and lock the customer's cart. Cart mutations use the same cart lock, so checkout cannot clear an item concurrently added to that cart.
3. Read cart items ordered by product ID and acquire each product inventory row lock individually in that order. Reject empty carts, inactive/deleted products, invalid quantities and insufficient stock.
4. Eager load discounts and recalculate all prices inside the transaction, using a single membership decision and UTC instant for the batch.
5. Validate exact totals and DECIMAL(12,2) bounds; create the order and immutable item snapshots.
6. Deduct inventory, record a successful mock payment, and clear only the purchased cart item IDs.
7. Commit. Any exception rolls back the order, items, payment, stock deductions and cart changes. Database deadlocks are retried up to three times.

Adding items to a cart still does not reserve or deduct inventory. Stock/readiness shown by a cart remains informational; checkout rechecks locked current stock. All checkout monetary calculations use 64-bit integer cents. Cart limits of 100 distinct products and 1000 units per line keep intermediate arithmetic within integer bounds; checkout additionally rejects an original subtotal or final total exceeding RM9,999,999,999.99 with HTTP 409 before persisting anything. Each line total and all stored unit values fit DECIMAL(12,2). Subtotal minus savings equals grand total, which equals the sum of final line totals and the payment amount.

## Schema

- `orders`: unique order number, customer FK (delete restricted), status, MYR currency, DECIMAL(12,2) subtotal/discount/grand total, customer-scoped idempotency key and timestamps. Unique `(user_id, checkout_idempotency_key)` uses a binary collation for case-sensitive keys. History and status indexes are included.
- `order_items`: order FK (cascade on deletion), nullable product FK (null on hard deletion), SKU/name snapshots, quantity, original/discount/final unit prices, final line total, nullable JSON discount snapshot and timestamps.
- `payments`: unique order FK, unique mock reference, DECIMAL(12,2) amount, status, paid timestamp and timestamps.

Order resources use snapshots, not live catalog relationships. Renaming/repricing/deleting a product or discount cannot rewrite historical financial values. A hard-deleted product's link becomes null while its SKU, name and price snapshots remain available. Users with order history cannot be hard-deleted through the FK; no user deletion endpoint is added.

The three migrations only create new tables/indexes/constraints. Development remains `ecommerce-api`; tests remain `ecommerce_api_testing`. Existing environment credentials and dependency versions are unchanged. Development product, category, discount, assignment, user, cart and token rows are checked against pre-change snapshots. No development checkout is needed for automated/HTTP verification.

## Postman setup

Start `php artisan serve --host=127.0.0.1 --port=8000`. Set `base_url` to `http://127.0.0.1:8000`. Register or log in as described in [customer authentication](customer-cart.md), store the returned token privately, and select Authorization → Bearer Token → `{{access_token}}`. Do not store real credentials or tokens in repository files.

Add products through POST `{{base_url}}/api/cart/items` with JSON `{"product_id":1,"quantity":2}`. For checkout use:

```http
POST {{base_url}}/api/checkout
Accept: application/json
Content-Type: application/json
Authorization: Bearer {{access_token}}
Idempotency-Key: postman-order-001
```

Body is `{}`. Use a new key for each intended new purchase. Keys must be 1–128 ASCII characters: an alphanumeric first character followed by alphanumerics, `.`, `_`, `:`, or `-`. The key must be in the header; a body key does not replace it. Missing/malformed keys return HTTP 422. Keys are case-sensitive and scoped to the authenticated customer, so two customers may safely use the same key.

A new checkout returns 201 and `Idempotency-Replayed: false`. Retrying a successful key returns 200 and `Idempotency-Replayed: true`, with the same order resource, no second payment, no extra stock deduction, and no removal of any newly populated cart. Failed transactions do not consume the key and can be retried after fixing the cause. A changed request using an already successful key still returns the existing order; choose a new key for a new purchase. Idempotency keys are retained with order history and are not expired in this phase.

Example response for two RM19.99 products with an eligible 10% sale (IDs, references and times are illustrative):

```json
{
  "data": {
    "id": 1,
    "order_number": "ORD-01JC7EXAMPLEORDER0000000000",
    "status": "placed",
    "currency": "MYR",
    "subtotal": "39.98",
    "discount_total": "4.00",
    "grand_total": "35.98",
    "items": [{
      "id": 1,
      "product_id": 1,
      "sku_snapshot": "EXAMPLE-001",
      "product_name_snapshot": "Example Product",
      "quantity": 2,
      "unit_original_price": "19.99",
      "unit_discount": "2.00",
      "unit_final_price": "17.99",
      "line_total": "35.98",
      "applied_discount_snapshot": {"id": 1, "name": "Example Sale", "type": "percentage", "value": "10.00"}
    }],
    "payment": {
      "id": 1,
      "order_id": 1,
      "reference": "MOCK-example-reference",
      "amount": "35.98",
      "status": "paid",
      "paid_at": "2026-11-08T12:00:00.000000Z",
      "created_at": "2026-11-08T12:00:00.000000Z"
    },
    "created_at": "2026-11-08T12:00:00.000000Z",
    "updated_at": "2026-11-08T12:00:00.000000Z"
  }
}
```

## Mock payments

All payments in this phase are simulations, including outside local/testing. They do not contact a real payment service, require payment credentials or perform a real charge. Successful simulation creates one `paid` payment with a unique `MOCK-` reference. Zero-value orders also receive a zero-value mock payment.

Trusted server configuration `checkout.mock_payment_outcome` defaults to `success`; it can be changed to `fail` for deterministic server-side testing. Local/testing requests may additionally set `X-Mock-Payment: fail` or `X-Mock-Payment: success`. The header is prohibited in other environments, even for `success`; client body success flags and client financial totals are prohibited. No environment credentials need modification.

To verify rollback locally, fill a cart and POST checkout using a new Idempotency-Key plus `X-Mock-Payment: fail`. Expect HTTP 409:

```json
{"message":"Mock payment declined. Checkout was rolled back."}
```

GET the cart again: quantities remain unchanged; stock, order and payment records were not committed. Remove the simulation header and retry the same key to succeed. Failure records are not retained as orders/payments because the whole transaction rolls back. This synchronous mock can participate in a DB transaction; replacing it with a real external payment gateway requires a separate payment/reconciliation design, not an external charge inside a retryable database transaction.

## Order history, details and payments

| Method | URL | Result |
| --- | --- | --- |
| GET | `{{base_url}}/api/orders?per_page=10&page=1` | Own orders, newest ID first, including snapshot items/payment |
| GET | `{{base_url}}/api/orders/{id}` | Own order resource |
| GET | `{{base_url}}/api/orders/{id}/payment` | Own payment resource |

History uses the usual `data`, `links`, and `meta` pagination shape; page is positive, page size is 1–100 (default 15). Empty histories return an empty data array. Details use the resource shown above. The payment endpoint wraps the payment object shown above in `data`. Items and payments are eager loaded for history/details. The order policy returns 404 for another customer's orders/payments, including requests by an admin; Phase 5 admin order APIs are not added here. Guest access returns 401.

## Status workflow and future cancellation

New successful orders are `placed`. The OrderStatus enum defines this transition blueprint:

```text
placed -> processing -> shipped -> completed
placed -> cancelled
processing -> cancelled
```

Completed and cancelled are terminal. Shipped orders cannot be cancelled through this workflow. There are no public or internal status-mutation/cancellation APIs in Phase 4, so no stock restoration can run prematurely or twice. Phase 5 administration must authorize transitions and execute cancellation under an order row lock, restoring stock only for eligible placed/processing orders, with an atomic persisted restoration marker/ledger and a no-op repeat cancellation. Payment refund handling must be designed alongside that workflow. This phase defines the states/transitions without implementing Phase 5 administration.

## Errors

| Status | Examples |
| --- | --- |
| 401 | Missing/invalid/revoked customer token |
| 404 | Missing order/payment or another customer's order |
| 409 | Empty cart, inactive/deleted product, insufficient stock, monetary bounds, mock payment failure |
| 422 | Invalid/missing idempotency header, prohibited client totals or simulation header in production, invalid pagination |
| 503 | Invalid trusted mock-payment configuration |

All API errors use JSON and preserve the earlier phases' conventions. The legacy GET `/discount` endpoint retains its HTTP 400 rules and original response contract.

## Verification and concurrency

Verify the actual development/testing targets before migrations; inspect pending SQL and run only safe ordinary pending migrations:

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

Do not use fresh/refresh/reset/wipe commands against development. PHPUnit's existing fail-closed base guard enforces the actual local `ecommerce_api_testing` connection before fixtures are changed. Most tests use rollback. Concurrency tests deliberately commit small, uniquely owned fixtures so independent PHP processes can see them, then remove only those fixture IDs; they never reset the database. The worker separately validates its testing environment and actual selected database before invoking checkout.

`CheckoutConcurrencyTest` launches two PHP workers on separate MySQL connections, holds an inventory barrier lock, and asserts both worker transactions are concurrently waiting on row locks using InnoDB lock metadata before releasing the barrier. Tests then verify (1) two customers competing for one unit produce one 201, one 409, one order/payment and nonnegative stock; (2) simultaneous same-customer/key retries produce one 201, one 200, the same order and one payment. These are genuine overlapping transactions, not sequential retry tests. InnoDB metadata polling leaves 250ms between reads so its cached snapshots can refresh.

The contention observer is verified on the local XAMPP InnoDB server and requires access to `information_schema.INNODB_TRX`/`INNODB_LOCK_WAITS` (including PROCESS privileges where required). On a server exposing only MySQL 8 performance_schema lock metadata, adapt the observer; do not report an unobserved race as verified. Tests fail rather than silently skip if concurrent waits cannot be observed. They verify the two tested races, not every possible future checkout/admin interaction.

HTTP smoke verification uses a temporary server configured exclusively for `ecommerce_api_testing`, synthetic users/products/discounts, and scoped cleanup. It verifies checkout/replay, cart clearing, inventory, paid-payment reads, ownership, authenticated loyalty and complete decline rollback without changing any existing development rows or retaining test tokens.

Known limits: mock payments only; no real gateway, shipping, tax, refund or cancellation execution; customer history only; DECIMAL limits are enforced rather than silently truncating large cart totals. Earlier recorded dependency advisories remain deferred to final maintenance; no Composer/NPM security updates were performed.

## Files

Created: `app/Enums/OrderStatus.php`; `app/Models/Order.php`, `OrderItem.php`, `Payment.php`; `app/Policies/OrderPolicy.php`; `app/Services/CheckoutService.php`, `MockPaymentService.php`; `app/Http/Controllers/CheckoutController.php`, `OrderController.php`; `app/Http/Requests/CheckoutRequest.php`, `IndexOrderRequest.php`; `app/Http/Resources/OrderResource.php`, `OrderItemResource.php`, `PaymentResource.php`; `config/checkout.php`; migrations `2026_11_08_090001_create_orders_table.php`, `2026_11_08_090002_create_order_items_table.php`, `2026_11_08_090003_create_payments_table.php`; `tests/Feature/CheckoutTest.php`, `CheckoutConcurrencyTest.php`; `tests/Unit/OrderStatusTest.php`; `tests/Support/checkout-worker.php`; this document.

Modified: `app/Models/User.php` (orders relationship), `routes/api.php` (four authenticated endpoints), `README.md` (documentation link).
