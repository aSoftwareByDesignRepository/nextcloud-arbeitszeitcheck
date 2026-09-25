<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Integration;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\OutlookIcalSubscriptionTokenMapper;
use OCA\ArbeitszeitCheck\Db\Team;
use OCA\ArbeitszeitCheck\Db\TeamManagerMapper;
use OCA\ArbeitszeitCheck\Db\TeamMapper;
use OCA\ArbeitszeitCheck\Exception\OutlookIcalSubscriptionAuthException;
use OCA\ArbeitszeitCheck\Exception\OutlookIcalSubscriptionBadRequestException;
use OCA\ArbeitszeitCheck\Service\OutlookIcalSubscriptionService;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IUserManager;
use Test\TestCase;

/**
 * Live DB lifecycle for Outlook iCal token create/rotate (scope + language).
 *
 * @group integration
 */
final class OutlookIcalSubscriptionIntegrationTest extends TestCase
{
	private string $managerUid = '';
	private string $memberUid = '';
	private ?int $teamId = null;
	private ?string $prevUseAppTeams = null;

	protected function setUp(): void
	{
		parent::setUp();
		if (!class_exists(\OC::class) || !isset(\OC::$server)) {
			$this->markTestSkipped('Nextcloud runtime required');
		}

		// Typed string API: use_app_teams is written typed by AdminController; an
		// untyped IConfig::setAppValue() write crashes with
		// AppConfigTypeConflictException once the key exists as VALUE_STRING.
		$appConfig = \OC::$server->get(IAppConfig::class);
		$this->prevUseAppTeams = $appConfig->getValueString('arbeitszeitcheck', 'use_app_teams', '0');
		$appConfig->setValueString('arbeitszeitcheck', 'use_app_teams', '1');

		$um = \OC::$server->get(IUserManager::class);
		$this->managerUid = 'azc_oical_mgr_' . bin2hex(random_bytes(3));
		$this->memberUid = 'azc_oical_mem_' . bin2hex(random_bytes(3));
		foreach ([$this->managerUid, $this->memberUid] as $uid) {
			if ($um->userExists($uid)) {
				$um->get($uid)?->delete();
			}
			$um->createUser($uid, 'Azc-Oical-' . bin2hex(random_bytes(4)) . '!');
		}

		$teamMapper = \OC::$server->get(TeamMapper::class);
		$team = new Team();
		$team->setName('Outlook iCal IT ' . bin2hex(random_bytes(2)));
		$team->setParentId(null);
		$team->setSortOrder(0);
		$team->setCreatedAt(new \DateTime('now', new \DateTimeZone('UTC')));
		$team = $teamMapper->insert($team);
		$this->teamId = (int)$team->getId();

		$teamManagerMapper = \OC::$server->get(TeamManagerMapper::class);
		$teamManagerMapper->addManager($this->teamId, $this->managerUid);

		$teamMemberMapper = \OC::$server->get(\OCA\ArbeitszeitCheck\Db\TeamMemberMapper::class);
		$teamMemberMapper->addMember($this->teamId, $this->memberUid);
	}

