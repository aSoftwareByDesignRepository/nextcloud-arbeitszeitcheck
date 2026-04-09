## Highlights

- Added a dedicated manager/admin in-app view for employee absences with secure scope filtering and localized status labels.
- Improved manager/admin UX by restructuring sidebar navigation into clearer grouped sections and reducing top-level clutter.
- Added working time model copy flow with modal UX, safer duplicate handling, and improved localization rendering.

## Security and Reliability

- Hardened absence iCal mail behavior with stricter status/date guards, recipient deduplication, and privacy-safe descriptions for substitute/manager recipients.
- Rolled back in-progress direct Nextcloud Calendar write/sync functionality; supported behavior remains optional `.ics` email attachments (no CalDAV sync).

## Documentation

- Updated EN/DE changelogs and user manuals to reflect manager UX changes and final calendar behavior.
