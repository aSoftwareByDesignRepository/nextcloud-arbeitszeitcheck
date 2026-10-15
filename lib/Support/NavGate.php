<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Synced verbatim from apps/_shared/nav-gate/NavGate.php.tpl — do not edit
 * the copy, edit the template and re-run sync_nav_gate.py.
 */

namespace OCA\ArbeitszeitCheck\Support;

use OCP\AppFramework\IAppContainer;
use OCP\INavigationManager;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\L10N\IFactory as IL10NFactory;
use Psr\Log\LoggerInterface;

/**
 * Per-user app-menu gating.
 *
 * Core offers no per-user gate for info.xml <navigation> entries (only
 * role="admin"), so a statically declared entry advertises the app to every
 * logged-in user — including users the app's own access gate would 403/404.
 * That is both a dead end and, for restricted apps, an existence oracle.
 *
 * INavigationManager::add() accepts a Closure resolved lazily inside
 * NavigationManager::init() on every request — so the entry can be
 * conditional. For excluded users we return a non-'link' entry, which
 * getAll('link') filters out of the web menu AND the OCS navigation
 * endpoint (NC mobile/desktop clients) alike. The entry does not reach
 * any nav surface; nothing renders, nothing flashes, nothing leaks.
 *
 * Group-limiting the app itself is NOT an alternative: it disables the app
 * for anonymous requests entirely, which would kill public pages.
 */
final class NavGate
{
	private function __construct()
	{
	}

	/**
	 * @param callable(string $uid, IAppContainer $container): bool $hasAccess
	 *        whole-app access predicate; runs on every nav build — keep it
	 *        cheap (group/config checks, not queries). Throws → hidden
	 *        (fail closed, never advertise a dead door).
	 */
	public static function register(
		IAppContainer $container,
		string $route,
		string $icon,
		string $name,
		int $order,
		callable $hasAccess,
	): void {
		try {
			$nav = $container->get(INavigationManager::class);
		} catch (\Throwable $e) {
			// Nav registration must never fatal a request — fail closed and log.
			try {
				$container->get(LoggerInterface::class)->error('NavGate: navigation manager unavailable', [
					'app' => 'arbeitszeitcheck', 'exception' => $e,
				]);
			} catch (\Throwable) {
			}
			return;
		}
		$nav->add(
			static function () use ($container, $route, $icon, $name, $order, $hasAccess): array {
				$uid = $container->get(IUserSession::class)->getUser()?->getUID();
				$allowed = false;
				if ($uid !== null && $uid !== '') {
					try {
						$allowed = (bool)$hasAccess($uid, $container);
					} catch (\Throwable) {
						$allowed = false;
					}
				}
				if (!$allowed) {
					return [
						'id' => 'arbeitszeitcheck',
						'type' => 'hidden',
						'name' => '',
						'order' => PHP_INT_MAX,
					];
				}
				$url = $container->get(IURLGenerator::class);
				try {
					$iconPath = $url->imagePath('arbeitszeitcheck', $icon);
				} catch (\RuntimeException) {
					$iconPath = $url->imagePath('core', 'default-app-icon');
				}
				return [
					'id' => 'arbeitszeitcheck',
					'app' => 'arbeitszeitcheck',
					'type' => 'link',
					'order' => $order,
					'href' => $url->linkToRoute($route),
					'icon' => $iconPath,
					'name' => $container->get(IL10NFactory::class)->get('arbeitszeitcheck')->t($name),
				];
			},
		);
	}
}
