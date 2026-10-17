<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Integration;

use OCA\ArbeitszeitCheck\Controller\AbsenceTypeAdminController;
use OCA\ArbeitszeitCheck\Controller\AdminController;
use OCA\ArbeitszeitCheck\Exception\NotAppAdminException;
use OCA\ArbeitszeitCheck\Middleware\AppAdminMiddleware;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\TemplateResponse;
use Test\TestCase;

class AppAdminAuthorizationIntegrationTest extends TestCase
{
	public function testMiddlewareAndAdminControllerAreResolvableFromContainer(): void
	{
		$middleware = \OCP\Server::get(AppAdminMiddleware::class);
		$controller = \OCP\Server::get(AdminController::class);

		$this->assertInstanceOf(AppAdminMiddleware::class, $middleware);
		$this->assertInstanceOf(AdminController::class, $controller);
	}

	public function testMiddlewareGatesAbsenceTypeAdminController(): void
	{
		$middleware = \OC::$server->query(AppAdminMiddleware::class);
		$controller = \OC::$server->query(AbsenceTypeAdminController::class);

		$this->assertInstanceOf(AbsenceTypeAdminController::class, $controller);
		$this->expectException(NotAppAdminException::class);
		$middleware->beforeController($controller, 'list');
	}

	public function testMiddlewareDeniesAdminControllerWhenNoAuthorizedSession(): void
	{
		$middleware = \OCP\Server::get(AppAdminMiddleware::class);
		$controller = \OCP\Server::get(AdminController::class);

		$this->expectException(NotAppAdminException::class);
		$middleware->beforeController($controller, 'settings');
	}

	public function testMiddlewareConvertsAuthorizationExceptionTo403Response(): void
	{
		$middleware = \OCP\Server::get(AppAdminMiddleware::class);
		$response = $middleware->afterException(
			new \stdClass(),
			'settings',
			new NotAppAdminException('Access denied for integration test')
		);

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('access-denied', $response->getTemplateName());
	}
}
