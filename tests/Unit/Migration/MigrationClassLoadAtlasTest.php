<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Migration;

use OCP\Migration\IMigrationStep;
use PHPUnit\Framework\TestCase;

/**
 * Proves each shipped Version* migration class loads and implements IMigrationStep.
 */
class MigrationClassLoadAtlasTest extends TestCase
{
	public function testAllVersionMigrationsAreLoadableSteps(): void
	{
		$dir = dirname(__DIR__, 3) . '/lib/Migration';
		$files = glob($dir . '/Version*.php') ?: [];
		$this->assertNotEmpty($files);

		foreach ($files as $file) {
			$class = 'OCA\\ArbeitszeitCheck\\Migration\\' . basename($file, '.php');
			$this->assertTrue(class_exists($class), $class . ' must autoload');
			$obj = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
			$this->assertInstanceOf(IMigrationStep::class, $obj, $class);
		}
	}
}
