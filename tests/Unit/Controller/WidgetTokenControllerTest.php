<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Controller;

use OCA\ArbeitszeitCheck\Controller\WidgetTokenController;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\WidgetToken;
use OCA\ArbeitszeitCheck\Exception\WidgetTokenLimitException;
use OCA\ArbeitszeitCheck\Service\LiveActivityPushService;
use OCA\ArbeitszeitCheck\Service\MobileStampReplayService;
use OCA\ArbeitszeitCheck\Service\WidgetTokenService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class WidgetTokenControllerTest extends TestCase
{
	private WidgetTokenController $controller;
	/** @var WidgetTokenService&MockObject */
	private $tokens;
	/** @var MobileStampReplayService&MockObject */
	private $stampReplay;
	/** @var TimeEntryMapper&MockObject */
	private $timeEntryMapper;
	/** @var IUserSession&MockObject */
	private $userSession;
	/** @var LiveActivityPushService&MockObject */
	private $livePush;

	protected function setUp(): void
	{
		parent::setUp();
		$this->tokens = $this->createMock(WidgetTokenService::class);
		$this->stampReplay = $this->createMock(MobileStampReplayService::class);
		$this->timeEntryMapper = $this->createMock(TimeEntryMapper::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->livePush = $this->createMock(LiveActivityPushService::class);

		$this->controller = new WidgetTokenController(
			'arbeitszeitcheck',
			$this->createMock(IRequest::class),
			$this->tokens,
			$this->stampReplay,
			$this->timeEntryMapper,
			$this->userSession,
			$this->livePush,
		);
	}

	private function loginAs(string $uid): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}

	public function testIssueRequiresSession(): void
	{
		$this->userSession->method('getUser')->willReturn(null);
		$res = $this->controller->issue();
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $res->getStatus());
	}

	public function testIssueReturnsRawTokenOnceWith201(): void
	{
		$this->loginAs('u1');
		$entity = new WidgetToken();
		$entity->setId(9);
		$entity->setScope(WidgetTokenService::SCOPE_CLOCK);
		$entity->setExpiresAt(new \DateTime('2027-05-01T00:00:00Z'));
		$this->tokens->expects($this->once())
			->method('issue')
			->with('u1', WidgetTokenService::SCOPE_CLOCK, 'Pixel 8')
			->willReturn([$entity, 'raw-xyz']);

		$res = $this->controller->issue('azc-clock', 'Pixel 8');

		$this->assertSame(Http::STATUS_CREATED, $res->getStatus());
		$data = $res->getData();
		$this->assertSame(9, $data['id']);
		$this->assertSame('raw-xyz', $data['token']);
		$this->assertSame('azc-clock', $data['scope']);
	}

	public function testIssueUnknownScopeYields400(): void
	{
		$this->loginAs('u1');
		$this->tokens->method('issue')->willThrowException(new \InvalidArgumentException());
		$res = $this->controller->issue('evil-scope');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $res->getStatus());
		$this->assertSame('unknown_scope', $res->getData()['error']);
	}

	public function testIssueAtCapYields409(): void
	{
		$this->loginAs('u1');
		$this->tokens->method('issue')->willThrowException(new WidgetTokenLimitException());
		$res = $this->controller->issue();
		$this->assertSame(Http::STATUS_CONFLICT, $res->getStatus());
		$this->assertSame('token_limit', $res->getData()['error']);
	}

	public function testIndexRequiresSession(): void
	{
		$this->userSession->method('getUser')->willReturn(null);
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller->index()->getStatus());
	}

	public function testIndexReturnsPublicSafeRows(): void
	{
		$this->loginAs('u1');
		$rows = [['id' => 3, 'scope' => 'azc-clock', 'revoked' => false]];
		$this->tokens->method('listForUser')->with('u1')->willReturn($rows);
		$res = $this->controller->index();
		$this->assertSame(Http::STATUS_OK, $res->getStatus());
		$this->assertSame($rows, $res->getData()['tokens']);
	}

	public function testRevokeNotFoundIsUniform404(): void
	{
		$this->loginAs('u1');
		$this->tokens->method('revoke')->with(5, 'u1')->willReturn(false);
		$res = $this->controller->revoke(5);
		$this->assertSame(Http::STATUS_NOT_FOUND, $res->getStatus());
		$this->assertSame('not_found', $res->getData()['error']);
	}

	public function testRevokeSuccess(): void
	{
		$this->loginAs('u1');
		$this->tokens->method('revoke')->with(5, 'u1')->willReturn(true);
		$res = $this->controller->revoke(5);
		$this->assertSame(Http::STATUS_OK, $res->getStatus());
		$this->assertTrue($res->getData()['ok']);
	}

	public function testClockToggleWithoutTokenDegradesToNavigate(): void
	{
		$res = $this->controller->clockToggle(null, 'req-1');
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $res->getStatus());
		$this->assertSame('navigate', $res->getData()['action']);
	}

	public function testClockToggleInvalidTokenDegradesToNavigate(): void
	{
		$this->tokens->method('verify')->willThrowException(new DoesNotExistException('x'));
		$res = $this->controller->clockToggle('bad-token', 'req-1');
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $res->getStatus());
		$this->assertSame('navigate', $res->getData()['action']);
	}

	public function testClockToggleExecutesClockInWithWidgetChannel(): void
	{
		$this->loginAs('unused'); // token is the credential — session may be absent
		$token = new WidgetToken();
		$token->setUserId('widget-user');
		$this->tokens->method('verify')
			->with('raw-tok', WidgetTokenService::SCOPE_CLOCK)
			->willReturn($token);
		$this->timeEntryMapper->method('findActiveByUser')->with('widget-user')->willReturn(null);
		$this->stampReplay->expects($this->once())
			->method('clockIn')
			->with('widget-user', null, null, 'req-42', null, null, 'widget')
			->willReturn(['success' => true, 'timeEntry' => ['id' => 1]]);
		$this->stampReplay->expects($this->never())->method('clockOut');

		$res = $this->controller->clockToggle('raw-tok', 'req-42');

		$data = $res->getData();
		$this->assertSame(Http::STATUS_OK, $res->getStatus());
		$this->assertSame('executed', $data['action']);
		$this->assertTrue($data['clockedIn']);
	}

	public function testClockToggleExecutesClockOutWithWidgetChannel(): void
	{
		$token = new WidgetToken();
		$token->setUserId('widget-user');
		$this->tokens->method('verify')->willReturn($token);
		$active = $this->createMock(TimeEntry::class);
		$this->timeEntryMapper->method('findActiveByUser')->with('widget-user')->willReturn($active);
		$this->stampReplay->expects($this->once())
			->method('clockOut')
			->with('widget-user', 'req-43', null, 'widget')
			->willReturn(['success' => true, 'timeEntry' => ['id' => 1]]);
		$this->stampReplay->expects($this->never())->method('clockIn');

		$res = $this->controller->clockToggle('raw-tok', 'req-43');

		$data = $res->getData();
		$this->assertSame('executed', $data['action']);
		$this->assertFalse($data['clockedIn']);
	}

	public function testClockToggleInternalFailureDegradesToNavigate500(): void
	{
		$token = new WidgetToken();
		$token->setUserId('widget-user');
		$this->tokens->method('verify')->willReturn($token);
		$this->timeEntryMapper->method('findActiveByUser')->willThrowException(new \RuntimeException('db down'));

		$res = $this->controller->clockToggle('raw-tok', 'req-1');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $res->getStatus());
		$this->assertSame('navigate', $res->getData()['action']);
	}
}
