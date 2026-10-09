<?php

declare(strict_types=1);

/**
 * AppConfig writes that survive mixed typed/untyped history.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Support;

use OCP\Exceptions\AppConfigTypeConflictException;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\Server;

/**
 * Writers MUST use the typed string API once a key exists as VALUE_STRING
 * (AdminController / AdminSettings write via
 * {@see \OCP\AppFramework\Services\IAppConfig::setAppValueString}). An untyped
 * {@see \OCP\IConfig::setAppValue()} on such a key throws
 * {@see AppConfigTypeConflictException} on Nextcloud 34+ — a live 500 was
 * observed on vacation-unit migration (vacation_carryover_max_days) and on
 * user deletion purging app-admin/access allowlists.
 *
 * The reverse direction is safe: keys stored as VALUE_MIXED (untyped history)
 * accept typed writes. So the rule is: write untyped first; on conflict, fall
 * back to the typed string write for that key.
 */
final class TypedAppConfigWrite
{
	/**
	 * @param callable(string, string, string): void|null $typedWriter
	 *        test seam — production resolves IAppConfig lazily.
	 */
	public static function setString(
		IConfig $config,
		string $app,
		string $key,
		string $value,
		?callable $typedWriter = null,
	): void {
		try {
			$config->setAppValue($app, $key, $value);
		} catch (AppConfigTypeConflictException) {
			$writer = $typedWriter ?? static function (string $a, string $k, string $v): void {
				Server::get(IAppConfig::class)->setValueString($a, $k, $v);
			};
			$writer($app, $key, $value);
		}
	}
}
