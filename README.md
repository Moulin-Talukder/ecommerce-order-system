# E-commerce Order Management System

A Laravel 13 order management system with a customer storefront for a Bangladesh-based shop. It uses Asia/Dhaka time, BDT, Cash on Delivery and Steadfast courier, with consent-gated GTM/GA4/Meta tracking.

- **Admin panel:** `/admin` (sign in at `/login`); roles Super Admin, Admin, Manager, Staff.
- **Storefront:** `/`; catalog, cart, checkout and order confirmation.
- **Further documentation:**
  - [DEPLOYMENT.md](DEPLOYMENT.md): server requirements, deployment steps, backups, restore and rollback.
  - [PRODUCTION_READINESS.md](PRODUCTION_READINESS.md): environment checklist, security checklist, smoke tests and owner actions.
  - [tests/PHASE_17_TESTING_REPORT.md](tests/PHASE_17_TESTING_REPORT.md): test coverage report.

## Requirements

| Software | Version |
|---|---|
| PHP | 8.3+ (developed on 8.5), with `bcmath`, `pdo_mysql`, `mbstring`, `gd`, `exif`, `fileinfo`, `intl`, `openssl`, `curl`, `ctype`, `dom`, `xml`, `tokenizer`, `zip` |
| Composer | 2.x |
| MySQL | 8.0.16+ (developed on 8.4); InnoDB, `utf8mb4` |
| Node.js / npm | Node 22+ (developed on 24) |

## Local setup

1. **Get the code and install dependencies**

   ```bash
   git clone https://github.com/Moulin-Talukder/ecommerce-order-system.git
   cd ecommerce-order-system
   composer install
   npm install
   ```

2. **Create the environment file and application key**

   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

3. **Create the two MySQL databases**: one for development and one for the test suite.

   ```sql
   CREATE DATABASE ecommerce_order_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE DATABASE ecommerce_order_system_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

   The MySQL user in `.env` needs access to **both** databases.

4. **Configure the main database** in `.env`:

   ```dotenv
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=ecommerce_order_system
   DB_USERNAME=your_mysql_user
   DB_PASSWORD=your_mysql_password
   ```

   **The test database is fixed:** `phpunit.xml` always uses **`ecommerce_order_system_test`** (with the host, username and password from `.env`), whatever `DB_DATABASE` is set to. As a safety guard, `tests/TestCase.php` refuses to run against a database whose name doesn't end in `_test`.

5. **Run the migrations and create the first Super Admin.** Set these in `.env` first:

   ```dotenv
   ADMIN_NAME="Your Name"
   ADMIN_EMAIL=you@example.com
   ADMIN_PASSWORD=   # at least 12 characters, upper and lower case, and a number
   ```

   ```bash
   php artisan migrate
   php artisan db:seed
   ```

   The seeder creates the Super Admin once. If the account already exists, it never changes its password. Remove `ADMIN_PASSWORD` from `.env` afterwards.

6. **Link public storage**, so product images are served:

   ```bash
   php artisan storage:link
   ```

## Running locally

```bash
composer run dev
```

This runs `php artisan dev`, which starts the local services, including the Vite dev server. Then open the URL it prints (`APP_URL`, `http://localhost:8000` by default).

Optional local settings (see `.env.example` for each one):
- `GTM_CONTAINER_ID`: leave empty locally. Tracking and the consent banner stay off.
- `STEADFAST_*`: courier credentials. Without them, courier booking reports that it isn't configured.

## Tests

```bash
php artisan test --compact
```

`composer test` does the same after clearing cached configuration. Tests use the `ecommerce_order_system_test` database (step 4) and never contact Google, Meta or Steadfast. If the guard reports a non-test database, run `php artisan config:clear`.

Formatting: `vendor/bin/pint --dirty --format agent`.

## Production build

```bash
npm run build
```

This builds the CSS (and fonts) into `public/build`. For a full production deployment, see [DEPLOYMENT.md](DEPLOYMENT.md).