	protected function tearDown(): void
	{
		if (!isset(\OC::$server)) {
			return;
		}

		if ($this->teamId !== null) {
			try {
				$tenantId = (string)\OC::$server->get(IConfig::class)->getSystemValue('instanceid', '');
				if ($tenantId !== '') {
					$db = \OC::$server->get(\OCP\IDBConnection::class);
					foreach ([$this->teamId, Constants::OUTLOOK_ICAL_ORG_WIDE_TEAM_ID] as $scopeTeamId) {
						$qb = $db->getQueryBuilder();
						$qb->delete('azc_outlook_ical_tokens')
							->where($qb->expr()->eq('tenant_id', $qb->createNamedParameter($tenantId)))
							->andWhere($qb->expr()->eq('team_id', $qb->createNamedParameter($scopeTeamId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
						$qb->executeStatement();
					}
				}
				\OC::$server->get(TeamManagerMapper::class)->removeManager($this->teamId, $this->managerUid);
				\OC::$server->get(\OCA\ArbeitszeitCheck\Db\TeamMemberMapper::class)->removeMember($this->teamId, $this->memberUid);
				\OC::$server->get(TeamMapper::class)->delete(\OC::$server->get(TeamMapper::class)->find($this->teamId));
			} catch (\Throwable) {
			}
		}

		$um = \OC::$server->get(IUserManager::class);
		foreach ([$this->managerUid, $this->memberUid] as $uid) {
			if ($uid === '') {
				continue;
			}
			try {
				$um->get($uid)?->delete();
			} catch (\Throwable) {
			}
		}

		if ($this->prevUseAppTeams !== null) {
			try {
				\OC::$server->get(IAppConfig::class)->setValueString('arbeitszeitcheck', 'use_app_teams', $this->prevUseAppTeams);
			} catch (\Throwable) {
			}
		}
	}

	public function testServiceResolvesFromContainer(): void
	{
		$service = \OC::$server->get(OutlookIcalSubscriptionService::class);
		self::assertInstanceOf(OutlookIcalSubscriptionService::class, $service);
	}

	public function testControllerResolvesFromContainer(): void
	{
		$controller = \OC::$server->query(\OCA\ArbeitszeitCheck\Controller\OutlookIcalSubscriptionController::class);
		self::assertInstanceOf(\OCA\ArbeitszeitCheck\Controller\OutlookIcalSubscriptionController::class, $controller);
	}

	public function testCreateAllowsOneRowPerScopeAndLanguage(): void
	{
		self::assertNotNull($this->teamId);

		$service = \OC::$server->get(OutlookIcalSubscriptionService::class);
		$tokenMapper = \OC::$server->get(OutlookIcalSubscriptionTokenMapper::class);
		$tenantId = (string)\OC::$server->get(IConfig::class)->getSystemValue('instanceid', '');
		self::assertNotSame('', $tenantId);

		$english = $service->createToken($this->managerUid, $this->teamId, 'en');
		$german = $service->createToken($this->managerUid, $this->teamId, 'de');

		self::assertNotSame($english['token'], $german['token']);

		$englishRow = $tokenMapper->findForScopeLanguage($tenantId, $this->teamId, 'en');
		$germanRow = $tokenMapper->findForScopeLanguage($tenantId, $this->teamId, 'de');
		self::assertNotNull($englishRow);
		self::assertNotNull($germanRow);
		self::assertNotSame($englishRow->getId(), $germanRow->getId());
		self::assertNotSame('', (string)$englishRow->getTokenEncrypted());
		self::assertNotSame('', (string)$germanRow->getTokenEncrypted());

		$duplicateRejected = false;
		try {
			$service->createToken($this->managerUid, $this->teamId, 'en');
		} catch (OutlookIcalSubscriptionBadRequestException) {
			$duplicateRejected = true;
		}
		self::assertTrue($duplicateRejected);
	}

	public function testRotateReplacesTokenInPlaceForScopeLanguage(): void
	{
		self::assertNotNull($this->teamId);

		$service = \OC::$server->get(OutlookIcalSubscriptionService::class);
		$tokenMapper = \OC::$server->get(OutlookIcalSubscriptionTokenMapper::class);
		$tenantId = (string)\OC::$server->get(IConfig::class)->getSystemValue('instanceid', '');
		self::assertNotSame('', $tenantId);

		$first = $service->createToken($this->managerUid, $this->teamId, 'de');
		$second = $service->rotateToken($this->managerUid, $this->teamId, 'de');

		self::assertNotSame($first['token'], $second['token']);

		$scopeRow = $tokenMapper->findForScopeLanguage($tenantId, $this->teamId, 'de');
		self::assertNotNull($scopeRow);
		self::assertSame(1, $scopeRow->getIsActive());
		self::assertSame(hash('sha256', $second['token']), $scopeRow->getTokenHash());

		$domain = 'integration.test';

		$oldTokenRejected = false;
		try {
			$service->buildTokenizedFeed($first['token'], $this->teamId, $domain);
		} catch (OutlookIcalSubscriptionAuthException) {
			$oldTokenRejected = true;
		}
		self::assertTrue($oldTokenRejected, 'First token must stop working after rotation');

		$feed = $service->buildTokenizedFeed($second['token'], $this->teamId, $domain);
		self::assertStringContainsString('BEGIN:VCALENDAR', $feed);
	}

	public function testOrgWideCreateInsertsTeamIdZeroRow(): void
	{
		$service = \OC::$server->get(OutlookIcalSubscriptionService::class);
		$tokenMapper = \OC::$server->get(OutlookIcalSubscriptionTokenMapper::class);
		$tenantId = (string)\OC::$server->get(IConfig::class)->getSystemValue('instanceid', '');
		self::assertNotSame('', $tenantId);

		$result = $service->createToken($this->managerUid, Constants::OUTLOOK_ICAL_ORG_WIDE_TEAM_ID, 'de');

		self::assertNotSame('', $result['token']);
		self::assertSame('de', $result['feedLanguageCode']);

		$scopeRow = $tokenMapper->findForScopeLanguage($tenantId, Constants::OUTLOOK_ICAL_ORG_WIDE_TEAM_ID, 'de');
		self::assertNotNull($scopeRow);
		self::assertSame(Constants::OUTLOOK_ICAL_ORG_WIDE_TEAM_ID, $scopeRow->getTeamId());
		self::assertSame(hash('sha256', $result['token']), $scopeRow->getTokenHash());
		self::assertNotSame('', (string)$scopeRow->getTokenEncrypted());

		$feed = $service->buildTokenizedFeed($result['token'], Constants::OUTLOOK_ICAL_ORG_WIDE_TEAM_ID, 'integration.test');
		self::assertStringContainsString('BEGIN:VCALENDAR', $feed);
		self::assertStringContainsString('X-WR-CALNAME:', $feed);
	}

	public function testGermanTokenizedFeedUsesShippedTranslationsAndEmployeeNames(): void
	{
		self::assertNotNull($this->teamId);

		$service = \OC::$server->get(OutlookIcalSubscriptionService::class);
		$result = $service->createToken($this->managerUid, $this->teamId, 'de');
		$feed = $service->buildTokenizedFeed($result['token'], $this->teamId, 'integration.test');

		self::assertStringContainsString('BEGIN:VCALENDAR', $feed);
		if (str_contains($feed, 'BEGIN:VEVENT')) {
			self::assertStringNotContainsString('SUMMARY:Absence', $feed);
			self::assertStringNotContainsString('SUMMARY:Vacation', $feed);
			self::assertMatchesRegularExpression('/SUMMARY:[^\\r\\n]+ \\([^\\r\\n]+\\)/', $feed);
		}
	}
}
