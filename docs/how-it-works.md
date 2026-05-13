# app-project-backup – how the backup works

## Call flow (Application::run)

```
Component.php
  └─ Application::run()
       1. initSapi()              – creates StorageApi client (KBC_URL + KBC_TOKEN)
       2. generateBackupPath()    – data-takeout/<region>/<projectId>/<backupId>/
          or getPath()            – if user-defined credentials are provided
       3. storageBackend->getBackup()  – creates S3Backup / AbsBackup / GcsBackup
       4. backup->backupProjectMetadata()
       5. backup->backupTablesMetadata()
       6. foreach tables:
            backup->backupTable($tableId)
       7. backup->backupConfigs()
       8. backup->backupTriggers()
       9. backup->backupNotifications()
      10. backup->backupPermanentFiles()
      11. (GCS + auto path only) backup->backupSignedUrls()
```

## Backup path

Path selection is based on whether `parameters.storageBackendType` is set in the input config (`Config::isUserDefinedCredentials()`):

- **User-defined credentials** (`storageBackendType` in `parameters`): `Application::run()` calls `Config::getPath()`, which returns `backupPath`.
- **Image parameter credentials** (no `storageBackendType` in `parameters`): path is auto-generated as:

```
data-takeout/<region>/<projectId>/<backupId>/
```

where `region` and `projectId` come from the SAPI `verifyToken()` response, and `backupId` is taken from `parameters.backupId` (cast to `int`; becomes `0` if not provided).

> Note: `backupId` is auto-generated via `$sapi->generateId()` only in the `generate-read-credentials` action, not in `run`.

The region is validated to match the region from the image parameters (can be disabled via `skipRegionValidation`).

## How tables are backed up

### Initialization

At the start, `defaultBranch` is fetched via `DevBranches::listBranches()`. A `BranchAwareClient` is created for operations on the default branch (metadata, configurations).

### backupTable – flow for a single table

```
getTableFileInfo($tableId)
  ├─ getTable($tableId)           – loads table metadata
  ├─ skip condition checks:
  │    stage === 'sys'            → SkipTableException
  │    hasExternalSchema          → SkipTableException
  │    sourceBucket (DataCatalog) → SkipTableException
  │    isAlias                    → SkipTableException
  ├─ exportTableAsync($tableId, ['gzip' => true])
  └─ getFile($fileId, federationToken: true)  → fileInfo with credentials

getFileClient($fileInfo)
  ├─ S3FileClient   – if credentials/absCredentials not in fileInfo
  ├─ AbsFileClient  – if absCredentials is in fileInfo
  └─ GcsFileClient  – if provider === 'gcp'

if isSliced:
  download manifest → foreach entries → putToStorage(table/name.part_N.csv.gz)
else:
  putToStorage(stage/c-bucket/table.csv.gz)
```

### FederationToken

`getFile()` is called with `setFederationToken(true)`. The returned `fileInfo` contains temporary credentials (AWS STS, ABS SAS token, or GCS ADC token) for direct access to cloud storage without going through SAPI. This significantly speeds up the transfer of large tables.

### Sliced vs. non-sliced

| Type | Approach |
|-----|--------|
| Non-sliced | Single `table.csv.gz` file → direct download via FileClient |
| Sliced | Manifest JSON → list of chunk URLs → each chunk separately via FileClient |

## Sync action: generate-read-credentials

`Application::generateReadCredentials()` returns temporary read-only credentials for the backup location:

```
initSapi()
backupId = config.getBackupId() ?: sapi.generateId()
path     = generateBackupPath() or getPath()
return storageBackend->generateTempReadCredentials(backupId, path)
```

This action is called by `app-project-migrate` (Phase 2) so the destination project can access the backup without sharing long-lived credentials.

## Backends – putToStorage

Each backend implements the abstract `putToStorage(string $name, $content)`:

| Backend | Implementation |
|---------|-------------|
| S3Backup | `S3Client::putObject(Bucket, Key, Body)` |
| AbsBackup | `BlobRestProxy::createBlockBlob(container, name, content)` |
| GcsBackup | `StorageObject::upload($content)` |

## Configurations

`backupConfigs()` iterates through all components with pagination (limit 2 per page, repeats while data exists). For each configuration it saves:
- `configurations/<componentId>/<configId>.json` – data + rows + state
- `configurations/<componentId>/<configId>.json.metadata` – configuration metadata

Configuration versions are saved only if `includeVersions: true`.

## GCS – backupSignedUrls

Only for GCS backend without user-defined credentials. Saves `signedUrls.json` with a signed URL for each backup file. Used for alternative access to the backup without credentials.
