<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Integration;

use OCA\ArbeitszeitCheck\AppInfo\Application;
use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Support\BreakPolicyStore;
use OCP\AppFramework\Db\TTransactional;
use OCP\IDBConnection;
use Test\TestCase;

/**
 * Integration coverage for the break-policy persistence seam: ensureRowsExist
 * seeds lockable appconfig rows, lockAndRead returns the stored document +
 * revision inside a transaction, and write persists both atomically.
 */
class BreakPolicyStoreIntegrationTest extends TestCase
{
	use TTransactional;

	private BreakPolicyStore $store;

	private IDBConnection $db;

	/** @var array<string, string|false> */
	private array $snapshot = [];

	protected function setUp(): void
	{
		parent::setUp();
		$this->store = \OC::$server->get(BreakPolicyStore::class);
		$this->db = \OC::$server->get(IDBConnection::class);
		foreach ([Constants::CONFIG_BREAK_POLICY_JSON, Constants::CONFIG_BREAK_POLICY_VERSION] as $key) {
			$this->snapshot[$key] = $this->readConfigValue($key);
		}
	}

	protected function tearDown(): void
	{
		foreach ($this->snapshot as $key => $value) {
			$this->deleteConfigValue($key);
			if ($value !== false) {
				$qb = $this->db->getQueryBuilder();
				$qb->insert('appconfig')
					->setValue('appid', $qb->createNamedParameter(Application::APP_ID))
					->setValue('configkey', $qb->createNamedParameter($key))
					->setValue('configvalue', $qb->createNamedParameter($value))
					->executeStatement();
			}
		}
		parent::tearDown();
	}

	public function testEnsureRowsExistMakesLockAndReadSucceed(): void
	{
		$this->store->ensureRowsExist();

		[$raw, $version] = $this->atomic(fn () => $this->store->lockAndRead(), $this->db);

		$this->assertIsInt($version);
		$this->assertGreaterThanOrEqual(0, $version);
		if ($raw !== null) {
			$this->assertIsString($raw);
		}
	}

	public function testWriteThenLockAndReadRoundTrips(): void
	{
		$doc = json_encode(['enforced' => true, 'weeklyHours' => 30], JSON_THROW_ON_ERROR);

		$this->atomic(function () use ($doc): void {
			$this->store->ensureRowsExist();
			[, $version] = $this->store->lockAndRead();
			$this->store->write($doc, $version + 1);
		}, $this->db);

		[$raw, $version] = $this->atomic(fn () => $this->store->lockAndRead(), $this->db);
		$this->assertSame($doc, $raw);
		$this->assertGreaterThanOrEqual(1, $version);
	}

	/**
	 * @return string|false stored configvalue, or false when the row is absent
	 */
	private function readConfigValue(string $key)
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('configvalue')
			->from('appconfig')
			->where($qb->expr()->eq('appid', $qb->createNamedParameter(Application::APP_ID)))
			->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter($key)));
		return $qb->executeQuery()->fetchOne();
	}

	private function deleteConfigValue(string $key): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete('appconfig')
			->where($qb->expr()->eq('appid', $qb->createNamedParameter(Application::APP_ID)))
			->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter($key)))
			->executeStatement();
	}
}
