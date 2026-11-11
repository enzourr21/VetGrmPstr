# VETGRPET

VETGRPET is a PHP and MySQL web application combining three business units:

- **Pet Store** — product catalog, shopping carts, purchases, and pickup/delivery orders.
- **Veterinary Clinic** — veterinary services and appointment workflows.
- **Grooming Services** — grooming services and appointment workflows.

The application uses PHP 8.3 and MySQL 8.4 without a PHP framework.

> **Development status:** Customer email OTP verification is planned and architecturally documented, but it has not yet been implemented. See [CUSTOMER_EMAIL_OTP_HANDOFF.md](CUSTOMER_EMAIL_OTP_HANDOFF.md) and [implementation_plan.md](implementation_plan.md) for the approved design and checkpoint status.

## Development environment (Windows / portable tools)

The project is intended to run from D:\VETGRPETproj with portable development tools under tools\. The setup below is for local development; adjust paths and ports if your installation differs.

Expected tool layout:

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
    ├── .env
    └── .env.example

The tools/, vendor/, and .env paths are local development assets and must not be committed. Keep composer.json and composer.lock under version control when they are added.

### 1. Prepare PHP

Install/extract PHP 8.3 (the development setup currently targets PHP 8.3.36 Non Thread Safe x64) into:

    D:\VETGRPETproj\tools\php83

Create php.ini from php.ini-development, enable the extensions required by the project, and set extension_dir to the absolute PHP ext directory. At minimum, verify the extensions used by the application and database connection, including openssl, curl, mbstring, and pdo_mysql.

Verify PHP from the project root:

    & ".\tools\php83\php.exe" -v
    & ".\tools\php83\php.exe" --ini
    & ".\tools\php83\php.exe" -m

### 2. Prepare MySQL

Install/extract MySQL 8.4.13 x64 under:

    D:\VETGRPETproj\tools\mysql84\mysql-8.4.13-winx64

Configure a local MySQL instance with its own data directory and a loopback-only bind address (for example, 127.0.0.1). Initialize the data directory once, securely set the root password, and create the project database and application account.

Example database creation (choose your own strong local passwords):

    CREATE DATABASE vetgrpet_db
      CHARACTER SET utf8mb4
      COLLATE utf8mb4_0900_ai_ci;

    CREATE USER 'vetgrpet_app'@'127.0.0.1'
      IDENTIFIED BY 'REPLACE_WITH_A_LOCAL_PASSWORD';

    GRANT ALL PRIVILEGES ON vetgrpet_db.*
      TO 'vetgrpet_app'@'127.0.0.1';

Use a database account whose host matches the connection host in .env. For example, if DB_HOST=127.0.0.1, create the account for '127.0.0.1'. Do not reuse the example password.

Keep the MySQL server running in its own terminal while using the application. Do not point destructive tests at a database containing data you need to preserve.

### 3. Configure the application

Create the local environment file if it does not already exist:

    Copy-Item ".env.example" ".env"
    notepad ".env"

Set the database values to match your local MySQL setup:

    DB_HOST=127.0.0.1
    DB_PORT=3306
    DB_DATABASE=vetgrpet_db
    DB_USERNAME=vetgrpet_app
    DB_PASSWORD=REPLACE_WITH_YOUR_LOCAL_PASSWORD

The real .env is machine-specific and must remain private. Never commit it or paste its secrets into issue reports, agent prompts, or handoff documents. Keep .env.example limited to placeholder values.

### 4. Install Composer locally

Composer is used for PHP dependencies. On public/shared computers, a project-local PHAR avoids requiring a system-wide Composer installation.

Download the official Composer installer from https://getcomposer.org/download/, verify its published SHA-384 signature/checksum using the official instructions, and install the PHAR with the project's PHP executable. Keep the PHAR under the ignored tools/ directory, for example:

    tools\composer\composer.phar

Verify it:

    & ".\tools\php83\php.exe" ".\tools\composer\composer.phar" --version
    & ".\tools\php83\php.exe" ".\tools\composer\composer.phar" diagnose

