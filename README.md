# PawCariño

PawCariño is a PHP and MySQL web application combining three animal-welfare business units:

- **Pet Store** — product catalog, shopping carts, purchases, and pickup/delivery orders.
- **Veterinary Clinic** — veterinary services, appointments, and clinical workflows.
- **Grooming Services** — grooming services and appointment workflows.

The application uses PHP 8.3 and MySQL 8.4 without a PHP framework. The repository and some technical identifiers may still use the former placeholder name, VETGRPET.

## Development environment (Windows / portable tools)

The setup below reflects the project's portable Windows development environment. Adjust paths and ports if your installation differs.

Expected layout:

    D:\VETGRPETproj
    ├── tools
    │   ├── php83
    │   │   └── php.exe
    │   ├── mysql84
    │   │   └── mysql-8.4.13-winx64
    │   └── composer
    │       └── composer.phar
    ├── app
    ├── config
    ├── database
    ├── public
    ├── tests
    ├── composer.json
    ├── composer.lock
    ├── .env
    └── .env.example

The `tools/`, `vendor/`, and real `.env` are local development assets and must not be committed. Keep `composer.json` and `composer.lock` under version control.

### 1. Prepare PHP

Install/extract PHP 8.3 x64 (the current development setup uses PHP 8.3.36 Non Thread Safe) into:

    D:\VETGRPETproj\tools\php83

Create `php.ini` from `php.ini-development`, enable the extensions required by the application, and set `extension_dir` to the PHP extension directory. At minimum, verify the extensions used by the application and database connection, including OpenSSL, cURL, mbstring, and PDO MySQL.

From the project root, verify PHP:

    & ".\tools\php83\php.exe" -v
    & ".\tools\php83\php.exe" --ini
    & ".\tools\php83\php.exe" -m

### 2. Prepare MySQL

The current development setup uses MySQL 8.4.13 x64 under:

    D:\VETGRPETproj\tools\mysql84\mysql-8.4.13-winx64

Configure a local MySQL instance with its own data directory and a loopback-only bind address (for example, `127.0.0.1`). Initialize the data directory once, securely set the root password, and create the project database and application account.

Example database creation (choose your own strong local password):

    CREATE DATABASE vetgrpet_db
      CHARACTER SET utf8mb4
      COLLATE utf8mb4_0900_ai_ci;

    CREATE USER 'vetgrpet_app'@'127.0.0.1'
      IDENTIFIED BY 'REPLACE_WITH_A_LOCAL_PASSWORD';

    GRANT ALL PRIVILEGES ON vetgrpet_db.*
      TO 'vetgrpet_app'@'127.0.0.1';

The database account host must match the connection host in `.env`. For example, if `DB_HOST=127.0.0.1`, create the account for `'127.0.0.1'`. Do not reuse the example password.

Keep the MySQL server running in its own terminal while using the application. Do not point destructive tests at a database containing data you need to preserve.

### 3. Configure the application

If the local environment file does not exist, create it from the example:

    Copy-Item ".env.example" ".env"
    notepad ".env"

Set the database values to match your local MySQL setup:

    DB_HOST=127.0.0.1
    DB_PORT=3306
    DB_DATABASE=vetgrpet_db
    DB_USERNAME=vetgrpet_app
    DB_PASSWORD=REPLACE_WITH_YOUR_LOCAL_PASSWORD

For customer email OTP, configure the values documented in `.env.example`:

- `OTP_HMAC_SECRET` — use a randomly generated secret of at least 32 bytes. Do not use the example placeholder.
- `MAIL_DRIVER=null` — safe local/test mode; messages are not sent.
- `MAIL_DRIVER=smtp` — enables actual email delivery and requires valid SMTP host, port, username, password, encryption, and sender settings.

Use the real SMTP credentials only in the private `.env` file. Never commit it or paste its secrets into issue reports, agent prompts, or handoff documents. Keep `.env.example` limited to placeholders.

### 4. Set up local Composer

The project uses Composer for PHP dependencies. A project-local Composer PHAR avoids requiring a system-wide Composer installation. The PHAR belongs in the ignored `tools/` directory.

From the project root, create the folder and download the official installer:

    New-Item -ItemType Directory -Force -Path ".\tools\composer"
    Invoke-WebRequest "https://getcomposer.org/installer" -OutFile ".\tools\composer\composer-setup.php"

Verify the installer against the official SHA-384 signature before running it:

    $expected = (Invoke-RestMethod "https://composer.github.io/installer.sig").Trim()
    $actual = & ".\tools\php83\php.exe" -r "echo hash_file('sha384', 'tools/composer/composer-setup.php');"

    if ($expected -ne $actual) {
        throw "Composer installer checksum verification failed."
    }

Install Composer into the project-local tools folder and remove the installer:

    & ".\tools\php83\php.exe" ".\tools\composer\composer-setup.php" --install-dir="tools\composer" --filename="composer.phar"
    Remove-Item ".\tools\composer\composer-setup.php"

Verify the installation:

    & ".\tools\php83\php.exe" ".\tools\composer\composer.phar" --version
    & ".\tools\php83\php.exe" ".\tools\composer\composer.phar" diagnose

