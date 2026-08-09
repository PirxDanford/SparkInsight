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

### Install and update without Composer on the server (RFC 14 package flow)

If your server does not provide Composer and you deploy with FTP, follow this exact flow.

If SparkInsight is already installed on the server, use only the patch/update steps in "Routine update steps" below. The "first activation" steps are one-time setup only.

Required PHP extensions:

- `sodium`
- `zip` / `ZipArchive`

Quick verification:

- Windows:
  ```bash
  php -m | findstr /I "sodium zip"
  ```
- Linux/macOS:
  ```bash
  php -m | grep -Ei 'sodium|zip'
  ```

One-time first activation (new host only):

1. Install dependencies locally:
   ```bash
   composer install
   ```
2. Generate signing keys once:
   ```bash
   composer release:keygen
   ```
   Fallback:
   ```bash
   php tools/release/generate_keys.php
   ```
3. Keep `.deploy/release-private.key` local only.
4. Upload `.deploy/trusted-release-key.pub` to the server.
5. Build a signed full package locally:
   ```bash
   php si.php package:build build/sparkinsight-full.zip --private-key .deploy/release-private.key --type full
   ```
6. Prepare the FTP wizard bundle once:
   ```bash
   composer release:wizard
   ```
   Fallback:
   ```bash
   php tools/release/prepare_wizard.php
   ```
7. Upload the contents of `.deploy/wizard` to the server web root.
8. Open the printed `/init?token=...` URL in your browser.
9. Upload the signed full package in `/init` and complete first activation.
10. Open the site root and sign in.
11. Remove `/init` from the server after successful activation.

Routine update steps (already installed system):

1. Build a signed package locally.
   - Full package:
     ```bash
     php si.php package:build build/sparkinsight-full.zip --private-key .deploy/release-private.key --type full
     ```
   - Patch package:
     ```bash
     php si.php package:build build/sparkinsight-patch.zip --private-key .deploy/release-private.key --type patch --base-root .deploy/releases/<base-release-id>
     ```
       `base-release-id` is the currently active release id on the server (the `current` value in `.deploy/current.json`).
       `--base-root` must point to a local directory that contains an exact copy of that active release content.
       If the base root path is missing or not a real release tree, the generated patch can become effectively full-size.
       If you do not have an exact local copy of the active release, build from a previously stored package artifact instead:
       ```bash
       php si.php package:build build/sparkinsight-patch.zip --private-key .deploy/release-private.key --type patch --base-package build/sparkinsight-full.zip
       ```
       `--base-package` accepts either a prior release ZIP (reads `manifest.json` inside) or a standalone manifest JSON file.
   - Automatic patch filename (recommended for incremental ordering):
     ```bash
     php si.php package:build build --private-key .deploy/release-private.key --type patch --base-package build/sparkinsight-full.zip
     ```
     This creates `build/sparkinsight-patch-000001.zip`, then `...000002.zip`, and so on.
       When patch ZIPs already exist in that output directory, SparkInsight automatically uses the latest numbered patch ZIP as the next patch base.
       This keeps incremental chains aligned with numbered patch artifacts without changing your command each time.
    - Reset local patch numbering and start over at `...000001.zip` (removes existing local patch ZIP + manifest sidecar files in the selected output directory):
       ```bash
       php si.php package:build build --private-key .deploy/release-private.key --type patch --base-package build/sparkinsight-full.zip --from-scratch
       ```
2. In Admin -> Packages, upload the package.
3. SparkInsight deploys automatically after upload.
4. Wait for the deployment result output in Admin.
5. Open the app and verify login + key workflows.

#### Rebase on current live release and clean patch backlog

Use this whenever patch uploads start to feel slow, or as a daily cleanup routine.

Goal:

- stop growing a long patch chain;
- make the currently live state your new baseline;
- keep only the package artifacts you still need.

Recommended flow (no mandatory full ZIP):

1. In Admin -> Packages, click **Download Current Live Manifest**.
   - This exports a manifest JSON of what is currently live on the server.
   - Use this to rebase patch generation even if there were manual/live drift corrections.
2. Build the next patch against that exported manifest:
   ```bash
   php si.php package:build build --private-key .deploy/release-private.key --type patch --base-package C:/path/to/sparkinsight-live-base-....manifest.json
   ```
3. Upload the patch in Admin -> Packages (auto-deploy).
4. Verify the app (login + key workflows).
5. Clean local patch artifacts you no longer need.
   - Keep at least:
     - last known good full ZIP (for emergency fallback);
     - latest exported live-base manifest JSON;
     - newest patches still relevant for rollback/testing.
   - Remove/archive older patch ZIPs after the rebase patch is confirmed.

