<?php
/**
 * Mutation suite: Global settings multipage catalog + partial-write gates.
 * Run: php tests/Mutation/run-admin-settings-multipage-mutations.php
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

$catalog = file_get_contents($root . '/lib/Service/AdminSettingsSectionCatalog.php');
$routes = file_get_contents($root . '/appinfo/routes.php');
$tpl = file_get_contents($root . '/templates/admin-settings.php');
$js = file_get_contents($root . '/js/admin-settings.js');
$legacy = file_get_contents($root . '/js/admin-settings-legacy-redirect.js');
$controller = file_get_contents($root . '/lib/Controller/AdminController.php');

assertTrue(is_string($catalog) && str_contains((string)$catalog, 'LEGACY_ANCHORS'), 'catalog LEGACY_ANCHORS');
assertTrue(str_contains((string)$catalog, 'SECTION_TIME_RECORDING'), 'catalog time-recording');
assertTrue(str_contains((string)$catalog, 'SECTION_TIME_APPROVALS'), 'catalog time-approvals');
assertTrue(str_contains((string)$catalog, "'section-time-approval-heading' => self::SECTION_TIME_APPROVALS"), 'approval anchor owns time-approvals');
assertTrue(str_contains((string)$routes, '/admin/settings/{section}'), 'routes section URL');
assertTrue(
	str_contains((string)$routes, 'AdminSettingsSectionCatalog::routeRequirement()'),
	'routes section allowlist from catalog'
);
assertTrue(str_contains((string)$tpl, 'azc-admin-settings-nav.php'), 'chip include');
assertTrue(!str_contains((string)$tpl, 'Jump to settings sections'), 'no jump TOC primary nav');
assertTrue(!str_contains((string)$tpl, 'Save all settings'), 'no Save all copy');
assertTrue(str_contains((string)$js, 'function hasField(name)'), 'JS present-field gate');
assertTrue(str_contains((string)$js, 'adminSettingsSaveInflight'), 'admin settings double-submit guard');
assertTrue(str_contains((string)$legacy, 'hasOwnProperty.call'), 'legacy fail-closed');
assertTrue(str_contains((string)$controller, 'allowedParamKeys($scope)'), 'controller section gate');
assertTrue(str_contains((string)$controller, 'function settings(): RedirectResponse'), 'index redirects');

// Breadcrumb IA: App → Administration (plain text) → Global settings (link) → section.
$crumb = file_get_contents($root . '/templates/common/breadcrumb.php');
$shell = file_get_contents($root . '/templates/common/page-start.php');
assertTrue(is_string($crumb) && str_contains((string)$crumb, 'azc-breadcrumb__list'), 'breadcrumb partial exists');
assertTrue(str_contains((string)$crumb, 'azc-breadcrumb__text'), 'text crumb for unlinked ancestors');
assertTrue(str_contains((string)$crumb, 'aria-current="page"'), 'current crumb marked');
assertTrue(str_contains((string)$shell, "include __DIR__ . '/breadcrumb.php'"), 'shell includes breadcrumb partial');
assertTrue(str_contains((string)$controller, "'breadcrumbParents'"), 'controller emits ancestor list');
assertTrue(str_contains((string)$controller, "\$this->l10n->t('Administration')"), 'Administration ancestor');
assertTrue(str_contains((string)$controller, "\$this->l10n->t('Global settings')"), 'Global settings ancestor link');

$mutCrumb = str_replace("'breadcrumbParents'", "'breadcrumbParentsRemoved'", (string)$controller);
assertTrue(!str_contains($mutCrumb, "'breadcrumbParents'"), 'mutation removes breadcrumbParents');
assertTrue(str_contains((string)$controller, "'breadcrumbParents'"), 'original keeps breadcrumbParents');

// Two-level topic tabs (DS §3.12.1): group tab row + active group's chips only.
$chipNav = file_get_contents($root . '/templates/common/azc-admin-settings-nav.php');
assertTrue(is_string($chipNav) && str_contains((string)$chipNav, 'azc-settings-nav__tabs'), 'group tab row');
assertTrue(str_contains((string)$chipNav, 'azc-settings-nav__tab'), 'group tab');
assertTrue(str_contains((string)$chipNav, 'aria-current="location"'), 'active group location marker');
assertTrue(str_contains((string)$chipNav, '$activeGroupSections'), 'only active group chips');
assertTrue(str_contains((string)$chipNav, 'azc-settings-nav__group'), 'index fallback grouped layout');

$mut = str_replace('public const LEGACY_ANCHORS = [', 'public const LEGACY_ANCHORS_REMOVED = [', (string)$catalog);
assertTrue(!str_contains($mut, 'public const LEGACY_ANCHORS = ['), 'mutation removes LEGACY_ANCHORS');
assertTrue(str_contains((string)$catalog, 'public const LEGACY_ANCHORS = ['), 'original keeps LEGACY_ANCHORS');

$mutJs = str_replace('function hasField(name)', 'function hasFieldRemoved(name)', (string)$js);
assertTrue(!str_contains($mutJs, 'function hasField(name)'), 'mutation removes hasField');
assertTrue(str_contains((string)$js, 'function hasField(name)'), 'original keeps hasField');

if ($failures > 0) {
	fwrite(STDERR, "\n$failures mutation contract failure(s)\n");
	exit(1);
}
fwrite(STDOUT, "\nAll admin settings multipage mutation contracts passed.\n");
exit(0);
