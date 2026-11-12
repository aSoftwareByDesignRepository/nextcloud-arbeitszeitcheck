#!/usr/bin/env bash
# Mutation gauntlet: manual-entry rest-period rejections must carry
# error_code + error_details so clients can localise the block.
set -euo pipefail

APP_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
CONTROLLER="$APP_ROOT/lib/Controller/TimeEntryController.php"
BACKUP_DIR="$APP_ROOT/tests/Mutation/.originals"
mkdir -p "$BACKUP_DIR"

CONTAINER="${NEXTCLOUD_DOCKER_CONTAINER:-nextcloud-app}"
PHP_FILTER='TimeEntryControllerTest::testStoreRestPeriodBlockReturnsErrorCodeAndDetails'

restore_all() {
	local bak="$BACKUP_DIR/TimeEntryController.php.rest-envelope.bak"
	if [[ -f "$bak" ]]; then
		mv -f "$bak" "$CONTROLLER"
	fi
}
trap restore_all EXIT

run_phpunit() {
	local phpunit="./vendor/bin/phpunit"
	if ! docker exec -w "/var/www/html/custom_apps/arbeitszeitcheck" "$CONTAINER" test -x ./vendor/bin/phpunit; then
		phpunit="/var/www/html/custom_apps/snackcheck/vendor/bin/phpunit"
	fi
	docker exec -u www-data -w "/var/www/html/custom_apps/arbeitszeitcheck" "$CONTAINER" \
		php -d opcache.enable_cli=0 "$phpunit" --filter "$PHP_FILTER"
}

kill_or_die() {
	local name="$1"
	local from="$2"
	local to="$3"
	local bak="$BACKUP_DIR/TimeEntryController.php.rest-envelope.bak"
	cp "$CONTROLLER" "$bak"
	python3 -c 'import pathlib,sys; p=pathlib.Path(sys.argv[1]); fr=sys.argv[2]; to=sys.argv[3]; t=p.read_text();
assert fr in t, repr(fr[:100]); p.write_text(t.replace(fr,to,1))' "$CONTROLLER" "$from" "$to"
	echo "== mutation: $name =="
	set +e
	run_phpunit
	code=$?
	set -e
	mv -f "$bak" "$CONTROLLER"
	if [[ $code -eq 0 ]]; then
		echo "MUTATION SURVIVED: $name" >&2
		exit 1
	fi
	echo "killed $name"
}

echo "== baseline =="
run_phpunit

kill_or_die wrong_error_code \
	"'error_code' => \\OCA\\ArbeitszeitCheck\\BusinessRuleCode::REST_PERIOD_REQUIRED," \
	"'error_code' => 'compliance_blocked',"

kill_or_die drop_error_details \
	"'error_details' => is_array(\$restPeriodCheck['details'] ?? null) ? \$restPeriodCheck['details'] : []," \
	"'error_details' => [],"

echo "All rest-period-envelope mutations killed."
