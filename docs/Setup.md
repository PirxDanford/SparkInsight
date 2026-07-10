# SparkInsight Local Setup

> Note: The `docs/` folder is reserved for public-facing setup and guidance. Internal RFC/Roadmap summaries belong in `rfc/`.

## Prerequisites

- PHP 8.1 or newer (8.5 recommended)
- Composer installed globally or via `composer.phar`
- A browser for OAuth redirects

## Install

1. Copy the example environment file:
   ```bash
   cp .env.example .env
   ```
2. Fill in the OAuth credentials in `.env`.
   - `OAUTH_GITHUB_CLIENT_ID`
   - `OAUTH_GITHUB_CLIENT_SECRET`
   - `OAUTH_GOOGLE_CLIENT_ID`
   - `OAUTH_GOOGLE_CLIENT_SECRET`
   - `APP_URL` should match the local callback URL host (default: `http://localhost:8000`)
3. Install dependencies:
   ```bash
   composer install
   ```

### Install without Composer on the server (FTP-only hosting)

If your server does not provide Composer and you can only deploy with FTP:

1. Prepare a complete project copy locally, including `vendor/`.
   - Use a release package that already contains dependencies, or
   - Run `composer install` locally first, then upload that full directory.
   - Keep `composer.lock` in the uploaded package to preserve exact dependency versions.
2. Upload the full project via FTP.
3. Point the host document root to `public/`.
4. Create or upload `.env` on the server and set:
   - `APP_URL` to your public base URL
   - OAuth credentials for enabled providers

Optional diagnosis page for target environment:

1. Add `DIAG_ACCESS_TOKEN=<temporary-random-token>` to `.env`.
2. Open `/install_diagnose.php?token=<temporary-random-token>` in your browser.
3. Confirm the checks are `OK` for PHP version/extensions, `.env`, `vendor/`, and lockfile parsing.
4. Remove `public/install_diagnose.php` or clear `DIAG_ACCESS_TOKEN` when done.

For updates, repeat the same process: prepare locally, then upload via FTP.

To keep releases reproducible across different target hosts, run the prep helper in `deployment/production-prep/` before uploading.

## Run locally

Start the built-in PHP server from the project root:

```bash
composer start
```

This command is intentionally long-running. Stop it with `Ctrl+C`.

If you prefer to run the server manually (without Composer), use:

```bash
php -S localhost:8000 -t public public/index.php
```

Open the app at:

```text
http://localhost:8000
```

## Invitation-based Authentication

SparkInsight uses an invitation system to control access. Users must have a valid invitation code before they can sign in via OAuth.

### Generate an invitation

Use the CLI command to generate invitation links:

```bash
php si.php invite:generate --admin
```

This creates an admin + author + reviewer invitation. Other role options:

- `--admin` - Sets roles: admin, author, reviewer
- `--author` - Sets roles: author, reviewer  
- `--reviewer` - Sets roles: reviewer (default if no role specified)

**Optional parameters:**

- `--email user@example.com` - Restrict the invitation to a specific email address
- `--hours 48` - Set expiration time (default: 24 hours)

**Example output:**

```
✓ Invitation generated successfully!

Invitation Details:
  Code: c1ad72a12267ffc5eb19e8abc19fa957562344dee9838fa6c1bb5150bbd936bb
  Roles: admin, author, reviewer
  Expires in: 24 hours

Sign-in link for each provider:
GitHub:  http://localhost:8000/auth/github?code=c1ad72a12267ffc5eb19e8abc19fa957562344dee9838fa6c1bb5150bbd936bb
Google:  http://localhost:8000/auth/google?code=c1ad72a12267ffc5eb19e8abc19fa957562344dee9838fa6c1bb5150bbd936bb
```

### Sign in with an invitation

1. Copy the sign-in link from the CLI output
2. Open the link in your browser
3. You'll be redirected to your OAuth provider (GitHub/Google)
4. After authentication, you'll be logged in and added to the users table with the specified roles
5. The invitation code becomes one-time use only

### Invitation email restriction

- If the invitation was created with `--email`, the OAuth email address must match that value.
- This prevents an invitation from being used by the wrong account.

## OAuth providers

This version supports:

- GitHub
- Google

LinkedIn is intentionally hidden for later implementation.

## Composer deployment

The project is prepared for composer-based deployment. On a webserver, you can install from GitHub with a repository entry in `composer.json` or by using `composer create-project` once the package is published.

## Database migrations (Contributor workflow)

Use the built-in migration command:

```bash
php si.php db:migrate --status
php si.php db:migrate
```

### Supported migration naming schemes

- Release/baseline migrations: `NNN_description.sql` (for example `001_initial_schema.sql`)
- Development snapshots: `dev_only_description.sql` (for example `dev_only_add_export_events.sql`)

### How versions are resolved

- For `NNN_*.sql`, the migration version is read from the numeric prefix (`NNN`).
- For `dev_only_*.sql`, the migration version is read from the SQL statement:
   - `INSERT INTO schema_version (version, applied_at) VALUES (<number>, NOW());`

`dev_only` files must include exactly one `schema_version` insert in the UP section so they can be ordered and tracked correctly.

### Ordering and execution

- The runner orders migrations by resolved version (ascending).
- If two files resolve to the same version, filename order is used as a tie-breaker.
- Any `.sql` file that does not resolve to a version is ignored.

### Rollback behavior

- `php si.php db:migrate --rollback` rolls back the file that matches the current `schema_version` value.
- Works for both `NNN_*.sql` and `dev_only_*.sql` files.
