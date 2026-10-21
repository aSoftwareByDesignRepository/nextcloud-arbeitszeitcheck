<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for azc-btn cascade hardening (settings panel + desklet + print).
 *
 * Usage (Docker):
 *   docker compose exec -T nextcloud bash -lc \
 *     'cd /var/www/html/custom_apps/arbeitszeitcheck && php -d opcache.enable_cli=0 tests/Mutation/run-azc-btn-cascade-mutations.php'
 *
 * @copyright Copyright (c) 2026, Software by Design GbR
 * @license AGPL-3.0-or-later
 */

$appRoot = dirname(__DIR__, 2);
$phpunit = 'phpunit';
foreach ([
	$appRoot . '/vendor/bin/phpunit',
	dirname($appRoot) . '/budgetcheck/vendor/bin/phpunit',
] as $candidate) {
	if (is_file($candidate)) {
		$phpunit = $candidate;
		break;
	}
}

/**
 * @param list<string> $filters
 */
function run_filters(string $appRoot, string $phpunit, array $filters): int {
	$filter = implode('|', $filters);
	$cmd = escapeshellarg('php')
		. ' -d opcache.enable_cli=0 -d opcache.enable=0 '
		. escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter ' . escapeshellarg($filter);
	passthru($cmd, $code);
	return (int)$code;
}

function restore_file(string $path, string $backup): void {
	if (is_file($backup)) {
		rename($backup, $path);
	}
}

/**
 * @param array{file:string,from:string,to:string,filters:list<string>} $mutation
 */
function apply_and_expect_fail(string $appRoot, string $phpunit, string $name, array $mutation): bool {
	$path = $appRoot . '/' . $mutation['file'];
	$backup = $path . '.mutation-bak';
	$original = file_get_contents($path);
	if ($original === false) {
		fwrite(STDERR, "Cannot read {$path}\n");
		return false;
	}
	if (!str_contains($original, $mutation['from'])) {
		fwrite(STDERR, "Mutation anchor not found for {$name}\n");
		return false;
	}
	file_put_contents($backup, $original);
	$mutated = str_replace($mutation['from'], $mutation['to'], $original);
	if ($mutated === $original) {
		fwrite(STDERR, "Mutation replace had no effect for {$name}\n");
		restore_file($path, $backup);
		return false;
	}
	file_put_contents($path, $mutated);
	echo "\n== mutation: {$name} ==\n";
	$code = run_filters($appRoot, $phpunit, $mutation['filters']);
	restore_file($path, $backup);
	if ($code === 0) {
		echo "MUTATION SURVIVED: {$name}\n";
		return false;
	}
	echo "killed {$name}\n";
	return true;
}

