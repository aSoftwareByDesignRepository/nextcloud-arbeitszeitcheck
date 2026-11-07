<?php

declare(strict_types=1);

/**
 * UserSettingsMapper for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * UserSettingsMapper
 */
class UserSettingsMapper extends QBMapper
{
	/**
	 * UserSettingsMapper constructor
	 *
	 * @param IDBConnection $db
	 */
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'at_settings', UserSetting::class);
	}

	/**
	 * Get a user setting by user ID and key
	 *
	 * @param string $userId
	 * @param string $settingKey
	 * @return UserSetting|null
	 */
	public function getSetting(string $userId, string $settingKey): ?UserSetting
	{
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')
				->from($this->getTableName())
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->eq('setting_key', $qb->createNamedParameter($settingKey)))
				->setMaxResults(1);

			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		} catch (\Throwable $e) {
			// If table doesn't exist or other database error, return null
			\OCP\Log\logger('arbeitszeitcheck')->error('Error getting setting ' . $settingKey . ' for user ' . $userId . ': ' . $e->getMessage(), ['exception' => $e]);
			return null;
		}
	}

	/**
	 * Get all settings for a user
	 *
	 * @param string $userId
	 * @return UserSetting[]
	 */
	public function getUserSettings(string $userId): array
	{
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')
				->from($this->getTableName())
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
				->orderBy('setting_key', 'ASC');

			return $this->findEntities($qb);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->warning(
				'Error loading user settings: ' . $e->getMessage(),
				['exception' => $e]
			);
			return [];
		}
	}

	/**
	 * Set a user setting (creates or updates) – concurrency-safe.
	 *
	 * The (user_id, setting_key) pair is protected by the unique index
	 * `at_settings_user_key_unique`. Two concurrent writers can both observe
	 * "no existing row" in {@see self::getSetting()} and then race on INSERT.
	 * In that case one of them gets a UniqueConstraintViolationException –
	 * we transparently retry with an UPDATE so the upsert is atomic from
	 * the caller's perspective.
	 *
	 * @param string $userId
	 * @param string $settingKey
	 * @param string|null $settingValue
	 * @return UserSetting
	 * @throws \RuntimeException If the upsert cannot be reconciled after a retry.
	 *
	 * unique-conflict:ok — the update() calls in this upsert only mutate
	 * setting_value/updated_at on a row re-read by its (user_id, setting_key)
	 * unique key; the insert carries a REASON_UNIQUE_CONSTRAINT_VIOLATION
	 * catch that falls through to a re-read + update of the winner row.
	 */
	public function setSetting(string $userId, string $settingKey, ?string $settingValue): UserSetting
	{
		$existingSetting = $this->getSetting($userId, $settingKey);

		if ($existingSetting) {
			$existingSetting->setSettingValue($settingValue);
			$existingSetting->setUpdatedAt(new \DateTime());
			return $this->update($existingSetting);
		}

		$newSetting = new UserSetting();
		$newSetting->setUserId($userId);
		$newSetting->setSettingKey($settingKey);
		$newSetting->setSettingValue($settingValue);
		$newSetting->setCreatedAt(new \DateTime());
		$newSetting->setUpdatedAt(new \DateTime());

		try {
			return $this->insert($newSetting);
		} catch (DbException $e) {
			// Only swallow uniqueness conflicts; re-throw other DB errors.
			$reason = $e->getReason();
			$isUniqueViolation = ($reason === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION);
			if (!$isUniqueViolation) {
				throw $e;
			}

			// Another writer beat us to it – switch to UPDATE.
			$existingSetting = $this->getSetting($userId, $settingKey);
			if ($existingSetting === null) {
				// The row should now exist; if it does not, something is wrong
				// (e.g. it was deleted in the meantime). Surface a clear error
				// rather than infinite-looping.
				throw new \RuntimeException('Failed to upsert user setting after unique constraint violation', 0, $e);
			}
			$existingSetting->setSettingValue($settingValue);
			$existingSetting->setUpdatedAt(new \DateTime());
			return $this->update($existingSetting);
		}
	}

	/**
	 * Delete a user setting
	 *
	 * @param string $userId
	 * @param string $settingKey
	 * @return void
	 */
	public function deleteSetting(string $userId, string $settingKey): void
	{
		$setting = $this->getSetting($userId, $settingKey);
		if ($setting) {
			$this->delete($setting);
		}
	}

	/**
	 * Delete all settings for a user (used on user deletion)
	 *
	 * @param string $userId
	 * @return int Number of deleted rows
	 */
	public function deleteByUser(string $userId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return $qb->executeStatement();
	}

	/**
	 * Get setting value as boolean
	 *
	 * @param string $userId
	 * @param string $settingKey
	 * @param bool $default
	 * @return bool
	 */
	public function getBooleanSetting(string $userId, string $settingKey, bool $default = false): bool
	{
		$setting = $this->getSetting($userId, $settingKey);
		if (!$setting || $setting->getSettingValue() === null) {
			return $default;
		}

		return filter_var($setting->getSettingValue(), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
	}

	/**
	 * Get setting value as integer
	 *
	 * @param string $userId
	 * @param string $settingKey
	 * @param int $default
	 * @return int
	 */
	public function getIntegerSetting(string $userId, string $settingKey, int $default = 0): int
	{
		$setting = $this->getSetting($userId, $settingKey);
		if (!$setting) {
			return $default;
		}
		$val = $setting->getSettingValue();
		if ($val === null || $val === '') {
			return $default;
		}
		return (int)$val;
	}

	/**
	 * Get setting value as float
	 *
	 * @param string $userId
	 * @param string $settingKey
	 * @param float $default
	 * @return float
	 */
	public function getFloatSetting(string $userId, string $settingKey, float $default = 0.0): float
	{
		$setting = $this->getSetting($userId, $settingKey);
		if (!$setting) {
			return $default;
		}
		$val = $setting->getSettingValue();
		if ($val === null || $val === '') {
			return $default;
		}
		return (float)$val;
	}

	/**
	 * Get setting value as string
	 *
	 * @param string $userId
	 * @param string $settingKey
	 * @param string $default
	 * @return string
	 */
	public function getStringSetting(string $userId, string $settingKey, string $default = ''): string
	{
		$setting = $this->getSetting($userId, $settingKey);
		if (!$setting) {
			return $default;
		}
		$val = $setting->getSettingValue();
		if ($val === null) {
			return $default;
		}
		return (string)$val;
	}

	/**
	 * Count distinct users that have a non-empty, non-null value for a setting key.
	 *
	 * Rows with `setting_value IS NULL` are excluded automatically because
	 * SQL `!= ''` against NULL yields UNKNOWN which fails the WHERE clause.
	 */
	public function countDistinctUsersWithNonEmptySetting(string $settingKey): int
	{
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select($qb->createFunction('COUNT(DISTINCT user_id)'))
				->from($this->getTableName())
				->where($qb->expr()->eq('setting_key', $qb->createNamedParameter($settingKey)))
				->andWhere($qb->expr()->isNotNull('setting_value'))
				->andWhere($qb->expr()->neq('setting_value', $qb->createNamedParameter('')));

			return (int)$qb->executeQuery()->fetchOne();
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error(
				'Error counting users with setting ' . $settingKey . ': ' . $e->getMessage(),
				['exception' => $e]
			);
			return 0;
		}
	}

	/**
	 * List user IDs that have a non-empty, non-null value for a setting key.
	 *
	 * Used for bulk lookups (e.g. admin dashboard drill-downs) so callers can
	 * avoid an O(N) per-user round-trip. Returns an empty array on any DB
	 * error so callers can degrade gracefully.
	 *
	 * @return list<string>
	 */
	public function findUserIdsWithNonEmptySetting(string $settingKey): array
	{
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->selectDistinct('user_id')
				->from($this->getTableName())
				->where($qb->expr()->eq('setting_key', $qb->createNamedParameter($settingKey)))
				->andWhere($qb->expr()->isNotNull('setting_value'))
				->andWhere($qb->expr()->neq('setting_value', $qb->createNamedParameter('')));

			$result = $qb->executeQuery();
			$userIds = [];
			while (($row = $result->fetch()) !== false) {
				$uid = (string)($row['user_id'] ?? '');
				if ($uid !== '') {
					$userIds[] = $uid;
				}
			}
			$result->closeCursor();
			return $userIds;
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error(
				'Error listing users with setting ' . $settingKey . ': ' . $e->getMessage(),
				['exception' => $e]
			);
			return [];
		}
	}
}