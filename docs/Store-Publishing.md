# Nextcloud App Store Publishing Guide

This document describes how to publish ArbeitszeitCheck to the Nextcloud App Store.

## Prerequisites

1. **Obtain a signing certificate** from Nextcloud:
   - Create `~/.nextcloud/certificates/`
   - Generate key and CSR: `openssl req -nodes -newkey rsa:4096 -keyout arbeitszeitcheck.key -out arbeitszeitcheck.csr -subj "/CN=arbeitszeitcheck"`
   - Submit the CSR to [app-certificate-requests](https://github.com/nextcloud/app-certificate-requests) as a pull request
   - Store the signed certificate as `arbeitszeitcheck.crt` after approval

2. **Register the app** on [apps.nextcloud.com/developer](https://apps.nextcloud.com/developer/apps/new) using your certificate.

## Build and Sign

```bash
# 1. Build release archive
make release

# 2. Sign the app (requires certificate in ~/.nextcloud/certificates/)
make sign

# 3. Commit appinfo/signature.json
git add appinfo/signature.json
git commit -m "Add app signature for store release"

# 4. Rebuild release with signature included
make release
```

## Upload to App Store

1. Go to [apps.nextcloud.com/developer](https://apps.nextcloud.com/developer/apps/releases/new)
2. Upload the signed `build/release/arbeitszeitcheck.tar.gz`
3. Or use the REST API / GitHub Actions for automated publishing

## Before Submitting

- [ ] Replace placeholder `screenshots/dashboard.png` with real screenshots (see `screenshots/README.md`)
- [x] `appinfo/info.xml` author set: Alexander Mäule <info@software-by-design.de>
- [ ] Ensure all tests pass: `composer test`
- [ ] Run `occ integrity:check-app --path=./apps/arbeitszeitcheck` to verify signing

## Store Requirements Checklist

- [x] LICENSE file (AGPL-3.0)
- [x] CHANGELOG.md
- [x] README.md with installation instructions
- [x] info.xml with valid schema
- [x] Screenshot (at least one)
- [ ] App signing (obtain certificate)
- [ ] Real screenshots (replace placeholder before final submission)
