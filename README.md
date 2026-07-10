# SparkInsight

SparkInsight is a lightweight review workflow platform skeleton built for Composer-based deployment and local development.

## What is included

- Slim 4 web application structure
- GitHub and Google OAuth login support
- Local demo login for development
- PSR-4 autoloading and Composer readiness

## Local development

1. Copy environment variables:
   ```bash
   cp .env.example .env
   ```
2. Set GitHub and/or Google OAuth credentials in `.env`.
3. Install dependencies:
   ```bash
   composer install
   ```
4. Start the app:
   ```bash
   composer start
   ```
   This is a long-running development server. Stop with `Ctrl+C`.

   Manual alternative:
   ```bash
   php -S localhost:8000 -t public public/index.php
   ```
5. Visit `http://localhost:8000`

## OAuth providers

This prototype supports:

- GitHub
- Google

LinkedIn is intentionally hidden in the UI and reserved for future implementation.

## Composer deployment

The project is designed to be installed and updated via Composer. Once published to GitHub, the repository can be used as a Composer repository target for webserver deployments.

If your hosting environment does not provide Composer access and only allows FTP uploads:

1. Prepare the project locally so the `vendor/` directory is included (for example from a release package or a local checkout where dependencies are already installed).
   - Keep `composer.lock` in the deployment package so dependency versions stay pinned.
2. Upload the complete project directory via FTP.
3. Set your web root/document root to `public/`.
4. Configure `.env` on the server (at minimum `APP_URL` and OAuth credentials).

Optional deployment diagnosis (for FTP-only servers):

1. Set `DIAG_ACCESS_TOKEN` in `.env` to a temporary random value.
2. Open `/install_diagnose.php?token=<your-token>` in the browser.
3. Review checks for PHP version, required extensions, `.env`, `vendor/`, and `composer.lock`.
4. Remove `public/install_diagnose.php` or clear `DIAG_ACCESS_TOKEN` after validation.

Without Composer on the server, package updates must be prepared locally first and then uploaded again via FTP.

To reduce environment drift across hosting targets, use the production prep workflow in `deployment/production-prep/` before creating an upload package.

## Documentation

See `docs/Setup.md` for more details on environment setup and running the app.
Migration naming and `dev_only_*.sql` contributor workflow are documented in `docs/Setup.md` under "Database migrations (Contributor workflow)".

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for version history.