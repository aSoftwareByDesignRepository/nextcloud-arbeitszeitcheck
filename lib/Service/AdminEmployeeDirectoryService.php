<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Exception\InvalidEmployeeListFilterException;
use OCA\ArbeitszeitCheck\Support\UserDirectorySearch;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Resolves admin employee directory pages: scan → access filter → optional active_today → sort → slice.
 */
final class AdminEmployeeDirectoryService
{
	public const FILTER_ALL = 'all';
	public const FILTER_APP_ACCESS = 'app_access';
	public const MODEL_NONE = 'none';
	public const MODEL_PERSONAL = 'personal';
	public const STATUS_ENABLED = 'enabled';
	public const STATUS_DISABLED = 'disabled';

	private const MAX_SEARCH_LENGTH = 200;

	public function __construct(
		private readonly IUserManager $userManager,
		private readonly PermissionService $permissionService,
		private readonly \OCA\ArbeitszeitCheck\Db\TimeEntryMapper $timeEntryMapper,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
		private readonly UserWorkingTimeModelMapper $userWorkingTimeModelMapper,
		private readonly WorkingTimeModelMapper $workingTimeModelMapper,
	) {
	}

	public function resolveDefaultFilter(): string
	{
		return $this->permissionService->isAccessRestrictionEnabled()
			? self::FILTER_APP_ACCESS
			: self::FILTER_ALL;
	}

	public function isValidFilter(string $filter): bool
	{
		return in_array($filter, [self::FILTER_ALL, self::FILTER_APP_ACCESS], true);
	}

	/**
	 * @param string|null $filter Raw request value; null/empty uses {@see resolveDefaultFilter()}.
	 *
	 * @return array{
	 *   users: list<IUser>,
	 *   total: int,
	 *   directoryTotal: int|null,
	 *   hiddenCount: int|null,
	 *   offset: int,
	 *   limit: int,
	 *   truncated: bool,
	 *   filter: string,
	 *   defaultFilter: string,
	 *   activeToday: bool,
	 *   model: string,
	 *   status: string
	 * }
	 */
	public function listUsers(
		?string $filter,
		string $search,
		int $limit,
		int $offset,
		bool $activeTodayOnly = false,
		?string $model = null,
		?string $status = null,
	): array {
		$defaultFilter = $this->resolveDefaultFilter();
		$normalizedFilter = $filter !== null ? strtolower(trim($filter)) : '';
		if ($normalizedFilter === '') {
			$normalizedFilter = $defaultFilter;
		}
		if (!$this->isValidFilter($normalizedFilter)) {
			throw new InvalidEmployeeListFilterException(
				$this->l10n->t('Invalid employee list filter.')
			);
		}

		$normalizedModel = $this->normalizeModel($model);
		$normalizedStatus = $this->normalizeStatus($status);

		$searchTerm = trim($search);
		if (mb_strlen($searchTerm) > self::MAX_SEARCH_LENGTH) {
			$searchTerm = mb_substr($searchTerm, 0, self::MAX_SEARCH_LENGTH);
		}

		$limit = max(1, min($limit, Constants::MAX_LIST_LIMIT));
		$offset = max(0, $offset);

		$scanCap = Constants::ADMIN_EMPLOYEE_FILTER_MAX_SCAN;
		$candidateResult = $this->collectCandidates($searchTerm, $scanCap);
		/** @var list<IUser> $candidates */
		$candidates = $candidateResult['users'];
		$scanTruncated = $candidateResult['truncated'];

		$afterAccessFilter = [];
		foreach ($candidates as $user) {
			if ($this->matchesAccessFilter((string)$user->getUID(), $normalizedFilter)) {
				$afterAccessFilter[] = $user;
			}
		}

		$directoryTotal = null;
		$hiddenCount = null;
		if ($this->permissionService->isAccessRestrictionEnabled()
			&& $normalizedFilter === self::FILTER_APP_ACCESS) {
			$directoryTotal = count($candidates);
			$hiddenCount = max(0, $directoryTotal - count($afterAccessFilter));
		}

		$filtered = $afterAccessFilter;
		if ($activeTodayOnly) {
			$today = new \DateTime();
			$today->setTime(0, 0, 0);
			$activeTodayLookup = array_fill_keys(
				$this->timeEntryMapper->findDistinctUserIdsByDate($today),
				true
			);
			$filtered = array_values(array_filter(
				$filtered,
				static fn (IUser $user): bool => isset($activeTodayLookup[(string)$user->getUID()])
			));
		}

		$filtered = $this->applyStatusFilter($filtered, $normalizedStatus);
		$filtered = $this->applyModelFilter($filtered, $normalizedModel);

		usort($filtered, static function (IUser $a, IUser $b): int {
			$byName = strcasecmp((string)$a->getDisplayName(), (string)$b->getDisplayName());
			if ($byName !== 0) {
				return $byName;
			}
			return strcmp((string)$a->getUID(), (string)$b->getUID());
		});

		$total = count($filtered);
		$page = array_slice($filtered, $offset, $limit);

		if ($scanTruncated) {
			$this->logger->warning('admin_employee_list_scan_truncated', [
				'filter' => $normalizedFilter,
				'search' => $searchTerm,
				'scanCap' => $scanCap,
				'directoryTotal' => $directoryTotal ?? count($candidates),
			]);
		}

		return [
			'users' => $page,
			'total' => $total,
			'directoryTotal' => $directoryTotal,
			'hiddenCount' => $hiddenCount,
			'offset' => $offset,
			'limit' => $limit,
			'truncated' => $scanTruncated,
			'filter' => $normalizedFilter,
			'defaultFilter' => $defaultFilter,
			'activeToday' => $activeTodayOnly,
			'model' => $normalizedModel,
			'status' => $normalizedStatus,
		];
	}

