# Nextcloud App Store — release workflow (ArbeitszeitCheck)

End-to-end steps to produce the **archive**, **checksums**, and **code signature** you need at [apps.nextcloud.com](https://apps.nextcloud.com) (developer account → your app → new version).

Replace `X.Y.Z` with the real version (e.g. `1.1.6`).

---

## 0. Prerequisites

- Registered app and **developer certificate** from Nextcloud (private key on your machine).
- Default key path used below: `~/.nextcloud/certificates/arbeitszeitcheck.key` (same basename as app id).
- This monorepo: build the tarball from **`apps/`** so the archive root is `arbeitszeitcheck/`.

---

## 1. Version and changelog

1. Bump **`appinfo/info.xml`**: `<version>X.Y.Z</version>` and any required `<dependencies>` / `<nextcloud min-version="…" max-version="…"/>`.
2. Update **`CHANGELOG.md`** / **`CHANGELOG.de.md`** for `X.Y.Z`.
3. Optionally add **`release/GITHUB_RELEASE_NOTES_X.Y.Z.md`** for GitHub.

---

## 2. Build the installable `.tar.gz`

From the repo root that contains `apps/arbeitszeitcheck` (here: `nextcloud-development/apps/`; local folder name may differ):

```bash
cd apps
VERSION=X.Y.Z
tar --exclude='arbeitszeitcheck/node_modules' \
    --exclude='arbeitszeitcheck/node_modules.broken-*' \
    --exclude='arbeitszeitcheck/test-results' \
    --exclude='arbeitszeitcheck/.git' \
    --exclude='arbeitszeitcheck/scripts' \
    --exclude='arbeitszeitcheck/release/arbeitszeitcheck-*.tar.gz' \
    -czf "arbeitszeitcheck/release/arbeitszeitcheck-${VERSION}.tar.gz" arbeitszeitcheck
```

**Do not commit** the tarball (see `.gitignore`).

---

## 3. SHA-256 / SHA-512 (app store + checksum file)

```bash
cd apps/arbeitszeitcheck/release
sha256sum "arbeitszeitcheck-${VERSION}.tar.gz"
sha512sum "arbeitszeitcheck-${VERSION}.tar.gz"
```

- The app store form usually asks for **SHA-256** of the uploaded archive.
- Copy the hashes into **`release/CHECKSUMS-X.Y.Z.txt`** (template: see existing `CHECKSUMS-*.txt`). Only commit the checksums file if you want them in git for traceability; the tarball itself stays **ignored**.

---

## 4. Code signature (base64) for the app store

The store expects a **base64-encoded** RSA signature over the **exact** `.tar.gz` bytes (SHA-512 digest signed with your app certificate key).

**One line** (copy output into the store’s signature field):

```bash
openssl dgst -sha512 -sign ~/.nextcloud/certificates/arbeitszeitcheck.key \
  "arbeitszeitcheck-${VERSION}.tar.gz" | openssl base64 | tr -d '\n'
```

If you prefer wrapped output, omit `| tr -d '\n'`.

**Important:** If you change the tarball or rebuild, **regenerate** the signature. Any byte change invalidates it.

**Do not commit** the private key or ad-hoc signature dump files (see `.gitignore`).

---

## 5. Optional: detached GPG sign the archive

Not required by the app store; useful for mirrors or GitHub releases.

```bash
gpg --detach-sign --armor "arbeitszeitcheck-${VERSION}.tar.gz"
```

Produces `arbeitszeitcheck-X.Y.Z.tar.gz.asc` — **ignored** by git.

---

## 6. Upload at apps.nextcloud.com

Typical fields:

| Field | Source |
|--------|--------|
| **Archive** | `release/arbeitszeitcheck-X.Y.Z.tar.gz` |
| **SHA-256** | From `sha256sum` / `CHECKSUMS-X.Y.Z.txt` |
| **Signature** | Output of the `openssl dgst … \| openssl base64` command |
| **Changelog** | Paste from `CHANGELOG.md` (or shortened) |

Submit; fix any validation errors (wrong checksum/signature almost always means a wrong file or stale copy).

---

## 7. GitHub release — **standalone app repo** (not the monorepo)

User-facing downloads and release tags belong on **`nextcloud-arbeitszeitcheck`** (the **only** public first-party app repo — see [REPOSITORY-LAYOUT.md](../../../ready2publish/REPOSITORY-LAYOUT.md)), not on the private development monorepo.

| Repository | Role |
|------------|------|
| **This workspace** (`nextcloud-development` or e.g. `nextcloud-dev`, …) | Day-to-day development; **do not** create product releases here unless you explicitly want a monorepo release. |
| **`aSoftwareByDesignRepository/nextcloud-arbeitszeitcheck`** | **Public** ArbeitszeitCheck repo — tags, GitHub Releases, and the `.tar.gz` asset users expect. |

**Canonical GitHub repo for releases**

- `https://github.com/aSoftwareByDesignRepository/nextcloud-arbeitszeitcheck`
- Shorthand for `gh`: `--repo aSoftwareByDesignRepository/nextcloud-arbeitszeitcheck`

Always pass **`--repo aSoftwareByDesignRepository/nextcloud-arbeitszeitcheck`** (or set `GH_REPO` once) so `gh` never targets your monorepo remote by mistake.

```bash
# Optional: default for this shell session
export GH_REPO=aSoftwareByDesignRepository/nextcloud-arbeitszeitcheck
```

Build the tarball **here** (monorepo `apps/`), then point `gh` at the file with an absolute or correct relative path.

### Create a new GitHub Release (tag + notes + asset)

From `apps/arbeitszeitcheck/release` after building `arbeitszeitcheck-${VERSION}.tar.gz`:

```bash
VERSION=X.Y.Z
cd /path/to/nextcloud-development/apps/arbeitszeitcheck/release

gh release create "v${VERSION}" \
  --repo aSoftwareByDesignRepository/nextcloud-arbeitszeitcheck \
  --title "v${VERSION}" \
  --notes-file "GITHUB_RELEASE_NOTES_${VERSION}.md" \
  "arbeitszeitcheck-${VERSION}.tar.gz"
```

If the release **already exists** and you only need to **replace the asset** (same version, new tarball):

```bash
gh release upload "v${VERSION}" "arbeitszeitcheck-${VERSION}.tar.gz" \
  --repo aSoftwareByDesignRepository/nextcloud-arbeitszeitcheck \
  --clobber
```

### Source code on GitHub

Publishing the **tarball** does not push git history. If you also publish app sources to that repo (e.g. `git subtree split` / manual sync), do that in your usual way **before or after** the release; the commands above only attach the built archive to **`nextcloud-arbeitszeitcheck`**, not to the monorepo.

---


## What is committed vs ignored

| Artifact | Committed? |
|----------|------------|
| `README.md`, `APPSTORE-RELEASE.md`, `GITHUB_RELEASE_NOTES_*.md` | Yes (workflow + notes) |
| `CHECKSUMS-X.Y.Z.txt` | Optional (recommended for your team) |
| `*.tar.gz`, `*.tar.gz.asc` | **No** (gitignored) |
| `SIGNATURE-*.txt` or local signature dumps | **No** (gitignored) |
| Private key `*.key` | **Never** in the repo |

---

## Quick checklist

- [ ] `info.xml` version = `X.Y.Z`
- [ ] Changelog updated
- [ ] Tarball built with excludes above
- [ ] SHA-256 + SHA-512 recorded; store gets **SHA-256**
- [ ] OpenSSL base64 signature **from the same tarball file**
- [ ] Nothing uploaded to git except docs/checksums (no `.tar.gz`, no keys)
- [ ] GitHub Release (if used): **`gh` with `--repo aSoftwareByDesignRepository/nextcloud-arbeitszeitcheck`**, not the monorepo
