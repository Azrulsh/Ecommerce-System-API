# E-commerce Discount System

Laravel 12 backend with a product catalog API, product discount integration, and the original discount calculator. Authentication, cart, and checkout are planned for later phases. See [Phase 2 discount documentation](docs/discounts.md) for pricing endpoints, eligibility, precision, administration services, and Postman examples.

Development sample data includes five promotions created by the dedicated `DiscountSampleSeeder`: Electronics Sale (10%), Fashion Promotion (20%), Gaming Voucher (RM50), Home Living Deal (RM30), and VIP Loyalty Discount (85%, excluded from public pricing). See [seeded pricing examples](docs/discounts.md#sample-discounts-and-http-examples) for verified requests and responses. Run only the intended seeder after verifying its database target; existing named promotions and their assignments are preserved on reruns.

## Database safety

Development uses local MySQL at `127.0.0.1:3306`, database `ecommerce-api`. Actual credentials live only in the ignored `.env` file. After explicit approval and verification that the database was empty, all five pending migrations were applied and the sample catalog was seeded. No existing records were reset or deleted.

Tests use the separate MySQL database `ecommerce_api_testing` and ignored `.env.testing`. A safe template is provided in `.env.testing.example`; supply your own local application key and credentials. Never commit environment secrets. PHPUnit forces the testing environment, local MySQL host/port, database name, and an empty DB_URL override. `Tests\TestCase` rejects cached configuration, unexpected databases, URL/read/write overrides, and mismatched actual `SELECT DATABASE()` results before test transaction setup.

The test database must already exist. Before applying test migrations, verify `.env.testing` and the actual selected database, then inspect pending migrations and preview SQL:

```shell
php artisan migrate:status --env=testing
php artisan migrate --env=testing --pretend
php artisan migrate --env=testing --no-interaction
php vendor/phpunit/phpunit/phpunit --do-not-cache-result
```

The first migration status check may report a missing migrations table on a new database. Laravel's migration preview can initialize that tracking table even with `--pretend`; use it only after confirming the testing target. Tests use database transactions and roll back fixtures; they do not reset the database. Do not substitute the development database name. Do not run `migrate:fresh`, resets, or the Composer `setup` script against valuable data.

## Catalog schema

- `categories`: ID, name, unique slug, nullable description, timestamps.
- `products`: ID, required category foreign key (delete restricted), unique SKU, indexed name, nullable description, unsigned `DECIMAL(12,2)` price, unsigned stock quantity (default 0), indexed active flag (default true), timestamps, soft-delete timestamp. Price is indexed and returned as a two-decimal JSON string. Soft-deleted product SKUs remain reserved.
- Category has many products; Product belongs to Category.

## Endpoints

All API responses are JSON, including errors without an Accept header. Public reads exclude inactive and soft-deleted products. Categories are listed alphabetically.

| Method | Path | Result |
| --- | --- | --- |
| GET | `/api/products` | Paginated active products, 200 |
| GET | `/api/products/{id}` | Active product, 200; missing/hidden product, 404 |
| POST | `/api/products` | Create product, 201 |
| PUT | `/api/products/{id}` | Update required product fields, 200 |
| PATCH | `/api/products/{id}` | Update supplied fields, 200 |
| DELETE | `/api/products/{id}` | Soft delete, 204 with no body |
| GET | `/api/categories` | Categories, 200 |
| GET | `/discount` | Unchanged legacy discount calculation |

Product writes are permitted only when APP_ENV is `local` or `testing`. All other environments return 403 for writes. This is a temporary development restriction, not customer/admin authentication. Local/testing writes can update inactive products.

List query parameters:

| Parameter | Rules |
| --- | --- |
| `search` | Product-name substring, max 255 characters; `%` and `_` treated literally |
| `category` | Category slug; unknown slug returns an empty collection |
| `min_price`, `max_price` | Inclusive nonnegative bounds, at most two decimal places; maximum must be at least minimum |
| `sort` | `name_asc` (default), `name_desc`, `price_asc`, `price_desc`; ID breaks ties |
| `page` | Positive integer |
| `per_page` | Integer 1–100, default 15 |

Example:

```http
GET /api/products?search=laptop&category=electronics&min_price=100&max_price=3000&sort=price_asc&per_page=10&page=1
```

## Create and update examples

Use an existing category ID. Phase 1 has no category write endpoint or automatic development seed data.

```http
POST /api/products
Content-Type: application/json
Accept: application/json

{
  "category_id": 1,
  "sku": "LAPTOP-001",
  "name": "Laptop",
  "description": "A laptop",
  "price": "1299.90",
  "stock_quantity": 10,
  "is_active": true
}
```

Example 201 response (IDs/timestamps depend on stored data):

```json
{
  "data": {
    "id": 1,
    "category_id": 1,
    "category": {"id": 1, "name": "Electronics", "slug": "electronics", "description": null},
    "sku": "LAPTOP-001",
    "name": "Laptop",
    "description": "A laptop",
    "price": "1299.90",
    "stock_quantity": 10,
    "is_active": true,
    "created_at": "2026-11-08T08:00:00.000000Z",
    "updated_at": "2026-11-08T08:00:00.000000Z"
  }
}
```

GET details and successful updates use the same resource shape. List responses put resources in a `data` array and include Laravel `links` and `meta` pagination objects. Categories return `{"data":[{"id":1,"name":"Electronics","slug":"electronics","description":null}]}`.

```http
PATCH /api/products/1
Content-Type: application/json

{"stock_quantity": 5, "price": "1199.90"}
```

PUT requires category_id, SKU, name, price, and stock_quantity; PATCH requires them only when supplied. Description may be null; is_active is optional and defaults true on creation. Prices must be 0–9999999999.99 with at most two decimal places. Stock is an integer 0–4294967295. SKU is unique, including soft-deleted records. Validation errors use HTTP 422:

```json
{
  "message": "The price field must be at least 0.",
  "errors": {"price": ["The price field must be at least 0."]}
}
```

## Legacy discount compatibility

```http
GET /discount?type=fixed&original=100&value=20
```

```json
{"original":100,"discounted_amount":80}
```

`discounted_amount` remains the final price. Percentage discount accepts 0–100; loyalty subtracts 85%, leaving 15% of the original price. Fixed amounts above the original price, percentages outside 0–100, and unknown types retain HTTP 400. Existing legacy input coercion and error messages are unchanged.

## Verification

The dedicated `CatalogSampleSeeder` inserts 40 products (10 each in Electronics, Fashion, Home & Living, and Gaming), with sample MYR prices, stock quantities and descriptions. There are 36 active products and four inactive products. Active out-of-stock items are included to distinguish visibility from stock availability.

After verifying the local development target, run only this seeder to add missing samples:

```shell
php artisan db:seed --class=CatalogSampleSeeder --no-interaction
```

It uses category slugs and product SKUs as stable identifiers, preserves existing records and edits, and does not restore soft-deleted products. Do not run the default DatabaseSeeder for this sample catalog; it creates a user instead. The sample seeder refuses environments and selected databases outside the expected local/testing targets.

## Manual testing with Postman

Start the local server from the project directory:

```shell
php artisan serve --host=127.0.0.1 --port=8000
```

Create a Postman environment variable `base_url` with value `http://127.0.0.1:8000`. Create a request, select GET, set the URL to one of the following, add `Accept: application/json` in Headers, and click Send. Use the Params tab to edit query parameters.

| Purpose | URL |
| --- | --- |
| List | `{{base_url}}/api/products` |
| Categories | `{{base_url}}/api/categories` |
| Detail | `{{base_url}}/api/products/1` |
| Search | `{{base_url}}/api/products?search=laptop` |
| Category | `{{base_url}}/api/products?category=electronics` |
| Price range | `{{base_url}}/api/products?min_price=100&max_price=300` |
| Sort by price | `{{base_url}}/api/products?sort=price_asc` |
| Sort by name | `{{base_url}}/api/products?sort=name_desc` |
| Pagination | `{{base_url}}/api/products?page=2&per_page=10` |
| Combined | `{{base_url}}/api/products?category=electronics&min_price=100&max_price=3000&sort=price_asc` |
| Legacy discount | `{{base_url}}/discount?type=loyalty&original=100` |

Expect HTTP 200 and 36 total visible products before manual changes. A page size of 10 produces four pages. Electronics has nine visible products. `search=laptop` finds two laptops. Inactive sample product `/api/products/10` returns 404 in the initial seeded database; IDs may differ if samples are added to an existing database. Invalid `per_page=101` returns 422. Loyalty returns `{"original":100,"discounted_amount":15}`.

For CRUD testing, use POST `/api/products` with Body → raw → JSON and the creation payload above. Use a new unique SKU such as `MANUAL-001`, and a category ID from GET `/api/categories`. Expect 201, save `data.id`, then use GET and PATCH on that ID. PATCH `{"stock_quantity":5}` should return 200. DELETE that same manually created product should return 204, and a subsequent GET should return 404. This changes only the manual test product. Keep `APP_ENV=local` for writes; other non-testing environments return 403. The API does not yet use authentication.

## Automated checks

```shell
php vendor/phpunit/phpunit/phpunit --do-not-cache-result
php artisan route:list --path=api
php artisan route:list --path=discount
```

Feature tests cover product CRUD, category listing, relationships, visibility, validation, query filters/sorting/pagination, write restrictions, JSON error responses, and legacy discount successes/errors. Run tests exclusively against the isolated testing database.