	private function normalizeModel(?string $model): string
	{
		if ($model === null) {
			return '';
		}
		$model = strtolower(trim($model));
		if ($model === '') {
			return '';
		}
		if ($model === self::MODEL_NONE || $model === self::MODEL_PERSONAL) {
			return $model;
		}
		if (preg_match('/^[1-9]\d{0,8}$/', $model) !== 1) {
			throw new InvalidEmployeeListFilterException(
				$this->l10n->t('Invalid employee list filter.')
			);
		}
		$id = (int)$model;
		try {
			$this->workingTimeModelMapper->find($id);
		} catch (DoesNotExistException|MultipleObjectsReturnedException) {
			throw new InvalidEmployeeListFilterException(
				$this->l10n->t('Invalid employee list filter.')
			);
		}
		return (string)$id;
	}

	private function normalizeStatus(?string $status): string
	{
		if ($status === null) {
			return '';
		}
		$status = strtolower(trim($status));
		if ($status === '') {
			return '';
		}
		if ($status !== self::STATUS_ENABLED && $status !== self::STATUS_DISABLED) {
			throw new InvalidEmployeeListFilterException(
				$this->l10n->t('Invalid employee list filter.')
			);
		}
		return $status;
	}

	/**
	 * @param list<IUser> $users
	 * @return list<IUser>
	 */
	private function applyStatusFilter(array $users, string $status): array
	{
		if ($status === '') {
			return $users;
		}
		$wantEnabled = $status === self::STATUS_ENABLED;
		return array_values(array_filter(
			$users,
			static fn (IUser $user): bool => $user->isEnabled() === $wantEnabled
		));
	}

	/**
	 * @param list<IUser> $users
	 * @return list<IUser>
	 */
	private function applyModelFilter(array $users, string $model): array
	{
		if ($model === '' || $users === []) {
			return $users;
		}

		$ids = [];
		foreach ($users as $user) {
			$ids[] = (string)$user->getUID();
		}
		$today = (new \DateTime())->format('Y-m-d');
		$current = $this->userWorkingTimeModelMapper->findCurrentModelIdsByUserIds($ids, $today);

		if ($model === self::MODEL_NONE || $model === self::MODEL_PERSONAL) {
			$flags = $this->workingTimeModelMapper->findPersonalFlagsByIds(array_values($current));
			return array_values(array_filter(
				$users,
				static function (IUser $user) use ($current, $flags, $model): bool {
					$uid = (string)$user->getUID();
					$modelId = $current[$uid] ?? null;
					if ($model === self::MODEL_NONE) {
						return $modelId === null || !isset($flags[$modelId]);
					}
					return $modelId !== null && ($flags[$modelId] ?? false) === true;
				}
			));
		}

		$wanted = (int)$model;
		return array_values(array_filter(
			$users,
			static function (IUser $user) use ($current, $wanted): bool {
				return ($current[(string)$user->getUID()] ?? null) === $wanted;
			}
		));
	}

	private function matchesAccessFilter(string $userId, string $filter): bool
	{
		if ($filter === self::FILTER_ALL) {
			return true;
		}
		return $this->permissionService->isUserAllowedByAccessGroups($userId);
	}

	/**
	 * @return array{users: list<IUser>, truncated: bool}
	 */
	private function collectCandidates(string $searchTerm, int $scanCap): array
	{
		if ($searchTerm !== '') {
			$byId = (array)$this->userManager->search($searchTerm, $scanCap + 1, 0);
			$byDisplayName = (array)$this->userManager->searchDisplayName($searchTerm, $scanCap + 1, 0);
			$merged = UserDirectorySearch::mergeUnique($byId, $byDisplayName);
			$backendCapped = count($byId) >= $scanCap + 1 || count($byDisplayName) >= $scanCap + 1;
			$truncated = $backendCapped || count($merged) > $scanCap;
			if (count($merged) > $scanCap) {
				$merged = array_slice($merged, 0, $scanCap);
			}
			return ['users' => $merged, 'truncated' => $truncated];
		}

		$users = (array)$this->userManager->search('', $scanCap + 1, 0);
		$truncated = count($users) > $scanCap;
		if ($truncated) {
			$users = array_slice($users, 0, $scanCap);
		}
		return ['users' => $users, 'truncated' => $truncated];
	}
}
