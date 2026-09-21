#!/usr/bin/env bash
# Targeted mutation gauntlet: admin profile year-scoped balances.
# 1) unchanged WTM assignment must still persist carryover/region/labour-law
# 2) overtime opening-balance response must echo the written year
#
# Usage (host, from app root):
#   bash tests/Mutation/run-admin-carryover-unchanged-assignment-mutations.sh
set -euo pipefail

APP_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
TARGET="$APP_ROOT/lib/Service/AdminUserProfileUpdateService.php"
BACKUP_DIR="$APP_ROOT/tests/Mutation/.originals"
mkdir -p "$BACKUP_DIR"
BAK="$BACKUP_DIR/$(basename "$TARGET").carryover-unchanged.bak"

CONTAINER="${NEXTCLOUD_DOCKER_CONTAINER:-nextcloud-app}"
PHP_FILTER_CARRY='AdminUserProfileUpdateServiceTest::testApplyWorkingTimeModelPersistsCarryoverWhenAssignmentUnchanged|AdminUserProfileUpdateServiceTest::testApplyWorkingTimeModelPersistsRegionWhenAssignmentUnchanged|AdminUserProfileUpdateServiceTest::testApplyWorkingTimeModelPersistsLaborLawWhenAssignmentUnchanged|AdminUserProfileUpdateServiceTest::testApplyWorkingTimeModelSourceDoesNotEarlyReturnBeforeSideEffects'
PHP_FILTER_OT='AdminUserProfileUpdateServiceTest::testApplyOvertimeSettingsReturnsWrittenOpeningBalanceYear'

restore() {
	if [[ -f "$BAK" ]]; then
		mv -f "$BAK" "$TARGET"
	fi
}
trap restore EXIT

run_phpunit() {
	local filter="$1"
	if ! docker container inspect "$CONTAINER" &>/dev/null; then
		echo "Docker container '$CONTAINER' is not running." >&2
		exit 1
	fi
	local phpunit="./vendor/bin/phpunit"
	if ! docker exec -w "/var/www/html/custom_apps/arbeitszeitcheck" "$CONTAINER" test -x ./vendor/bin/phpunit; then
		phpunit="/var/www/html/custom_apps/snackcheck/vendor/bin/phpunit"
	fi
	docker exec -u www-data -w "/var/www/html/custom_apps/arbeitszeitcheck" "$CONTAINER" \
		php -d opcache.enable_cli=0 "$phpunit" --filter "$filter"
}

echo "== baseline carryover =="
run_phpunit "$PHP_FILTER_CARRY"
echo "== baseline overtime year =="
run_phpunit "$PHP_FILTER_OT"

echo "== mutation: restore_early_return_before_carryover =="
cp "$TARGET" "$BAK"
python3 - "$TARGET" <<'PY'
import pathlib, sys
path = pathlib.Path(sys.argv[1])
text = path.read_text()
old = """\t\tif ($this->workingTimeModelAssignmentMatches($currentModel, $workingTimeModelId, $vacationDaysPerYear, $startDate, $endDate)) {
\t\t\t\t$updated = $currentModel;
\t\t\t} else {"""
new = """\t\tif ($this->workingTimeModelAssignmentMatches($currentModel, $workingTimeModelId, $vacationDaysPerYear, $startDate, $endDate)) {
\t\t\t\treturn [
\t\t\t\t\t'userWorkingTimeModel' => $this->presentUserModelSummary($currentModel->getSummary()),
\t\t\t\t\t'unchanged' => true,
\t\t\t\t];
\t\t\t} else {"""
if old not in text:
    raise SystemExit('anchor missing for early-return mutation')
path.write_text(text.replace(old, new, 1))
PY
set +e
run_phpunit "$PHP_FILTER_CARRY"
code=$?
set -e
mv -f "$BAK" "$TARGET"
if [[ $code -eq 0 ]]; then
	echo "MUTATION SURVIVED: restore_early_return_before_carryover" >&2
	exit 1
fi
echo "killed restore_early_return_before_carryover"

echo "== mutation: overtime_response_always_current_year =="
cp "$TARGET" "$BAK"
python3 - "$TARGET" <<'PY'
import pathlib, sys
path = pathlib.Path(sys.argv[1])
text = path.read_text()
old = "\t\t\t$balanceYear = $year;"
new = "\t\t\t$balanceYear = (int)date('Y');"
if old not in text:
    raise SystemExit('anchor missing for overtime balanceYear mutation')
path.write_text(text.replace(old, new, 1))
PY
set +e
run_phpunit "$PHP_FILTER_OT"
code=$?
set -e
mv -f "$BAK" "$TARGET"
if [[ $code -eq 0 ]]; then
	echo "MUTATION SURVIVED: overtime_response_always_current_year" >&2
	exit 1
fi
echo "killed overtime_response_always_current_year"

echo "All admin year-scoped balance mutations killed."
