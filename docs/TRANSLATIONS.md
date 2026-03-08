# ArbeitszeitCheck – Translation Notes

## Overview

The app uses Nextcloud's translation system:

- **PHP:** `$l->t('Key')` or `$this->l10n->t('Key', [$param])`
- **JavaScript:** `window.t('arbeitszeitcheck', 'Key')` or `t(key, fallback)` (manager-dashboard, substitution-requests)
- **l10n files:** `l10n/en.json`, `l10n/de.json` – key = English source string

`Util::addTranslations('arbeitszeitcheck')` must be called on pages that use JS translations (ManagerController, PageController, etc.).

## Keys Added (Translation Audit Fix)

The following keys were added to both `en.json` and `de.json` to fix missing translations:

### Compliance & ArbZG
- `CRITICAL: Maximum daily working hours (10h) exceeded! Automatically clocking out...`
- `Note: You are approaching the maximum working hours...`
- `Maximum daily working hours already reached`
- `This time entry would exceed the maximum daily working hours...`
- `Mandatory 30-minute break missing after 6 hours of work`
- `Mandatory 45-minute break missing after 9 hours of work`
- `Working hours exceeded 10 hours in a single day`
- `Daily working hours limit reached (10 hours maximum)`
- `Weekly working hours average limit (48 hours) exceeded`
- `Weekly working hours average limit (48 hours) exceeded over the last 6 months`
- `Minimum 11-hour rest period required between shifts (ArbZG §5)`
- `Night work detected: %.2f hours between 11 PM and 6 AM`
- `Work performed on Sunday`
- `Work performed on public holiday`
- `Cannot resume: Maximum daily working hours...`
- `Cannot clock in: Maximum daily working hours...`

### Manager Dashboard / Team
- `Actions for unit`, `Clocked In`, `Current Session:`, `Delete unit`, `Edit unit`
- `Total absences: %s`, `Total Entries`, `Total time entries: %s`
- `Generate and export working time reports`
- `Manage your personal preferences and working time settings`
- `Overview of your working time and status`
- `View your working time history in chronological order`
- `days`, `Original:`, `Proposed:`, `Time entry correction`
- `Optional reason for rejection (leave empty for none):`
- `Reason for rejection (optional)`, `Enter reason for rejection...`
- `Confirm rejection`, `Reject Request`
- `Optional reason for declining (leave empty for none):`
- `Reason for declining (optional)`, `Enter reason for declining...`
- `Confirm decline`, `Decline substitution`, `asks you to cover`
- `Unable to load compliance data.`, `Error loading team compliance.`
- `Compliant`, `Warnings`, `Critical Violations`, `Total Violations`
- `Some team members have compliance issues...`
- `All team members are compliant.`, `No team members.`

### Admin / Settings
- `Onboarding status will be saved after database migration`
- `Onboarding status updated`
- `Team name is required`, `A team cannot be its own parent`
- `Team not found`, `User is required`, `User not found`

### Absences / Forms
- `Submitting...`, `Failed to submit absence request`

### Time Entry Forms (de-only → en)
- `Optional: Record your break times...`
- `Optional: When did your break end?`, `Optional: When did your break start?`
- `Select the day you worked...`
- `What time did you finish working?`, `What time did you start working?`
- `When enabled, breaks required by German law...`

## Consistency

- **Loading:** Use `Loading…` (Unicode ellipsis U+2026) in templates for consistency with l10n keys.
- **Placeholders:** Use `%s`, `%.1f`, `%.2f` as in PHP – keys must match exactly.

## Regenerating l10n/js

Nextcloud may generate `l10n/en.js`, `l10n/de.js` from the JSON files. If these exist, regenerate them after adding keys (e.g. via Nextcloud's `occ` or build process).
