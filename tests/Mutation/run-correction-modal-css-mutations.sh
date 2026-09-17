#!/usr/bin/env bash
# Mutation gauntlet: broken employee/manager CSS selector pairing must fail
# js/time-entry-correction.css-selectors.test.js.
#
# Usage (host, from app root):
#   bash tests/Mutation/run-correction-modal-css-mutations.sh
set -euo pipefail

APP_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
TARGET="$APP_ROOT/css/time-entry-correction.css"
BACKUP="$TARGET.mutation-bak"

restore() {
	if [[ -f "$BACKUP" ]]; then
		mv -f "$BACKUP" "$TARGET"
	fi
}
trap restore EXIT

run_vitest() {
	(cd "$APP_ROOT" && npx vitest run js/time-entry-correction.css-selectors.test.js)
}

kill_or_die() {
	local name="$1"
	local from="$2"
	local to="$3"
	cp "$TARGET" "$BACKUP"
	if ! grep -Fq "$from" "$TARGET"; then
		echo "Mutation anchor not found for $name" >&2
		exit 1
	fi
	python3 - "$TARGET" "$from" "$to" <<'PY'
import pathlib, sys
path, fr, to = sys.argv[1], sys.argv[2], sys.argv[3]
text = pathlib.Path(path).read_text()
if fr not in text:
    raise SystemExit('anchor missing at apply time')
pathlib.Path(path).write_text(text.replace(fr, to, 1))
PY
	echo "== mutation: $name =="
	set +e
	run_vitest
	code=$?
	set -e
	restore
	if [[ $code -eq 0 ]]; then
		echo "MUTATION SURVIVED: $name" >&2
		exit 1
	fi
	echo "mutation killed OK: $name"
}

echo "== baseline (must pass) =="
run_vitest

# Reintroduce the exact production bug: bare employee id paired with manager descendant.
kill_or_die \
	'bare-id-with-date-flex-wrap' \
	'#time-entry-correction-modal .form-input-wrapper--date,
.azc-manager-correction-modal .form-input-wrapper--date' \
	'#time-entry-correction-modal,
.azc-manager-correction-modal .form-input-wrapper--date'

kill_or_die \
	'bare-id-with-modal-body' \
	'#time-entry-correction-modal .modal-body,
.azc-manager-correction-modal .modal-body' \
	'#time-entry-correction-modal,
.azc-manager-correction-modal .modal-body'

echo "All CSS selector mutations killed."
