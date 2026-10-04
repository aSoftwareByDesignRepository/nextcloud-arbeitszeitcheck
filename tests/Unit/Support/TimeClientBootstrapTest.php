<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use OCA\ArbeitszeitCheck\Service\TimeZoneService;
use OCA\ArbeitszeitCheck\Support\DashboardWidgetAssetBootstrap;
use OCA\ArbeitszeitCheck\Support\TimeClientBootstrap;
use OCP\AppFramework\Services\IInitialState;
use OCP\IConfig;
use OCP\IDateTimeZone;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class TimeClientBootstrapTest extends TestCase {
	protected function tearDown(): void {
		$ref = new \ReflectionClass(TimeClientBootstrap::class);
		foreach (['configRegistered', 'scriptsRegistered'] as $property) {
			$prop = $ref->getProperty($property);
			$prop->setAccessible(true);
			$prop->setValue(null, false);
		}
		$ref = new \ReflectionClass(DashboardWidgetAssetBootstrap::class);
		$prop = $ref->getProperty('deskletAssetsRegistered');
		$prop->setAccessible(true);
		$prop->setValue(null, false);
		parent::tearDown();
	}

	private function createBootstrap(IInitialState $initialState): TimeClientBootstrap {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(fn ($app, $key, $default) => match ($key) {
			'app_timezone' => 'Europe/Berlin',
			default => $default,
		});
		$dateTimeZone = $this->createMock(IDateTimeZone::class);
		$dateTimeZone->method('getTimeZone')->willReturn(new \DateTimeZone('Europe/Berlin'));
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);
		$timeZoneService = new TimeZoneService($config, $dateTimeZone, $userSession, new NullLogger());

		return new TimeClientBootstrap($timeZoneService, $dateTimeZone, $initialState);
	}

	public function testRegisterConfigEmitsTimeInitialState(): void {
		$initialState = $this->createMock(IInitialState::class);
		$initialState->expects($this->once())
			->method('provideInitialState')
			->with(
				'time',
				$this->callback(static function (array $payload): bool {
					return isset($payload['tz']['storage'], $payload['tz']['display'], $payload['serverNow'])
						&& $payload['tz']['storage'] === 'Europe/Berlin'
						&& $payload['tz']['display'] === 'Europe/Berlin'
						&& is_string($payload['serverNow'])
						&& $payload['serverNow'] !== '';
				})
			);

		$bootstrap = $this->createBootstrap($initialState);
		$bootstrap->registerConfig();
	}

	public function testRegisterConfigIsIdempotentPerRequest(): void {
		$initialState = $this->createMock(IInitialState::class);
		$initialState->expects($this->once())->method('provideInitialState');

		$bootstrap = $this->createBootstrap($initialState);
		$bootstrap->registerConfig();
		$bootstrap->registerConfig();
	}

	public function testRegisterConfigDoesNotPushTranslationsIntoInitQueue(): void {
		$initialState = $this->createMock(IInitialState::class);
		$bootstrap = $this->createBootstrap($initialState);
		$bootstrap->registerConfig();

		$ref = new \ReflectionClass(\OCP\Util::class);
		$initProp = $ref->getProperty('scriptsInit');
		$initProp->setAccessible(true);
		/** @var list<string> $initScripts */
		$initScripts = $initProp->getValue();

		foreach ($initScripts as $path) {
			$this->assertStringNotContainsString(
				'arbeitszeitcheck/l10n',
				(string)$path,
				'l10n/*.js must not load via scriptsInit — OC is undefined that early on /apps/dashboard'
			);
			$this->assertStringNotContainsString(
				'arbeitszeitcheck/js/common/time-init',
				(string)$path,
				'time-init must not use addInitScript (forces premature l10n preload)'
			);
		}

		$scriptsProp = $ref->getProperty('scripts');
		$scriptsProp->setAccessible(true);
		/** @var array<string, list<string>> $scripts */
		$scripts = $scriptsProp->getValue();
		$azc = $scripts['arbeitszeitcheck'] ?? [];
		$this->assertContains(
			'arbeitszeitcheck/js/common/time-init',
			$azc,
			'time-init must register as a normal app script after OC exists'
		);
	}

	public function testSourceForbidsAddInitScriptCall(): void {
		$src = (string)file_get_contents(__DIR__ . '/../../../lib/Support/TimeClientBootstrap.php');
		// Docblocks may mention addInitScript as a warning; executable calls must not exist.
		$this->assertStringNotContainsString(
			'Util::addInitScript(Application::APP_ID',
			$src
		);
		$this->assertDoesNotMatchRegularExpression(
			'/^\s*Util::addInitScript\s*\(/m',
			$src
		);
		$this->assertStringContainsString("Util::addScript(Application::APP_ID, 'common/time-init')", $src);
	}

	public function testDashboardPathsLoadL10nBootBeforeLocaleJs(): void {
		$initialState = $this->createMock(IInitialState::class);
		$this->createBootstrap($initialState)->registerConfig();
		DashboardWidgetAssetBootstrap::registerDeskletAssets();

		$ref = new \ReflectionClass(\OCP\Util::class);
		$scriptsProp = $ref->getProperty('scripts');
		$scriptsProp->setAccessible(true);
		/** @var array<string, list<string>> $scripts */
		$scripts = $scriptsProp->getValue();
		$azc = $scripts['arbeitszeitcheck'] ?? [];

		$bootIdx = array_search('arbeitszeitcheck/js/common/l10n-boot', $azc, true);
		$l10nIdx = false;
		foreach ($azc as $idx => $path) {
			if (str_contains((string)$path, 'arbeitszeitcheck/l10n/')) {
				$l10nIdx = $idx;
				break;
			}
		}

		$this->assertNotFalse($bootIdx, 'l10n-boot must register on dashboard script paths');
		$this->assertNotFalse($l10nIdx, 'locale JS must be injected alongside app scripts');
		$this->assertLessThan(
			$l10nIdx,
			$bootIdx,
			'l10n-boot must load before the injected l10n/*.js or __azcBootL10n is undefined on /apps/dashboard'
		);
	}

	public function testStorageAndDisplayTimeZonesMatchService(): void {
		$initialState = $this->createMock(IInitialState::class);
		$bootstrap = $this->createBootstrap($initialState);

		$this->assertSame('Europe/Berlin', $bootstrap->storageTimeZone()->getName());
		$this->assertSame('Europe/Berlin', $bootstrap->userDisplayTimeZone()->getName());
		$this->assertNotSame('', $bootstrap->serverNowIso());
	}
}
