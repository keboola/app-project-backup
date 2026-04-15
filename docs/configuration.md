# Configuration parameter reference – app-project-backup

## Required parameters

| Parameter | Description |
|---|---|
| `storageBackendType` | Cloud storage type: `s3`, `abs`, or `gcs` |

The backup path must be defined in exactly one of two ways:
- `backupId` – an ID from which the path is assembled automatically
- `backupPath` – custom full path in storage

## General parameters

| Parameter | Default | Description |
|---|---|---|
| `exportStructureOnly` | `false` | If `true`, backs up only metadata (buckets, tables, configurations) without table data |
| `includeVersions` | not set | If `true`, includes configuration version history |
| `skipRegionValidation` | `false` | Skips validation that project region matches storage backend region |

## S3 credentials

Used if `storageBackendType: s3`.

| Parameter | Required | Description |
|---|---|---|
| `access_key_id` | ✓ | AWS Access Key ID |
| `#secret_access_key` | ✓ | AWS Secret Access Key |
| `region` | ✓ | AWS region (e.g. `us-east-1`) |
| `#bucket` | ✓ | S3 bucket name |
| `backupId` or `backupPath` | ✓ | Backup path |

## ABS credentials (Azure Blob Storage)

Used if `storageBackendType: abs`.

| Parameter | Required | Description |
|---|---|---|
| `accountName` | ✓ | Azure storage account name |
| `#accountKey` | ✓ | Azure storage key |
| `backupPath` | ✓ | Backup path (container/path) |

## GCS credentials (Google Cloud Storage)

Used if `storageBackendType: gcs`.

| Parameter | Required | Description |
|---|---|---|
| `#jsonKey` | ✓ | JSON service account key (full JSON as string) |
| `#bucket` | ✓ | GCS bucket name (encrypted) |
| `region` | ✓ | GCP region (e.g. `europe-west3`) |
| `backupId` or `backupPath` | ✓ | Backup path |

> `projectId` is NOT an input parameter – it is extracted from `#jsonKey` (`jsonKey['project_id']`).

## Sync action: generate-read-credentials

This action is not called directly by users – it is called by `app-project-migrate` after backup. It returns temporary read-only credentials for the backup that are passed to `app-project-restore`.

## Configuration examples

### Backup to S3 (full)

```json
{
  "parameters": {
    "storageBackendType": "s3",
    "backupId": "12345",
    "access_key_id": "AKIA...",
    "#secret_access_key": "secret",
    "region": "us-east-1",
    "#bucket": "my-kbc-backups",
    "includeVersions": true
  }
}
```

### Backup to S3 (structure only, no table data)

```json
{
  "parameters": {
    "storageBackendType": "s3",
    "backupId": "12345",
    "access_key_id": "AKIA...",
    "#secret_access_key": "secret",
    "region": "us-east-1",
    "#bucket": "my-kbc-backups",
    "exportStructureOnly": true
  }
}
```

### Backup to GCS

```json
{
  "parameters": {
    "storageBackendType": "gcs",
    "backupId": "12345",
    "#jsonKey": "{\"type\":\"service_account\",\"project_id\":\"my-gcp-project\",...}",
    "#bucket": "my-kbc-backups",
    "region": "europe-west3"
  }
}
```

### Backup to ABS (Azure)

```json
{
  "parameters": {
    "storageBackendType": "abs",
    "accountName": "mystorageaccount",
    "#accountKey": "base64key==",
    "backupPath": "my-container/backups/project-123"
  }
}
```
