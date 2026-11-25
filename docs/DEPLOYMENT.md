# Production deployment

Use feature branches and merge reviewed changes into `main`. Only pushes to `main` publish production builds. A feature-branch push alone does not update production.

1. GitHub Actions runs Blade, SQLite/MySQL, browser and upgrade tests.
2. Composer installs locked production dependencies on the remote runner. Commit both composer.json and composer.lock when changing dependencies; manual vendor uploads are unnecessary.
3. `hostinger-artifacts` stores the immutable checksummed build including vendor. Never connect this branch to public_html: it contains a ZIP, not the web entry point.
4. `hostinger-deploy` stores only a denied release pointer. Hostinger Advanced > Git must use this branch and directory `public_html/_deployment`, with auto-deployment on.
5. The public index.php adapter resolves the pointer, downloads the checksum-pinned build over verified HTTPS into the private application directory and activates it only after every file verifies. Environment and shared storage remain outside public_html and Git.
6. The workflow verifies the live X-Vidya-Release matches the source commit and checks public assets and private-file protections.

Do not deploy another branch into public_html itself; Hostinger publication can replace the existing public entry and cause 403. Do not extract private source/vendor or upload .env into public_html. The one-time public index.php and .htaccess adapters must remain in place.

## Database changes

Production database migrations are deliberately gated. A changed migration set stops publication until the upgrade is reviewed and applied safely, and the schema baseline and private approved fingerprint are updated. Never run migrate:fresh, key:generate or first-install SQL against production.

## Rollback

Private existing releases, environment, shared data and the original web-entry copy are retained. Set enabled=false in the private deployment-settings.json to select the existing fallback release; restore its public assets if the public root was independently replaced. For an application rollback, republish the previous known-good artifact pointer; no database reset or key rotation is involved.

## Checks

The public register route intentionally returns 404. Unauthenticated /admin redirects to /login. A green Hostinger clone alone does not prove a functioning application: the workflow must pass and the live release header must equal the main commit.
