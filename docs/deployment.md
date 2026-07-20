# Production Deployment and Certification

Rishe releases are built as deterministic production archives, protected by SHA-256 checksums and RSA signatures, verified before upload, promoted through protected GitHub environments, and validated again inside WordPress after activation.

## WP-CLI operations

The plugin registers these commands when WP-CLI is available:

- `wp rishe diagnostics`
- `wp rishe certify --environment=staging|production`
- `wp rishe queue enqueue <job-type>`
- `wp rishe queue run --limit=25`
- `wp rishe queue recover --timeout=900`
- `wp rishe backup create`
- `wp rishe backup verify <archive>`
- `wp rishe backup restore <archive> --confirm=<site-url>`
- `wp rishe deploy record --environment=<name> --version=<version> --sha256=<hash> --status=<status>`

Production restore additionally requires `--allow-production`. Restore verifies the archive, creates a safety backup, imports the database, reapplies migrations, restores the allowlisted non-secret configuration package, and writes an audit event.

## Certification

Certification combines the authenticated operations diagnostics with deployment checks for:

- MySQL 8+ or MariaDB 10.6+
- installed migration version
- HTTPS and production debug mode
- writable WordPress content storage
- a recently verified backup
- completeness of active tax, logistics, and treasury provider configuration
- failed operation jobs and unresolved incidents

A failed check makes an environment non-certifiable. Warnings remain certifiable for staging but `--strict` can reject warnings. Every run is audited and its latest report is stored in WordPress options for operators.

## Release workflow

`.github/workflows/release.yml` runs quality gates, builds `rishe-<version>.zip`, produces a checksum and manifest, signs the checksum with RSA-SHA256, verifies the package, uploads a workflow artifact, and publishes tag builds as GitHub Releases.

Required repository secrets:

- `RELEASE_SIGNING_KEY_B64`: base64-encoded RSA private key
- `RELEASE_SIGNING_PUBLIC_KEY_B64`: base64-encoded public key

The production package excludes tests, development workflows, source-control metadata, local logs, and development dependencies. Composer installs an optimized production autoloader inside the package.

## Staging and production promotion

`.github/workflows/deploy.yml` is manually dispatched with a release version, environment, and dry-run flag. It uses GitHub Environments so production can require approval.

Required environment secrets:

- `DEPLOY_HOST`
- `DEPLOY_USER`
- `DEPLOY_PORT` (optional, defaults to 22)
- `DEPLOY_SSH_KEY`
- `DEPLOY_KNOWN_HOSTS`
- `DEPLOY_WP_PATH`

The workflow downloads and cryptographically verifies the GitHub Release, uploads it over SSH, runs remote certification, exports a database rollback file, swaps the plugin directory, activates the release, runs diagnostics and certification, records the artifact hash, and rolls back both files and database if verification fails.

## Real database integration

`.github/workflows/integration.yml` installs WordPress and the plugin against MySQL 8.0 and MariaDB 10.6. It activates all migrations and triggers, executes diagnostics and certification, creates and verifies a real database backup, races eight idempotent enqueue requests and four workers, and verifies that append-only job events reject direct updates.

## Release procedure

1. Merge a version bump with green CI and integration checks.
2. Configure or rotate the release signing key secrets.
3. Create and push tag `vX.Y.Z`.
4. Verify the published checksum, signature, public key, dependency inventory, and release manifest.
5. Dispatch Deploy to `staging` with dry-run enabled.
6. Deploy to staging, execute business smoke tests, and verify a fresh backup.
7. Dispatch Deploy to the protected `production` environment.
8. Confirm the recorded deployment hash and certification report in the WordPress operations center.

Provider certification remains account-specific. Taxpayer, payment, and carrier credentials, accepted sample payloads, callback signatures, and error catalogs must be validated with the live providers before production traffic is enabled.