When composer.json and composer.lock are present, install the locked dependencies from the project root:

    & ".\tools\php83\php.exe" ".\tools\composer\composer.phar" install

Do not run composer require independently when following a planned dependency change; update the dependency manifest and lock file together. The customer email OTP work plans to use PHPMailer, but Composer/PHPMailer have not yet been implemented in the repository.

### 5. Database migrations and seed data

Start the local MySQL server, confirm that .env points to the intended local database, then run the repository's migration and seed scripts from the project root:

    & ".\tools\php83\php.exe" ".\database\migrate.php"
    & ".\tools\php83\php.exe" ".\database\seed.php"

Follow any project-specific prerequisite steps documented by the current migration/seed scripts. Inspect seed and migration requirements before running them on a database that contains data you need to keep. The migration runner tracks applied migrations; do not manually mark migrations as applied.

### 6. Start the development server

From the project root:

    & ".\tools\php83\php.exe" -S 127.0.0.1:8000 -t public

Open http://127.0.0.1:8000.

If the application requires the front controller to be supplied as a router script in your local setup, use:

    & ".\tools\php83\php.exe" -S 127.0.0.1:8000 -t public "public\index.php"

## Application structure

| Path | Purpose |
|---|---|
| public/index.php | Front controller and route registration |
| public/assets/ | Frontend CSS, JavaScript, and static assets |
| app/Core/ | Core application infrastructure, sessions, CSRF, routing, and controllers |
| app/Controllers/ | HTTP controllers |
| app/Models/ | Data access and application services |
| app/Views/ | PHP view templates |
| config/ | Application configuration |
| database/migrations/ | Versioned SQL migrations |
| database/seeders/ | Reference and sample seed data |
| database/migrate.php | Migration runner |
| database/seed.php | Seed runner |
| tests/ | Acceptance and regression test scripts |
| CUSTOMER_EMAIL_OTP_HANDOFF.md | Approved customer email OTP architecture and implementation handoff |
| implementation_plan.md | Repository findings and implementation plan |

## Current customer email OTP plan

The approved design uses a temporary pending-registration record. A permanent user_account and customer record must not be created until the customer successfully verifies ownership of the email address.

Key approved requirements:

- Six-digit numeric OTP, preserving leading zeroes.
- Five-minute OTP expiry, five attempts per code, and a 60-second resend cooldown.
- HMAC-SHA256 OTP digest using a server-side secret; never store plaintext OTPs.
- Database-backed send and failure limits, session-bound pending registration, and concurrency protection.
- A null mail driver for automated tests and an authenticated SMTP driver for real delivery.
- Existing customer login and employee/administrator invitation behavior must remain compatible.
- The existing GCash/Maya OTP service and its otp_verification table must remain separate.
- The actual PDO connection timezone must be explicitly inspected and verified before implementing timestamp calculations.

**Implementation status:** Checkpoint 1 (repository review and architecture) is complete. Checkpoint 2 (migration, Composer/PHPMailer, mail service, configuration, and test-isolation foundation) has not yet been implemented locally. Checkpoints 3–6 remain pending. Refer to the handoff document for the complete specifications and exact next actions.

## Tests

The repository contains HTTP/database acceptance and administrative regression suites under tests/. Review each test script before running it: the existing suites may use the database configured in .env and may create or modify records.

Run tests only against a dedicated disposable test database or the isolated test harness configured for the project. Never run destructive tests against production or a database containing data you need to preserve.

Example (after confirming the test environment is isolated):

    & ".\tools\php83\php.exe" ".\tests\acceptance.php"

## Security and repository hygiene

- Never commit .env, database data directories, local binaries, Composer's local PHAR, or vendor/.
- Keep composer.json and composer.lock tracked.
- Use unique local database credentials; do not use sample passwords in a real environment.
- Bind development MySQL to loopback unless there is a deliberate, secured reason not to.
- Do not expose OTPs, password hashes, SMTP credentials, session tokens, or HMAC secrets in logs, screenshots, commits, or handoff documents.
- Back up the project before schema changes, and inspect migration and rollback SQL before applying it.
