#!/usr/bin/env bash
# Targeted mutation gauntlet: stamp-replay claim core (mobile + kiosk).
#
# One fail-open mutation at a time in MobileStampReplayService /
# KioskOfflineStampService; the focused PHPUnit suite MUST fail for each
# (mutant killed) and pass on the untouched baseline.
#
# Usage (host, from app root — tests run inside the nextcloud container):
#   bash tests/Mutation/run-stamp-replay-claim-mutations.sh
set -euo pipefail

APP_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
NC_ROOT="$(cd "$APP_ROOT/../.." && pwd)"
PHPUNIT=(docker compose -f "$NC_ROOT/docker-compose.yml" exec -u www-data nextcloud
	php custom_apps/arbeitszeitcheck/vendor/bin/phpunit
	-c custom_apps/arbeitszeitcheck/phpunit.xml
	--filter 'MobileStampReplayServiceTest|KioskOfflineStampServiceTest')

SURVIVED=0

kill_or_die() {
	local name="$1" file="$2" from="$3" to="$4"
	local target="$APP_ROOT/$file"
	local backup="$target.mutation-bak"
	cp "$target" "$backup"
	if ! grep -Fq "$from" "$target"; then
		echo "Mutation anchor not found for $name in $file" >&2
		mv -f "$backup" "$target"
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
	"${PHPUNIT[@]}" >/dev/null 2>&1
	code=$?
	set -e
	mv -f "$backup" "$target"
	if [[ $code -eq 0 ]]; then
		echo "MUTATION SURVIVED: $name" >&2
		SURVIVED=$((SURVIVED + 1))
	else
		echo "killed $name"
	fi
}

restore_all() {
	for f in "$APP_ROOT"/lib/Service/*.php.mutation-bak "$APP_ROOT"/lib/Service/Kiosk/*.php.mutation-bak; do
		if [[ -f "$f" ]]; then
			mv -f "$f" "${f%.mutation-bak}"
		fi
	done
	return 0
}
trap restore_all EXIT

# Baseline must be green first.
"${PHPUNIT[@]}" >/dev/null

kill_or_die payload-mismatch-accepted \
	lib/Service/MobileStampReplayService.php \
	'			$this->assertPayloadMatches($row->getPayloadHash(), $payloadHash);' \
	'			/* mutated: same request-id replays a foreign payload */'

kill_or_die stale-claim-never-reclaimed \
	lib/Service/MobileStampReplayService.php \
	'			if ($this->timeFactory->getTime() - $row->getCreatedAt() >= self::STALE_CLAIM_SECONDS) {' \
	'			if (false && $this->timeFactory->getTime() - $row->getCreatedAt() >= self::STALE_CLAIM_SECONDS) { /* mutated: abandoned claim wedges the key */'

kill_or_die claim-not-released-on-failure \
	lib/Service/MobileStampReplayService.php \
	'		} catch (\Throwable $e) {
			if ($claimId !== null) {
				$this->idempotencyMapper->deleteById($claimId);
			}
			throw $e;
		}' \
	'		} catch (\Throwable $e) {
			throw $e; /* mutated: dead claim left behind */
		}'

kill_or_die response-not-persisted \
	lib/Service/MobileStampReplayService.php \
	'			$this->idempotencyMapper->updateResponseJsonForId($claimId, $json);' \
	'			/* mutated: claim stays pending — replays hit IN_FLIGHT forever */'

kill_or_die kiosk-payload-mismatch-accepted \
	lib/Service/Kiosk/KioskOfflineStampService.php \
	'			$storedHash = $row->getPayloadHash();
			if ($storedHash !== null && !hash_equals($storedHash, $payloadHash)) {
				throw new StampReplayException('"'"'STAMP_CLIENT_REQUEST_MISMATCH'"'"');
			}' \
	'			/* mutated: kiosk replays foreign payloads on request-id reuse */'

kill_or_die kiosk-stale-claim-never-reclaimed \
	lib/Service/Kiosk/KioskOfflineStampService.php \
	'			if ($this->timeFactory->getTime() - $row->getCreatedAt() >= self::STALE_CLAIM_SECONDS) {' \
	'			if (false && $this->timeFactory->getTime() - $row->getCreatedAt() >= self::STALE_CLAIM_SECONDS) { /* mutated */'

kill_or_die kiosk-claim-not-released-on-failure \
	lib/Service/Kiosk/KioskOfflineStampService.php \
	'		} catch (\Throwable $e) {
			$this->idempotencyMapper->deleteById($claimId);' \
	'		} catch (\Throwable $e) {
			/* mutated: claim survives failed mutation */'

if [[ $SURVIVED -gt 0 ]]; then
	echo "$SURVIVED mutation(s) survived — tests do not cover this logic" >&2
	exit 1
fi
echo "All stamp-replay claim mutations killed."
