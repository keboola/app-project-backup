# app-project-backup – AI Development Context

## What this repository does

Keboola App component for backing up a project to cloud storage (S3, ABS, GCS). Wraps the `php-kbc-project-backup` library. Run by `app-project-migrate` as Phase 1 of the pipeline, but can also be run standalone – e.g. for periodic project backups.

## Documentation

- **`docs/overview.md`** – what is backed up, what is skipped, storage backends, parameters
- **`docs/how-it-works.md`** – step-by-step backup flow (`Application::run`, `federationToken`, sliced vs non-sliced)
- **`docs/configuration.md`** – complete input parameter reference for all 3 backends

## Required environment variables

Before running tests, verify that these variables are present in `.env` (or exported in the shell). If missing, ask for them explicitly.

**For AWS S3 tests:**

| Variable | Description |
|---|---|
| `TEST_STORAGE_API_URL` | URL of the Keboola project on AWS stack |
| `TEST_STORAGE_API_TOKEN` | Storage token of the project on AWS stack |
| `TEST_AWS_ACCESS_KEY_ID` | AWS Access Key ID |
| `TEST_AWS_SECRET_ACCESS_KEY` | AWS Secret Access Key |
| `TEST_AWS_REGION` | AWS region (e.g. `us-east-1`) |
| `TEST_AWS_S3_BUCKET` | S3 bucket name for test backups |

**For Azure ABS tests:**

| Variable | Description |
|---|---|
| `TEST_AZURE_STORAGE_API_URL` | URL of the Keboola project on Azure stack |
| `TEST_AZURE_STORAGE_API_TOKEN` | Storage token of the project on Azure stack |
| `TEST_AZURE_ACCOUNT_NAME` | Azure storage account name |
| `TEST_AZURE_ACCOUNT_KEY` | Azure storage key |
| `TEST_AZURE_REGION` | Azure region |

**For GCS tests:**

| Variable | Description |
|---|---|
| `TEST_GCP_STORAGE_API_URL` | URL of the Keboola project on GCP stack |
| `TEST_GCP_STORAGE_API_TOKEN` | Storage token of the project on GCP stack |
| `TEST_GCP_BUCKET` | GCS bucket name for test backups |
| `TEST_GCP_REGION` | GCP region |
| `TEST_GCP_SERVICE_ACCOUNT` | JSON service account key (full JSON as string) |

**Platform-injected (automatically set by Keboola Runner when running in Keboola):**

| Variable | Description |
|---|---|
| `KBC_URL` | Keboola project URL (for production) |
| `KBC_TOKEN` | Project storage token (for production) |
| `KBC_RUNID` | Job run ID, set on the SAPI client |

> Check that the `.env` file exists in the repo root. If not, create it based on the list above.

## Development commands

Service name in `docker-compose.yml` is `dev`.

```bash
docker compose run --rm dev composer phpcs
docker compose run --rm dev composer phpstan
docker compose run --rm dev composer tests
docker compose run --rm dev composer build     # phplint + phpcs + phpstan + tests
```

## Key files

| File | Purpose |
|---|---|
| `src/Component.php` | Entry point, action routing |
| `src/Application.php` | Backup orchestration, calls storage backend |
| `src/Config/Config.php` | Configuration getters |
| `src/Config/ConfigDefinition.php` | Parameter validation |
| `src/Config/S3Config.php` | AWS S3-specific configuration |
| `src/Config/AbsConfig.php` | Azure Blob Storage-specific configuration |
| `src/Config/GcsConfig.php` | Google Cloud Storage-specific configuration |
| `src/Storages/` | Adapters for S3, ABS, GCS |

## Sync action: generate-read-credentials

Returns temporary read-only credentials for the backup location. Called by `app-project-migrate` after backup so the destination project can access storage without sharing long-lived credentials.

## Coding standards

- PHP 8.x with strict types
- PHPStan level max
- Keboola coding standard (PSR-12)

## Related repositories

- Library: `php-kbc-project-backup`
- Orchestrator: `app-project-migrate`
- Paired with: `app-project-restore`
