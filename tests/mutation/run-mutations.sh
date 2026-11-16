#!/usr/bin/env bash
# Targeted mutation gate for the split-service accounting engine (spec §10.2).
#
# For each named mutant we patch ONE exact expression in the source, run the
# relevant PHPUnit filter inside the Nextcloud container and require that the
# suite FAILS (mutant killed). A mutant that leaves the suite green has
# SURVIVED — the tests have a blind spot and this script exits non-zero.
#
# Usage (from nextcloud/):
#   ../apps/arbeitszeitcheck/tests/mutation/run-mutations.sh
#   ../apps/arbeitszeitcheck/tests/mutation/run-mutations.sh cutoff-gte floor-gt
#
# Mutants are exact-string Python replacements applied to a backup copy; the
# original file is always restored (even on failure) via trap.

set -euo pipefail

APP_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
NC_DIR="$(cd "$APP_DIR/../.." && pwd)"
PHPUNIT="$NC_DIR/docker/run-app-phpunit.sh"

if [[ ! -x "$PHPUNIT" ]]; then
	echo "phpunit helper not found at $PHPUNIT" >&2
	exit 2
fi

# name|relative file|test filter|python expression: ORIG@@REPL (exact, must occur once)
MUTANTS=(
	"cutoff-gte|lib/Support/SplitServiceAccounting.php|SplitServiceAccountingTest|self::localSecondsOfDay(\$tz, \$e) > \$cfg['cutoffLsod']@@self::localSecondsOfDay(\$tz, \$e) >= \$cfg['cutoffLsod']"
	"gapstart-strict-lt|lib/Support/SplitServiceAccounting.php|SplitServiceAccountingTest|if (\$startTs <= \$g[0]) {@@if (\$startTs < \$g[0]) {"
	"gapend-strict-gt|lib/Support/SplitServiceAccounting.php|SplitServiceAccountingTest|if (\$e >= \$maxGapEnd) {@@if (\$e > \$maxGapEnd) {"
	"floor-strict-gt|lib/Support/SplitServiceAccounting.php|SplitServiceAccountingTest|if (\$m >= \$floor) {@@if (\$m > \$floor) {"
	"stretch-gte|lib/Support/SplitServiceAccounting.php|SplitServiceAccountingTest|if ((int)\$stretch > \$maxStretchSeconds) {@@if ((int)\$stretch >= \$maxStretchSeconds) {"
	"requirement-gate-drop|lib/Support/SplitServiceAccounting.php|SplitServiceAccountingTest|if (!BreakSplitValidator::meetsRequirement(\$portions, \$requiredBreakMinutes, \$allowedPatterns)) {@@if (false) {"
	"schedule-cutoff-le|lib/Support/WeekdaySchedule.php|WeekdayScheduleTest|\$splitInfo['cutoffMinutes'] < \$latestGapEnd@@\$splitInfo['cutoffMinutes'] <= \$latestGapEnd"
	"block-touching-drop|lib/Support/WeekdaySchedule.php|WeekdayScheduleTest|\$errors[] = 'SCHEDULE_BLOCK_TOUCHING';@@\$errors[] = 'SCHEDULE_BLOCKS_OVERLAP';"
	"calculator-x-kept|lib/Service/DailyWorkingHoursCalculator.php|DailyWorkingHoursCalculatorTest|if (\$split !== null && \$split['excluded'] !== []) {@@if (false) {"
	"entity-x-included|lib/Db/TimeEntry.php|TimeEntryTest|return max(0, \$totalDuration - \$excludedDuration - \$effectiveBreakDuration);@@return max(0, \$totalDuration - \$effectiveBreakDuration);"
)

run_filter() {
	# Args: filter
	"$PHPUNIT" arbeitszeitcheck --filter "$1" 2>&1 | tail -8
}

patch_file() {
	# Args: file orig repl — apply exact replacement (must match once), die otherwise
	python3 - "$1" "$2" "$3" <<'PYEOF'
import sys
path, orig, repl = sys.argv[1], sys.argv[2], sys.argv[3]
src = open(path).read()
n = src.count(orig)
if n != 1:
    print(f"MUTANT-APPLY-FAILED {path}: pattern occurs {n}x", file=sys.stderr)
    sys.exit(2)
open(path, 'w').write(src.replace(orig, repl))
PYEOF
}

selected=("$@")
declare -a results=()
survivors=0
applied_fail=0

for entry in "${MUTANTS[@]}"; do
	name="${entry%%|*}"
	rest="${entry#*|}"
	file="${rest%%|*}"
	rest="${rest#*|}"
	filter="${rest%%|*}"
	pair="${rest#*|}"
	orig="${pair%%@@*}"
	repl="${pair#*@@}"

	if [[ ${#selected[@]} -gt 0 ]]; then
		skip=1
		for sel in "${selected[@]}"; do [[ "$sel" == "$name" ]] && skip=0; done
		[[ $skip -eq 1 ]] && continue
	fi

	full="$APP_DIR/$file"
	[[ -f "$full" ]] || { echo "MISSING FILE $full"; exit 2; }
	backup="$(mktemp)"
	cp "$full" "$backup"
	# shellcheck disable=SC2064
	trap "cp '$backup' '$full'; rm -f '$backup'" EXIT

	if ! patch_file "$full" "$orig" "$repl"; then
		results+=("APPLY-FAIL  $name")
		applied_fail=1
		cp "$backup" "$full"; rm -f "$backup"; trap - EXIT
		continue
	fi

	echo "=== mutant: $name  ($file)  filter=$filter"
	out="$(run_filter "$filter" || true)"
	if echo "$out" | grep -qE "^OK \(|^OK,"; then
		results+=("SURVIVED    $name   <-- tests did NOT catch it")
		survivors=$((survivors + 1))
	elif echo "$out" | grep -qE "FAILURES|Errors|Failed asserting|was expected"; then
		results+=("KILLED      $name")
	else
		# Ambiguous output — treat as killed only if a failure marker exists.
		if echo "$out" | grep -qiE "fail"; then
			results+=("KILLED      $name")
		else
			results+=("UNKNOWN     $name   (unparseable output, inspect manually)")
			survivors=$((survivors + 1))
		fi
	fi

	cp "$backup" "$full"; rm -f "$backup"; trap - EXIT
done

echo
echo "================ mutation results ================"
printf '%s\n' "${results[@]}"
echo "=================================================="

if [[ $applied_fail -eq 1 ]]; then
	echo "Some mutants could not be applied (source drift?)" >&2
	exit 2
fi
if [[ $survivors -gt 0 ]]; then
	echo "$survivors mutant(s) SURVIVED/UNKNOWN — test suite has blind spots." >&2
	exit 1
fi
echo "All mutants killed."
exit 0
