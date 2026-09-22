#!/usr/bin/env bash
# Prove people-search + auMsg injection contracts kill Kraft-class regressions.
set -euo pipefail

APP="$(cd "$(dirname "$0")/../.." && pwd)"
ROOT="$(cd "$APP/../../.." && pwd)"
BAK_DIR="$APP/tests/Mutation/.originals"
mkdir -p "$BAK_DIR"

TEAMS_JS="$APP/js/admin-teams.js"
HELPER="$APP/lib/Support/PeopleSearchQuery.php"
L10N="$APP/templates/partials/admin-user-edit-l10n.php"
CSS="$APP/css/common/user-picker.css"
PHPUNIT="$ROOT/nextcloud/docker/run-app-phpunit.sh"

restore_one() {
	local bak="$1"
	local dest="$2"
	if [[ -f "$bak" ]]; then
		mv -f "$bak" "$dest"
	fi
}

restore() {
	restore_one "$BAK_DIR/admin-teams.js.people-search.bak" "$TEAMS_JS"
	restore_one "$BAK_DIR/PeopleSearchQuery.php.people-search.bak" "$HELPER"
	restore_one "$BAK_DIR/admin-user-edit-l10n.php.people-search.bak" "$L10N"
	restore_one "$BAK_DIR/user-picker.css.people-search.bak" "$CSS"
}
trap restore EXIT

run_phpunit() {
	bash "$PHPUNIT" arbeitszeitcheck --filter 'PeopleSearchParamContractTest|AdminTeamsBulkSearchParamContractTest|AdminUsersAuMsgInjectionContractTest|PeopleSearchQueryTest|PeopleSearchParamAliasContractTest'
}

echo "== baseline (must pass) =="
run_phpunit

echo "== mutation: teams bulk search reintroduces ?q= =="
cp "$TEAMS_JS" "$BAK_DIR/admin-teams.js.people-search.bak"
python3 - "$TEAMS_JS" <<'PY'
from pathlib import Path
import sys
p = Path(sys.argv[1])
text = p.read_text()
assert 'search: q' in text
p.write_text(text + "\n// MUTATED\n'?q=' + encodeURIComponent(q) + '&picker=1\n")
PY
set +e
run_phpunit
code=$?
set -e
restore
if [[ $code -eq 0 ]]; then
	echo "MUTATION SURVIVED: teams ?q= bulk search" >&2
	exit 1
fi
echo "killed teams ?q= bulk search"

echo "== mutation: PeopleSearchQuery drops q alias =="
cp "$HELPER" "$BAK_DIR/PeopleSearchQuery.php.people-search.bak"
python3 - "$HELPER" <<'PY'
from pathlib import Path
import sys
p = Path(sys.argv[1])
text = p.read_text()
needle = "getParam('q'"
assert needle in text
# Kill dual-read: stop accepting q entirely
mut = text.replace(
	"return trim((string)($request->getParam('q') ?? ''));",
	"return ''; // MUTATED: q alias dropped",
	1,
)
assert mut != text, 'expected q-fallback return line'
p.write_text(mut)
PY
set +e
run_phpunit
code=$?
set -e
restore
if [[ $code -eq 0 ]]; then
	echo "MUTATION SURVIVED: drop q alias" >&2
	exit 1
fi
echo "killed drop q alias"

echo "== mutation: remove holidayRegion auMsg injection =="
cp "$L10N" "$BAK_DIR/admin-user-edit-l10n.php.people-search.bak"
python3 - "$L10N" <<'PY'
from pathlib import Path
import sys
p = Path(sys.argv[1])
text = p.read_text()
needle = 'window.ArbeitszeitCheck.l10n.holidayRegion'
assert needle in text
# Remove the whole injection line (substring rename still matches str_contains)
lines = [ln for ln in text.splitlines(True) if needle not in ln]
assert len(lines) < len(text.splitlines(True))
p.write_text(''.join(lines))
PY
set +e
run_phpunit
code=$?
set -e
restore
if [[ $code -eq 0 ]]; then
	echo "MUTATION SURVIVED: holidayRegion auMsg injection" >&2
	exit 1
fi
echo "killed holidayRegion injection drop"

echo "== mutation: in-modal picker list absolute again =="
cp "$CSS" "$BAK_DIR/user-picker.css.people-search.bak"
python3 - "$CSS" <<'PY'
from pathlib import Path
import sys
p = Path(sys.argv[1])
text = p.read_text()
assert 'position: static' in text
p.write_text(text.replace('position: static', 'position: absolute', 1))
PY
set +e
run_phpunit
code=$?
set -e
restore
if [[ $code -eq 0 ]]; then
	echo "MUTATION SURVIVED: in-modal absolute list" >&2
	exit 1
fi
echo "killed in-modal absolute list"

echo "All people-search / auMsg / in-modal mutations killed."
