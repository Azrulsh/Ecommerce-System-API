# E-commerce Discount System

Laravel 12 backend with a product catalog API, discount integration, Sanctum customer authentication, shopping carts, transactional checkout, order history, mock payments/refunds, product reviews, wishlists, admin management, sales reporting, and the original discount calculator. See [discount documentation](docs/discounts.md), [customer authentication and cart documentation](docs/customer-cart.md), [checkout and orders](docs/checkout-orders.md), and [advanced e-commerce](docs/advanced-ecommerce.md) for API contracts, Postman examples, authorization, and testing instructions.

Development sample data includes five promotions created by the dedicated `DiscountSampleSeeder`: Electronics Sale (10%), Fashion Promotion (20%), Gaming Voucher (RM50), Home Living Deal (RM30), and VIP Loyalty Discount (85%, excluded from public pricing). See [seeded pricing examples](docs/discounts.md#sample-discounts-and-http-examples) for verified requests and responses. Run only the intended seeder after verifying its database target; existing named promotions and their assignments are preserved on reruns.

## Fresh-clone installation

These steps install the existing application; they do not rebuild its APIs or upgrade dependencies. All payments and refunds are simulations: no real charges or refunds occur, even outside local/testing. See [checkout](docs/checkout-orders.md#mock-payments) and [cancellation/refund limitations](docs/advanced-ecommerce.md#order-management-cancellation-and-refunds).

### Prerequisites

- Git and a terminal. Commands below use Windows PowerShell; use `npm.cmd` to avoid PowerShell's `npm.ps1` execution-policy restriction. On other shells use `npm` instead, and replace `Copy-Item` with the equivalent copy command.
- **64-bit PHP 8.2 or later within PHP 8.x**, as required by `composer.json` (`^8.2`). Current verification uses PHP 8.4.27. Enable Ctype, cURL, DOM, Fileinfo, Filter, Hash, iconv, JSON, Mbstring, OpenSSL, PCRE, PDO, **pdo_mysql**, Session, Tokenizer, and XML/XMLWriter. Composer/PHPUnit also need Phar and libxml. Use `php -m` to inspect the CLI PHP installation; ensure the web server uses a compatible PHP configuration. A ZIP extension or an extraction tool such as 7-Zip is useful for Composer archives.
- Composer 2 with the required Composer runtime API (the lockfile requires `composer-runtime-api ^2.2`); verified with Composer 2.11.1. Use the committed `composer.lock`.
- Node.js satisfying **`^20.19.0 || >=22.12.0`**, the installed Vite 7/Laravel Vite plugin engine range, and NPM. Verified with Node 24.22.2 and NPM 11.19.1. Use the committed `package-lock.json`.
- A running local MySQL/MariaDB server at `127.0.0.1:3306`, using InnoDB with foreign keys, CHECK constraints and DECIMAL support. Verification uses MariaDB 10.11.16 through the Laravel `mysql` connection. XAMPP may supply the local server; put its MySQL client on PATH if using the commands below. Database creation requires an account with the appropriate permission.
- The full concurrency suite additionally needs MariaDB's `information_schema.INNODB_TRX` / `INNODB_LOCK_WAITS` and suitable metadata privileges. The observer must be adapted for MySQL 8 servers exposing performance_schema lock metadata instead; see [test portability](docs/advanced-ecommerce.md#database-safety-and-verification).

### 1. Clone and install locked dependencies

Replace the repository URL placeholder with this project's GitHub URL:

```powershell
git clone https://github.com/YOUR_ACCOUNT/YOUR_REPOSITORY.git ecommerce-system
Set-Location ecommerce-system
composer install --no-scripts --no-interaction
npm.cmd ci --ignore-scripts
composer check-platform-reqs
```

NPM installation scripts are disabled; the existing Vite build works with this installation. Keep frontend dependencies: the welcome page, asset build and development tooling reference them. Do not use `composer update` or `npm update` for installation. Do not use Composer's `setup` script: it combines environment/key changes, migrations and dependency installation without the target checks below.

### 2. Configure the development environment

Copy the template only when the destination does not exist:

```powershell
if (-not (Test-Path -LiteralPath .env)) {
    Copy-Item -LiteralPath .env.example -Destination .env
} else {
    Write-Output 'Existing .env preserved; review it manually.'
}
```

In the ignored `.env`, set these local development values and replace credential placeholders with your actual local account details. Do not assume the password is empty. Quote values when required by dotenv syntax, such as values containing spaces or `#`.

```dotenv
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ecommerce-api
DB_USERNAME=your_mysql_username
DB_PASSWORD=your_mysql_password
DB_URL=
```

Generate a key **only for a newly created environment whose APP_KEY is empty**. Preserve an existing application's key. These are local-development examples; do not publish environment files or expose debug output in production.

```powershell
php artisan config:clear
if (Select-String -LiteralPath .env -Pattern '^\s*APP_KEY=\s*$' -Quiet) {
    php artisan key:generate
} else {
    Write-Output 'Existing APP_KEY preserved.'
}
php artisan package:discover
```

### 3. Create and verify the development database

Use phpMyAdmin or connect with a database administrator account; `-p` prompts privately:

```powershell
mysql -h 127.0.0.1 -P 3306 -u YOUR_DB_ADMIN_USER -p
```

Check whether the database already exists. Run CREATE **only if it is absent**; if it exists, reuse it after checking its contents and permissions. Do not drop, replace or reset an existing database.

```sql
SHOW DATABASES;
CREATE DATABASE `ecommerce-api` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Exit the SQL client and verify Laravel's actual selected database using the already-installed Tinker. The output must be `mysql` and `ecommerce-api`; stop and correct configuration if it differs or the connection fails:

```powershell
php artisan tinker --execute="dump(config('database.default'), DB::selectOne('SELECT DATABASE() AS name')->name);"
```

### 4. Inspect and apply ordinary migrations, then add development samples

For a newly created empty development database, inspect the migration files under `database/migrations`, check status and preview SQL before running only pending migrations. For an existing database, review its data and pending SQL first; do not assume a fresh-clone workflow means its database is empty.

```powershell
php artisan migrate:status
php artisan migrate --pretend
php artisan migrate --no-interaction
```

On a fresh database, the first status check may report that the migrations table does not exist. Migration preview can initialize that tracking table even with `--pretend`; run it only after confirming the target. No reset/fresh/refresh/wipe commands are needed.

After migrations succeed, verify the development target again, then run these **local sample seeders in this order**:

```powershell
php artisan tinker --execute="dump(config('database.default'), DB::selectOne('SELECT DATABASE() AS name')->name);"
php artisan db:seed --class=CatalogSampleSeeder --no-interaction
php artisan db:seed --class=DiscountSampleSeeder --no-interaction
```

The catalog creates missing samples: 40 products in four categories, including four inactive products. The discount seeder needs that catalog and creates five promotions with 30-day UTC validity windows. Both preserve existing sample records/edits on rerun; the discount seeder also preserves existing assignments and does not renew expired promotions. See [catalog details](#verification) and [promotion details](docs/discounts.md#sample-discounts-and-http-examples). Do not run the default DatabaseSeeder for sample setup; it creates a user instead.

### 5. Build assets, start the server and use Postman

```powershell
npm.cmd run build
php artisan serve --host=127.0.0.1 --port=8000
```

The build creates ignored `public/build` assets for the welcome page; the REST APIs need no frontend client. For asset development, run `npm.cmd run dev` in a separate terminal. In Postman set `base_url` to `http://127.0.0.1:8000`, add `Accept: application/json`, and try:

```http
GET {{base_url}}/api/categories
GET {{base_url}}/api/products?search=laptop&sort=price_asc&per_page=10
GET {{base_url}}/discount?type=loyalty&original=100
```

Use returned product IDs for `/api/products/{id}/price` and `/api/products/{id}/reviews`; IDs are not portable between installations. For protected endpoints, [register/login](docs/customer-cart.md#registration-login-profile-logout), keep the token in a private Postman variable, and send Bearer authentication. Add JSON `{"product_id":42,"quantity":1}` to POST `/api/cart/items` using an available returned ID. Admin writes require a separately provisioned server-managed admin role; registration cannot grant it. See [role provisioning](docs/customer-cart.md#roles-and-loyalty), [checkout](docs/checkout-orders.md#postman-setup), and [admin/review/wishlist examples](docs/advanced-ecommerce.md#postman-walkthrough). Never commit real tokens or exported Postman secrets.

## Isolated testing and database safety

Development uses **`ecommerce-api`**; every automated database test must use **`ecommerce_api_testing`** at `127.0.0.1:3306` through the `mysql` connection. Create the testing database separately with your database administrator, only if it is absent:

```sql
SHOW DATABASES;
CREATE DATABASE `ecommerce_api_testing` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Copy the safe testing template without overwriting an existing file:

```powershell
if (-not (Test-Path -LiteralPath .env.testing)) {
    Copy-Item -LiteralPath .env.testing.example -Destination .env.testing
} else {
    Write-Output 'Existing .env.testing preserved; review it manually.'
}
```

Edit only your ignored `.env.testing`: keep `APP_ENV=testing`, `DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`, `DB_PORT=3306`, `DB_DATABASE=ecommerce_api_testing`, `DB_URL=`, `CACHE_STORE=array`, `SESSION_DRIVER=array`, `QUEUE_CONNECTION=sync`, and `MAIL_MAILER=array`. Supply your own username/password; the template's root/blank entries are examples, not an instruction to assume an empty password. Prefer a testing-only account with access only to the testing database, plus the metadata access needed by the contention observer. Generate a testing key only if APP_KEY is empty:

```powershell
php artisan config:clear
if (Select-String -LiteralPath .env.testing -Pattern '^\s*APP_KEY=\s*$' -Quiet) {
    php artisan key:generate --env=testing
} else {
    Write-Output 'Existing testing APP_KEY preserved.'
}
php artisan tinker --env=testing --execute="dump(app()->environment(), config('database.default'), DB::selectOne('SELECT DATABASE() AS name')->name);"
```

The verification output must be `testing`, `mysql`, `ecommerce_api_testing`. Stop if it differs. Review test migrations before applying pending ones. Do not substitute the development database or seed development samples into testing; tests create their own fixtures.

```powershell
php artisan migrate:status --env=testing
php artisan migrate --env=testing --pretend
php artisan migrate --env=testing --no-interaction
php vendor/phpunit/phpunit/phpunit --do-not-cache-result
php vendor/bin/pint --test
composer audit --locked
npm.cmd audit --include=dev
```

The same fresh-database migration-table caveat applies to testing. PHPUnit forces the testing environment, local MySQL host/port, database name and empty DB_URL. `Tests\TestCase` rejects cached configuration, URL/read/write overrides, unexpected configuration and a mismatched actual `SELECT DATABASE()` **before fixtures can mutate data**. Most tests roll back fixtures; concurrency/HTTP checks use committed owned fixtures with scoped cleanup. Run checks sharing the test database sequentially. See [concurrency prerequisites and HTTP verification](docs/advanced-ecommerce.md#database-safety-and-verification). Never run destructive migrations or database resets against development or production.

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

Product writes require a valid Sanctum Bearer token and a server-managed admin role in every environment. Guests receive 401 and regular customers receive 403. Product reads remain public. Admins can update inactive products. Phase 5 adds admin category/discount/order management and sales reports under `/api/admin`, plus customer reviews and wishlists; see the [complete endpoint list and examples](docs/advanced-ecommerce.md).

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

Use an existing category ID from `/api/categories`, or create a category through the admin API before creating the product.

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

For CRUD testing, use an authenticated admin Bearer token and POST `/api/products` with Body → raw → JSON and the creation payload above. Use a new unique SKU such as `MANUAL-001`, and a category ID from GET `/api/categories`. Expect 201, save `data.id`, then use GET and PATCH on that ID. PATCH `{"stock_quantity":5}` should return 200. DELETE that same manually created product should return 204, and a subsequent GET should return 404. This changes only the manual test product. See [customer setup and Postman authentication](docs/customer-cart.md) for token and role instructions.

## Automated checks

Configure and verify the isolated testing environment as described above before running these commands. Run checks sequentially:

```powershell
php vendor/phpunit/phpunit/phpunit --do-not-cache-result
php vendor/bin/pint --test
composer audit --locked
npm.cmd audit --include=dev
php artisan route:list --path=api
php artisan route:list --path=discount
```

The latest release-readiness verification passed **199 tests, 1,195 assertions**, the full repository Pint check, and both dependency audits with zero reported advisories/vulnerabilities. Tests cover all five phases, ownership/admin authorization, checkout/cancellation contention, exact reporting and legacy discount successes/errors. Run tests exclusively against the isolated testing database. Audit results are a verified snapshot; rerun audits when installing or releasing.

## Dependency security status

Final security maintenance is complete. The earlier 13 Composer advisories in seven packages recorded during Phase 3 were resolved with the following locked versions; no unrelated package versions were upgraded. PHP `^8.2` compatibility, Laravel 12 and Symfony 7 were preserved:

| Package | Locked version |
| --- | --- |
| laravel/framework | 12.71.0 |
| league/commonmark | 2.10.7 |
| symfony/http-foundation | 7.4.23 |
| symfony/http-kernel | 7.4.23 |
| symfony/mime | 7.4.22 |
| symfony/routing | 7.4.23 |
| symfony/yaml | 7.4.23 |

The committed NPM lockfile resolves the existing frontend ranges to Tailwind / @tailwindcss/vite 4.3.5, Axios 1.21.0, Concurrently 9.2.5, laravel-vite-plugin 2.1.0 and Vite 7.3.7. Composer and NPM audits, including development dependencies, report zero vulnerabilities in the latest verification. No advisory suppression or forced major upgrade was used. Legacy Pint formatting cleanup is complete; the full repository check passes.

## Known limitations and release housekeeping

This is a REST API with simulated payments/refunds, not a real payment integration. There is no real gateway, partial-refund workflow, shipping/tax calculation, password reset or email-verification workflow. Admin/loyalty privileges require trusted internal provisioning, not public privilege-management APIs. Cancellation is allowed only before shipment; hard-deleted inventory references or stock overflow return HTTP 409 for manual resolution without partial changes. See [workflow and reporting definitions](docs/advanced-ecommerce.md), [cart limitations](docs/customer-cart.md#availability-changes-and-errors), and the concurrency portability requirements above.

This project is licensed under the [MIT License](LICENSE), matching the `"license": "MIT"` metadata in `composer.json`. Copyright (c) 2026 Azrulsh. The root LICENSE file contains the standard permission notice, conditions and warranty disclaimer and is included in source archives.

`.gitattributes` excludes `/.github`, `CHANGELOG.md` and `.styleci.yml` from Git-generated source archives; it does not untrack those paths. No CHANGELOG is currently supplied. If one is added later and should ship in release archives, review its export-ignore rule. Documentation, tests, migrations, seeders, both lockfiles and safe environment examples are tracked; secrets, actual environment files, databases, `vendor`, `node_modules` and built `public/build` assets are ignored. A source archive therefore requires dependency installation and an asset build. No v1.0.0 tag or GitHub Release is created by these instructions.
