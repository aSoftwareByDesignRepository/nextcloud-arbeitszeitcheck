<?php

declare(strict_types=1);

/**
 * Auth/surface contract for the employee Saldo PDF endpoint (Slice B).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Controller;

use OCA\ArbeitszeitCheck\Controller\PageController;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class OvertimeBalancePdfAuthContractTest extends TestCase
{
	public function testOvertimeBalancePdfIsSessionScopedNotPublic(): void
	{
		$method = new ReflectionMethod(PageController::class, 'overtimeBalancePdf');
		$attrs = array_map(
			static fn ($a) => $a->getName(),
			$method->getAttributes()
		);

		$this->assertContains(NoAdminRequired::class, $attrs, 'Employees must reach the endpoint');
		$this->assertContains(NoCSRFRequired::class, $attrs, 'GET download uses session cookie');
		$this->assertContains(
			\OCP\AppFramework\Http\Attribute\UserRateLimit::class,
			$attrs,
			'PDF generation must be rate-limited'
		);
		$this->assertNotContains(PublicPage::class, $attrs, 'Must never be anonymously downloadable');

		$params = $method->getParameters();
		$this->assertSame([], $params, 'No userId/query target — balance is always the session user');

		$src = file_get_contents(__DIR__ . '/../../../lib/Controller/PageController.php');
		$this->assertNotFalse($src);
		$this->assertMatchesRegularExpression(
			'/function overtimeBalancePdf\(\)[\s\S]*?getUserId\(\)/',
			$src
		);
		$this->assertDoesNotMatchRegularExpression(
			'/function overtimeBalancePdf\(\)[\s\S]*?getParam\(\s*[\'"]userId[\'"]/',
			$src
		);
	}
}
