# Nextcloud App Store Publishing Guide

This document describes how to publish ArbeitszeitCheck to the Nextcloud App Store.

## Prerequisites

### 1. Obtain an App Signing Certificate

Code signing is **required** for all apps on [apps.nextcloud.com](https://apps.nextcloud.com). Steps:

1. **Generate private key and CSR** (replace paths if needed):

   ```bash
   mkdir -p ~/.nextcloud/certificates
   cd ~/.nextcloud/certificates
   openssl req -nodes -newkey rsa:4096 \
     -keyout arbeitszeitcheck.key \
     -out arbeitszeitcheck.csr \
     -subj "/CN=arbeitszeitcheck"
   ```

   ⚠️ **Keep `arbeitszeitcheck.key` secret.** Never commit or share it.

2. **Submit a Pull Request** to [github.com/nextcloud/app-certificate-requests](https://github.com/nextcloud/app-certificate-requests):
   - Click **"Create new file"**
   - File path: `arbeitszeitcheck/arbeitszeitcheck.csr`
   - Paste the **contents** of `arbeitszeitcheck.csr` (the `-----BEGIN CERTIFICATE REQUEST-----` … `-----END CERTIFICATE REQUEST-----` block)
   - In the PR description, add a link to your app source:  
     `https://github.com/aSoftwareByDesignRepository/ArbeitszeitCheck`
   - Submit the PR

3. **Wait for approval.** Nextcloud maintainers will review and merge. They will add a signed certificate file to the PR (e.g. `arbeitszeitcheck/arbeitszeitcheck.crt`).

4. **Save the certificate** to `~/.nextcloud/certificates/arbeitszeitcheck.crt` (copy the contents from the merged PR).

### 2. Register the App

Register the app on [apps.nextcloud.com/developer/apps/new](https://apps.nextcloud.com/developer/apps/new) using your certificate.

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

## Screenshots

Screenshots are stored in the **`screenshots/`** directory at the app root. The app ships with `screenshots/dashboard.png`; you can add more.

To add screenshots:

1. Place PNG files in `screenshots/` (e.g. `screenshots/time-entries.png`, `screenshots/compliance.png`).
2. Add them in `appinfo/info.xml` inside the `<screenshots>` block:

   ```xml
   <screenshots>
       <screenshot>screenshots/dashboard.png</screenshot>
       <screenshot>screenshots/time-entries.png</screenshot>
   </screenshots>
   ```

3. Recommended: at least 1280×720 px, PNG format, showing the app in use. See `screenshots/README.md` for details.

## Tests

| Test | Command | Notes |
|------|---------|-------|
| PHP unit/integration | `composer test` | Requires a full Nextcloud install (app at `nextcloud/apps/arbeitszeitcheck`). Run from within your Nextcloud dev environment or via CI against a checkout. |
| PHP syntax | `composer lint` | Runs without Nextcloud. |
| JavaScript lint | `npm run lint` | ESLint. |
| JS “tests” | `npm test` | Currently a placeholder (exit 0). |
| Accessibility | `npm run test:a11y` | Currently a placeholder (exit 0). |

**PHP tests:** The bootstrap loads Nextcloud core (`lib/base.php`). For the standalone repo, run tests inside a Nextcloud checkout where this app is at `apps/arbeitszeitcheck`, or use a CI setup that provides it. GitHub Actions in this repo will need such an environment to run PHP tests.

## Before Submitting

- [ ] Replace placeholder `screenshots/dashboard.png` with real screenshots if needed (see `screenshots/README.md`)
- [x] `appinfo/info.xml` author set: Alexander Mäule <info@software-by-design.de>
- [ ] Ensure PHP tests pass in a Nextcloud environment: `composer test`
- [ ] Run `occ integrity:check-app arbeitszeitcheck` to verify signing (from Nextcloud root after signing)

## Store Requirements Checklist

- [x] LICENSE file (AGPL-3.0)
- [x] CHANGELOG.md
- [x] README.md with installation instructions
- [x] info.xml with valid schema
- [x] Screenshot (at least one in `screenshots/`)
- [ ] App signing (obtain certificate, see above)
- [ ] Real screenshots (replace placeholder before final submission)
