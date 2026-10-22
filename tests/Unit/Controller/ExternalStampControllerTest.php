<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Controller;

use OCA\ArbeitszeitCheck\Controller\ExternalStampController;
use OCA\ArbeitszeitCheck\Exception\BusinessRuleException;
use OCA\ArbeitszeitCheck\Exception\StampReplayException;
use OCA\ArbeitszeitCheck\Service\ExternalStampService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

class ExternalStampControllerTest extends TestCase {
	private ExternalStampService $service;
	private IUserSession $userSession;
	private IL10N $l10n;
	private ExternalStampController $controller;

	protected function setUp(): void {
		$this->service = $this->createMock(ExternalStampService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnArgument(0);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('worker1');
		$this->userSession->method('getUser')->willReturn($user);

		$this->controller = new ExternalStampController(
			'arbeitszeitcheck',
			$this->createMock(IRequest::class),
			$this->service,
			$this->userSession,
			$this->l10n,
		);
	}

	public function testConfirmHappy(): void {
		$this->service->method('confirm')->willReturn(['timeEntryId' => 42]);
		$res = $this->controller->confirm(7);
		$this->assertSame(Http::STATUS_OK, $res->getStatus());
		$this->assertTrue($res->getData()['success']);
		$this->assertSame(42, $res->getData()['timeEntryId']);
	}

	public function testConfirmNotFoundCollapsesForeignAndMissing(): void {
		$this->service->method('confirm')
			->willThrowException(new BusinessRuleException('Not found.', 'not_found'));
		$res = $this->controller->confirm(999999);
		$this->assertSame(Http::STATUS_NOT_FOUND, $res->getStatus());
		$this->assertSame('NOT_FOUND', $res->getData()['error']);
		$this->assertFalse($res->getData()['success']);
	}

	public function testConfirmAlreadyDecidedMapsToConflict(): void {
		$this->service->method('confirm')
			->willThrowException(new BusinessRuleException('decided', 'already_decided'));
		$res = $this->controller->confirm(7);
		$this->assertSame(Http::STATUS_CONFLICT, $res->getStatus());
		$this->assertSame('already_decided', $res->getData()['error_code']);
	}

	public function testConfirmStaleStampMapsToUnprocessableNotServerError(): void {
		$this->service->method('confirm')
			->willThrowException(new StampReplayException('STAMP_OCCURRED_AT_OUT_OF_BOUNDS'));
		$res = $this->controller->confirm(7);
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $res->getStatus());
		$this->assertFalse($res->getData()['success']);
		$this->assertSame('STAMP_OCCURRED_AT_OUT_OF_BOUNDS', $res->getData()['error_code']);
		$this->assertNotSame('An unexpected error occurred. Please try again.', $res->getData()['error']);
	}

	public function testDismissNotFound(): void {
		$this->service->method('dismiss')
			->willThrowException(new BusinessRuleException('Not found.', 'not_found'));
		$res = $this->controller->dismiss(999999);
		$this->assertSame(Http::STATUS_NOT_FOUND, $res->getStatus());
		$this->assertSame('NOT_FOUND', $res->getData()['error']);
	}

	public function testDismissAlreadyDecidedMapsToConflict(): void {
		$this->service->method('dismiss')
			->willThrowException(new BusinessRuleException('decided', 'already_decided'));
		$res = $this->controller->dismiss(7);
		$this->assertSame(Http::STATUS_CONFLICT, $res->getStatus());
	}

	public function testIndexUnauthenticated(): void {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);
		$controller = new ExternalStampController(
			'arbeitszeitcheck',
			$this->createMock(IRequest::class),
			$this->service,
			$userSession,
			$this->l10n,
		);
		$res = $controller->index();
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $res->getStatus());
	}
}
