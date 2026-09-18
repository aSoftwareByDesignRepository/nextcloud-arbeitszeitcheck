#!/usr/bin/env bash
# Targeted mutation gauntlet: pending time-entry approval cards must read
# nested summary and distinguish manual_create from corrections.
#
# Usage (host, from app root):
#   bash tests/Mutation/run-pending-time-entry-approval-card-mutations.sh
set -euo pipefail

APP_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
CARD_JS="$APP_ROOT/js/manager-pending-time-entry-card.js"
DASH_JS="$APP_ROOT/js/manager-dashboard.js"
CONTROLLER="$APP_ROOT/lib/Controller/ManagerController.php"
BACKUP_DIR="$APP_ROOT/tests/Mutation/.originals"
mkdir -p "$BACKUP_DIR"

CONTAINER="${NEXTCLOUD_DOCKER_CONTAINER:-nextcloud-app}"
PHP_FILTER='ManagerControllerTest::testGetPendingApprovalsExposesManualCreateRequestType|ManagerControllerTest::testGetPendingApprovalsReturnsBothTypes'

restore_all() {
	for f in "$CARD_JS" "$DASH_JS" "$CONTROLLER"; do
		local bak="$BACKUP_DIR/$(basename "$f").pending-te-card.bak"
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
	(cd "$APP_ROOT" && npx vitest run --reporter=dot js/manager-pending-time-entry-card.test.js)
}

kill_or_die_replace() {
	local name="$1"
	local target="$2"
	local from="$3"
	local to="$4"
	local bak="$BACKUP_DIR/$(basename "$target").pending-te-card.bak"
	cp "$target" "$bak"
	python3 -c 'import pathlib,sys; p=pathlib.Path(sys.argv[1]); fr=sys.argv[2]; to=sys.argv[3]; t=p.read_text();
assert fr in t, fr[:80]; p.write_text(t.replace(fr,to,1))' "$target" "$from" "$to"
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

kill_or_die_replace drop_summary_parse \
	"$CARD_JS" \
	'const summary = parseSummary(item.summary);' \
	'const summary = {}; /* mutated: ignore nested summary */'

kill_or_die_replace drop_manual_create_label \
	"$CARD_JS" \
	"? t('New manual time entry', 'New manual time entry')" \
	"? t('Time entry correction', 'Time entry correction')"

kill_or_die_replace drop_dashboard_delegate \
	"$DASH_JS" \
	'return Card.renderTimeEntryApprovalCardHtml(item, {' \
	'return ""; /* mutated: skip card render */ void ({'

kill_or_die_replace drop_api_request_type \
	"$CONTROLLER" \
	"'requestType' => \$requestType," \
	'/* mutated requestType omitted */'

echo "All pending-time-entry-approval-card mutations killed."
