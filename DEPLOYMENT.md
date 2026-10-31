# Deployment Guide

How to deploy, back up, restore and roll back the e-commerce order management system on a typical Linux server.

**Who it's for:** whoever runs the server. It assumes basic Linux, MySQL and web-server administration.

**What's specific to this project:** everything below that names a version, setting or command comes from the repository. Items marked **[hosting-specific]** depend on your provider (VPS, managed platform or shared hosting) and must be adapted. The repository doesn't fix a web server, process manager or host. `public/.htaccess` is Laravel's default and does not mean Apache is required.

See also: [PRODUCTION_READINESS.md](PRODUCTION_READINESS.md) for the environment checklist, security checklist, smoke tests and owner actions.

---

## 1. Server prerequisites

| Component | Requirement (from the repository) |
|---|---|
| PHP | 8.3+ (`composer.json`); developed and tested on **8.5** |
| PHP extensions | `bcmath` (money), `pdo_mysql`, `mbstring`, `gd` (image re-encoding), `exif`, `fileinfo`, `intl`, `openssl`, `curl`, `ctype`, `dom`, `xml`, `tokenizer`, `zip` |
| Database | **MySQL 8.0.16+** (CHECK constraints are used), InnoDB, `utf8mb4` / `utf8mb4_unicode_ci`, strict mode (set by Laravel). Developed and tested on **8.4** |
| Composer | 2.x |
| Node / npm | Only to **build** assets: Node 22+ (developed on 24) and npm. Not needed at runtime if you build elsewhere |
| Web server | Any server that runs PHP-FPM with the document root set to `public/` **[hosting-specific]** |
| HTTPS | A TLS certificate for the domain (required: secure cookies, consent cookie, HSTS) **[hosting-specific]** |
| Cron / process manager | **Not required today**: no scheduled tasks and no queued jobs (see §14 and §15) |

`php.ini` for production: `expose_php = Off`, `display_errors = Off`, `log_errors = On`, `upload_max_filesize ≥ 2M` and `post_max_size ≥ 9M`. Product images are limited to 2 MB each (`MEDIA_IMAGE_MAX_KB`), 4 per upload (`config/media.php`).

## 2. Web server rules (any server)

