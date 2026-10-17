#!/usr/bin/env node
/**
 * Behavioural tests for ArbeitszeitCheck app-feedback.js (mailto + toast hook).
 *
 * Run: node tests/js/app-feedback.test.cjs
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ROOT = path.join(__dirname, '..', '..');
const SRC = fs.readFileSync(path.join(ROOT, 'js', 'common', 'app-feedback.js'), 'utf8');

let failures = 0;
function assert(cond, msg) {
	if (!cond) {
		failures += 1;
		process.stderr.write('FAIL: ' + msg + '\n');
	}
}

function makeDom() {
	const nodes = new Map();
	const doc = {
		readyState: 'complete',
		documentElement: { lang: 'en' },
		getElementById(id) {
			return nodes.get(id) || null;
		},
		querySelectorAll() {
			return [];
		},
		addEventListener() {},
	};
	return { doc, nodes };
}

function boot(extra) {
	const { doc, nodes } = makeDom();
	const config = {
		appId: 'arbeitszeitcheck',
		appDisplayName: 'ArbeitszeitCheck',
		appVersion: '1.0.0',
		feedbackEmail: 'dev@software-by-design.de',
		githubIssuesUrl: 'https://github.com/aSoftwareByDesignRepository/nextcloud-arbeitszeitcheck/issues',
		cssPrefix: 'azc',
		...(extra || {}),
	};
	const cfgEl = {
		textContent: JSON.stringify(config),
	};
	nodes.set('azc-app-feedback-config', cfgEl);

	const problem = {
		setAttribute(k, v) {
			this[k] = v;
		},
		href: '',
	};
	const idea = {
		setAttribute(k, v) {
			this[k] = v;
		},
		href: '',
	};
	nodes.set('azc-feedback-problem', problem);
	nodes.set('azc-feedback-idea', idea);

	const trigger = {
		className: 'azc-nav-footer__trigger',
		getAttribute(k) {
			return this[k] || null;
		},
		setAttribute(k, v) {
			this[k] = v;
		},
		contains() {
			return false;
		},
		addEventListener() {},
		focus() {},
	};
	trigger['aria-expanded'] = 'false';
	trigger['aria-controls'] = 'azc-feedback-menu';
	const menu = {
		id: 'azc-feedback-menu',
		hidden: true,
		contains() {
			return false;
		},
		querySelector() {
			return null;
		},
	};
	nodes.set('azc-feedback-menu', menu);
	doc.querySelector = (sel) => {
		if (sel === '.azc-nav-footer__trigger') {
			return trigger;
		}
		return null;
	};
	doc.getElementById = (id) => {
		if (id === 'azc-feedback-menu') {
			return menu;
		}
		return nodes.get(id) || null;
	};

	const sandbox = {
		console,
		URL,
		URLSearchParams,
		document: doc,
		window: null,
		location: {
			origin: 'https://cloud.example',
			pathname: '/apps/arbeitszeitcheck/today',
			search: '?token=secret&keep=1',
			href: 'https://cloud.example/apps/arbeitszeitcheck/today?token=secret&keep=1',
		},
		ArbeitszeitCheckComponents: null,
		DutyCheckComponents: null,
		CustomerCheckComponents: null,
		BudgetCheckComponents: null,
		AudioCheckComponents: null,
		ProjectCheckComponents: null,
		TicketCheckComponents: null,
		SnackCheckComponents: null,
		InventoryCheckComponents: null,
		MaintenanceCheckComponents: null,
		InvoiceCheckComponents: null,
		DeskCheckComponents: null,
		MobilityCheckComponents: null,
		t(appId, key) {
			return key;
		},
	};
	sandbox.window = sandbox;
	vm.runInNewContext(SRC, sandbox, { filename: 'app-feedback.js' });
	return { sandbox, problem, idea, doc, trigger, menu };
}

function decodeBody(mailto) {
	const q = mailto.split('?')[1] || '';
	const params = new URLSearchParams(q);
	return params.get('body') || '';
}

function main() {
	assert(SRC.includes('installPopover'), 'popover installer present in source');
	const installed = boot();
	assert(typeof installed.sandbox.SbdAppFeedback.install === 'function', 'install exported');
	installed.sandbox.SbdAppFeedback.install();
	assert(installed.trigger['aria-expanded'] === 'false', 'popover starts collapsed');
	assert(installed.menu.hidden === true, 'menu starts hidden');

	const { sandbox, problem, doc } = boot();
	assert(typeof sandbox.SbdAppFeedback === 'object', 'SbdAppFeedback exported');
	assert(typeof sandbox.SbdAppFeedback.sanitizePageUrl === 'function', 'sanitizePageUrl present');

	const clean = sandbox.SbdAppFeedback.sanitizePageUrl(
		'https://cloud.example/apps/arbeitszeitcheck/x?token=abc&keep=1&password=no'
	);
	assert(clean.includes('keep=1'), 'keeps safe query keys');
	assert(!clean.includes('token='), 'strips token query key');
	assert(!clean.includes('password='), 'strips password query key');
	assert(sandbox.SbdAppFeedback.sanitizePageUrl('javascript:alert(1)') === '', 'blocks javascript:');
	assert(sandbox.SbdAppFeedback.sanitizePageUrl('data:text/html,hi') === '', 'blocks data:');
	const upgradeAck = sandbox.SbdAppFeedback.sanitizePageUrl(
		'/apps/arbeitszeitcheck/?IKnowThatThisIsABigInstanceAndTheUpdateRequestCouldRunIntoATimeoutAndHowToRestoreABackup=IAmSuperSureToDoThis'
	);
	assert(upgradeAck === '/apps/arbeitszeitcheck/', 'strips Nextcloud web-upgrade ack query key');

	const mailto = sandbox.SbdAppFeedback.buildMailto('problem', { errorCode: 'CONFLICT' });
	assert(mailto.startsWith('mailto:dev@software-by-design.de'), 'mailto uses dev@ inbox');
	assert(!mailto.includes('info@software-by-design.de'), 'never routes to info@');
	assert(decodeBody(mailto).includes('Error code: CONFLICT'), 'includes safe error code');
	const unsafe = sandbox.SbdAppFeedback.buildMailto('problem', { errorCode: '<script>' });
	assert(!decodeBody(unsafe).includes('Error code:'), 'rejects unsafe error codes');

	assert(typeof problem.href === 'string' && problem.href.startsWith('mailto:dev@'), 'nav problem link refreshed');

	// Toast hook must not throw when wrapping showError.
	function makeToast(hasReportLink) {
		return {
			className: 'toast toast--error',
			setAttribute(k, v) {
				this[k] = v;
			},
			getAttribute(k) {
				return this[k] || null;
			},
			querySelector(sel) {
				// attachReportLink dedup guard: matches existing self-attached
				// report links (family class variants + any mailto: anchor).
				if (/toast__feedback|toast__report|mailto:/.test(String(sel))) {
					return hasReportLink ? { tagName: 'A' } : null;
				}
				return { appendChild() {} };
			},
		};
	}
	sandbox.BudgetCheckComponents = {
		showError(msg) {
			return msg;
		},
	};
	const toast = makeToast(false);
	doc.querySelectorAll = () => [toast];
	sandbox.SbdAppFeedback.install();
	try {
		sandbox.BudgetCheckComponents.showError('Save failed');
	} catch (e) {
		assert(false, 'showError wrapper must not throw: ' + e.message);
	}
	assert(toast.getAttribute('data-app-feedback-bound') === '1', 'toast gets report link binding');

	// Regression (duplicate_cta_in_toast_chrome): a toast that already
	// carries a self-attached mailto report link must not be bound again —
	// the observer/wrapper would otherwise append a second identical link.
	const preLinked = makeToast(true);
	doc.querySelectorAll = () => [preLinked];
	sandbox.BudgetCheckComponents.showError('Save failed again');
	assert(preLinked.getAttribute('data-app-feedback-bound') !== '1', 'pre-linked toast skipped — no duplicate report link');

	if (failures > 0) {
		process.stderr.write('\n' + failures + ' failure(s)\n');
		process.exit(1);
	}
	process.stdout.write('app-feedback.test.js OK (arbeitszeitcheck)\n');
}

main();
