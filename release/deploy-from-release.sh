#!/usr/bin/env bash
set -euo pipefail

APP_ID="arbeitszeitcheck"
ARCHIVE_PATH=""
TARGET_APPS_DIR=""
OCC_PATH=""
RUN_INTEGRITY_CHECK=1
TOGGLE_APP=1
KEEP_BACKUP=0
ALLOW_NO_OCC=0

usage() {
	echo "Deploy a signed Nextcloud app release tarball safely."
	echo
	echo "Usage:"
	echo "  $0 --archive <path/to/${APP_ID}-X.Y.Z.tar.gz> --target-apps-dir <path/to/custom_apps> [options]"
	echo
	echo "Options:"
	echo "  --app-id <id>             App id (default: ${APP_ID})"
	echo "  --occ <path>              Path to Nextcloud occ file (required by default)"
	echo "  --allow-no-occ            Allow deployment without --occ (not recommended)"
	echo "  --no-integrity-check      Skip 'occ integrity:check-app' after deploy"
	echo "  --no-toggle               Do not disable/enable app during deployment"
	echo "  --keep-backup             Keep backup copy (default: remove after success)"
	echo "  -h, --help                Show this help"
}

while [[ $# -gt 0 ]]; do
	case "$1" in
		--archive)
			ARCHIVE_PATH="${2:-}"
			shift 2
			;;
		--target-apps-dir)
			TARGET_APPS_DIR="${2:-}"
			shift 2
			;;
		--app-id)
			APP_ID="${2:-}"
			shift 2
			;;
		--occ)
			OCC_PATH="${2:-}"
			shift 2
			;;
		--no-integrity-check)
			RUN_INTEGRITY_CHECK=0
			shift
			;;
		--no-toggle)
			TOGGLE_APP=0
			shift
			;;
		--allow-no-occ)
			ALLOW_NO_OCC=1
			shift
			;;
		--keep-backup)
			KEEP_BACKUP=1
			shift
			;;
		-h|--help)
			usage
			exit 0
			;;
		*)
			echo "Unknown argument: $1" >&2
			usage
			exit 1
			;;
	esac
done

if [[ -z "${ARCHIVE_PATH}" || -z "${TARGET_APPS_DIR}" ]]; then
	echo "Error: --archive and --target-apps-dir are required." >&2
	usage
	exit 1
fi

if [[ ! -f "${ARCHIVE_PATH}" ]]; then
	echo "Error: archive not found: ${ARCHIVE_PATH}" >&2
	exit 1
fi

if [[ ! -d "${TARGET_APPS_DIR}" ]]; then
	echo "Error: target apps directory not found: ${TARGET_APPS_DIR}" >&2
	exit 1
fi

if [[ -n "${OCC_PATH}" && ! -f "${OCC_PATH}" ]]; then
	echo "Error: occ not found: ${OCC_PATH}" >&2
	exit 1
fi

if [[ -z "${OCC_PATH}" && "${ALLOW_NO_OCC}" -ne 1 ]]; then
	echo "Error: --occ is required for safe deployment. Use --allow-no-occ only for exceptional/manual recovery scenarios." >&2
	exit 1
fi

if ! tar -tzf "${ARCHIVE_PATH}" | grep -Eq "^${APP_ID}/appinfo/info.xml$"; then
	echo "Error: archive does not contain expected app root '${APP_ID}/' with appinfo/info.xml" >&2
	exit 1
fi

# Dev/QA artifact paths that must never ship inside a release tarball.
# This denylist mirrors the pack step's --exclude set (Makefile) — the
# deploy-time scan below fails closed when a wrongly-built archive leaks
# development files instead of silently installing them.
DEV_ARTIFACT_EXCLUDES=(
	'.git' '.github' '.auth' '.vscode' '.idea'
	'build' 'docs' 'documentation' 'e2e' 'scripts' 'tests' 'tools'
	'test-results' 'playwright-report' 'blob-report' 'qa-report' 'atlas'
	'node_modules' 'node_modules.broken-*'
	'.phpunit.result.cache' '.DS_Store'
	'Makefile' 'composer.lock' 'package.json' 'package-lock.json'
	'phpunit.xml' 'phpunit.cov.xml' 'playwright.config.js' 'playwright.config.ts'
	'vitest.config.js' 'vitest.config.ts' 'infection.json5' 'infection-security.json5'
	'*.tar.gz' '*.tgz'
)

# ERE alternation over the denylist ('*' entries become '.*').
deny_ere="$(printf '%s\n' "${DEV_ARTIFACT_EXCLUDES[@]}" \
	| sed -e 's/[][\.^$*]/\\&/g' -e 's/\\\*/.*/g' \
	| paste -sd'|' -)"
