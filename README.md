# hkm-plugin-storage

> HKM Kernel plugin — provides **`storage.local`**.
> Part of the [HKM Kernel](https://github.com/AlfaCode-Team/hkm-kernel) Gated Demand Architecture framework.

[![License: MIT](https://img.shields.io/badge/License-MIT-green)](LICENSE)
![PHP](https://img.shields.io/badge/PHP-8.4%2B-777bb4)

## Install

```bash
composer require alfacode-team/hkm-plugin-storage
# or, from a project:
hkm plugins add storage
```

## Capability

Provides `storage.local` — the kernel `StoragePort` (and its optional
`RangeReadableStorage` capability) on the local disk or any S3-compatible bucket.

## S3-compatible providers

`STORAGE_DRIVER=s3` with `STORAGE_S3_BUCKET`, `STORAGE_S3_REGION`,
`STORAGE_S3_KEY`, `STORAGE_S3_SECRET`, and `STORAGE_S3_ENDPOINT` /
`STORAGE_S3_PATH_STYLE` for anything that is not AWS.

Two switches exist for providers that are compatible but not identical to S3:

| Variable | Use |
|---|---|
| `STORAGE_S3_ACL=false` | the provider has no object ACLs — the `ACL` parameter is dropped from every command |
| `STORAGE_S3_CHECKSUM=when_required` | the provider rejects `x-amz-checksum-*` headers on single-part uploads |

MEGA S4 needs both:

```dotenv
STORAGE_DRIVER=s3
STORAGE_S3_BUCKET=my-bucket
STORAGE_S3_REGION=eu-central-1
STORAGE_S3_ENDPOINT=https://s3.eu-central-1.s4.mega.io
STORAGE_S3_PATH_STYLE=true
STORAGE_S3_ACL=false
STORAGE_S3_CHECKSUM=when_required
```

## Moving existing files into a bucket

`storage:migrate` copies everything under `STORAGE_ROOT` into the configured
bucket under the same keys, so rows that already reference a file keep working
after the switch. Run it while `STORAGE_DRIVER` is still `local`:

```bash
hkm storage:migrate --dry                  # what would be copied
hkm storage:migrate                        # copy (safe to re-run; same-size objects are skipped)
hkm storage:migrate --prefix=submissions   # one sub-tree
hkm storage:migrate --from=/srv/old/root   # a root other than STORAGE_ROOT
```

Local files are never deleted, so switching back to `local` remains a working
rollback. Run it once more just before setting `STORAGE_DRIVER=s3` to pick up
anything uploaded in between.

## Documentation

- [Kernel guides](https://github.com/AlfaCode-Team/hkm-kernel/tree/main/docs/guides) — the framework contracts this plugin builds on.

## License

MIT — see [LICENSE](LICENSE).
