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
   - `OAUTH_LINKEDIN_CLIENT_ID`
   - `OAUTH_LINKEDIN_CLIENT_SECRET`
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
LinkedIn: http://localhost:8000/auth/linkedin?code=c1ad72a12267ffc5eb19e8abc19fa957562344dee9838fa6c1bb5150bbd936bb
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
- LinkedIn

Facebook/Meta OAuth is intentionally deferred to a future contributor-facing item.

## OAuth app setup for a real website (GitHub, Google, LinkedIn)

This section documents provider app registration for production-style deployments (public domain + HTTPS), including redirect URI setup and safe client secret handling.

### 1. Decide your canonical production URL first

Choose one canonical base URL and use it consistently in `.env`:

- Example: `https://app.example.com`
- Set `APP_URL=https://app.example.com`

SparkInsight derives provider callback URLs from `APP_URL`:

- GitHub callback: `https://app.example.com/callback/github`
- Google callback: `https://app.example.com/callback/google`
- LinkedIn callback: `https://app.example.com/callback/linkedin`

If your production host supports both `www` and apex domains, pick one as canonical and redirect the other to avoid OAuth callback mismatches.

### 2. Register OAuth apps in each provider console

Create a separate OAuth app/client for each environment (local, staging, production). Do not reuse production client secrets in non-production systems.

#### GitHub OAuth app

1. Open GitHub Developer Settings: `Settings -> Developer settings -> OAuth Apps -> New OAuth App`.
2. Set Homepage URL to your production base URL, for example `https://app.example.com`.
3. Set Authorization callback URL to:
   - `https://app.example.com/callback/github`
4. Create the app, copy Client ID and generate/copy Client Secret.
5. Store in production `.env`:
   - `OAUTH_GITHUB_CLIENT_ID=<client-id>`
   - `OAUTH_GITHUB_CLIENT_SECRET=<client-secret>`

GitHub notes:

- SparkInsight uses `read:user user:email` scope for GitHub.
- If users keep email private on GitHub, the `user:email` scope is required for login/signup mapping.

#### Google OAuth client

1. Open Google Cloud Console and select/create your project.
2. Configure OAuth consent screen for your organization/use case.
3. Go to `APIs & Services -> Credentials -> Create Credentials -> OAuth client ID`.
4. Choose `Web application`.
5. Add Authorized redirect URI:
   - `https://app.example.com/callback/google`
6. Create the client and copy Client ID and Client Secret.
7. Store in production `.env`:
   - `OAUTH_GOOGLE_CLIENT_ID=<client-id>`
   - `OAUTH_GOOGLE_CLIENT_SECRET=<client-secret>`

Google notes:

- SparkInsight uses `openid profile email` scopes.
- In testing mode, only approved test users may sign in until app publishing/verification requirements are satisfied.

#### LinkedIn OAuth app

1. Open LinkedIn Developer portal and create/select your app.
2. Ensure required products are enabled for Sign In with LinkedIn/OpenID Connect.
3. In OAuth settings, add Authorized redirect URL:
   - `https://app.example.com/callback/linkedin`
4. Copy Client ID and Client Secret.
5. Store in production `.env`:
   - `OAUTH_LINKEDIN_CLIENT_ID=<client-id>`
   - `OAUTH_LINKEDIN_CLIENT_SECRET=<client-secret>`

LinkedIn notes:

- SparkInsight uses `openid profile email` scopes.
- LinkedIn app review/product enablement requirements can delay readiness, so complete this early in release preparation.

### 3. Handle client secrets safely

For FTP-style hosting without a native secret manager, treat `.env` as sensitive deployment material:

- Never commit `.env` to Git.
- Use unique, high-entropy secrets per environment and provider.
- Restrict file permissions so only the web process and authorized administrators can read the file.
- Rotate secrets immediately if exposed in logs, screenshots, backups, chat, or support tickets.
- Keep secrets out of browser-side code and templates; OAuth secrets must remain server-side only.

If your host supports environment variables or a secret vault, prefer that over storing long-lived secrets directly in uploaded files.

### 4. Validate provider configuration end-to-end

After setting `.env` and provider app credentials:

1. Generate a fresh invitation (`php si.php invite:generate --reviewer` or needed role).
2. Open each provider invite link (GitHub/Google/LinkedIn) from the generated output.
3. Complete login and verify callback returns to SparkInsight without provider redirect errors.
4. Confirm the user is created/linked and invitation constraints (email restriction, one-time use) are enforced.

If login fails with redirect mismatch errors, verify:

- `APP_URL` exactly matches the public URL used by end users.
- Provider callback URL exactly matches `/callback/<provider>` (including scheme and host).
- The correct environment credentials were deployed.

### 5. Production hardening checklist

- HTTPS enabled for the canonical domain.
- Production provider apps separated from local/staging apps.
- Only required scopes configured per provider.
- Secret rotation process documented in operations notes.
- A fallback admin account access procedure exists in case one provider is temporarily unavailable.

### Live test-system OAuth QA

For the FTP/live test system, set the same provider variables in `.env` for the target host and verify the callback URLs registered in each provider console point to the live `APP_URL` plus the provider callback path.

- Google: `APP_URL/callback/google`
- LinkedIn: `APP_URL/callback/linkedin`

Before creating the provider app, use the SparkInsight logo asset at `/assets/sparkinsight-logo.png` as the application icon. LinkedIn requires a square image of at least 100px on one side.

Provider setup notes:

- Google: include the exact callback URI, not just the domain root.
- LinkedIn: upload the square logo and complete the OAuth product/application setup before testing.

Use a fresh invitation and a new account for each provider when validating the full flow:

1. Create a new invitation code.
2. Open the provider signup link from the live admin/dashboard flow.
3. Sign in with a brand-new Google or LinkedIn account.
4. Confirm the account is created, the invitation is consumed, and the user lands in the dashboard.
5. Repeat the same sequence separately for each provider.

If a provider redirects back with an error, verify the `.env` values, the provider app registration, the redirect URI, and the provider-specific scopes.

## Composer deployment

The project is prepared for composer-based deployment. On a webserver, you can install from GitHub with a repository entry in `composer.json` or by using `composer create-project` once the package is published.

## Import and migration documentation

Detailed contributor/operator documentation is available in dedicated pages:

- `docs/scrivener-setup.md` for author-side Scrivener project setup, backup workflow conventions, and pre-import validation
- `docs/imports.md` for Scrivener import workflow, dry-run validation, and import batch maintenance commands
- `docs/migrations.md` for migration runner usage, version resolution, rollback rules, and migration file templates

## Production one-time cronjob examples

The Admin dashboard now exposes a One-time Cronjob Examples section under Settings.
This section is intended for hosting panels where you can schedule one-time jobs but do not have shell access.

Provided examples cover typical production maintenance operations:

- apply pending migrations (`db:migrate`)
- run environment checks (`check-environment`)
- run/import Scrivener content (`content:import-scrivener`)
- purge expired invitations (`invite:purge-expired --force`)
- inspect import batches before cleanup (`content:list-imports`)
- purge a specific import batch when required (`content:purge-imports --id=<import-id> --force`)
- generate invitations as an operational fallback (`invite:generate`)

Each example is formatted as a cron line and should be removed after it has run.