# Top-level app members matching the denylist (vendor/ internals exempt —
# dependency packages own their trees).
TOP_DENY_RE="^${APP_ID}/(${deny_ere})(/|$)"
# Never legitimate in ANY position (dev caches, QA captures, audit docs).
ANYWHERE_DENY_RE='\.phpunit\.result\.cache|\.DS_Store|ATLAS-[^/]*\.md|qa-report/|\.(trace\.zip|webm)$'
# Forbidden in the app's own surface but permissible inside vendor/.
NONVENDOR_DENY_RE='atlas(/|-)[^/]*|[^/]*\.(tar\.gz|tgz)$'

archive_members="$(tar -tzf "${ARCHIVE_PATH}")"

forbidden_members="$(
	printf '%s\n' "${archive_members}" \
		| awk -v app="${APP_ID}" -v top="${TOP_DENY_RE}" -v any="${ANYWHERE_DENY_RE}" -v nv="${NONVENDOR_DENY_RE}" '
			/^'"${APP_ID}"'\/vendor\// { if ($0 ~ any) print; next }
			{ if ($0 ~ top || $0 ~ any || $0 ~ nv) print }
		'
)"

if [[ -n "${forbidden_members}" ]]; then
	echo "Error: archive contains forbidden development paths:" >&2
	printf '%s\n' "${forbidden_members}" >&2
	exit 1
fi

if ! printf '%s\n' "${archive_members}" | grep -Eq "^${APP_ID}/appinfo/signature.json$"; then
	echo "Error: archive is missing appinfo/signature.json (unsigned release)." >&2
	exit 1
fi

if ! tar -xOf "${ARCHIVE_PATH}" "${APP_ID}/appinfo/signature.json" | grep -Eq .; then
	echo "Error: appinfo/signature.json could not be read from archive." >&2
	exit 1
fi

# Signature paths are app-root-relative — scan them against the same
# denylist (unanchored) so a signature minted over a polluted tree fails too.
if tar -xOf "${ARCHIVE_PATH}" "${APP_ID}/appinfo/signature.json" \
	| grep -Eq '"('"$deny_ere"')/|"[^"]*/('"$deny_ere"')/|'"${ANYWHERE_DENY_RE}"; then
	echo "Error: signature.json references forbidden development paths." >&2
	exit 1
fi

timestamp="$(date +%Y%m%d-%H%M%S)"
tmp_dir="$(mktemp -d)"
backup_root="${TARGET_APPS_DIR}/.deploy-backups"
backup_path="${backup_root}/${APP_ID}-${timestamp}"
target_app_path="${TARGET_APPS_DIR}/${APP_ID}"

cleanup() {
	rm -rf "${tmp_dir}"
}
trap cleanup EXIT

echo "Extracting archive..."
tar -xzf "${ARCHIVE_PATH}" -C "${tmp_dir}"

mkdir -p "${backup_root}"
if [[ -d "${target_app_path}" ]]; then
	echo "Creating backup at: ${backup_path}"
	cp -a "${target_app_path}" "${backup_path}"
fi

if [[ -n "${OCC_PATH}" && "${TOGGLE_APP}" -eq 1 ]]; then
	echo "Disabling app ${APP_ID}..."
	php "${OCC_PATH}" app:disable "${APP_ID}" >/dev/null || true
fi

echo "Deploying app files to: ${target_app_path}"
rm -rf "${target_app_path}"
cp -a "${tmp_dir}/${APP_ID}" "${target_app_path}"

if [[ -n "${OCC_PATH}" && "${TOGGLE_APP}" -eq 1 ]]; then
	echo "Enabling app ${APP_ID}..."
	php "${OCC_PATH}" app:enable "${APP_ID}" >/dev/null
fi

if [[ -n "${OCC_PATH}" && "${RUN_INTEGRITY_CHECK}" -eq 1 ]]; then
	echo "Running integrity check..."
	php "${OCC_PATH}" integrity:check-app "${APP_ID}"
fi

if [[ "${KEEP_BACKUP}" -eq 0 && -d "${backup_path}" ]]; then
	rm -rf "${backup_path}"
fi

echo
echo "Deployment complete."
if [[ -z "${OCC_PATH}" ]]; then
	echo "Tip: run these manually in Nextcloud root:"
	echo "  php occ app:disable ${APP_ID}"
	echo "  php occ app:enable ${APP_ID}"
	echo "  php occ integrity:check-app ${APP_ID}"
fi
