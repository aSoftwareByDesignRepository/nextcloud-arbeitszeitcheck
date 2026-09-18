#!/usr/bin/env bash
# Mutation gauntlet: pending approval decisions must use status-guarded write.
set -euo pipefail

APP_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
SERVICE="$APP_ROOT/lib/Service/TimeEntryCorrectionService.php"
MAPPER="$APP_ROOT/lib/Db/TimeEntryMapper.php"
BACKUP_DIR="$APP_ROOT/tests/Mutation/.originals"
mkdir -p "$BACKUP_DIR"

CONTAINER="${NEXTCLOUD_DOCKER_CONTAINER:-nextcloud-app}"
PHP_FILTER='TimeEntryCorrectionServiceTest::testApproveThrowsWhenPendingDecisionLostRace|TimeEntryCorrectionServiceTest::testRejectThrowsWhenPendingDecisionLostRace|TimeEntryCorrectionServiceTest::testRejectManualCreateMarksRejectedNotCompleted|TimeEntryCorrectionServiceTest::testApproveManualCreateCompletesEntry|TimeEntryMapperPendingDecisionRaceContractTest|TimeEntryCorrectionPendingDecisionRaceContractTest'

restore_all() {
	for f in "$SERVICE" "$MAPPER"; do
		local bak="$BACKUP_DIR/$(basename "$f").pending-race.bak"
		if [[ -f "$bak" ]]; then
			mv -f "$bak" "$f"
		fi
	done
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
	local target="$2"
	local from="$3"
	local to="$4"
	local bak="$BACKUP_DIR/$(basename "$target").pending-race.bak"
	cp "$target" "$bak"
	python3 -c 'import pathlib,sys; p=pathlib.Path(sys.argv[1]); fr=sys.argv[2]; to=sys.argv[3]; t=p.read_text();
assert fr in t, repr(fr[:100]); p.write_text(t.replace(fr,to,1))' "$target" "$from" "$to"
	echo "== mutation: $name =="
	set +e
	run_phpunit
	code=$?
	set -e
	mv -f "$bak" "$target"
	if [[ $code -eq 0 ]]; then
		echo "MUTATION SURVIVED: $name" >&2
		exit 1
	fi
	echo "killed $name"
}

echo "== baseline =="
run_phpunit

# Skipping the lost-race throw lets concurrent losers silently "succeed".
kill_or_die skip_concurrent_throw \
	"$SERVICE" \
	'if (!$this->timeEntryMapper->updateIfPendingApproval($entry)) {' \
	'if (false && !$this->timeEntryMapper->updateIfPendingApproval($entry)) {'

# Dropping the status predicate re-introduces last-write-wins on concurrent approve/reject.
kill_or_die drop_pending_status_guard \
	"$MAPPER" \
	"\$qb->where(\$qb->expr()->eq('id', \$qb->createNamedParameter(\$id, \$idType)))
			->andWhere(\$qb->expr()->eq(
				'status',
				\$qb->createNamedParameter(TimeEntry::STATUS_PENDING_APPROVAL, IQueryBuilder::PARAM_STR)
			));" \
	"\$qb->where(\$qb->expr()->eq('id', \$qb->createNamedParameter(\$id, \$idType)));"

echo "All pending-decision race mutations killed."
