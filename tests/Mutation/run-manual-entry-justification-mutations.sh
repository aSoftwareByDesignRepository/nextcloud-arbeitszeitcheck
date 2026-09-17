#!/usr/bin/env bash
# Targeted mutation gauntlet: create-form must expose + submit justification
# when four-eyes manual approval is enabled.
#
# Usage (host, from app root):
#   bash tests/Mutation/run-manual-entry-justification-mutations.sh
set -euo pipefail

APP_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
TEMPLATE="$APP_ROOT/templates/time-entries.php"
FORM_JS="$APP_ROOT/js/time-entry-form.js"
CONTROLLER="$APP_ROOT/lib/Controller/TimeEntryController.php"
BACKUP_DIR="$APP_ROOT/tests/Mutation/.originals"
mkdir -p "$BACKUP_DIR"

CONTAINER="${NEXTCLOUD_DOCKER_CONTAINER:-nextcloud-app}"
PHP_FILTER='TimeEntryFormUxContractTest::testManualCreateShowsJustificationWhenApprovalRequired|TimeEntryControllerTest::testApiStoreStartEndRequiresJustificationWhenFourEyesEnabled|TimeEntryControllerTest::testApiStoreStartEndCreatesPendingWhenFourEyesAndManagerExist'

restore_all() {
	for f in "$TEMPLATE" "$FORM_JS" "$CONTROLLER"; do
		local bak="$BACKUP_DIR/$(basename "$f").manual-justification.bak"
		if [[ -f "$bak" ]]; then
			mv -f "$bak" "$f"
		fi
	done
}
trap restore_all EXIT

run_phpunit() {
	if ! docker container inspect "$CONTAINER" &>/dev/null; then
		echo "Docker container '$CONTAINER' is not running." >&2
		exit 1
	fi
	local phpunit="./vendor/bin/phpunit"
	if ! docker exec -w "/var/www/html/custom_apps/arbeitszeitcheck" "$CONTAINER" test -x ./vendor/bin/phpunit; then
		phpunit="/var/www/html/custom_apps/snackcheck/vendor/bin/phpunit"
	fi
	docker exec -u www-data -w "/var/www/html/custom_apps/arbeitszeitcheck" "$CONTAINER" \
		php -d opcache.enable_cli=0 "$phpunit" --filter "$PHP_FILTER"
}

run_vitest() {
	(cd "$APP_ROOT" && npx vitest run --reporter=dot js/time-entry-form.justification.test.js)
}

kill_or_die_replace() {
	local name="$1"
	local target="$2"
	local from="$3"
	local to="$4"
	local bak="$BACKUP_DIR/$(basename "$target").manual-justification.bak"
	cp "$target" "$bak"
	python3 - "$target" "$from" "$to" <<'PY'
import pathlib, sys
path, fr, to = sys.argv[1], sys.argv[2], sys.argv[3]
text = pathlib.Path(path).read_text()
if fr not in text:
    raise SystemExit(f'anchor missing for mutation: {fr[:80]!r}')
pathlib.Path(path).write_text(text.replace(fr, to, 1))
PY
	echo "== mutation: $name =="
	set +e
	if [[ "$target" == *.js ]]; then
		run_vitest
		code=$?
	else
		run_phpunit
		code=$?
	fi
	set -e
	mv -f "$bak" "$target"
	if [[ $code -eq 0 ]]; then
		echo "MUTATION SURVIVED: $name" >&2
		exit 1
	fi
	echo "killed $name"
}

echo "== baseline phpunit =="
run_phpunit
echo "== baseline vitest =="
run_vitest

kill_or_die_replace drop_justification_field_id \
	"$TEMPLATE" \
	'id="entry-justification"' \
	'id="entry-justification-MISSING"'

kill_or_die_replace drop_js_justification_payload \
	"$FORM_JS" \
	'data.justification = this.getJustificationValue();' \
	'/* mutated: omit justification from create payload */'

# Mutate only the apiStore (start/end) floor — second mb_strlen check.
echo "== mutation: skip_server_justification_floor_apistore =="
cp "$CONTROLLER" "$BACKUP_DIR/$(basename "$CONTROLLER").manual-justification.bak"
python3 - "$CONTROLLER" <<'PY'
import pathlib, sys
p = pathlib.Path(sys.argv[1])
t = p.read_text()
old = "if (mb_strlen($justificationText) < 10) {"
i = t.find(old)
if i < 0:
    raise SystemExit("first floor missing")
j = t.find(old, i + 1)
if j < 0:
    raise SystemExit("apiStore floor missing")
new = "if (false && mb_strlen($justificationText) < 10) {"
p.write_text(t[:j] + new + t[j + len(old):])
PY
set +e
run_phpunit
code=$?
set -e
mv -f "$BACKUP_DIR/$(basename "$CONTROLLER").manual-justification.bak" "$CONTROLLER"
if [[ $code -eq 0 ]]; then
	echo "MUTATION SURVIVED: skip_server_justification_floor_apistore" >&2
	exit 1
fi
echo "killed skip_server_justification_floor_apistore"

echo "All manual-entry-justification mutations killed."
