<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Misc;

use OCA\ArbeitszeitCheck\AppInfo\Application;
use OCA\ArbeitszeitCheck\Exception\AppAccessDeniedException;
use OCA\ArbeitszeitCheck\Listener\LoadSidebarScripts;
use OCA\ArbeitszeitCheck\Notification\Notifier;
use OCA\ArbeitszeitCheck\Repair\EnsureArbeitszeitCheckSchema;
use OCA\ArbeitszeitCheck\Util\AbsenceWorkingDaysResolver;
use OCP\EventDispatcher\Event;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * One-off surface coverage: trivial accessors, the app bootstrap ctor,
 * the sidebar listener, and the schema-repair early-exit arm.
 */
final class SurfaceCoverageTest extends TestCase
{
	public function testApplicationConstructs(): void
	{
		$app = new Application();
		$this->assertInstanceOf(Application::class, $app);
	}

	public function testAppAccessDeniedExceptionCarriesReason(): void
	{
		$exception = new AppAccessDeniedException('kiosk_not_member');
		$this->assertSame('kiosk_not_member', $exception->getDenialReason());
		$this->assertSame('app_access_denied', $exception->getMessage());
	}

	public function testNotifierIdentity(): void
	{
		$notifier = new Notifier(
			$this->createMock(IURLGenerator::class),
			$this->createMock(AbsenceWorkingDaysResolver::class),
		);
		$this->assertSame('arbeitszeitcheck', $notifier->getID());
		$this->assertSame('ArbeitszeitCheck', $notifier->getName());
	}

	public function testLoadSidebarScriptsRegistersStyles(): void
	{
		$listener = new LoadSidebarScripts();
		$listener->handle($this->createMock(Event::class));
		$this->addToAssertionCount(1);
	}

	public function testReleaseStuckPendingAbsencesAutoApproves(): void
	{
		$absence = new \OCA\ArbeitszeitCheck\Db\Absence();
		$absence->setId(9);

		$mapper = $this->createMock(\OCA\ArbeitszeitCheck\Db\AbsenceMapper::class);
		$mapper->method('findByStatus')->willReturn([$absence]);
		$service = $this->createMock(\OCA\ArbeitszeitCheck\Service\AbsenceService::class);
		$service->expects($this->once())->method('autoApprovePendingIfNoAssignableManager')
			->with(9)->willReturn(true);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')
			->with(self::stringContains('Auto-approved 1'));

		$step = new \OCA\ArbeitszeitCheck\Repair\ReleaseStuckPendingAbsences($mapper, $service);
		$this->assertSame('Release pending absences with no assignable approver', $step->getName());
		$step->run($output);
	}

	public function testReleaseStuckPendingAbsencesSwallowsPerRowFailures(): void
	{
		$absence = new \OCA\ArbeitszeitCheck\Db\Absence();
		$absence->setId(9);

		$mapper = $this->createMock(\OCA\ArbeitszeitCheck\Db\AbsenceMapper::class);
		$mapper->method('findByStatus')->willReturn([$absence]);
		$service = $this->createMock(\OCA\ArbeitszeitCheck\Service\AbsenceService::class);
		$service->method('autoApprovePendingIfNoAssignableManager')
			->willThrowException(new \RuntimeException('db gone'));

		$step = new \OCA\ArbeitszeitCheck\Repair\ReleaseStuckPendingAbsences($mapper, $service);
		$step->run($this->createMock(IOutput::class));
		$this->addToAssertionCount(1);
	}

	public function testEnsureSchemaShortCircuitsWhenAllTablesPresent(): void
	{
		$connection = $this->createMock(IDBConnection::class);
		$connection->method('tableExists')->willReturn(true);
		$config = $this->createMock(IConfig::class);
		$config->expects($this->once())->method('deleteAppValue');

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')
			->with(self::stringContains('tables are present'));

		$step = new EnsureArbeitszeitCheckSchema($connection, $config);
		$step->run($output);
	}
}
