#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Ensures every translated string keeps the same placeholders as the English
 * source value (not the msgid: this app uses key-style ids such as "maxLength"
 * whose placeholders only appear in the en.json value).
 *
 * Checked per string, against en.json, for every locale:
 *   - printf-style placeholders consumed by IL10N/vsprintf: %s %d %1$s %2$d ...
 *     (compared as the multiset of consumed argument positions, so reordering
 *     via %1$s/%2$s is allowed but dropping/duplicating an argument is not)
 *   - literal %% escapes (IL10N always runs vsprintf, a bare % is a bug)
 *   - named tokens like {project} used by JS t() and notification rich objects
 *   - plural arrays: every form must carry the same tokens as the en forms
 *
 * Modeled on apps/projectcheck/scripts/check-l10n-placeholders.php, extended
 * to all locales.
 *
 * Exit 0 = OK, 1 = mismatch printed to STDERR.
 *
 * Usage (from app root):
 *   php scripts/check-l10n-placeholders.php
 *
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

$base = __DIR__ . '/../l10n';
$locales = ['de', 'fr', 'es', 'da', 'nl', 'it', 'pl', 'sv', 'nb', 'pt', 'pt_BR'];

/**
 * Extract a normalized placeholder signature of a string.
 *
 * @return array{positions: list<int>, named: list<string>, escapes: int}
 */
function azPlaceholderSignature(string $s): array {
	$positions = [];
	$named = [];
	$escapes = 0;
	$auto = 0;
	preg_match_all('/%%|%(\d+)\$[sd]|%[sd]|\{([A-Za-z0-9_]+)\}/', $s, $m, PREG_SET_ORDER);
	foreach ($m as $hit) {
		if ($hit[0] === '%%') {
			$escapes++;
		} elseif (isset($hit[2]) && $hit[2] !== '') {
			$named[] = $hit[2];
		} elseif (isset($hit[1]) && $hit[1] !== '') {
			$positions[] = (int)$hit[1];
		} else {
			$auto++;
			$positions[] = $auto;
		}
	}
	sort($positions);
	sort($named);
	return ['positions' => $positions, 'named' => $named, 'escapes' => $escapes];
}

function azSignatureToString(array $sig): string {
	$parts = [];
	foreach ($sig['positions'] as $p) {
		$parts[] = "%{$p}\$_";
	}
	foreach ($sig['named'] as $n) {
		$parts[] = '{' . $n . '}';
	}
	if ($sig['escapes'] > 0) {
		$parts[] = '%% x' . $sig['escapes'];
	}
	return $parts === [] ? '(none)' : implode(', ', $parts);
}

$enPath = $base . '/en.json';
if (!is_file($enPath)) {
	fwrite(STDERR, "Missing locale file: $enPath\n");
	exit(1);
}
$en = json_decode((string)file_get_contents($enPath), true, 512, JSON_THROW_ON_ERROR);
$enT = $en['translations'] ?? [];

$failed = false;

foreach ($locales as $lang) {
	$path = $base . '/' . $lang . '.json';
	if (!is_file($path)) {
		fwrite(STDERR, "Missing locale file: $path\n");
		exit(1);
	}
	$cat = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
	$trans = $cat['translations'] ?? [];

	foreach ($enT as $key => $enVal) {
		if (!array_key_exists($key, $trans)) {
			continue; // parity script reports missing keys
		}
		$val = $trans[$key];

		if (is_array($enVal)) {
			if (!is_array($val) || $val === []) {
				$failed = true;
				fwrite(STDERR, "{$lang}.json plural shape mismatch for key: {$key}\n");
				continue;
			}
			$enSig = azPlaceholderSignature(implode(' ', $enVal));
			// every plural form of the en source carries the same tokens; require
			// each translated form to match the first en form's signature
			$formSig = azPlaceholderSignature((string)$enVal[0]);
			foreach ($val as $i => $form) {
				$sig = azPlaceholderSignature((string)$form);
				if ($sig !== $formSig) {
					$failed = true;
					fwrite(STDERR, "{$lang}.json placeholder mismatch in plural form {$i} for key: {$key}\n");
					fwrite(STDERR, '  expected: ' . azSignatureToString($formSig) . "\n");
					fwrite(STDERR, '  got:      ' . azSignatureToString($sig) . "\n");
				}
			}
			continue;
		}

		$enSig = azPlaceholderSignature((string)$enVal);
		if ($enSig === ['positions' => [], 'named' => [], 'escapes' => 0]) {
			continue;
		}
		$sig = azPlaceholderSignature((string)$val);
		if ($sig !== $enSig) {
			$failed = true;
			fwrite(STDERR, "{$lang}.json placeholder mismatch for key: {$key}\n");
			fwrite(STDERR, '  expected: ' . azSignatureToString($enSig) . "\n");
			fwrite(STDERR, '  got:      ' . azSignatureToString($sig) . "\n");
		}
	}
}

// self-check: en msgids with printf placeholders must match their en value
foreach ($enT as $key => $enVal) {
	if (is_array($enVal)) {
		continue;
	}
	$keySig = azPlaceholderSignature($key);
	if ($keySig['positions'] === [] && $keySig['named'] === []) {
		continue;
	}
	$valSig = azPlaceholderSignature((string)$enVal);
	if ($valSig['positions'] !== $keySig['positions'] || $valSig['named'] !== $keySig['named']) {
		$failed = true;
		fwrite(STDERR, "en.json value placeholder mismatch against its own msgid: {$key}\n");
		fwrite(STDERR, '  msgid: ' . azSignatureToString($keySig) . "\n");
		fwrite(STDERR, '  value: ' . azSignatureToString($valSig) . "\n");
	}
}

if ($failed) {
	fwrite(STDERR, "\nl10n placeholder check FAILED.\n");
	exit(1);
}

echo "l10n placeholder check OK (printf + named tokens match en for de/fr/es/da/nl/it/pl/sv/nb).\n";
exit(0);
