/**
 * Mutation gauntlet for the compliance page scope and report totals.
 * Each mutant must fail the compliance unit tests. The sources are restored.
 *
 * Run from nextcloud/:
 *   node apps/arbeitszeitcheck/tests/Mutation/run-compliance-page-mutations.mjs
 */
import { spawnSync } from 'node:child_process'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const appRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..')
const nextcloudRoot = path.resolve(appRoot, '../..')

const mutations = [
	['other id treated as self', 'lib/Support/CompliancePageScope.php', '|| $requested === $currentUserId', '|| $requested !== $currentUserId'],
	['permission check inverted', 'lib/Support/CompliancePageScope.php', '$canView($currentUserId, $requested) !== true', '$canView($currentUserId, $requested) === true'],
	['strict permission loosened', 'lib/Support/CompliancePageScope.php', '!== true', '!= true'],
	['non-strings are cast', 'lib/Support/CompliancePageScope.php', 'is_string($requestedUserId) ? trim($requestedUserId) : \'\'', 'trim((string)$requestedUserId)'],
	['self page exposes a filter', 'lib/Support/CompliancePageScope.php', "'filterUserId' => null", "'filterUserId' => $requested"],
	['unresolved inverted', 'lib/Support/ComplianceReportSummary.php', 'empty($row[\'resolved\'])', '!empty($row[\'resolved\'])'],
	['total not counted', 'lib/Support/ComplianceReportSummary.php', "$report['total_violations']++", "$report['total_violations'] += 0"],
	['open problems not counted', 'lib/Support/ComplianceReportSummary.php', "$report['unresolved']++", "$report['unresolved'] += 0"],
	['rows that are not arrays are counted', 'lib/Support/ComplianceReportSummary.php', 'if (!is_array($row))', 'if (is_array($row))'],
	['type order not stable', 'lib/Support/ComplianceReportSummary.php', "ksort(\$report['by_type']);\n\t\tksort(\$report['by_severity']);", ''],
	['page ignores the scoped person', 'lib/Controller/ComplianceController.php', "$targetUserId = \$scope['targetUserId'];", '$targetUserId = $userId;'],
	['admin scan shown to everyone', 'lib/Controller/ComplianceController.php', 'showComplianceRunCheck\' => $this->permissionService->isAdmin($userId)', "showComplianceRunCheck' => true"],
	['help disclosure comes back', 'lib/Controller/ComplianceController.php', "'collapsePageHelp' => false", "'collapsePageHelp' => true"],
]

const files = [
	'tests/Unit/Support/CompliancePageScopeTest.php',
	'tests/Unit/Support/ComplianceReportSummaryTest.php',
	'tests/Unit/CompliancePageTemplateContractTest.php',
	'tests/Unit/Controller/ComplianceControllerDashboardTest.php',
]

function phpunit() {
	const script = files.map((file) =>
		`php -d opcache.enable_cli=0 /var/www/html/custom_apps/budgetcheck/vendor/bin/phpunit --bootstrap tests/bootstrap.php --no-configuration ${file}`,
	).join(' && ')
	return spawnSync('docker', [
		'compose', 'exec', '-u', 'www-data', '-T',
		'-w', '/var/www/html/custom_apps/arbeitszeitcheck',
		'nextcloud', 'sh', '-c', script,
	], {
		cwd: nextcloudRoot,
		encoding: 'utf8',
	})
}

const originals = new Map()
for (const file of new Set(mutations.map((item) => item[1]))) {
	const full = path.join(appRoot, file)
	originals.set(full, fs.readFileSync(full, 'utf8'))
}

const survived = []
let killed = 0

try {
	for (const [name, file, from, to] of mutations) {
		const full = path.join(appRoot, file)
		const original = originals.get(full)
		const mutated = original.replace(from, to)
		if (mutated === original) {
			survived.push(`${name} (miss)`)
			console.error(`MISS ${name}`)
			continue
		}
		fs.writeFileSync(full, mutated)
		const result = phpunit()
		fs.writeFileSync(full, original)
		if (result.status === 0) {
			survived.push(name)
			console.error(`SURVIVED ${name}`)
		} else {
			killed += 1
			console.log(`Killed ${name}`)
		}
	}
} finally {
	for (const [full, original] of originals) {
		fs.writeFileSync(full, original)
	}
}

console.log(`Killed ${killed} / ${mutations.length}`)
if (survived.length > 0) {
	console.error(`Surviving mutants: ${survived.join(', ')}`)
	process.exit(1)
}