- The document root **must** be `public/`. Never serve the project root, because `.env`, `storage/`, `vendor/` and the source code must not be reachable.
- Send every request that isn't for an existing file to `public/index.php`.
- Deny dotfiles (`.env`, `.git`, `.htaccess`) if your server could ever serve the project root by mistake.
- Serve `public/build/*` (Vite assets) and `public/storage/*` (product images) as static files.
- Point the health check at `GET /up` (Laravel's built-in route).
- Security headers (nosniff, frame protection, Referrer- and Permissions-Policy, optional HSTS) are **set by the application**. Don't add conflicting copies at the web server or CDN.

## 3. Domain, HTTPS and proxies

1. Point the domain at the server **[hosting-specific]** and install a TLS certificate.
2. Redirect HTTP to HTTPS at the web server or load balancer.
3. **If a load balancer or CDN terminates HTTPS**, set `TRUSTED_PROXIES` to its IPs or CIDRs (or `*` only when the app can be reached *solely* through it). Otherwise Laravel sees `http`.
4. Set `SESSION_SECURE_COOKIE=true`.
5. **Only after HTTPS works for the whole domain and its subdomains**, set `SECURITY_HSTS_MAX_AGE=31536000`. Browsers remember HSTS, so it's hard to undo.

## 4. Environment variables

Copy `.env.example` to `.env` **on the server only** and fill it in using the checklist in [PRODUCTION_READINESS.md §2](PRODUCTION_READINESS.md#2-production-environment-checklist). Never commit `.env`; it's already in `.gitignore`.

- Run `php artisan key:generate` **once**, on first install. Never regenerate it on an existing install: that logs everyone out and makes encrypted cookies unreadable.
- Restrict the file: `chmod 640 .env`, readable by the deploy user and the PHP-FPM group only.
- After `ADMIN_PASSWORD` has been used by the seeder (§11), remove it from `.env`.

## 5. Code deployment (release directories, recommended for rollback)

A layout that makes rollback a symlink switch:

```
/var/www/shop/
├── releases/2026-11-01_1200/   # one directory per release (git checkout or archive)
├── shared/.env                  # symlinked into every release
├── shared/storage/              # symlinked into every release (logs, uploads, sessions files)
└── current -> releases/2026-11-01_1200
```

The web server document root is `/var/www/shop/current/public` **[hosting-specific paths]**.

Simpler single-directory deployments (`git pull` in place) also work, but rolling back then means checking out the previous commit (§17).

## 6. Deployment steps (in this order)

Run as the deploy user inside the **new release** directory:

```bash
# 0. Back up the database first (see §16).
# 1. Code
git fetch --tags && git checkout <release-tag-or-commit>     # or unpack a release archive

# 2. Shared files
ln -sfn /var/www/shop/shared/.env .env
rm -rf storage && ln -sfn /var/www/shop/shared/storage storage

# 3. PHP dependencies (no dev packages, optimized autoloader)
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

# 4. Frontend assets (here, or build in CI and upload public/build)
npm ci && npm run build

# 5. Database (only after the backup in step 0)
php artisan migrate --force

# 6. Storage link (first deploy, or if public/storage is missing)
php artisan storage:link

# 7. Caches. Rebuild on every deploy; never cache in development
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# 8. Switch traffic, then reload PHP-FPM so OPcache picks up the new code
ln -sfn /var/www/shop/releases/<release> /var/www/shop/current
sudo systemctl reload php8.x-fpm                          # [hosting-specific service name]

# 9. Smoke tests (PRODUCTION_READINESS.md §5)
```

Notes:
- All four caches were verified to build locally for this codebase.
- `config:cache` reads `.env` once. After any `.env` change, run `php artisan config:cache` again.
- For longer migrations, put the site in maintenance mode first with `php artisan down --retry=60`, then run `php artisan up` afterwards.

## 7. First install only

```bash
php artisan key:generate                   # once
php artisan migrate --force
php artisan db:seed --class=SuperAdminSeeder --force   # needs ADMIN_NAME / ADMIN_EMAIL / ADMIN_PASSWORD
# then remove ADMIN_PASSWORD from .env and run: php artisan config:cache
```

The seeder refuses weak passwords: at least 12 characters, with upper and lower case and a number. If the account already exists, it never overwrites the password.

## 8. File permissions

- The code is owned by the deploy user and read-only to PHP-FPM.
- PHP-FPM must be able to write to `storage/` and `bootstrap/cache/` only.

```bash
chown -R deploy:www-data storage bootstrap/cache      # [hosting-specific user/group]
find storage bootstrap/cache -type d -exec chmod 775 {} \;
find storage bootstrap/cache -type f -exec chmod 664 {} \;
```

- Product images live in `storage/app/public/` (served through `public/storage`) and **must be backed up** (§16).

## 9. Queue workers

**Not needed today.** No job or listener is queued: `OrderPlaced` and `OrderStatusChanged` have no listeners. `QUEUE_CONNECTION=database` is fine as is.

If queued work is added later, run a worker under your process manager **[hosting-specific]**, for example Supervisor:

```ini
[program:shop-worker]
command=php /var/www/shop/current/artisan queue:work database --sleep=3 --tries=3 --max-time=3600
user=deploy
numprocs=1
autostart=true
autorestart=true
stopwaitsecs=3600
redirect_stderr=true
stdout_logfile=/var/www/shop/shared/storage/logs/worker.log
```

Then run `php artisan queue:restart` after every deploy. Failed jobs go to the `failed_jobs` table: `php artisan queue:failed` lists them, and `queue:retry <id>` retries one.

## 10. Scheduler

**No scheduled tasks exist today**: `routes/console.php` contains only the default `inspire` command. Courier status updates arrive by webhook or the admin "sync" button. If scheduled tasks are added later, add one cron entry **[hosting-specific]**:

```cron
* * * * * cd /var/www/shop/current && php artisan schedule:run >> /dev/null 2>&1
```

## 11. Cache, sessions and multiple servers

- Sessions (`SESSION_DRIVER=database`) and cache (`CACHE_STORE=database`) live in MySQL. This is **already shared** across several app servers, which login and checkout throttling rely on.
- Don't switch to `file` or `array` cache on multi-server setups: each server would keep its own throttle counters.
- Use Redis only if you choose to; that needs the PHP extension and the `REDIS_*` settings.
- **Maintenance mode** uses the `file` driver (per server). On several servers, set `APP_MAINTENANCE_DRIVER=cache` so `php artisan down` applies everywhere.

## 12. Logging and log rotation

- For production, set `LOG_STACK=daily` (rotated files, `LOG_DAILY_DAYS` days, default 14) and `LOG_LEVEL=warning` (or `error`).
  - With `LOG_STACK=single`, use logrotate on `storage/logs/laravel.log` **[hosting-specific]**.
- What the app logs:
  - courier failures, **without** credentials (tested);
  - rejected webhook tokens, with the IP;
  - checkout honeypot hits, with the IP.

  Passwords, session tokens and card data are never logged; there's no card data in the system at all.
- Also watch the PHP-FPM error log and the web-server error log **[hosting-specific locations]**.

## 13. Monitoring

| What | How |
|---|---|
| Uptime | External check of `GET /up` (HTTP 200) and the homepage |
| HTTP 5xx | Web-server access log, and `ERROR` entries in `storage/logs/` |
| Database | MySQL availability and connection count (`max_connections` ≥ PHP-FPM workers × app servers + headroom) |
| Disk | Free space on the volume holding MySQL data, `storage/logs`, `storage/app/public` and backups |
| Courier | `Steadfast` warnings and errors in the logs; orders stuck as "booking pending" in admin |
| Webhook | Repeated "Steadfast webhook rejected" entries (wrong token or probing) |
| Security | Spikes in HTTP 429 (login throttling) or 403 responses |
| Failed jobs | Not applicable today (no queued jobs) |

## 14. Investigating a production error

1. Find the time and URL from the user or the access log.
2. Look in `storage/logs/laravel*.log` around that time. With `APP_DEBUG=false`, users only see a generic error page.
3. Check the PHP-FPM and web-server error logs for fatal errors or timeouts.
4. Reproduce on staging with the same release. **Never** turn on `APP_DEBUG` in production.

## 15. Restarting safely

- **After changing `.env`:** run `php artisan config:cache`, then reload PHP-FPM.
- **After a deploy:** reload PHP-FPM (clears OPcache). Run `php artisan queue:restart` only if workers exist.
- **Never** run `php artisan optimize:clear` or `cache:clear` casually in production: `cache:clear` also wipes throttle counters and the once-per-order purchase-tracking claims.

## 16. Backups

**What to back up:**

| What | Where | Frequency (recommended) | Retention (recommended) |
|---|---|---|---|
| MySQL database | `DB_DATABASE` | Daily full + **before every deploy/migration** | 7 daily, 4 weekly, 6 monthly |
| Product images | `storage/app/public/` | Daily (incremental, e.g. rsync) | Same as the database |
| `.env` | server only | On every change, **encrypted**, stored separately | Latest + previous |

Keep at least one copy **off the server**, and test a restore at least monthly. The orders table holds customer personal data, so encrypt backups at rest and limit access to them.

**Database backup** (credentials in a protected option file, never on the command line):

```bash
# ~/.my.cnf for the backup user (chmod 600):
# [client]
# user=backup_user
# password=********
# host=127.0.0.1

mysqldump --defaults-file=~/.my.cnf --single-transaction --quick --routines --triggers \
  --default-character-set=utf8mb4 ecommerce_order_system \
  | gzip > /backups/shop-$(date +%Y%m%d-%H%M%S).sql.gz
```

`--single-transaction` takes a consistent InnoDB snapshot without locking the shop.

**Restore**: rehearse on staging first, and restore into a **new** database, then switch:

```bash
mysql --defaults-file=~/.my.cnf -e "CREATE DATABASE shop_restore CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
gunzip < /backups/shop-YYYYMMDD-HHMMSS.sql.gz | mysql --defaults-file=~/.my.cnf shop_restore
# verify (row counts, latest orders), then point DB_DATABASE at shop_restore,
# run php artisan config:cache and reload PHP-FPM
```

**Images:** `rsync -a /backups/images/latest/ /var/www/shop/shared/storage/app/public/`

## 17. Rolling back a release

1. **Code only** (no new migrations in the release): point `current` back to the previous release directory, run `php artisan config:cache`, and reload PHP-FPM. In single-directory setups, `git checkout <previous-tag>`, then repeat §6 steps 3, 4 and 7.
2. **The release included migrations:**
   - Prefer restoring the **pre-deploy backup** (§16), then roll the code back as above. Orders placed after the backup would be lost, so put the site in maintenance mode first and note them down.
   - `php artisan migrate:rollback` is only safe for migrations known to be reversible without data loss. Test it on staging first.
3. **A failed migration halfway through:** MySQL can't roll back DDL. Put the site in maintenance mode, restore the pre-deploy backup into a new database (§16), switch to it, and deploy the previous release.

## 18. Smoke tests after every deploy

Run the checklist in [PRODUCTION_READINESS.md §5](PRODUCTION_READINESS.md#5-production-smoke-test-checklist).
