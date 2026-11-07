<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\WidgetToken;
use OCA\ArbeitszeitCheck\Db\WidgetTokenMapper;
use OCA\ArbeitszeitCheck\Exception\WidgetTokenLimitException;
use OCA\ArbeitszeitCheck\Service\WidgetTokenService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class WidgetTokenServiceTest extends TestCase
{
	/** @var WidgetTokenMapper&MockObject */
	private $mapper;
	private WidgetTokenService $service;

	protected function setUp(): void
	{
		parent::setUp();
		$this->mapper = $this->createMock(WidgetTokenMapper::class);
		$this->service = new WidgetTokenService($this->mapper);
	}

	public function testIssuePersistsHashOnlyAndReturnsRawOnce(): void
	{
		$this->mapper->method('countActiveByUser')->with('u1')->willReturn(0);
		$inserted = new WidgetToken();
		$storedHash = null;
		$this->mapper->expects($this->once())
			->method('insert')
			->willReturnCallback(function (WidgetToken $t) use ($inserted, &$storedHash) {
				$this->assertSame('u1', $t->getUserId());
				$this->assertSame(WidgetTokenService::SCOPE_CLOCK, $t->getScope());
				$this->assertSame('Pixel 8', $t->getDeviceLabel());
				$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $t->getTokenHash());
				$this->assertNotNull($t->getExpiresAt());
				$storedHash = $t->getTokenHash();
				$inserted->setId(7);
				return $inserted;
			});

		[$entity, $raw] = $this->service->issue('u1', WidgetTokenService::SCOPE_CLOCK, 'Pixel 8');

		$this->assertSame(7, $entity->getId());
		$this->assertMatchesRegularExpression('/^[A-Za-z0-9\-_]{43}$/', $raw);
		$this->assertSame(hash('sha256', $raw), $storedHash);
	}

	public function testIssueTruncatesOverlongDeviceLabel(): void
	{
		$this->mapper->method('countActiveByUser')->willReturn(0);
		$this->mapper->expects($this->once())
			->method('insert')
			->willReturnCallback(function (WidgetToken $t) {
				$this->assertSame(128, mb_strlen((string)$t->getDeviceLabel()));
				return $t;
			});
		$this->service->issue('u1', WidgetTokenService::SCOPE_CLOCK, str_repeat('x', 200));
	}

	public function testIssueRejectsUnknownScopeDenyByDefault(): void
	{
		$this->mapper->expects($this->never())->method('insert');
		$this->expectException(\InvalidArgumentException::class);
		$this->service->issue('u1', 'azc-admin-full');
	}

	public function testIssueEnforcesActiveTokenCap(): void
	{
		$this->mapper->method('countActiveByUser')->with('u1')->willReturn(WidgetTokenService::MAX_ACTIVE_TOKENS_PER_USER);
		$this->mapper->expects($this->never())->method('insert');
		$this->expectException(WidgetTokenLimitException::class);
		$this->service->issue('u1', WidgetTokenService::SCOPE_CLOCK);
	}

	private function tokenFixture(): WidgetToken
	{
		$t = new WidgetToken();
		$t->setId(3);
		$t->setUserId('u1');
		$t->setTokenHash(hash('sha256', 'raw-token'));
		$t->setScope(WidgetTokenService::SCOPE_CLOCK);
		$t->setExpiresAt((new \DateTime())->modify('+10 days'));
		return $t;
	}

	public function testVerifyReturnsTokenAndTouchesUsage(): void
	{
		$token = $this->tokenFixture();
		$this->mapper->method('findByHash')->willReturn($token);
		$this->mapper->expects($this->once())->method('touchUsed')->with(3);

		$this->assertSame($token, $this->service->verify('raw-token', WidgetTokenService::SCOPE_CLOCK));
	}

	public function testVerifyRejectsUnknownToken(): void
	{
		$this->mapper->method('findByHash')->willReturn(null);
		$this->expectException(DoesNotExistException::class);
		$this->service->verify('nope', WidgetTokenService::SCOPE_CLOCK);
	}

	public function testVerifyRejectsRevokedToken(): void
	{
		$token = $this->tokenFixture();
		$token->setRevokedAt(new \DateTime());
		$this->mapper->method('findByHash')->willReturn($token);
		$this->expectException(\RuntimeException::class);
		$this->service->verify('raw-token', WidgetTokenService::SCOPE_CLOCK);
	}

	public function testVerifyRejectsExpiredToken(): void
	{
		$token = $this->tokenFixture();
		$token->setExpiresAt((new \DateTime())->modify('-1 day'));
		$this->mapper->method('findByHash')->willReturn($token);
		$this->expectException(\RuntimeException::class);
		$this->service->verify('raw-token', WidgetTokenService::SCOPE_CLOCK);
	}

	public function testVerifyRejectsScopeMismatchDenyByDefault(): void
	{
		$token = $this->tokenFixture();
		$this->mapper->method('findByHash')->willReturn($token);
		$this->mapper->expects($this->never())->method('touchUsed');
		$this->expectException(\RuntimeException::class);
		$this->service->verify('raw-token', 'azc-other-scope');
	}

	public function testListForUserExposesPublicSafeViewWithoutHash(): void
	{
		$token = $this->tokenFixture();
		$token->setDeviceLabel('Pixel 8');
		$token->setCreatedAt(new \DateTime('2026-11-01 10:00:00'));
		$this->mapper->method('findByUser')->with('u1')->willReturn([$token]);

		$rows = $this->service->listForUser('u1');

		$this->assertCount(1, $rows);
		$row = $rows[0];
		$this->assertSame(3, $row['id']);
		$this->assertSame(WidgetTokenService::SCOPE_CLOCK, $row['scope']);
		$this->assertSame('Pixel 8', $row['deviceLabel']);
		$this->assertFalse($row['revoked']);
		$this->assertArrayNotHasKey('tokenHash', $row);
		$this->assertArrayNotHasKey('token_hash', $row);
		$this->assertArrayNotHasKey('token', $row);
	}
}
