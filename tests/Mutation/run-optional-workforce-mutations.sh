#!/usr/bin/env bash
# Targeted mutation gauntlet for the optional-workforce feature surface
# (hourly absences, completed-absence corrections, financial revisions,
# year-end reset). Every injected mutant MUST fail the focused suite —
# a survivor means the tests do not actually pin the behaviour.
#
# Usage (host, from app root):
#   bash tests/Mutation/run-optional-workforce-mutations.sh
set -euo pipefail

APP_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
CONTAINER="${NEXTCLOUD_DOCKER_CONTAINER:-nextcloud-app}"
FILTER='AbsenceServiceOptionalWorkforceTest|WorkforcePolicyTest|FinancialRevisionServiceTest|YearEndResetServiceTest'

ABSENCE_SVC="$APP_ROOT/lib/Service/AbsenceService.php"
REVISION_SVC="$APP_ROOT/lib/Service/FinancialRevisionService.php"
YEAREND_SVC="$APP_ROOT/lib/Service/YearEndResetService.php"
POLICY_VO="$APP_ROOT/lib/Support/WorkforcePolicy.php"

CURRENT_TARGET=""
CURRENT_BACKUP=""

restore() {
	if [[ -n "$CURRENT_BACKUP" && -f "$CURRENT_BACKUP" ]]; then
		mv -f "$CURRENT_BACKUP" "$CURRENT_TARGET"
	fi
}
trap restore EXIT

run_phpunit() {
	if ! docker container inspect "$CONTAINER" &>/dev/null; then
		echo "Docker container '$CONTAINER' is not running." >&2
		exit 1
	fi
	docker exec -u www-data -w "/var/www/html/custom_apps/arbeitszeitcheck" "$CONTAINER" \
		php -d opcache.enable_cli=0 ./vendor/bin/phpunit --filter "$FILTER"
}

kill_or_die() {
	local name="$1" target="$2" from="$3" to="$4"
	CURRENT_TARGET="$target"
	CURRENT_BACKUP="$target.mutation-bak"
	cp "$target" "$CURRENT_BACKUP"
	if ! grep -Fq "$from" "$target"; then
		echo "Mutation anchor not found for $name" >&2
		exit 1
	fi
	python3 - "$target" "$from" "$to" <<'PY'
import pathlib, sys
path, fr, to = sys.argv[1], sys.argv[2], sys.argv[3]
text = pathlib.Path(path).read_text()
if fr not in text:
    raise SystemExit('anchor missing at apply time')
pathlib.Path(path).write_text(text.replace(fr, to, 1))
PY
	echo "== mutation: $name =="
	set +e
	run_phpunit
	code=$?
	set -e
	restore
	if [[ $code -eq 0 ]]; then
		echo "MUTATION SURVIVED: $name" >&2
		exit 1
	fi
	echo "killed $name"
}

# Same as kill_or_die but the anchor is applied to the first occurrence at
# or after LINE — needed when identical expressions exist in earlier methods.
kill_or_die_at() {
	local name="$1" target="$2" line="$3" from="$4" to="$5"
	CURRENT_TARGET="$target"
	CURRENT_BACKUP="$target.mutation-bak"
	cp "$target" "$CURRENT_BACKUP"
	python3 - "$target" "$line" "$from" "$to" <<'PY'
import pathlib, sys
path, line, fr, to = sys.argv[1], int(sys.argv[2]), sys.argv[3], sys.argv[4]
lines = pathlib.Path(path).read_text().splitlines(keepends=True)
idx = next((i for i, l in enumerate(lines[line - 1:], start=line - 1) if fr in l), None)
if idx is None:
    raise SystemExit('anchor missing at/after line ' + str(line))
lines[idx] = lines[idx].replace(fr, to, 1)
pathlib.Path(path).write_text(''.join(lines))
PY
	echo "== mutation: $name =="
	set +e
	run_phpunit
	code=$?
	set -e
	restore
	if [[ $code -eq 0 ]]; then
		echo "MUTATION SURVIVED: $name" >&2
		exit 1
	fi
	echo "killed $name"
}

echo "== baseline =="
run_phpunit

# --- AbsenceService -----------------------------------------------------------

kill_or_die unclamped_coverage_share "$ABSENCE_SVC" \
	'return min(1.0, $m / $p);' \
	'return $m / $p;'

kill_or_die completed_boundary_le "$ABSENCE_SVC" \
	'return $end->format('"'"'Y-m-d'"'"') < $this->todayDateInStorage()->format('"'"'Y-m-d'"'"');' \
	'return $end->format('"'"'Y-m-d'"'"') <= $this->todayDateInStorage()->format('"'"'Y-m-d'"'"');'

