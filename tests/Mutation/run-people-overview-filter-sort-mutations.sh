#!/usr/bin/env bash
# Targeted mutation gauntlet: people-overview search/filter/sort params
# (hr-people-oversight-2026-10 §9.2) must survive Kraft-class regressions.
#
# Usage (host, from app root):
#   bash tests/Mutation/run-people-overview-filter-sort-mutations.sh
set -euo pipefail

APP_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
CONTROLLER="$APP_ROOT/lib/Controller/ManagerController.php"
BACKUP_DIR="$APP_ROOT/tests/Mutation/.originals"
mkdir -p "$BACKUP_DIR"

CONTAINER="${NEXTCLOUD_DOCKER_CONTAINER:-nextcloud-app}"
PHP_FILTER='PeopleOverview'

restore_all() {
	local bak="$BACKUP_DIR/$(basename "$CONTROLLER").people-filter-sort.bak"
	if [[ -f "$bak" ]]; then
		mv -f "$bak" "$CONTROLLER"
	fi
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

kill_or_die_replace() {
	local name="$1"
	local target="$2"
	local from="$3"
	local to="$4"
	local bak="$BACKUP_DIR/$(basename "$target").people-filter-sort.bak"
	cp "$target" "$bak"
	python3 -c 'import pathlib,sys; p=pathlib.Path(sys.argv[1]); fr=sys.argv[2]; to=sys.argv[3]; t=p.read_text();
assert fr in t, fr[:80]; p.write_text(t.replace(fr,to,1))' "$target" "$from" "$to"
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

echo "== baseline phpunit =="
run_phpunit

kill_or_die_replace filter_whitelist_open \
	"$CONTROLLER" \
	"if (!in_array(\$filter, ['all', 'needs_attention', 'pending_absences'], true)) {" \
	"if (false) { /* mutated: any filter accepted */"

kill_or_die_replace sort_whitelist_open \
	"$CONTROLLER" \
	"if (!in_array(\$sort, ['violations', 'name', 'vacation', 'overtime'], true)) {" \
	"if (false) { /* mutated: any sort accepted */"

kill_or_die_replace default_sort_name \
	"$CONTROLLER" \
	"\$sort = \$sort !== null ? strtolower(trim(\$sort)) : 'violations';" \
	"\$sort = \$sort !== null ? strtolower(trim(\$sort)) : 'name';"

kill_or_die_replace search_clamp_widened \
	"$CONTROLLER" \
	"\$search = mb_substr(\$search, 0, 200);" \
	"\$search = mb_substr(\$search, 0, 400);"

kill_or_die_replace search_case_sensitive \
	"$CONTROLLER" \
	"return mb_stripos(\$displayNames[\$uid] ?? '', \$search) !== false" \
	"return mb_strpos(\$displayNames[\$uid] ?? '', \$search) !== false"

kill_or_die_replace violations_sort_asc \
	"$CONTROLLER" \
	"return ((int)(\$openByUser[\$b] ?? 0) <=> (int)(\$openByUser[\$a] ?? 0)) ?: \$nameCmp(\$a, \$b);" \
	"return ((int)(\$openByUser[\$a] ?? 0) <=> (int)(\$openByUser[\$b] ?? 0)) ?: \$nameCmp(\$a, \$b);"

kill_or_die_replace metric_nulls_first \
	"$CONTROLLER" \
	"if (\$ma === null) {
						return 1;
					}" \
	"if (\$ma === null) {
						return -1;
					}"

kill_or_die_replace metric_sort_asc \
	"$CONTROLLER" \
	"return (\$mb <=> \$ma) ?: \$nameCmp(\$a, \$b);" \
	"return (\$ma <=> \$mb) ?: \$nameCmp(\$a, \$b);"

kill_or_die_replace pending_filter_includes_zero \
	"$CONTROLLER" \
	"static fn (string \$uid): bool => (int)(\$pendingByUser[\$uid] ?? 0) > 0" \
	"static fn (string \$uid): bool => (int)(\$pendingByUser[\$uid] ?? 0) >= 0"

kill_or_die_replace critical_severity_wrong \
	"$CONTROLLER" \
	"(int)(\$sevMap[\OCA\ArbeitszeitCheck\Db\ComplianceViolation::SEVERITY_ERROR] ?? 0);" \
	"(int)(\$sevMap[\OCA\ArbeitszeitCheck\Db\ComplianceViolation::SEVERITY_WARNING] ?? 0);"

kill_or_die_replace attention_needs_no_violation \
	"$CONTROLLER" \
	"if ((int)(\$openByUser[\$uid] ?? 0) > 0) {
							return true;
						}" \
	"if (false) { /* mutated: violations never flag attention */"

echo "All people-overview filter/sort mutations killed."
