# app-project-backup – overview

## What the component does

Backs up a Keboola project to cloud storage (S3, Azure Blob Storage or GCS). This is a Keboola App component that wraps the `php-kbc-project-backup` library.

Run by `app-project-migrate` as Phase 1 of the migration pipeline, but can also be run standalone – e.g. for periodic project backups.

## What is backed up

| Artifact | Path in storage |
|---|---|
| Default branch metadata | `defaultBranchMetadata.json` |
| Bucket definitions | `buckets.json` |
| Table definitions + column metadata | `tables.json` |
| Table data (gzip CSV, non-sliced) | `<stage>/c-<bucket>/<table>.csv.gz` |
| Table data (sliced) | `<stage>/c-<bucket>/<table>.part_N.csv.gz` |
| Component index with configurations | `configurations.json` |
| Component configurations | `configurations/<componentId>/<configId>.json` |
| Configuration metadata | `configurations/<componentId>/<configId>.json.metadata` |
| Configuration version history | part of config JSON (if `includeVersions: true`) |
| Permanent files | `files/<fileId>` |
| Permanent files metadata | `permanentFiles.json` |
| Triggers | `triggers.json` |
| Notifications (default branch only) | `notifications.json` |
| GCS signed URLs | `signedUrls.json` (GCS backend only) |

## What is skipped

- **Sys bucket** tables
- **Alias** tables (have no own data)
- **External schema** tables
- **Data Catalog** tables (have `sourceBucket` set)
- Notifications not belonging to the default branch

## Storage backends

| Backend | Authentication |
|---|---|
| AWS S3 | `access_key_id` + `#secret_access_key` + `region` + `#bucket` |
| Azure Blob Storage | `accountName` + `#accountKey` |
| GCS | `#jsonKey` (JSON service account) + `#bucket` + `region` (projectId extracted from jsonKey) |

The storage path depends on which credentials are used:
- **Image parameter credentials** (no `storageBackendType` in `parameters`): path is `data-takeout/<region>/<projectId>/<backupId>/`. `backupId` must be provided in `parameters` for the `run` action; in `generate-read-credentials` it is auto-generated if missing.
- **User-defined credentials** (`storageBackendType` set in `parameters`): path comes from `backupPath`.

## Sync action: `generate-read-credentials`

Returns temporary read-only credentials for the backup location. Used by `app-project-migrate` so the destination project can access the backup without sharing long-lived credentials.

## Architecture

```
Component.php
  └─ Application.php
       └─ php-kbc-project-backup (library)
            ├─ S3Backup / AbsBackup / GcsBackup
            └─ FileClient / S3FileClient / AbsFileClient / GcsFileClient
```

## Key files

| File | Description |
|---|---|
| `src/Component.php` | Entry point, action routing |
| `src/Application.php` | Backup orchestration, calls the correct storage backend |
| `src/Config/Config.php` | Configuration getters |
| `src/Config/ConfigDefinition.php` | Parameter validation |
| `src/Config/S3Config.php` | AWS S3-specific configuration |
| `src/Config/AbsConfig.php` | Azure Blob Storage-specific configuration |
| `src/Config/GcsConfig.php` | Google Cloud Storage-specific configuration |
| `src/Storages/` | Adapters for S3, ABS, GCS |

## Development and testing

```bash
docker compose run --rm dev composer phpcs
docker compose run --rm dev composer phpstan
docker compose run --rm dev composer tests
```

## Related repositories

- Library: `php-kbc-project-backup`
- Uses it: `app-project-migrate` (Phase 1 + Phase 2 sync action)
- Paired with: `app-project-restore`
