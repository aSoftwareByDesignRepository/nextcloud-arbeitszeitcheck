## ArbeitszeitCheck 1.1.6 (2026-03-27)

### Added
- **Development tooling**: `occ arbeitszeitcheck:generate-test-data` CLI for deterministic demo data (time entries, absences, optional violations, demo app team).

### Fixed
- **Reports UI**: Report type cards are no longer incorrectly disabled when a team-related scope is selected.
- **Reports (tests)**: Team report CSV download test uses `DataDownloadResponse::render()`
- **Team reports**: Deduplicate user IDs before permission checks and aggregation.
- **Absence type badges**: Theme-safe contrast for vacation / sick / home office / other.

### Changed
- **Compatibility (dev)**: Local stacks aligned with Nextcloud 33.x (e.g. official `nextcloud` Docker image).
- **Reports layout**: Reverted an overly aggressive full-width parameter form rule.

---

**Full changelog:** see `CHANGELOG.md` / `CHANGELOG.de.md`.

**App store upload:** use the SHA-256 from `release/CHECKSUMS-1.1.6.txt` (or verify with `sha256sum` locally).

**GPG signature:** not generated in CI here (no secret key). On your machine:

```bash
gpg --detach-sign --armor arbeitszeitcheck-1.1.6.tar.gz
```

Attach `arbeitszeitcheck-1.1.6.tar.gz` and optionally `arbeitszeitcheck-1.1.6.tar.gz.asc` to the GitHub release.
