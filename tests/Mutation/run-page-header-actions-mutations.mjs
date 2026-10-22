/**
 * Mutation gauntlet for header-action placement.
 * Each mutant must fail the matching test. Sources are restored.
 *
 * Run from the app directory:
 *   node tests/Mutation/run-page-header-actions-mutations.mjs
 */
import { spawnSync } from 'node:child_process'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const appRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..')
const nextcloudRoot = path.resolve(appRoot, '../..')

const fragment = `      const fragment = document.createDocumentFragment();
      while (source.firstChild) {
        fragment.appendChild(source.firstChild);
      }
      target.appendChild(fragment);`

const perNode = `      while (source.firstChild) {
        target.appendChild(source.firstChild);
      }`

const guard = `      if (source === target || target.contains(source)) {
        return;
      }
`

const mutations = [
	['per-button append', 'js/common/components.js', fragment, perNode, 'js'],
	['unwraps controls already in the slot', 'js/common/components.js', guard, '', 'js'],
	['steals card header actions', 'js/common/components.js', "'.azc-page-actions-source, .header-actions'", "'.azc-page-actions-source, .header-actions, .azc-card__header-actions'", 'js'],
	['slot is a live region again', 'templates/common/page-start.php', '<div id="azc-page-actions" class="azc-page-header__actions">', '<div id="azc-page-actions" class="azc-page-header__actions" aria-live="polite">', 'php'],
	['non-strings are printed', 'templates/common/page-start.php', 'is_string($pageHeaderActions) && ', '', 'php'],
	['empty slot still takes a column', 'css/app.css', '.azc-page-header__actions:empty {\n\tdisplay: none;\n}', '.azc-page-header__actions:empty {\n\tdisplay: flex;\n}', 'php'],
]

function run(kind) {
	if (kind === 'js') {
		return spawnSync('./node_modules/.bin/vitest', ['run', 'js/common/components.test.js'], {
			cwd: appRoot,
			encoding: 'utf8',
		})
	}
	return spawnSync('docker', [
		'compose', 'exec', '-u', 'www-data', '-T',
		'-w', '/var/www/html/custom_apps/arbeitszeitcheck',
		'nextcloud', 'php', '-d', 'opcache.enable_cli=0',
		'/var/www/html/custom_apps/budgetcheck/vendor/bin/phpunit',
		'--bootstrap', 'tests/bootstrap.php', '--no-configuration',
		'tests/Unit/Templates/PageHeaderActionsPlacementContractTest.php',
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
	for (const [name, file, from, to, kind] of mutations) {
		const full = path.join(appRoot, file)
		const original = originals.get(full)
		if (!original.includes(from)) {
			survived.push(`${name} (miss)`)
			console.error(`MISS ${name}`)
			continue
		}
		fs.writeFileSync(full, original.replace(from, to))
		const result = run(kind)
		fs.writeFileSync(full, original)
		if (result.status === 0) {
			survived.push(name)
			console.error(`SURVIVED ${name}`)
			if (result.stdout) {
				console.error(result.stdout.slice(-500))
			}
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

console.log(`${killed} killed, ${survived.length} survived`)
if (survived.length > 0) {
	console.error(survived.join('\n'))
	process.exit(1)
}
