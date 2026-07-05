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

## Documentation

See `docs/Setup.md` for more details on environment setup and running the app.

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for version history.