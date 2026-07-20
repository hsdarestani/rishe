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

## Release candidate certification

`.github/workflows/release-candidate.yml` proves that the production artifact itself works before a real signing key or staging host is used. It does not install the source checkout or a plugin-directory symlink.

The workflow:

1. resolves the version from `RISHE_VERSION`;
2. generates an ephemeral RSA key pair scoped to the CI run;
3. builds the deterministic production ZIP;
4. verifies its checksum and RSA signature;
5. rejects unsafe archive paths, multiple roots, missing production autoloaders, development files, and PHP syntax errors;
6. installs the exact ZIP on clean WordPress instances backed by MySQL 8.0 and MariaDB 10.6;
7. runs diagnostics and staging certification;
8. creates a real backup, mutates both WordPress and ERP data, restores the backup, and proves the mutation was removed;
9. verifies the mandatory pre-restore safety backup and uploads machine-readable evidence.

Run the package-only rehearsal locally with:

```bash
composer release:candidate
```

The ephemeral key proves the build and verification path but is not a production trust root. Tagged releases still require the repository signing secrets.

## Package policy

`scripts/package-smoke.sh` enforces the production package boundary. A valid archive has exactly one `rishe/` root, includes `rishe.php`, `composer.json`, and `vendor/autoload.php`, and contains no unsafe traversal paths.

The following development-only content is rejected:

- `.git` and `.github`
- `tests`, `docs`, `scripts`, `dist`, and `node_modules`
- PHPUnit and PHPCS configuration
- `composer.lock`
- local logs and editor artifacts

Every PHP file in the extracted package is syntax-checked. The evidence contains the artifact hash, size, file count, PHP file count, version, and certification timestamp.

## Disaster recovery rehearsal

`scripts/recovery-rehearsal.sh` refuses to run when WordPress reports the `production` environment. On staging or CI it performs a complete restore exercise:

1. stores a unique WordPress marker and completes an immutable `system.noop` ERP job;
2. creates and verifies a source backup;
3. changes the marker and creates a second ERP job after the backup;
4. restores the source backup using the normal guarded WP-CLI command;
5. proves the original marker and job returned;
6. proves the post-backup ERP job disappeared;
7. verifies the automatic safety backup;
8. re-verifies the source backup and runs diagnostics and certification.

This validates database export/import, migration replay, configuration replay, immutable operations data, backup checksums, and the safety-backup path in one executable test.

## Release workflow

`.github/workflows/release.yml` runs quality gates, builds `rishe-<version>.zip`, produces a checksum and manifest, signs the checksum with RSA-SHA256, verifies the package and production file policy, uploads workflow evidence, and publishes tag builds as GitHub Releases.

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

`.github/workflows/integration.yml` installs WordPress and the source checkout against MySQL 8.0 and MariaDB 10.6. It activates all migrations and triggers, executes diagnostics and certification, creates and verifies a real database backup, races eight idempotent enqueue requests and four workers, and verifies that append-only job events reject direct updates.

`.github/workflows/release-candidate.yml` complements that suite by installing the packaged ZIP and executing the full disaster-recovery round trip on both database engines.

## Release procedure

1. Merge a version bump with green CI, integration, and release-candidate checks.
2. Review the package and recovery evidence artifacts for both database engines.
3. Configure or rotate the release signing key secrets.
4. Create and push tag `vX.Y.Z`.
5. Verify the published checksum, signature, public key, dependency inventory, package evidence, and release manifest.
6. Dispatch Deploy to `staging` with dry-run enabled.
7. Deploy to staging, execute business and provider smoke tests, and run a fresh recovery rehearsal in a staging clone.
8. Dispatch Deploy to the protected `production` environment.
9. Confirm the recorded deployment hash and certification report in the WordPress operations center.

Provider certification remains account-specific. Taxpayer, payment, and carrier credentials, accepted sample payloads, callback signatures, and error catalogs must be validated with the live providers before production traffic is enabled.
