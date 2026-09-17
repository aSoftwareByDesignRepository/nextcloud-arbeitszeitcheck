#!/usr/bin/env bash
# Prove the UI↔API contract checker kills drift (web + companion needles).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../../../../.." && pwd)"
APP="$ROOT/nextcloud/apps/arbeitszeitcheck"
REGISTRY="$APP/tests/contracts/ui-api-client.json"
TEMPLATE="$APP/templates/time-entries.php"
MOBILE="$ROOT/mobile/arbeitszeitcheck/src/screens/CreateTimeEntryScreen.tsx"
CHECKER="$ROOT/scripts/check-ui-api-client-contracts.py"
BAK_DIR="$APP/tests/Mutation/.originals"
mkdir -p "$BAK_DIR"

restore() {
	for name in ui-api-client.json time-entries.php CreateTimeEntryScreen.tsx; do
		local bak="$BAK_DIR/${name}.ui-api-contract.bak"
		if [[ -f "$bak" ]]; then
			case "$name" in
				ui-api-client.json) mv -f "$bak" "$REGISTRY" ;;
				time-entries.php) mv -f "$bak" "$TEMPLATE" ;;
				CreateTimeEntryScreen.tsx) mv -f "$bak" "$MOBILE" ;;
			esac
		fi
	done
}
trap restore EXIT

run_check() {
	python3 "$CHECKER" --app arbeitszeitcheck --heuristic
}

echo "== baseline =="
run_check

echo "== mutation: drop web justification id from template =="
cp "$TEMPLATE" "$BAK_DIR/time-entries.php.ui-api-contract.bak"
python3 -c "
from pathlib import Path
p = Path(r'''$TEMPLATE''')
text = p.read_text()
assert 'id=\"entry-justification\"' in text
p.write_text(text.replace('id=\"entry-justification\"', 'id=\"entry-justification-MUTATED\"', 1))
"
set +e
run_check
code=$?
set -e
restore
if [[ $code -eq 0 ]]; then
	echo "MUTATION SURVIVED: template justification id" >&2
	exit 1
fi
echo "killed template justification id"

echo "== mutation: drop companion justification testID =="
cp "$MOBILE" "$BAK_DIR/CreateTimeEntryScreen.tsx.ui-api-contract.bak"
python3 -c "
from pathlib import Path
p = Path(r'''$MOBILE''')
text = p.read_text()
needle = 'testID=\"time.create.justification\"'
assert needle in text
p.write_text(text.replace(needle, 'testID=\"time.create.justification-MUTATED\"', 1))
"
set +e
run_check
code=$?
set -e
restore
if [[ $code -eq 0 ]]; then
	echo "MUTATION SURVIVED: mobile justification testID" >&2
	exit 1
fi
echo "killed mobile justification testID"

echo "All ui-api-client-contract mutations killed."