The project tracks `composer.json` and `composer.lock`. The manifest requires `phpmailer/phpmailer: ^7.2`, and the lock file pins **PHPMailer 7.2.0**.

### 5. Install PHPMailer 7.2.0 and project dependencies

From the project root, run:

    & ".\tools\php83\php.exe" ".\tools\composer\composer.phar" install

This installs the exact dependency versions recorded in `composer.lock`, including PHPMailer 7.2.0, into the ignored `vendor/` directory. On a fresh clone or another computer, this is the normal command to restore dependencies.

Verify the installed PHPMailer version and autoloader:

    & ".\tools\php83\php.exe" ".\tools\composer\composer.phar" show phpmailer/phpmailer
    & ".\tools\php83\php.exe" -r "require __DIR__ . '/vendor/autoload.php'; var_dump(class_exists('PHPMailer\\PHPMailer\\PHPMailer'));"

The class check should print `bool(true)`. The package version should show `7.2.0`.

**Important:** Do not run `composer require phpmailer/phpmailer` again on this repository. The dependency is already declared and locked. Use `install` to reproduce the committed dependency versions. When intentionally changing dependencies in the future, update `composer.json` and `composer.lock` together and commit both files.

### 6. Database migrations and seed data

Start the intended local MySQL server and confirm that `.env` points to the correct database before running database scripts.

    & ".\tools\php83\php.exe" ".\database\migrate.php"
    & ".\tools\php83\php.exe" ".\database\seed.php"

Follow any project-specific prerequisite steps documented by the current migration and seed scripts. Inspect migration requirements before running them against a database containing data you need to preserve. The migration runner tracks applied migrations; do not manually mark migrations as applied during normal setup.

### 7. Start the development server

From the project root:

    & ".\tools\php83\php.exe" -S 127.0.0.1:8000 -t public

Open http://127.0.0.1:8000.

If your local setup requires the front controller as a router script, use:

    & ".\tools\php83\php.exe" -S 127.0.0.1:8000 -t public "public\index.php"

## Application structure

| Path | Purpose |
|---|---|
| public/index.php | Front controller and route registration |
| public/assets/ | Frontend CSS, JavaScript, and static assets |
| app/Core/ | Core infrastructure, sessions, CSRF, routing, and configuration |
| app/Controllers/ | HTTP controllers |
| app/Models/ | Data access and domain models |
| app/Services/ | Application services, including mail and customer registration |
| app/Views/ | PHP view templates |
| config/ | Application configuration |
| database/migrations/ | Versioned SQL migrations |
| database/migrations/down/ | Corresponding manual rollback SQL |
| database/seeders/ | Reference and sample seed data |
| database/migrate.php | Migration runner |
| database/seed.php | Seed runner |
| tests/ | Acceptance, regression, and service test scripts |
| CUSTOMER_EMAIL_OTP_HANDOFF.md | Customer email OTP architecture and implementation handoff |
| implementation_plan.md | Repository findings and implementation plan |

## Customer email OTP

Customer registration uses a temporary pending-registration record. A permanent `user_account` and `customer` record must not be created until the customer successfully verifies ownership of the email address and completes the password step.

Key requirements include:

- Six-digit numeric OTP, preserving leading zeroes.
- Five-minute OTP expiry, five attempts per code, and a 60-second resend cooldown.
- HMAC-SHA256 OTP digest using a server-side secret; plaintext OTPs are not stored.
- Database-backed send limits and session-bound pending registration.
- Concurrency protection for rate limits and verification.
- A `null` mail driver for tests and an authenticated SMTP driver for real delivery.
- Existing customer login and employee/administrator invitation behavior remain separate and compatible.
- The existing GCash/Maya OTP service and its `otp_verification` table remain separate.

**Implementation status:** The customer signup UI refactor, pending-registration migration, environment configuration, mail service, and pending-registration service are present in the repository. The related test scripts are under `tests/`. Review the OTP handoff document for detailed requirements, operational considerations, and remaining follow-up items.

## Tests

The repository contains HTTP/database acceptance and administrative regression suites, plus focused customer registration and configuration tests under `tests/`.

Review each test script before running it: existing suites may use the database configured in `.env` and may create or modify records. Run tests only against a dedicated disposable test database or an isolated test harness. Never run destructive tests against production or a database containing data you need to preserve.

Example (only after confirming the test environment is isolated):

    & ".\tools\php83\php.exe" ".\tests\acceptance.php"

## Security and repository hygiene

- Never commit `.env`, database data directories, local binaries, Composer's local PHAR, or `vendor/`.
- Keep `composer.json` and `composer.lock` tracked so dependency installation is reproducible.
- Use unique local database credentials; do not use sample passwords in a real environment.
- Bind development MySQL to loopback unless there is a deliberate, secured reason not to.
- Do not expose OTPs, password hashes, SMTP credentials, session tokens, or HMAC secrets in logs, screenshots, commits, or handoff documents.
- Back up the project before schema changes, and inspect migration and rollback SQL before applying it.
