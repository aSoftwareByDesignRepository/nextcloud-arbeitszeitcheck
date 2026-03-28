# Release folder

This directory holds **release documentation** and optional **checksum list files** for published versions.

## Full workflow (Nextcloud App Store)

See **[APPSTORE-RELEASE.md](./APPSTORE-RELEASE.md)** — build tarball, SHA-256/512, OpenSSL signature, what to paste in the store, **GitHub Release on the public app repo** (`aSoftwareByDesignRepository/nextcloud-arbeitszeitcheck`, not the private monorepo — see `ready2publish/REPOSITORY-LAYOUT.md`), and a **gitignore checklist** (what must not be committed).

## Files in this folder

| File | Purpose |
|------|---------|
| `APPSTORE-RELEASE.md` | Step-by-step app store upload workflow |
| `CHECKSUMS-X.Y.Z.txt` | Optional: SHA-256 / SHA-512 for the matching tarball |
| `GITHUB_RELEASE_NOTES_*.md` | Optional: copy-paste for GitHub releases |

**Generated** (not committed — see root `.gitignore`):

- `arbeitszeitcheck-X.Y.Z.tar.gz`
- `arbeitszeitcheck-X.Y.Z.tar.gz.asc` (optional GPG)
- `SIGNATURE-*.txt` / `APPSTORE-SIGNATURE*.txt` / `*.b64` if you save signature output locally

## One-liner: build tarball (example version `1.1.6`)

```bash
cd apps
VERSION=1.1.6
tar --exclude='arbeitszeitcheck/node_modules' \
    --exclude='arbeitszeitcheck/node_modules.broken-*' \
    --exclude='arbeitszeitcheck/test-results' \
    --exclude='arbeitszeitcheck/.git' \
    --exclude='arbeitszeitcheck/scripts' \
    --exclude='arbeitszeitcheck/release/arbeitszeitcheck-*.tar.gz' \
    -czf "arbeitszeitcheck/release/arbeitszeitcheck-${VERSION}.tar.gz" arbeitszeitcheck
```

Details and signing commands: **`APPSTORE-RELEASE.md`**.
