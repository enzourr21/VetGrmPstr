# VETGRPET — Customer, Grooming and Shop module (first release)

PHP 8.3 + MySQL 8.4, no framework. Scope: customer accounts, pets, **Grooming Services** appointment requests with receptionist management, product catalog, per-business persistent carts, pickup/delivery orders, public discovery pages (**Products**, **Services**, **Staffs**, **Customer Reviews**), and progressive single-page authentication.

## Setup (Windows, bundled tools)

All commands from `D:\VETGRPETproj`. `.env` already exists and is never modified by this project (see `.env.example`).

```powershell
# 1. Start MySQL (bundled). Leave this window open.
tools\mysql84\mysql-8.4.3-winx64\bin\mysqld.exe --defaults-file=tools\mysql84\my.ini --console

# 2. Apply versioned migrations (idempotent; tracked in table schema_migrations)
tools\php83\php.exe database\migrate.php

# 3. Seed reference + sample data (idempotent; never resets stock)
tools\php83\php.exe database\seed.php

# 4. Run the app
tools\php83\php.exe -S 127.0.0.1:8080 -t public public\index.php
#   open http://127.0.0.1:8080
```

## Public & Authenticated Routes

* **Public Navigation:**
  * `/` — Homepage with scroll-driven pet & botanical side illustrations
  * `/products` (and `/shop`) — Product catalog across business units
  * `/services` — Veterinary Clinic and Grooming Services menus with descriptions and pricing
  * `/staffs` — Public directory of Business Managers and clinical/grooming staff (private fields excluded)
  * `/reviews` — Persistent customer reviews (`POST /reviews` for signed-in customers; no fake reviews seeded)
* **Customer Authentication:**
  * `/login` — Single-page email-first sign-in (Continue button validates email before revealing password)
  * `/register` — 4-step progressive registration (1. Account Email → 2. Full Name → 3. Additional Details → 4. Set Password with live requirements & confirmation check)
* **Receptionist (development only, hidden from public navigation):**
  * Access directly at `/staff/login`. Create a test receptionist account with:
    ```powershell
    $env:RECEPTIONIST_PASSWORD = "choose-a-long-password"
    tools\php83\php.exe scripts\create_receptionist.php staff@example.test Rita Receptionist
    ```

## Layout

| Path | Purpose |
|---|---|
| `public/index.php` | Front controller and routes |
| `public/assets/css/app.css` | Design system, typography, translucent chrome, responsive grids, scenic rails |
| `public/assets/js/app.js` | Progressive form step controller, password visibility & match checks, scroll scene observer |
| `app/Core/Core.php` | Session, CSRF, Router, base Controller (auth + permission checks) |
| `app/Controllers`, `app/Models`, `app/Views` | Application code |
| `database/migrations/*.sql` | Versioned SQL migrations (`001`–`003`) |
| `database/seeders/*.sql` | Roles, permissions, business units, branches, statuses, **[SAMPLE]** services/products/staff |
| `database/migrate.php`, `database/seed.php` | Runners |
| `tests/acceptance.php` → `tests/TEST_REPORT.md` | 17 acceptance scenarios over real HTTP + DB |

## Run the tests

With MySQL and the dev server running:

```powershell
tools\php83\php.exe tests\acceptance.php
```
