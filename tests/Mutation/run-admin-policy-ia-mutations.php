<?php
/**
 * Mutation suite: admin policy IA split + partial-write gates.
 * Run: php tests/Mutation/run-admin-policy-ia-mutations.php
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$failures = 0;

function assertTrue(bool $cond, string $msg): void
{
	global $failures;
	if (!$cond) {
		fwrite(STDERR, "FAIL: $msg\n");
		$failures++;
	} else {
		fwrite(STDOUT, "OK: $msg\n");
	}
}

$controller = file_get_contents($root . '/lib/Controller/AdminController.php');
$js = file_get_contents($root . '/js/admin-notifications.js');
$nav = file_get_contents($root . '/templates/common/navigation.php');
$notifications = file_get_contents($root . '/templates/admin-notifications.php');
$overtime = file_get_contents($root . '/templates/admin-overtime-settings.php');
$vacation = file_get_contents($root . '/templates/admin-vacation-layers.php');
$vacationRules = file_get_contents($root . '/templates/admin-vacation-rules.php');

assertTrue(is_string($controller) && $controller !== '', 'controller readable');
assertTrue(str_contains((string)$controller, '$hasHrSection'), 'HR section gate present');
assertTrue(str_contains((string)$controller, "array_key_exists('hrNotificationsEnabled', \$params)"), 'HR gate prefers hrNotificationsEnabled');
assertTrue(!str_contains((string)$controller, "\$hasHrSection = array_key_exists('enabled', \$params)"), 'HR not gated on bare enabled');
assertTrue(str_contains((string)$controller, '$hasTrafficSection'), 'traffic section gate present');
assertTrue(str_contains((string)$controller, '$hasBankSection'), 'bank section gate present');
assertTrue(str_contains((string)$controller, "array_key_exists('overtimeBankMaxHours', \$params)"), 'bank max patch gate');
assertTrue(str_contains((string)$controller, "\$writeHrEnabled"), 'HR enabled patch flag');
assertTrue(str_contains((string)$controller, 'Pre-validate carryover max'), 'carryover validated before commit');
assertTrue(str_contains((string)$controller, 'function overtimeSettings'), 'overtimeSettings action present');

assertTrue(is_string($js) && str_contains((string)$js, 'function initForm(form)'), 'JS initForm present');
assertTrue(str_contains((string)$js, 'hasPremium'), 'JS hasPremium detection');
assertTrue(str_contains((string)$js, 'payload.hrNotificationsEnabled'), 'JS sends hrNotificationsEnabled');
assertTrue(str_contains((string)$js, 'policyScope'), 'JS sends policyScope');
assertTrue(str_contains((string)$js, 'el.disabled = false'), 'JS keeps dependent fields enabled');
assertTrue(!str_contains((string)$js, 'el.disabled = !on'), 'JS does not disable dependents');
assertTrue(!str_contains((string)$notifications, 'premium-surcharges-heading'), 'notifications no premiums');
assertTrue(!str_contains((string)$notifications, 'overtime-bank-heading'), 'notifications no bank');
assertTrue(str_contains((string)$overtime, 'admin-policy-hour-premiums.php'), 'overtime hosts premiums');
assertTrue(str_contains((string)$overtime, 'admin-policy-overtime-bank.php'), 'overtime hosts bank');
assertTrue(str_contains((string)$vacationRules, 'admin-policy-vacation.php'), 'vacation rules hosts rules');
assertTrue(!str_contains((string)$vacation, 'admin-policy-vacation.php'), 'layers page no rules partial');
assertTrue(str_contains((string)$nav, 'Policy settings'), 'nav Policy settings entry');
assertTrue(str_contains((string)$nav, 'admin-nav-group-policy'), 'nav policy group');
assertTrue(str_contains((string)$nav, 'admin.vacationRules'), 'nav opens vacation rules as policy default');
assertTrue(!str_contains((string)$nav, 'admin-nav-group-alerts'), 'nav no nested alerts group');
assertTrue(!str_contains((string)$nav, 'admin.overtimeSettings'), 'nav no nested overtime link');
assertTrue(!str_contains((string)$notifications, 'azc-jump-nav.php'), 'notifications no jump-nav');
assertTrue(!str_contains((string)$overtime, 'azc-jump-nav.php'), 'overtime no jump-nav');
assertTrue(!str_contains((string)$vacation, 'azc-jump-nav.php'), 'vacation layers no jump-nav');
assertTrue(!str_contains((string)$vacationRules, 'azc-jump-nav.php'), 'vacation rules no jump-nav');

$catalog = file_get_contents($root . '/lib/Service/AdminPolicyPagesCatalog.php');
$chipNav = file_get_contents($root . '/templates/common/azc-policy-pages-nav.php');
$legacyJs = file_get_contents($root . '/js/admin-policy-legacy-redirect.js');
$payouts = file_get_contents($root . '/templates/admin-overtime-payouts.php');
$payoutAudit = file_get_contents($root . '/templates/admin-overtime-payout-audit.php');
$css = file_get_contents($root . '/css/admin-notifications.css');

assertTrue(str_contains((string)$catalog, 'SECTION_GROUPS'), 'catalog has SECTION_GROUPS');
assertTrue(str_contains((string)$chipNav, 'azc-settings-nav--grouped'), 'chip bar grouped markup');
assertTrue(str_contains((string)$chipNav, 'Choose a topic'), 'chip bar topic title');
assertTrue(str_contains((string)$chipNav, 'azc-settings-nav__tabs'), 'group tab row (§3.12.1)');
assertTrue(str_contains((string)$chipNav, 'aria-current="location"'), 'active group location marker');
assertTrue(str_contains((string)$chipNav, '$activeGroupSections'), 'only active group chips');
assertTrue(is_string($catalog) && str_contains((string)$catalog, 'LEGACY_ANCHORS'), 'catalog has LEGACY_ANCHORS');
assertTrue(str_contains((string)$catalog, 'SECTION_OVERTIME'), 'catalog has overtime section');
assertTrue(str_contains((string)$chipNav, 'azc-settings-nav'), 'chip bar markup present');
assertTrue(str_contains((string)$notifications, 'azc-policy-pages-nav.php'), 'notifications chip include');
assertTrue(str_contains((string)$overtime, 'azc-policy-pages-nav.php'), 'overtime chip include');
assertTrue(str_contains((string)$vacationRules, 'azc-policy-pages-nav.php'), 'vacation rules chip include');
assertTrue(str_contains((string)$vacation, 'azc-policy-pages-nav.php'), 'vacation layers chip include');
assertTrue(str_contains((string)$payouts, 'azc-policy-pages-nav.php'), 'payouts chip include');
assertTrue(str_contains((string)$payoutAudit, 'azc-policy-pages-nav.php'), 'payout audit chip include');
assertTrue(str_contains((string)$legacyJs, 'hasOwnProperty.call'), 'legacy redirect fail-closed');
assertTrue(str_contains((string)$controller, 'admin-policy-legacy-redirect'), 'controller ships legacy redirect');
assertTrue(str_contains((string)$css, 'var(--azc-touch'), 'chip uses --azc-touch');
assertTrue(!preg_match('/#[0-9a-fA-F]{3,8}\b/', (string)$css), 'policy CSS has no raw hex');
assertTrue(
	!preg_match(
		'/#app-content\.azc-app--admin-notifications,\s*#app-content\.azc-app--admin-overtime-settings,\s*#app-content\.azc-app--admin-vacation-layers\s*\{[^}]*pointer-events:\s*none/s',
		(string)$css
	),
	'no bare-page pointer-events:none'
);
assertTrue(str_contains((string)$css, "[data-settings-disabled='true']"), 'disabled lock scoped');

// Atomic lock preflight: busy 409 must precede sibling commits (VAC_YEAR_MODE_BUSY / PREMIUM_POLICY_BUSY).
$updStart = strpos((string)$controller, 'public function updateNotificationSettings');
$updEnd = strpos((string)$controller, 'public function migrateVacationUnit', $updStart !== false ? $updStart : 0);
assertTrue($updStart !== false && $updEnd !== false && $updEnd > $updStart, 'updateNotificationSettings bounds');
$upd = substr((string)$controller, (int)$updStart, (int)$updEnd - (int)$updStart);
$policyLockPos = strpos($upd, 'acquireLock($policyLock');
$yearLockPos = strpos($upd, 'acquireLock($yearLock');
$siblingCommitPos = strpos($upd, '// ── Commit phase (sibling settings)');
assertTrue($policyLockPos !== false, 'premium preflight lock present');
assertTrue($yearLockPos !== false, 'year-mode preflight lock present');
assertTrue($siblingCommitPos !== false, 'sibling commit marker present');
assertTrue($policyLockPos < $siblingCommitPos, 'premium lock before sibling commit');
assertTrue($yearLockPos < $siblingCommitPos, 'year lock before sibling commit');
assertTrue(str_contains($upd, 'VAC_YEAR_MODE_BUSY'), 'year busy code present');
assertTrue(str_contains($upd, 'PREMIUM_POLICY_BUSY'), 'premium busy code present');
assertTrue(str_contains((string)$js, 'VAC_YEAR_MODE_BUSY'), 'JS retries year busy');
assertTrue(str_contains((string)$js, 'settingsBusyRetrying'), 'JS busy retry live message');
assertTrue(str_contains((string)$vacationRules, 'settingsBusyRetrying'), 'vacation rules exposes busy retry l10n');

// Mutate: remove bank gate → must be detectable as regression.
$mutated = str_replace('$hasBankSection = array_key_exists(\'overtimeBankEnabled\', $params);', '', (string)$controller);
assertTrue(!str_contains($mutated, '$hasBankSection = array_key_exists'), 'mutation removed bank gate');
assertTrue(str_contains((string)$controller, '$hasBankSection = array_key_exists'), 'original still has bank gate');

// Mutate: drop year-mode busy preflight → detectable.
$mutBusy = str_replace("'code' => 'VAC_YEAR_MODE_BUSY'", "'code' => 'VAC_YEAR_MODE_BUSY_REMOVED'", $upd);
assertTrue(!str_contains($mutBusy, "'code' => 'VAC_YEAR_MODE_BUSY'"), 'mutation removed VAC_YEAR_MODE_BUSY');
assertTrue(str_contains($upd, "'code' => 'VAC_YEAR_MODE_BUSY'"), 'original still has VAC_YEAR_MODE_BUSY');

// Mutate: drop LEGACY_ANCHORS map → detectable.
$mutCatalog = str_replace('public const LEGACY_ANCHORS = [', 'public const LEGACY_ANCHORS_REMOVED = [', (string)$catalog);
assertTrue(!str_contains($mutCatalog, 'public const LEGACY_ANCHORS = ['), 'mutation removed LEGACY_ANCHORS');
assertTrue(str_contains((string)$catalog, 'public const LEGACY_ANCHORS = ['), 'original still has LEGACY_ANCHORS');

if ($failures > 0) {
	fwrite(STDERR, "\n$failures mutation contract failure(s)\n");
	exit(1);
}
fwrite(STDOUT, "\nAll admin policy IA mutation contracts passed.\n");
exit(0);