Optional hard reset baseline:

- If you want to collapse everything into one artifact periodically, deploy a new full package, then continue patching from that new baseline.

Server-side note:

- SparkInsight already clears `.deploy/releases` during deployment in the current flow.
- If your host still accumulates old files under `.deploy/uploads`, `.deploy/state`, or `.deploy/logs`, prune old completed-operation files periodically (via FTP/file manager).
- Never delete `.env`, `.deploy/current.json`, or trusted key files during cleanup.

Practical cadence:

- If you deploy many times per day, rebase to a new full package at end of day.
- If changes are small and infrequent, rebase weekly is usually enough.

Important:

- Keep `.env` on the server and out of packages.
- Set `APP_URL` and OAuth credentials directly on the server.

#### First initialization without shell access (temporary password-gated flow)

If you cannot run CLI/cron output reliably during first setup:

1. Set `BOOTSTRAP_INIT_PASSWORD` in `.env` to a strong temporary value.
2. Open `/bootstrap/init`.
3. Unlock with the temporary password.
4. Run actions in this order:
   - Migration status
   - Apply migrations
   - Migration status (again)
   - Create first admin invitation
5. Open one generated provider invite link and complete OAuth signup for the first admin.
6. Remove `BOOTSTRAP_INIT_PASSWORD` from `.env` immediately after bootstrap is complete.

Safety notes:

- Bootstrap init auto-disables by default once an admin exists.
- `BOOTSTRAP_INIT_ALLOW_AFTER_ADMIN` should remain `0` unless you intentionally need a temporary emergency reopen.

#### Confirm the server start page order before going live

Some FTP hosts will serve `index.htm` or `index.html` before `index.php`, and a temporary placeholder page can hide the actual app entrypoint.

1. Remove any temporary `index.htm` / `index.html` from the active web root once testing is finished.
2. Confirm which start pages the host uses, ideally through the hosting panel or web server documentation.
3. For Apache-style hosting, check for `DirectoryIndex` ordering in the root `.htaccess` or panel-managed settings.
4. For nginx-style hosting, check the `index` directive in the server configuration or ask the host which filenames are prioritized.
5. Verify that the real app entrypoint is served through the root `index.php` front controller after the placeholder page is removed.
6. If you need a minimal probe, create a temporary one-line `index_check.php` in the web root, verify output, then remove it immediately.

Optional diagnosis page for target environment:

1. Open `/install_diagnose.php` in your browser.
2. If `DIAG_ACCESS_TOKEN` is set in `.env`, pass `?token=<temporary-random-token>`; otherwise the page is open for temporary validation.
3. Confirm the checks are `OK` for PHP version/extensions, `.env`, `vendor/`, and lockfile parsing.
4. Review the `Webserver context` section for values such as `SERVER_SOFTWARE`, `HTTP_HOST`, `DOCUMENT_ROOT`, `SCRIPT_FILENAME`, `SCRIPT_NAME`, and `REQUEST_URI`.
5. Review the `Startpage clues` section. It is only conclusive when the diagnostics page is requested at `/`; a direct `/install_diagnose.php` request can still echo server context values, but it cannot prove the default start page by itself.
6. Use the webserver context values to verify whether the host is Apache-style or nginx-style and whether the expected start page is being served.
7. Remove `public/install_diagnose.php` after validation if you do not want a live diagnostics endpoint.

For updates, repeat the package flow: build signed package locally, upload in Admin -> Packages, then execute `release:deploy`.

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

The project is prepared for composer-based deployment.

### Pre-release install simulation (before Packagist/tag)

Run this from the repository root to verify that Composer can consume SparkInsight from a separate project context:

```bash
composer test:install-sim
```

This command runs a local consumer-project simulation using a path repository and fails if dependency resolution does not work.

### Coverage guardrail for method-level test execution

SparkInsight enforces a method-level coverage guardrail in CI and local contributor workflows:

```bash
composer coverage
```

This command now:

1. Runs PHPUnit with text + Clover coverage output.
2. Fails if any executable method remains below 100% coverage.

No exception ledger is used anymore. Every uncovered method must be covered by tests.

### Production/public install after first tag + Packagist registration

After `sparkinsight/sparkinsight` is registered and tagged (for example `v1.0.0`), install with:

```bash
composer create-project sparkinsight/sparkinsight:^1.0 sparkinsight
```

### Install directly from GitHub (without Packagist)

If Packagist is not used yet, install from GitHub by defining a VCS repository in the consumer `composer.json` and requiring the desired branch/tag.

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
