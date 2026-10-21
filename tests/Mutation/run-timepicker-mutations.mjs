/**
 * Mutation gauntlet for the time-picker rules.
 * Each mutant must make js/common/timepicker-logic.test.js fail.
 * The source file is restored even when a mutant throws.
 *
 * Run: node tests/Mutation/run-timepicker-mutations.mjs
 */
import { spawnSync } from 'node:child_process'
import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..')
const srcPath = path.join(root, 'js/common/timepicker-logic.js')
const original = fs.readFileSync(srcPath, 'utf8')

const mutations = [
	['hour max 24', 'var HOUR_MAX = 23;', 'var HOUR_MAX = 24;'],
	['minute max 60', 'var MINUTE_MAX = 59;', 'var MINUTE_MAX = 60;'],
	['hour instant from 4', 'var HOUR_INSTANT_FROM = 3;', 'var HOUR_INSTANT_FROM = 4;'],
	['minute instant from 7', 'var MINUTE_INSTANT_FROM = 6;', 'var MINUTE_INSTANT_FROM = 7;'],
	['hour wrap drops a turn', '(current + dir + HOUR_MAX + 1) % (HOUR_MAX + 1)', '(current + dir + HOUR_MAX) % (HOUR_MAX + 1)'],
	['minute step stays put', 'Math.floor(current / s) * s + s', 'Math.floor(current / s) * s'],
	['minute wrap down to zero', 'return Math.floor(MINUTE_MAX / s) * s;', 'return 0;'],
	['clock accepts 24', '2[0-3]', '2[0-4]'],
	['step 45 kept', 'n > 30', 'n > 60'],
	['minute grid skips zero', 'for (var m = 0; m < 60; m += s)', 'for (var m = s; m < 60; m += s)'],
]

const survivors = []

function runTests() {
	return spawnSync('npx', ['vitest', 'run', 'js/common/timepicker-logic.test.js', '--no-cache'], {
		cwd: root,
		encoding: 'utf8',
	})
}

try {
	for (const [name, from, to] of mutations) {
		if (!original.includes(from)) {
			survivors.push(name + ' (needle missing)')
			continue
		}
		const next = original.replace(from, to)
		if (next === original) {
			survivors.push(name + ' (no change)')
			continue
		}
		fs.writeFileSync(srcPath, next)
		const result = runTests()
		if (result.status === 0) {
			survivors.push(name)
		}
	}
} finally {
	fs.writeFileSync(srcPath, original)
}

if (survivors.length > 0) {
	console.error('Surviving mutations:\n' + survivors.map((s) => ' - ' + s).join('\n'))
	process.exit(1)
}

console.log('Time picker mutations killed: ' + mutations.length)
