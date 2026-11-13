<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for month-closure PDF premiums + DATEV Lohnart map.
 *
 * Usage (Docker):
 *   docker compose exec -u www-data -T nextcloud php -d opcache.enable_cli=0 -d opcache.enable=0 \
 *     /var/www/html/custom_apps/arbeitszeitcheck/tests/Mutation/run-premium-export-mutations.php
 *
 * Afterward repair bind-mount ownership (mutation rewrites sources as www-data).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

$appRoot = dirname(__DIR__, 2);
$phpunit = is_file($appRoot . '/vendor/bin/phpunit')
	? $appRoot . '/vendor/bin/phpunit'
	: 'phpunit';

/**
 * @param list<string> $filters
 */
function run_unit_tests(string $appRoot, string $phpunit, array $filters): int
{
	$filter = implode('|', $filters);
	$cmd = 'php -d opcache.enable_cli=0 -d opcache.enable=0 '
		. escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter ' . escapeshellarg($filter);
	passthru($cmd, $code);
	return (int)$code;
}

function restore(string $source, string $backup): void
{
	if (is_file($backup)) {
		copy($backup, $source); unlink($backup);
	}
}

$suiteFilters = [
	'MonthClosurePdfDocumentBuilderTest',
	'DatevPremiumLohnartMapTest',
	'DatevExportServiceTest::testExportAppendsMappedPremiumHoursOnly',
	'DatevExportServiceTest::testExportSkipsPremiumWhenMapEmptyEvenIfEnabled',
	'DatevExportServiceTest::testExportPrefersFrozenClosurePremiumOverLive',
	'DatevExportServiceTest::testExclusiveEndDoesNotBleedIntoNextMonthLivePremiums',
	'DatevExportServiceTest::testPartialClosedMonthStillUsesFrozenNotLive',
];

echo "== baseline premium export unit tests ==\n";
if (run_unit_tests($appRoot, $phpunit, $suiteFilters) !== 0) {
	fwrite(STDERR, "Baseline tests must pass before mutation run\n");
	exit(1);
}

/** @var list<array{name:string,file:string,from:string,to:string,filters?:list<string>}> $mutations */
$mutations = [
	[
		'name' => 'pdf_always_render_premium',
		'file' => 'lib/Service/MonthClosurePdfDocumentBuilder.php',
		'from' => 'if (is_array($premium) && !empty($premium[\'enabled\'])) {
			$this->renderPremiumSection($premium);
		}',
		'to' => 'if (is_array($premium)) {
			$this->renderPremiumSection($premium);
		}',
		'filters' => ['MonthClosurePdfDocumentBuilderTest::testPdfOmitsPremiumSectionWhenDisabledInSnapshot'],
	],
	[
		'name' => 'lohnart_accept_leading_zero',
		'file' => 'lib/Support/DatevPremiumLohnartMap.php',
		'from' => 'if (!preg_match(\'/^[1-9]\\d{0,3}$/\', $codeStr)) {',
		'to' => 'if (!preg_match(\'/^[0-9]{1,5}$/\', $codeStr)) {',
		'filters' => ['DatevPremiumLohnartMapTest::testValidateRejectsZeroPaddedAndNonDigits'],
	],
	[
		'name' => 'datev_export_valued_hours',
		'file' => 'lib/Service/DatevExportService.php',
		'from' => '$hours = round((float)($bucket[\'hours\'] ?? 0), 2);',
		'to' => '$hours = round((float)($bucket[\'valued_hours\'] ?? 0), 2);',
		'filters' => ['DatevExportServiceTest::testExportAppendsMappedPremiumHoursOnly'],
	],
	[
		'name' => 'datev_ignore_frozen_closure',
		'file' => 'lib/Service/DatevExportService.php',
		'from' => 'if ($frozen !== null) {
					// Sealed month: always emit frozen full-month buckets (payroll immutable).
					// Mid-month DATEV of a sealed month is month-granular by design.
					$this->mergePremiumBuckets($mergedHours, $mergedMeta, $frozen);
				} elseif ($this->premiumSurchargeService !== null) {',
		'to' => 'if (false && $frozen !== null) {
					// Sealed month: always emit frozen full-month buckets (payroll immutable).
					// Mid-month DATEV of a sealed month is month-granular by design.
					$this->mergePremiumBuckets($mergedHours, $mergedMeta, $frozen);
				} elseif ($this->premiumSurchargeService !== null) {',
		'filters' => [
			'DatevExportServiceTest::testExportPrefersFrozenClosurePremiumOverLive',
			'DatevExportServiceTest::testPartialClosedMonthStillUsesFrozenNotLive',
		],
	],
	[
		'name' => 'datev_treat_exclusive_end_as_inclusive',
		'file' => 'lib/Service/DatevExportService.php',
		'from' => '$rangeEndInclusive = $endEx->modify(\'-1 day\');
		return [$rangeStart, $rangeEndInclusive];',
		'to' => '$rangeEndInclusive = $endEx;
		return [$rangeStart, $rangeEndInclusive];',
		'filters' => ['DatevExportServiceTest::testExclusiveEndDoesNotBleedIntoNextMonthLivePremiums'],
	],
];

$failures = 0;
foreach ($mutations as $mutation) {
	$source = $appRoot . '/' . $mutation['file'];
	$backup = $source . '.mutbak';
	$filters = $mutation['filters'] ?? $suiteFilters;
	echo "== mutate: {$mutation['name']} ==\n";
	$contents = file_get_contents($source);
	if ($contents === false) {
		fwrite(STDERR, "Cannot read {$source}\n");
		$failures++;
		continue;
	}
	if (!str_contains($contents, $mutation['from'])) {
		fwrite(STDERR, "Mutation anchor not found for {$mutation['name']}\n");
		$failures++;
		continue;
	}
	copy($source, $backup);
	file_put_contents($source, str_replace($mutation['from'], $mutation['to'], $contents));
	$code = run_unit_tests($appRoot, $phpunit, $filters);
	restore($source, $backup);
	if ($code === 0) {
		fwrite(STDERR, "MUTATION SURVIVED (tests should have failed): {$mutation['name']}\n");
		$failures++;
	} else {
		echo "killed OK: {$mutation['name']}\n";
	}
}

if ($failures > 0) {
	fwrite(STDERR, "Mutation gauntlet failed: {$failures}\n");
	exit(1);
}

echo "All premium-export mutations killed.\n";
exit(0);
