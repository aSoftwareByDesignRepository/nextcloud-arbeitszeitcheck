<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Support;

use OCA\ArbeitszeitCheck\AppInfo\Application;
use OCA\ArbeitszeitCheck\Service\TimeZoneService;
use OCP\AppFramework\Services\IInitialState;
use OCP\IDateTimeZone;
use OCP\Util;

/**
 * Single entry point for loading the client-side timezone stack.
 *
 * Every page, template, and dashboard widget that runs ArbeitszeitCheck
 * JavaScript must call {@see register()} (or at minimum {@see registerConfig()})
 * so `window.ArbeitszeitCheck.tz` and `window.ArbeitszeitCheck.serverNow` are
 * always defined before `js/common/time.js` executes.
 *
 * Configuration is emitted through Nextcloud {@see IInitialState} and applied
 * by `js/common/time-init.js` (regular app script) and again by `time.js` as a
 * safety net. This works on full app pages, widget-only dashboard loads, and
 * any future route that skips `templates/common/navigation.php`.
 *
 * CRITICAL: never register `time-init` via {@see Util::addInitScript()}. That
 * API auto-injects `l10n/<lang>.js` into the early init queue. On the Vue
 * home dashboard (`/apps/dashboard`) those translation files execute before
 * `window.OC` exists → `ReferenceError: OC is not defined` and a dead shell.
 */
final class TimeClientBootstrap {
	private const INITIAL_STATE_KEY = 'time';

	private static bool $configRegistered = false;
	private static bool $scriptsRegistered = false;

	public function __construct(
		private readonly TimeZoneService $timeZoneService,
		private readonly IDateTimeZone $dateTimeZone,
		private readonly IInitialState $initialState,
	) {
	}

	/**
	 * Register InitialState + time-init as a normal app script (idempotent per request).
	 */
	public function registerConfig(): void {
		if (self::$configRegistered) {
			return;
		}
		self::$configRegistered = true;

		$storageTz = $this->timeZoneService->storageTimeZone();
		try {
			$displayTz = $this->dateTimeZone->getTimeZone();
		} catch (\Throwable $e) {
			$displayTz = $storageTz;
		}

		$this->initialState->provideInitialState(self::INITIAL_STATE_KEY, [
			'tz' => [
				'storage' => $storageTz->getName(),
				'display' => $displayTz->getName(),
			],
			'serverNow' => $this->timeZoneService->nowInStorage()->format(\DateTimeInterface::ATOM),
		]);

		// l10n-boot defines __azcBootL10n and must precede the l10n/<lang>.js the
		// first non-l10n addScript() injects; its own path contains 'l10n' so it
		// triggers no injection itself.
		Util::addScript(Application::APP_ID, 'common/l10n-boot');
		// Regular script (NOT addInitScript): translations load only after OC exists.
		Util::addScript(Application::APP_ID, 'common/time-init');
	}

	/**
	 * Register `common/utils` (optional) and `common/time` (idempotent per request).
	 */
	public function registerScripts(bool $includeUtils = true): void {
		if (self::$scriptsRegistered) {
			return;
		}
		self::$scriptsRegistered = true;

		if ($includeUtils) {
			Util::addScript(Application::APP_ID, 'common/utils');
		}
		Util::addScript(Application::APP_ID, 'common/time');
	}

	/**
	 * Full client stack: config + scripts. Prefer this on widgets and new pages.
	 */
	public function register(bool $includeUtils = true): void {
		$this->registerConfig();
		$this->registerScripts($includeUtils);
	}

	/**
	 * Storage timezone for PHP templates (same source as the JS bootstrap).
	 */
	public function storageTimeZone(): \DateTimeZone {
		return $this->timeZoneService->storageTimeZone();
	}

	/**
	 * User display timezone for PHP templates.
	 */
	public function userDisplayTimeZone(): \DateTimeZone {
		try {
			return $this->dateTimeZone->getTimeZone();
		} catch (\Throwable $e) {
			return $this->storageTimeZone();
		}
	}

	/**
	 * Current server instant as ISO-8601 (storage TZ) for PHP templates.
	 */
	public function serverNowIso(): string {
		return $this->timeZoneService->nowInStorage()->format(\DateTimeInterface::ATOM);
	}
}