$mutations = [
	'drop_settings_panel_btn_scope' => [
		'file' => 'css/app.css',
		'from' => '.azc-nc-settings-panel .azc-btn--primary',
		'to' => '.azc-nc-settings-panel .azc-btn--primX',
		'filters' => [
			'AzcBtnCascadeContractTest::testNcPersonalSettingsPanelIsHardenedLikeAppShell',
		],
	],
	'revert_desklet_to_bootstrap_btn_classes' => [
		'file' => 'templates/partials/dashboard-desklet-workspace.php',
		'from' => 'class="azc-btn azc-btn--primary" id="dz-clock-in"',
		'to' => 'class="btn btn-primary" id="dz-clock-in"',
		'filters' => [
			'AzcBtnCascadeContractTest::testDeskletUsesAzcBtnTaxonomyWithScopedFills',
			'DashboardDeskletWorkspaceRendererIntegrationTest::testRenderProducesDeskletMarkup',
		],
	],
	'drop_desklet_primary_fill' => [
		'file' => 'css/dashboard-widgets.css',
		'from' => ".dz-workspace .azc-btn--primary,\n.dz-workspace .btn-primary,\n.dz-workspace .btn--primary {\n\tbackground-color: var(--color-primary-element) !important;",
		'to' => ".dz-workspace .azc-btn--primary,\n.dz-workspace .btn-primary,\n.dz-workspace .btn--primary {\n\tbackground-color: transparent;",
		'filters' => [
			'AzcBtnCascadeContractTest::testDeskletUsesAzcBtnTaxonomyWithScopedFills',
		],
	],
	'reallow_print_underline_on_buttons' => [
		'file' => 'css/common/typography.css',
		'from' => "a:not(.btn):not(.azc-btn) {\n    color: var(--arbeitszeitcheck-text-color-primary) !important;\n    text-decoration: underline !important;\n  }",
		'to' => "a {\n    color: var(--arbeitszeitcheck-text-color-primary) !important;\n    text-decoration: underline !important;\n  }",
		'filters' => [
			'AzcBtnCascadeContractTest::testPrintStylesDoNotUnderlineButtonAnchors',
		],
	],
	'strip_kiosk_pin_secondary_variant' => [
		'file' => 'templates/admin-kiosk.php',
		'from' => 'id="azc-kiosk-pin-email" class="azc-btn azc-btn--secondary"',
		'to' => 'id="azc-kiosk-pin-email" class="azc-btn"',
		'filters' => [
			'AzcBtnCascadeContractTest::testKioskPinActionsUseButtonVariants',
		],
	],
	'drop_small_alias' => [
		'file' => 'css/app.css',
		'from' => ".azc-btn--small",
		'to' => ".azc-btn--tiny",
		'filters' => [
			'AzcBtnCascadeContractTest::testSmallAliasMirrorsSmSizing',
		],
	],
	'revert_secondary_fill_to_grey' => [
		'file' => 'css/app.css',
		'from' => "background-color: var(--color-primary-element-light, var(--color-primary-light)) !important;\n\tborder-color: var(--color-primary-element) !important;\n\tcolor: var(--azc-text, var(--color-main-text)) !important;",
		'to' => "background-color: color-mix(in srgb, var(--color-main-background) 82%, var(--color-main-text) 18%) !important;\n\tborder-color: var(--color-border) !important;\n\tcolor: var(--color-primary-element-light-text) !important;",
		'filters' => [
			'AzcBtnCascadeContractTest::testSecondaryFillIsPrimaryLightOnEverySurface',
		],
	],
	'drop_dialog_secondary_scope' => [
		'file' => 'css/app.css',
		'from' => '.azc-modal-backdrop .btn--secondary,',
		'to' => '.azc-modal-backdrop .btn--second,',
		'filters' => [
			'AzcBtnCascadeContractTest::testSecondaryFillIsPrimaryLightOnEverySurface',
		],
	],
	'revert_desklet_secondary_fill' => [
		'file' => 'css/dashboard-widgets.css',
		'from' => "background-color: var(--color-primary-element-light, var(--color-primary-light)) !important;\n\tborder-color: var(--color-primary-element) !important;",
		'to' => "background-color: color-mix(in srgb, var(--color-main-background) 82%, var(--color-main-text) 18%) !important;\n\tborder-color: var(--azc-form-border) !important;",
		'filters' => [
			'AzcBtnCascadeContractTest::testSecondaryFillIsPrimaryLightOnEverySurface',
		],
	],
	'revert_support_us_secondary_fill' => [
		'file' => 'css/admin-support-us.css',
		'from' => "a.azc-support-us__cta.azc-btn--secondary {\n\tbackground-color: var(--color-primary-element-light, var(--color-primary-light)) !important;",
		'to' => "a.azc-support-us__cta.azc-btn--secondary {\n\tbackground-color: color-mix(in srgb, var(--color-main-background) 82%, var(--color-main-text) 18%) !important;",
		'filters' => [
			'AzcBtnCascadeContractTest::testSecondaryFillIsPrimaryLightOnEverySurface',
		],
	],
	'revert_component_library_secondary_fill' => [
		'file' => 'css/common/components.css',
		'from' => ".btn--secondary {\n  background-color: var(--color-primary-element-light, var(--color-primary-light));",
		'to' => ".btn--secondary {\n  background-color: var(--color-background-dark);",
		'filters' => [
			'AzcBtnCascadeContractTest::testSecondaryFillIsPrimaryLightOnEverySurface',
		],
	],
];

echo "== baseline azc-btn cascade contracts ==\n";
$baseline = run_filters($appRoot, $phpunit, [
	'AzcBtnCascadeContractTest',
	'DashboardDeskletWorkspaceRendererIntegrationTest',
]);
if ($baseline !== 0) {
	fwrite(STDERR, "Baseline tests must pass before mutation run\n");
	exit(1);
}

$failed = [];
foreach ($mutations as $name => $mutation) {
	if (!apply_and_expect_fail($appRoot, $phpunit, $name, $mutation)) {
		$failed[] = $name;
	}
}

foreach ($mutations as $mutation) {
	$bak = $appRoot . '/' . $mutation['file'] . '.mutation-bak';
	if (is_file($bak)) {
		restore_file($appRoot . '/' . $mutation['file'], $bak);
	}
}

if ($failed !== []) {
	fwrite(STDERR, "Surviving mutations: " . implode(', ', $failed) . "\n");
	exit(1);
}

echo "\nAll azc-btn cascade mutations killed.\n";
exit(0);
