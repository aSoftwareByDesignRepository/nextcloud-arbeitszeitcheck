<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Util;

use OCP\IL10N;

/**
 * Safe server-side translations for templates (avoids json_encode on lazy IL10NString / vsprintf crashes).
 */
final class TemplateL10n {
	public const JSON_ENCODE_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;

	/**
	 * Numeric base for sentinel values used to round-trip %d placeholders through vsprintf.
	 * The formatted sentinel is replaced with the original placeholder after translation.
	 */
	private const NUMERIC_SENTINEL_BASE = 1908074000;

	/**
	 * Translate a message id without crashing on printf-style placeholders.
	 *
	 * When no parameters are given, placeholders (%s, %d, %1$s, %2$d, …) are preserved
	 * verbatim in the translated string so they can be substituted client-side.
	 *
	 * @param list<mixed> $parameters
	 */
	public static function translate(IL10N $l, string $id, array $parameters = []): string {
		if ($parameters !== []) {
			return (string)$l->t($id, $parameters);
		}

		[$arguments, $restore] = self::placeholderPreservingArguments($id);
		if ($arguments === []) {
			return (string)$l->t($id);
		}

		$translated = (string)$l->t($id, $arguments);

		return $restore === [] ? $translated : strtr($translated, $restore);
	}

	/**
	 * @param list<string> $messageIds Message ids used as both array keys and translation ids
	 *
	 * @return array<string, string>
	 */
	public static function mapFromMessageIds(IL10N $l, array $messageIds): array {
		$map = [];
		foreach ($messageIds as $messageId) {
			$map[$messageId] = self::translate($l, $messageId);
		}

		return $map;
	}

	/**
	 * Default vsprintf arguments when a message contains format placeholders.
	 * %s placeholders are passed through as their own literal (vsprintf('%1$s', ['%1$s'])
	 * is a fixed point); %d placeholders use numeric sentinels restored by translate().
	 *
	 * @return list<int|string>
	 */
	public static function placeholderArguments(string $id): array {
		return self::placeholderPreservingArguments($id)[0];
	}

	/**
	 * @return array{0: list<int|string>, 1: array<string, string>}
	 */
	private static function placeholderPreservingArguments(string $id): array {
		// %n is a client-side plural token (not vsprintf); preserve it like %s.
		if (preg_match_all('/%(?:(\d+)\$)?[sd]|%n/', $id, $matches, PREG_SET_ORDER) === false || $matches === []) {
			return [[], []];
		}

		$argumentsByPosition = [];
		$restore = [];
		$sequential = 0;
		foreach ($matches as $match) {
			$spec = $match[0];
			if ($spec === '%n') {
				// vsprintf ignores %n; pass a dummy so IL10N always receives args.
				$argumentsByPosition[$sequential++] = '%n';
				continue;
			}
			// PHP's argument pointer is only advanced by non-positional specs
			$position = ($match[1] ?? '') !== '' ? ((int)$match[1]) - 1 : $sequential++;

			if (str_ends_with($spec, 'd')) {
				$sentinel = self::NUMERIC_SENTINEL_BASE + $position;
				$argumentsByPosition[$position] = $sentinel;
				$restore[(string)$sentinel] = $spec;
			} else {
				$argumentsByPosition[$position] = $spec;
			}
		}

		$arguments = [];
		$maxPosition = max(array_keys($argumentsByPosition));
		for ($i = 0; $i <= $maxPosition; $i++) {
			$arguments[] = $argumentsByPosition[$i] ?? '';
		}

		return [$arguments, $restore];
	}
}
