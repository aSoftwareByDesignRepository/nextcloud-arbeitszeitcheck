<?php
/**
 * Mutation contract for Outlook iCal subscription feature wiring.
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

$catalog = file_get_contents($root . '/lib/Service/AdminSettingsSectionCatalog.php');
$routes = file_get_contents($root . '/appinfo/routes.php');
$controller = file_get_contents($root . '/lib/Controller/OutlookIcalSubscriptionController.php');
$service = file_get_contents($root . '/lib/Service/OutlookIcalSubscriptionService.php');
$feedService = file_get_contents($root . '/lib/Service/OutlookIcalSubscriptionFeedService.php');
$template = file_get_contents($root . '/templates/partials/admin-settings/outlook-ical-subscription.php');
$js = file_get_contents($root . '/js/admin-outlook-ical-subscription.js');
$adminSettingsJs = file_get_contents($root . '/js/admin-settings.js');

assertTrue(str_contains((string)$catalog, "SECTION_OUTLOOK_SUBSCRIPTION = 'outlook-subscription'"), 'catalog exposes outlook section');
assertTrue(str_contains((string)$routes, '/api/admin/outlook-ical/create'), 'routes expose create endpoint');
assertTrue(str_contains((string)$routes, '/api/admin/outlook-ical/subscription-links'), 'routes expose subscription-links create endpoint');
assertTrue(str_contains((string)$routes, '/api/admin/outlook-ical/rotate'), 'routes expose rotate endpoint');
assertTrue(str_contains((string)$routes, '/api/admin/outlook-ical/active-subscriptions'), 'routes expose active subscriptions endpoint');
assertTrue(str_contains((string)$template, 'id="outlookIcalSubscriptionTable"'), 'template lists subscription table');
assertTrue(str_contains((string)$js, 'renderSubscriptionTable'), 'js renders subscription table rows');
assertTrue(str_contains((string)$js, 'OUTLOOK_API_PATHS'), 'js uses canonical outlook API paths');
assertTrue(str_contains((string)$js, '/api/admin/outlook-ical/create'), 'js posts to create endpoint');
assertTrue(str_contains((string)$js, 'setSubscriptionsLoading'), 'js shows loading state while fetching subscriptions');
assertTrue(str_contains((string)$js, 'activeSubscriptionsRequestId'), 'js ignores stale subscription list responses');
assertTrue(str_contains((string)$js, 'loadActiveSubscriptions'), 'js loads active subscription metadata');
assertTrue(str_contains((string)$template, 'outlookIcalSubscriptionsLoading'), 'template exposes subscription loading indicator');
assertTrue(str_contains((string)$service, 'findForScopeLanguage'), 'service keys tokens by scope and language');
assertTrue(str_contains((string)$service, 'createToken'), 'service exposes createToken');
assertTrue(str_contains((string)$service, 'tokenEncrypted'), 'service stores encrypted token');
assertTrue(str_contains((string)$template, 'id="outlookIcalCreateBtn"'), 'template exposes create CTA');
assertTrue(!str_contains((string)$template, 'outlookIcalManagerSearch'), 'template no longer asks for manager picker');
assertTrue(str_contains((string)$template, 'Rotate link'), 'template documents rotate CTA');
assertTrue(str_contains((string)$template, 'data-outlook-create-url'), 'template exposes create API URL for JS bootstrap');
assertTrue(str_contains((string)$service, 'listAppAccessUserIds'), 'service supports org-wide employee scope');
assertTrue(str_contains((string)$service, 'feedLanguageCode'), 'service persists explicit feed language on token');
assertTrue(str_contains((string)$service, 'OutlookIcalSubscriptionLanguageCatalog'), 'service validates feed language whitelist');
assertTrue(str_contains((string)$template, 'id="outlookIcalFeedLanguage"'), 'template exposes calendar language selector');
assertTrue(str_contains((string)$service, 'OUTLOOK_ICAL_ORG_WIDE_TEAM_ID'), 'service defines org-wide scope constant');
assertTrue(str_contains((string)$template, 'data-org-wide-available'), 'template exposes org-wide scope');
assertTrue(str_contains((string)$js, 'prefetchOrgWide'), 'js preselects org-wide scope for small teams');
assertTrue(str_contains((string)$feedService, 'buildEventTitle'), 'feed uses thunderbird-safe event title builder');
assertTrue(str_contains((string)$feedService, 'LOCATION:'), 'feed duplicates employee name in LOCATION for calendar clients');
assertTrue(str_contains((string)$template, 'Event titles look like'), 'template explains event title format');
assertTrue(str_contains((string)$js, 'outlookTeamLoadFailed'), 'js uses team-specific load failure copy');
assertTrue(str_contains((string)$adminSettingsJs, '#monthClosureReopenUserSearch'), 'admin-settings references month reopen search');
assertTrue(str_contains((string)$adminSettingsJs, 'if (!search || !hidden || !list)'), 'admin-settings skips month reopen picker when controls absent');
assertTrue(str_contains((string)$controller, '#[NoAdminRequired]'), 'outlook admin endpoints allow delegated app admins through NC middleware');
assertTrue(str_contains((string)$controller, "#[NoAdminRequired]\n\t#[NoCSRFRequired]\n\tpublic function authenticatedFeed"), 'authenticatedFeed has NoAdminRequired so managers are not blocked by NC admin middleware');

$phpunit = is_file($root . '/vendor/bin/phpunit') ? $root . '/vendor/bin/phpunit' : 'phpunit';
$appId = basename($root);
$workspaceRoot = dirname($root, 2);

function runOutlookMutationFilter(string $root, string $phpunit, string $filter, string $workspaceRoot, string $appId, string $testFile = 'tests/Unit/Service/OutlookIcalSubscriptionServiceTest.php'): int
{
	$inside = is_file('/var/www/html/lib/base.php');
	if ($inside) {
		$cmd = escapeshellarg(PHP_BINARY)
			. ' -d opcache.enable_cli=0 -d opcache.enable=0 '
			. escapeshellarg($phpunit)
			. ' -c ' . escapeshellarg($root . '/phpunit.xml')
			. ' ' . escapeshellarg($testFile)
			. ' --filter ' . escapeshellarg($filter)
			. ' > /dev/null 2>&1';
	} else {
		$cmd = 'docker compose -f ' . escapeshellarg($workspaceRoot . '/docker-compose.yml')
			. ' exec -u www-data -T nextcloud php -d opcache.enable_cli=0 -d opcache.enable=0 '
			. '/var/www/html/custom_apps/' . $appId . '/vendor/bin/phpunit '
			. '-c /var/www/html/custom_apps/' . $appId . '/phpunit.xml '
			. '/var/www/html/custom_apps/' . $appId . '/' . $testFile . ' '
			. '--filter ' . escapeshellarg($filter)
			. ' > /dev/null 2>&1';
	}
	exec($cmd, $output, $code);
	return (int)$code;
}

function restoreOutlookMutationFile(string $path, string $backup): void
{
	if (is_file($backup)) {
		copy($backup, $path); unlink($backup);
	}
}

$servicePath = $root . '/lib/Service/OutlookIcalSubscriptionService.php';
$serviceOriginal = file_get_contents($servicePath);
if ($serviceOriginal === false) {
	fwrite(STDERR, "Cannot read service file\n");
	exit(1);
}

$baseline = runOutlookMutationFilter($root, $phpunit, 'testRotateTokenUpdatesExistingScopeRowInPlace', $workspaceRoot, $appId);
if ($baseline !== 0) {
	fwrite(STDERR, "Baseline upsert unit test must pass before mutation run\n");
	exit(1);
}

$anchor = "\$this->tokenMapper->update(\$existing);";
if (!str_contains($serviceOriginal, $anchor)) {
	fwrite(STDERR, "Mutation anchor not found for in-place rotation\n");
	exit(1);
}

$backup = $servicePath . '.mutation-bak';
file_put_contents($backup, $serviceOriginal);
file_put_contents($servicePath, str_replace($anchor, '// mutation: removed in-place update', $serviceOriginal));

echo "\n== mutation: drop_in_place_scope_update ==\n";
$mutated = runOutlookMutationFilter($root, $phpunit, 'testRotateTokenUpdatesExistingScopeRowInPlace', $workspaceRoot, $appId);
restoreOutlookMutationFile($servicePath, $backup);

if ($mutated === 0) {
	fwrite(STDERR, "MUTATION SURVIVED: drop_in_place_scope_update\n");
	exit(1);
}
fwrite(STDOUT, "killed drop_in_place_scope_update\n");

// --- half-day label shape authority (FU5 sealed columns vs literal days) ---
$feedPath = $root . '/lib/Service/OutlookIcalSubscriptionFeedService.php';
$feedOriginal = file_get_contents($feedPath);
if ($feedOriginal === false) {
	fwrite(STDERR, "Cannot read feed service file\n");
	exit(1);
}
$halfTestFile = 'tests/Unit/Service/OutlookIcalHalfDayLabelTest.php';
$halfBaseline = runOutlookMutationFilter($root, $phpunit, 'OutlookIcalHalfDayLabelTest', $workspaceRoot, $appId, $halfTestFile);
if ($halfBaseline !== 0) {
	fwrite(STDERR, "Baseline halfDayLabel unit tests must pass before mutation run\n");
	exit(1);
}

$shapeAnchor = "\t\t\$frac = \$absence->getStartDayFraction();\n\t\t\$isHalf = \$start->format('Y-m-d') === \$end->format('Y-m-d') // locked decision: half-day is single-day\n\t\t\t&& (\$frac !== null\n\t\t\t\t? (float)\$frac < 0.999\n\t\t\t\t: (\$days !== null && abs((float)\$days - 0.5) < 1e-9));";
$shapeLiteral = "\t\t\$isHalf = \$days !== null && abs((float)\$days - 0.5) < 1e-9\n\t\t\t&& \$start->format('Y-m-d') === \$end->format('Y-m-d');";
$shapeNoFallback = "\t\t\$frac = \$absence->getStartDayFraction();\n\t\t\$isHalf = \$start->format('Y-m-d') === \$end->format('Y-m-d')\n\t\t\t&& \$frac !== null && (float)\$frac < 0.999;";

foreach ([
	['halfday_label_drops_shape', $shapeAnchor, $shapeLiteral],
	['halfday_label_drops_legacy_fallback', $shapeAnchor, $shapeNoFallback],
] as [$name, $from, $to]) {
	if (!str_contains($feedOriginal, $from)) {
		fwrite(STDERR, "Mutation anchor not found for $name\n");
		exit(1);
	}
	$backup2 = $feedPath . '.mutation-bak';
	file_put_contents($backup2, $feedOriginal);
	file_put_contents($feedPath, str_replace($from, $to, $feedOriginal));
	echo "\n== mutation: $name ==\n";
	$rc = runOutlookMutationFilter($root, $phpunit, 'OutlookIcalHalfDayLabelTest', $workspaceRoot, $appId, $halfTestFile);
	restoreOutlookMutationFile($feedPath, $backup2);
	if ($rc === 0) {
		fwrite(STDERR, "MUTATION SURVIVED: $name\n");
		exit(1);
	}
	fwrite(STDOUT, "killed $name\n");
}

if ($failures > 0) {
	fwrite(STDERR, "\n$failures mutation contract failure(s)\n");
	exit(1);
}

fwrite(STDOUT, "\nAll Outlook iCal subscription mutation contracts passed.\n");
exit(0);
