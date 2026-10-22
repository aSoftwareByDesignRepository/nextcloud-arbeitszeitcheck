<?php

declare(strict_types=1);

/**
 * Server markup for the ArbeitszeitCheck time picker host.
 * The hidden input is the submitted value. js/common/timepicker.js builds the field.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Support;

final class TimePickerField {
	/**
	 * @param array{
	 *   id: string,
	 *   name: string,
	 *   value?: string,
	 *   labelId?: string,
	 *   describedBy?: string,
	 *   hourLabel: string,
	 *   minuteLabel: string,
	 *   openLabel: string,
	 *   hoursLabel?: string,
	 *   minutesLabel?: string,
	 *   doneLabel?: string,
	 *   notSetLabel?: string,
	 *   clearLabel?: string,
	 *   hiddenClass?: string,
	 *   breakIndex?: string,
	 *   required?: bool,
	 *   allowEmpty?: bool
	 * } $spec
	 */
	public static function render(array $spec): string {
		$id = self::token($spec['id'] ?? '', 'time picker id');
		$name = self::name($spec['name'] ?? '');
		$value = self::timeValue($spec['value'] ?? '');
		$allowEmpty = !empty($spec['allowEmpty']);
		$required = !empty($spec['required']);

		$attrs = [
			'class' => 'azc-timepicker',
			'data-azc-timepicker' => '',
			'data-allow-empty' => $allowEmpty ? '1' : '0',
			'data-hour-label' => self::plain($spec['hourLabel'] ?? ''),
			'data-minute-label' => self::plain($spec['minuteLabel'] ?? ''),
			'data-open-label' => self::plain($spec['openLabel'] ?? ''),
		];
		if ($required) {
			$attrs['data-required'] = '1';
		}
		self::optionalAttr($attrs, 'data-label-id', $spec['labelId'] ?? '', true);
		self::optionalAttr($attrs, 'data-described-by', $spec['describedBy'] ?? '', false);
		self::optionalAttr($attrs, 'data-hours-label', $spec['hoursLabel'] ?? '', false);
		self::optionalAttr($attrs, 'data-minutes-label', $spec['minutesLabel'] ?? '', false);
		self::optionalAttr($attrs, 'data-done-label', $spec['doneLabel'] ?? '', false);
		self::optionalAttr($attrs, 'data-not-set-label', $spec['notSetLabel'] ?? '', false);
		self::optionalAttr($attrs, 'data-clear-label', $spec['clearLabel'] ?? '', false);

		$hidden = [
			'type' => 'hidden',
			'id' => $id,
			'name' => $name,
			'value' => $value,
		];
		$hiddenClass = $spec['hiddenClass'] ?? '';
		if ($hiddenClass !== '') {
			$hidden['class'] = self::token($hiddenClass, 'time picker class');
		}
		if (isset($spec['breakIndex']) && $spec['breakIndex'] !== '') {
			if (!preg_match('/^\d+$/', (string)$spec['breakIndex'])) {
				throw new \InvalidArgumentException('time picker break index');
			}
			$hidden['data-break-index'] = (string)$spec['breakIndex'];
		}
		if ($required) {
			$hidden['required'] = 'required';
		}

		return '<div ' . self::attrList($attrs) . '><input ' . self::attrList($hidden) . '></div>';
	}

	/**
	 * @param array<string, string> $attrs
	 */
	private static function optionalAttr(array &$attrs, string $name, string $value, bool $token): void {
		if ($value === '') {
			return;
		}
		if ($token && !preg_match('/^[A-Za-z][A-Za-z0-9_.: -]*$/', $value)) {
			throw new \InvalidArgumentException('time picker ' . $name);
		}
		$attrs[$name] = $token ? $value : self::plain($value);
	}

	private static function token(string $value, string $label): string {
		if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $value)) {
			throw new \InvalidArgumentException($label);
		}

		return $value;
	}

	private static function name(string $value): string {
		if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]*(\[\d+\]\[[A-Za-z]+\])?$/', $value)) {
			throw new \InvalidArgumentException('time picker name');
		}

		return $value;
	}

	private static function timeValue(string $value): string {
		if ($value === '') {
			return '';
		}
		if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value)) {
			return '';
		}

		return $value;
	}

	private static function plain(string $value): string {
		return str_replace(["\r", "\n"], ' ', $value);
	}

	/**
	 * @param array<string, string> $attrs
	 */
	private static function attrList(array $attrs): string {
		$parts = [];
		foreach ($attrs as $name => $value) {
			if ($value === '' && str_starts_with($name, 'data-azc')) {
				$parts[] = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
				continue;
			}
			$parts[] = htmlspecialchars($name, ENT_QUOTES, 'UTF-8')
				. '="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"';
		}

		return implode(' ', $parts);
	}
}
