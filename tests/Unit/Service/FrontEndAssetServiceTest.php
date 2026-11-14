<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\AppInfo\Application;
use OCA\ArbeitszeitCheck\Service\FrontEndAssetService;
use OCP\Util;
use PHPUnit\Framework\TestCase;

final class FrontEndAssetServiceTest extends TestCase
{
	protected function tearDown(): void
	{
		$ref = new \ReflectionClass(FrontEndAssetService::class);
		foreach (['coreRegistered', 'translationsRegistered'] as $property) {
			$prop = $ref->getProperty($property);
			$prop->setValue(null, false);
		}
		parent::tearDown();
	}

	public function testRegisterTranslationsLoadsBootBeforeLocaleJs(): void
	{
		FrontEndAssetService::registerTranslations();

		$ref = new \ReflectionClass(Util::class);
		$scriptsProp = $ref->getProperty('scripts');
		/** @var array<string, list<string>> $scripts */
		$scripts = $scriptsProp->getValue();
		$azc = $scripts[Application::APP_ID] ?? [];

		$bootIdx = array_search(Application::APP_ID . '/js/common/l10n-boot', $azc, true);
		$l10nIdx = false;
		foreach ($azc as $idx => $path) {
			if (str_contains((string)$path, Application::APP_ID . '/l10n/')) {
				$l10nIdx = $idx;
				break;
			}
		}

		$this->assertNotFalse($bootIdx, 'l10n-boot must be registered');
		$this->assertNotFalse($l10nIdx, 'locale JS must be registered');
		$this->assertLessThan($l10nIdx, $bootIdx, 'l10n-boot must load before locale JS');
	}
}
