# Database setup

The complete schema is defined in `migrations/`. Run `php artisan migrate` against a new **local** MySQL/MariaDB database. The seven migrations create the tables and approved centre/service settings. They do not import production users, passwords, inquiries, reviews or private records.

Optional local database creation:

```sql
CREATE DATABASE vidya_local CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Configure its credentials in your untracked `.env`, then run migrations and `php artisan vidya:local-admin`. The latter is interactive and refuses production/remote application URLs. `/register` remains closed.

Production data exports must remain outside this public repository. If you later import a private export locally, protect it and keep the correct encryption key securely: a newly generated local APP_KEY cannot decrypt production encrypted fields. Never run `migrate:fresh` or import a first-install schema on the live database.
