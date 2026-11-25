# Vidya Counseling Centre

Complete Laravel 13 application: Blade, plain CSS and vanilla JavaScript. No Node.js/npm/Vite build is required. Includes Services, Our Story/settings, consented Client Stories, moderated Reviews, encrypted Consultation Inquiries, shared FAQs/template draft helper, staff permissions and the existing Blog/editorial system.

## Clone and run locally

Install PHP 8.3+, Composer 2, Git and MySQL 8/MariaDB for your own local development environment when you are ready. PHP requires the usual Laravel extensions plus GD, PDO MySQL, mbstring, DOM/XML, fileinfo, ctype, curl, openssl, tokenizer and zip. This repository does not install anything on your PC automatically.

```powershell
git clone https://github.com/nsrdrhitesh/vidya_counselling_center.git
cd vidya_counselling_center
composer install
Copy-Item .env.example .env
```

Edit `.env` for **local development** before continuing:

```dotenv
APP_ENV=local
APP_URL=http://127.0.0.1:8000
APP_DEBUG=true
SESSION_SECURE_COOKIE=false
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=vidya_local
DB_USERNAME=your_local_database_user
DB_PASSWORD=your_local_database_password
```

Create the empty local database (see [database/README.md](database/README.md)), then:

```powershell
php artisan key:generate
php artisan migrate
php artisan vidya:local-admin
php artisan serve
```

Visit http://127.0.0.1:8000 and sign in at `/login` with the account you created interactively. There are no committed/default admin credentials. The local-admin command refuses production and remote database hosts; public `/register` remains closed. Do not copy a production encryption key into a public file or generate a new key on production.

## Database and privacy

All seven schema migrations and approved centre seed content are included. Production `.env`, credentials, users, client data, database exports, private media, logs and backups are excluded. Migrations rebuild a fresh local schema without copying private production data. Keep any independently downloaded production export outside this repository.

Private inquiry/contact fields and moderation/source notes are encrypted. Only Administrators or explicitly authorised staff access inquiries; Editors do not receive access by default. Reviews start Pending and client stories need recorded consent. No fake reviews, clinical credentials, fees or opening hours are seeded.

## Tests and deployment

GitHub Actions installs the committed Composer lock, compiles/lints Blade, runs SQLite/MySQL regressions, tests the additive previous-release SQL upgrade and exercises actual rendered pages at 360/390/768/1280/1440 widths. It builds separate private/public artifacts with production dependencies; no local frontend compilation is needed.

```powershell
php artisan test
```

Run tests only against an isolated local database. Never run `migrate:fresh` or first-install SQL against production. After a feature branch is merged into main, GitHub Actions tests the source and builds locked production dependencies. The generated hostinger-deploy branch is connected to public_html/_deployment; do not connect main or hostinger-artifacts directly to the website root. See [production deployment](docs/DEPLOYMENT.md).

[Centre editing and privacy guide](docs/CENTRE-MODULES.md). Quill 2.0.3 is self-hosted under its BSD-3-Clause notice in `public/vendor/quill/LICENSE`; Symfony HTML Sanitizer handles server-side rich-text sanitization. Operational account paths, private exports and backups are intentionally excluded.
