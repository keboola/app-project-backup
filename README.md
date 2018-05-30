# Project Migration - Snapshot to AWS S3

[![Build Status](https://travis-ci.org/keboola/app-project-backup.svg?branch=master)](https://travis-ci.org/keboola/app-project-backup)

Application for creating temporary backup of KBC project.
Backup is stored in AWS S3 and will be automaticaly deleted due configured Lifecycle Policy ([aws-cf-template.json](./aws-cf-template.json#L17)).

Generated backup is used by `keboola.project-restore` app (https://github.com/keboola/app-project-restore) to restore project in one of KBC stacks.

### Backup contains
- **storage buckets**
  - bucket attributes
  - bucket metadata
- **storage tables**
  - structure and data
  - table attributes
  - table and columns metadata

- **component configurations**
  - configuration with their state
  - config rows with their state

### What is not in backup

- Sys stage
- Table data from linked buckets and table aliases
- Table snapshots
- Storage Events
- Versions of configurations and configuration rows

### The project backup process consists of two steps

1) Obtain ID of backup and read-only credentials into AWS S3
2) Process backup

## Usage

### 1. Prepare storage for backup

Use `generate-read-credentials` action to prepare AWS S3 storage and read-only credentials.

```
curl -X POST \
  https://docker-runner.keboola.com/docker/keboola.project-backup/action/generate-read-credentials \
  -H 'X-StorageApi-Token: **STORAGE_API_TOKEN**' \
  -d '{"configData": {"parameters": {"backupId": null}}}'
```

You will retrieve:

- `backupId` - Generated id for backup
- `backupUri` - Uri of S3 storage for your project backup
- `region` - AWS region where backup will be located _(Same as your KBC project)_
- `credentials` - Temporary read-only credentials to AWS S3 _(Expiration is set for 24 hours)_
    
### 2. Run backup

Use `backupId` from the first step and create asynchronous job.

```
curl -X POST \
  https://docker-runner.keboola.com/docker/keboola.project-backup/run \
  -H 'X-StorageApi-Token: **STORAGE_API_TOKEN**' \
  -d '{"configData": {"parameters": {"backupId": **BACKUP_ID**}}}'
```

# Development

### Preparation

- Clone this repository:

```
git clone https://github.com/keboola/app-project-backup.git
cd app-project-backup
```

- Create AWS services from CloudFormation template [aws-cf-template.json](./aws-cf-template.json)

    It will create new S3 bucket and IAM User in AWS
    
- Create `.env` file an fill variables:

    - `TEST_AWS_*` - Output of your CloudFormation stack
    - `TEST_STORAGE_API_URL` - KBC Storage API endpoint
    - `TEST_STORAGE_API_TOKEN` - KBC Storage API token
    
```
TEST_AWS_ACCESS_KEY_ID=
TEST_AWS_SECRET_ACCESS_KEY=
TEST_AWS_REGION=
TEST_AWS_S3_BUCKET=

TEST_STORAGE_API_URL=
TEST_STORAGE_API_TOKEN=
```

- Build Docker image

```
docker-compose build
```

- Run the test suite using this command

    **Tests will delete all current component configurations and data from the KBC project!**

```
docker-compose run --rm dev composer ci
```
 
# Integration

For information about deployment and integration with KBC, please refer to the [deployment section of developers documentation](https://developers.keboola.com/extend/component/deployment/) 