kill_or_die self_approval_ban_inverted "$ABSENCE_SVC" \
	'if ($actorUserId === $ownerUserId) {
			throw new \Exception('"'"'self_approval_forbidden'"'"');' \
	'if ($actorUserId !== $ownerUserId) {
			throw new \Exception('"'"'self_approval_forbidden'"'"');'

kill_or_die owner_flag_policy_drop "$ABSENCE_SVC" \
	'&& $this->workforcePolicy()->isEmployeeCorrectionRequestEnabled(),' \
	'&& true,'

kill_or_die correction_decide_policy_drop "$ABSENCE_SVC" \
	'&& $this->workforcePolicy()->isPastCorrectionEnabled(),' \
	'&& true,'

kill_or_die dead_cancel_decide_flag "$ABSENCE_SVC" \
	"'canDecideCancelRequest' => \$approved && !\$completed && \$openRequest," \
	"'canDecideCancelRequest' => \$approved && \$openRequest,"

kill_or_die feature_gate_inverted "$ABSENCE_SVC" \
	'if (!$this->workforcePolicy()->isPastCorrectionEnabled()) {
			throw new \Exception('"'"'feature_disabled'"'"');' \
	'if ($this->workforcePolicy()->isPastCorrectionEnabled()) {
			throw new \Exception('"'"'feature_disabled'"'"');'

kill_or_die operation_key_regex_inverted "$ABSENCE_SVC" \
	'{12}$/'"'"', $key) !== 1) {' \
	'{12}$/'"'"', $key) === 1) {'

# --- hour-mode request validation (§9.2) --------------------------------------

kill_or_die hourly_type_allowlist_inverted "$ABSENCE_SVC" \
	'if (!in_array($type, \OCA\ArbeitszeitCheck\Support\WorkforcePolicy::HOURLY_TYPES, true)) {' \
	'if (in_array($type, \OCA\ArbeitszeitCheck\Support\WorkforcePolicy::HOURLY_TYPES, true)) {'

kill_or_die hourly_single_date_inverted "$ABSENCE_SVC" \
	"if (\$startDate->format('Y-m-d') !== \$endDate->format('Y-m-d')) {
			throw new BusinessRuleException(
				\$this->l10n->t('An hour request must start and end on the same day.')," \
	"if (\$startDate->format('Y-m-d') === \$endDate->format('Y-m-d')) {
			throw new BusinessRuleException(
				\$this->l10n->t('An hour request must start and end on the same day.'),"

kill_or_die hourly_upper_bound_dropped "$ABSENCE_SVC" \
	'$minutes === null || $minutes < 1 || $minutes > 24 * 60' \
	'$minutes === null || $minutes < 1'

kill_or_die hourly_planned_time_inverted "$ABSENCE_SVC" \
	'if ($this->plannedMinutesForDay($userId, $startDate) <= 0) {' \
	'if ($this->plannedMinutesForDay($userId, $startDate) >= 0) {'

kill_or_die hourly_debit_unclamped "$ABSENCE_SVC" \
	'return round(min(1.0, $minutes / $p) * $weight, 4);' \
	'return round($minutes / $p * $weight, 4);'

# requestAbsenceCorrection CAS (line ~4853; identical expressions exist earlier).
kill_or_die_at owner_request_cas_bypass "$ABSENCE_SVC" 4853 \
	"if (\$expected === '' || \$expected !== \$actualUpdatedAt) {" \
	"if (\$expected === '' && \$expected !== \$actualUpdatedAt) {"

# --- FinancialRevisionService -------------------------------------------------

kill_or_die revision_max_to_min "$REVISION_SVC" \
	'$latest = max($latest, $this->balanceRevisionMapper->bump($userId, (int)$year));' \
	'$latest = min($latest, $this->balanceRevisionMapper->bump($userId, (int)$year));'

kill_or_die needs_review_flagging_dropped "$REVISION_SVC" \
	'$this->itemMapper()->flagStaleForUser(' \
	'false && $this->itemMapper()->flagStaleForUser('

kill_or_die staleness_check_inverted "$REVISION_SVC" \
	'return !hash_equals($this->sourceRevision($userId, $calendarYear), $storedSourceRevision);' \
	'return hash_equals($this->sourceRevision($userId, $calendarYear), $storedSourceRevision);'

# --- YearEndResetService ------------------------------------------------------

kill_or_die policy_revision_cas_inverted "$YEAREND_SVC" \
	'if ($expected !== $this->policyService->revision()) {' \
	'if ($expected === $this->policyService->revision()) {'

kill_or_die preview_expiry_inverted "$YEAREND_SVC" \
	'|| $run->getPreviewExpiresAt() <= $now) {' \
	'|| $run->getPreviewExpiresAt() >= $now) {'

kill_or_die operation_id_check_inverted "$YEAREND_SVC" \
	"if (!preg_match('/^[0-9a-fA-F-]{32,36}$/', \$operationId)) {" \
	"if (preg_match('/^[0-9a-fA-F-]{32,36}$/', \$operationId)) {"

# --- WorkforcePolicy (capabilities truth table) -------------------------------

kill_or_die subgate_parent_drop "$POLICY_VO" \
	"'employeeCorrectionRequestsEnabled' => \$this->pastCorrectionEnabled && \$this->employeeCorrectionRequests," \
	"'employeeCorrectionRequestsEnabled' => \$this->employeeCorrectionRequests,"

kill_or_die manager_scope_drop "$POLICY_VO" \
	"'scopedManagerCorrectionApprovalEnabled' => \$isManager && " \
	"'scopedManagerCorrectionApprovalEnabled' => "

kill_or_die admin_scope_drop "$POLICY_VO" \
	"'yearEndAdminEnabled' => \$isAdmin && \$this->yearEndManualEnabled," \
	"'yearEndAdminEnabled' => \$this->yearEndManualEnabled,"

kill_or_die hourly_allowlist_dropped "$POLICY_VO" \
	'self::HOURLY_TYPES,
		));' \
	'[],
		));'

echo "All optional-workforce mutations killed."
