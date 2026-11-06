/**
 * Contract: rules that match <select> must not use the bare `background:`
 * shorthand — it resets the chevron's image/position/repeat/size to initial.
 * Regression of the 2026-11-04 farm class-gate
 * `required_field_or_step_undiscoverable` sweep (check-select-background-
 * shorthand.py): `.form-input`/`.form-select` rules match `select.form-input`
 * without naming `select`, and the shorthand wiped the NC core picker caret
 * (light: no chevron — reads as a plain text field; dark: tiled caret grid
 * after the theme rule re-added image only).
 *
 * Legs:
 *  1. literal — every ruleset whose selector text mentions `select` must not
 *     set the bare `background:` property inside its body.
 *  2. class sweep — classes provably applied to <select> elements in
 *     templates/ + js/ + src/ must not carry the bare `background:` shorthand
 *     in ANY rule that uses them (dialog selects sit outside #app-content,
 *     where no restoring rule exists).
 *  3. pins — the five rules the sweep fixed must paint `background-color:`
 *     and never reintroduce the shorthand.
 *
 * Run: node --test tests/js/select-chevron-contract.test.mjs
 */
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { dirname, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const appRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const cssRoot = join(appRoot, 'css');

function cssFiles(dir) {
	return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
		const path = join(dir, entry.name);
		if (entry.isDirectory()) {
			return cssFiles(path);
		}
		return entry.name.endsWith('.css') ? [path] : [];
	});
}

function stripComments(css) {
	return css.replace(/\/\*[\s\S]*?\*\//g, '');
}

/** Brace-aware rule extractor: yields {selector, body} for every ruleset. */
function* rules(css) {
	let i = 0;
	while (i < css.length) {
		const open = css.indexOf('{', i);
		if (open === -1) {
			return;
		}
		const selector = css.slice(css.lastIndexOf('}', i - 1) + 1, open).trim();
		let depth = 1;
		let j = open + 1;
		while (j < css.length && depth > 0) {
			if (css[j] === '{') depth++;
			else if (css[j] === '}') depth--;
			j++;
		}
		yield { selector, body: css.slice(open + 1, j - 1) };
		i = j;
	}
}

const BARE_BG = /(^|;)\s*background\s*:/;

function sourceFiles(dir, exts) {
	try {
		return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
			const path = join(dir, entry.name);
			if (entry.isDirectory()) {
				return sourceFiles(path, exts);
			}
			return exts.some((ext) => entry.name.endsWith(ext)) ? [path] : [];
		});
	} catch {
		return [];
	}
}

/** Classes provably applied to <select> elements in templates/ + js/ + src/. */
function selectAppliedClasses() {
	const classes = new Set();
	const addClassValue = (raw) => {
		for (const tok of raw.split(/\s+/)) {
			if (/^[A-Za-z_-][\w-]*$/.test(tok)) {
				classes.add(tok);
			}
		}
	};
	const dirs = [join(appRoot, 'templates'), join(appRoot, 'js'), join(appRoot, 'src')];
	for (const dir of dirs) {
		for (const file of sourceFiles(dir, ['.php', '.js', '.ts', '.vue'])) {
			const src = stripComments(readFileSync(file, 'utf8'));
			for (const m of src.matchAll(/<select\b[^>]*?\bclass\s*=\s*["']([^"']+)["']/gi)) {
				addClassValue(m[1]);
			}
			for (const m of src.matchAll(/(?:\bcreateElement|\bh|\$)\(\s*["']select["']/gi)) {
				let segment = src.slice(m.index + m[0].length, m.index + m[0].length + 600);
				const nxt = segment.search(/(?:\bcreateElement|\bh|\$)\(/i);
				if (nxt !== -1) {
					segment = segment.slice(0, nxt);
				}
				for (const cm of segment.matchAll(/class(?:Name)?\s*[:=]\s*["']([^"']+)["']/g)) {
					addClassValue(cm[1]);
				}
				for (const cl of segment.matchAll(/classList\.add\(([^)]*)\)/g)) {
					for (const qm of cl[1].matchAll(/["']([^"']+)["']/g)) {
						addClassValue(qm[1]);
					}
				}
			}
		}
	}
	return classes;
}

function* allRules() {
	for (const file of cssFiles(cssRoot)) {
		const css = stripComments(readFileSync(file, 'utf8'));
		for (const rule of rules(css)) {
			yield { file: relative(appRoot, file), ...rule };
		}
	}
}

describe('select chevron contract (bare background: shorthand)', () => {
	it('no select-matching rule uses the bare background: shorthand', () => {
		const offenders = [];
		for (const { file, selector, body } of allRules()) {
			if (!/\bselect\b/.test(selector)) {
				continue;
			}
			if (BARE_BG.test(body)) {
				offenders.push(`${file}: ${selector}`);
			}
		}
		assert.deepEqual(offenders, [],
			`bare background: shorthand resets the chevron (use background-color:):\n${offenders.join('\n')}`);
	});

	it('no rule on a select-applied class uses the bare background: shorthand', () => {
		const selectClasses = selectAppliedClasses();
		const offenders = [];
		for (const { file, selector, body } of allRules()) {
			if (/\bselect\b/.test(selector)) {
				continue; // covered by the literal-select leg
			}
			const hit = [...selectClasses].find((cls) =>
				new RegExp(`(?<![\\w-])\\.${cls.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}(?![\\w-])`).test(selector));
			if (hit && BARE_BG.test(body)) {
				offenders.push(`${file}: ${selector}  [class .${hit} applied to <select>]`);
			}
		}
		assert.deepEqual(offenders, [],
			`class rules matching <select> reset the chevron where no restoring rule re-adds it (use background-color:):\n${offenders.join('\n')}`);
	});

	it('the five fixed rules paint background-color:, never the shorthand', () => {
		const pins = [
			['css/admin-vacation-layers.css', /^\.form-input,\s*\.form-select,\s*\.form-textarea$/m],
			['css/app.css', /^\.form-input\[readonly\]$/m],
			['css/dashboard-widgets.css', /^\.dz-project-picker__select$/m],
			['css/reports.css', /^\.report-form \.form-input,\s*\.report-form \.form-select$/m],
			['css/time-entry-correction.css', /\.azc-manager-correction-modal select$/m],
		];
		for (const [file, selectorRe] of pins) {
			const css = stripComments(readFileSync(join(appRoot, file), 'utf8'));
			const rule = [...rules(css)].find((r) => selectorRe.test(r.selector));
			assert.ok(rule, `${file}: pinned rule not found (${selectorRe})`);
			assert.match(rule.body, /background-color\s*:/,
				`${file}: ${rule.selector} lost background-color:`);
			assert.doesNotMatch(rule.body, BARE_BG,
				`${file}: ${rule.selector} reintroduced the bare background: shorthand`);
		}
	});
});
