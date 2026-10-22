<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\TeamManagerMapper;
use OCA\ArbeitszeitCheck\Db\TeamMapper;
use OCA\ArbeitszeitCheck\Db\TeamMemberMapper;
use OCA\ArbeitszeitCheck\Exception\AdminUserProfileUpdateException;
use OCA\ArbeitszeitCheck\Support\AdminBatchUserIds;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Thin batch façades over proven single-user writers (teams + profile + L3 vacation).
 * Partial success only — never all-or-nothing across the batch.
 */
class AdminBatchMutationService
{
	public function __construct(
		private readonly IUserManager $userManager,
		private readonly TeamMapper $teamMapper,
		private readonly TeamMemberMapper $teamMemberMapper,
		private readonly TeamManagerMapper $teamManagerMapper,
		private readonly AuditLogMapper $auditLogMapper,
		private readonly AdminUserProfileUpdateService $adminUserProfileUpdateService,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * @param list<string>|mixed $rawUserIds
	 * @return array<string, mixed>
	 */
	public function addTeamMembersBatch(int $teamId, mixed $rawUserIds, string $performedBy): array
	{
		$normalized = AdminBatchUserIds::normalize($rawUserIds);
		if (!$normalized['ok']) {
			return $normalized;
		}

		try {
			$team = $this->teamMapper->find($teamId);
		} catch (DoesNotExistException) {
			return ['ok' => false, 'error' => 'team_not_found', 'httpStatus' => 404];
		}

		$existing = [];
		foreach ($this->teamMemberMapper->findByTeamId($teamId) as $m) {
			$existing[$m->getUserId()] = true;
		}

		$results = [];
		$added = 0;
		$skipped = 0;
		$failed = 0;

		foreach ($normalized['userIds'] as $userId) {
			$user = $this->userManager->get($userId);
			if ($user === null) {
				$results[] = ['userId' => $userId, 'status' => 'failed', 'error' => 'user_not_found'];
				$failed++;
				continue;
			}
			if (!$user->isEnabled()) {
				$results[] = ['userId' => $userId, 'status' => 'failed', 'error' => 'user_disabled'];
				$failed++;
				continue;
			}
			if (isset($existing[$userId])) {
				$results[] = ['userId' => $userId, 'status' => 'skipped', 'error' => 'already_member'];
				$skipped++;
				continue;
			}

			try {
				$this->teamMemberMapper->addMember($teamId, $userId);
				$this->auditLogMapper->logAction(
					$userId,
					'team_member_added',
					'team_member',
					$teamId,
					null,
					['teamId' => $teamId, 'teamName' => $team->getName(), 'userId' => $userId],
					$performedBy
				);
				$existing[$userId] = true;
				$results[] = ['userId' => $userId, 'status' => 'added'];
				$added++;
			} catch (\Throwable $e) {
				$this->logger->error('addTeamMembersBatch item failed: ' . $e->getMessage(), [
					'exception' => $e,
					'teamId' => $teamId,
					'userId' => $userId,
				]);
				$results[] = ['userId' => $userId, 'status' => 'failed', 'error' => 'write_failed'];
				$failed++;
			}
		}

		$this->logger->info('admin_batch team_members', [
			'actor' => $performedBy,
			'op' => 'team_members',
			'teamId' => $teamId,
			'ok' => $added,
			'skip' => $skipped,
			'fail' => $failed,
		]);

		$members = [];
		foreach ($this->teamMemberMapper->findByTeamId($teamId) as $m) {
			$u = $this->userManager->get($m->getUserId());
			$members[] = [
				'userId' => $m->getUserId(),
				'displayName' => $u ? $u->getDisplayName() : $m->getUserId(),
			];
		}

		return [
			'ok' => true,
			'summary' => ['added' => $added, 'skipped' => $skipped, 'failed' => $failed],
			'results' => $results,
			'members' => $members,
		];
	}

	/**
	 * @param list<string>|mixed $rawUserIds
	 * @return array<string, mixed>
	 */
	public function addTeamManagersBatch(int $teamId, mixed $rawUserIds, string $performedBy): array
	{
		$normalized = AdminBatchUserIds::normalize($rawUserIds);
		if (!$normalized['ok']) {
			return $normalized;
		}

		try {
			$team = $this->teamMapper->find($teamId);
		} catch (DoesNotExistException) {
			return ['ok' => false, 'error' => 'team_not_found', 'httpStatus' => 404];
		}

		$existing = [];
		foreach ($this->teamManagerMapper->findByTeamId($teamId) as $m) {
			$existing[$m->getUserId()] = true;
		}

		$results = [];
		$added = 0;
		$skipped = 0;
		$failed = 0;

		foreach ($normalized['userIds'] as $userId) {
			$user = $this->userManager->get($userId);
			if ($user === null) {
				$results[] = ['userId' => $userId, 'status' => 'failed', 'error' => 'user_not_found'];
				$failed++;
				continue;
			}
			if (!$user->isEnabled()) {
				$results[] = ['userId' => $userId, 'status' => 'failed', 'error' => 'user_disabled'];
				$failed++;
				continue;
			}
			if (isset($existing[$userId])) {
				$results[] = ['userId' => $userId, 'status' => 'skipped', 'error' => 'already_manager'];
				$skipped++;
				continue;
			}

			try {
				$this->teamManagerMapper->addManager($teamId, $userId);
				$this->auditLogMapper->logAction(
					$userId,
					'team_manager_added',
					'team_manager',
					$teamId,
					null,
					['teamId' => $teamId, 'teamName' => $team->getName(), 'userId' => $userId],
					$performedBy
				);
				$existing[$userId] = true;
				$results[] = ['userId' => $userId, 'status' => 'added'];
				$added++;
			} catch (\Throwable $e) {
				$this->logger->error('addTeamManagersBatch item failed: ' . $e->getMessage(), [
					'exception' => $e,
					'teamId' => $teamId,
					'userId' => $userId,
				]);
				$results[] = ['userId' => $userId, 'status' => 'failed', 'error' => 'write_failed'];
				$failed++;
			}
		}

		$this->logger->info('admin_batch team_managers', [
			'actor' => $performedBy,
			'op' => 'team_managers',
			'teamId' => $teamId,
			'ok' => $added,
			'skip' => $skipped,
			'fail' => $failed,
		]);

		$managers = [];
		foreach ($this->teamManagerMapper->findByTeamId($teamId) as $m) {
			$u = $this->userManager->get($m->getUserId());
			$managers[] = [
				'userId' => $m->getUserId(),
				'displayName' => $u ? $u->getDisplayName() : $m->getUserId(),
			];
		}

		return [
			'ok' => true,
			'summary' => ['added' => $added, 'skipped' => $skipped, 'failed' => $failed],
			'results' => $results,
			'managers' => $managers,
		];
	}

	/**
	 * @param list<string>|mixed $rawUserIds
	 * @param array{workingTimeModel?: array<string, mixed>, holidayRegion?: array{germanState?: string}} $fields
	 * @return array<string, mixed>
	 */
	public function batchProfile(mixed $rawUserIds, array $fields, string $performedBy, bool $dryRun = false): array
	{
		$normalized = AdminBatchUserIds::normalize($rawUserIds);
		if (!$normalized['ok']) {
			return $normalized;
		}

		$workingTimeModel = is_array($fields['workingTimeModel'] ?? null) ? $fields['workingTimeModel'] : null;
		$holidayRegion = is_array($fields['holidayRegion'] ?? null) ? $fields['holidayRegion'] : null;

		if ($workingTimeModel === null && $holidayRegion === null) {
			return ['ok' => false, 'error' => 'fields_required', 'httpStatus' => 400];
		}

		$payload = $this->buildProfilePayload($workingTimeModel, $holidayRegion);

		// Validate-once against first existing user before any writes.
		$probeUserId = $this->firstExistingUserId($normalized['userIds']);
		if ($probeUserId !== null) {
			try {
				$this->adminUserProfileUpdateService->validateProfileFields($probeUserId, $payload);
			} catch (AdminUserProfileUpdateException $e) {
				return [
					'ok' => false,
					'error' => 'validation_failed',
					'message' => $e->userMessage,
					'httpStatus' => $e->httpStatus > 0 ? $e->httpStatus : 400,
				];
			}
		}

		$results = [];
		$applied = 0;
		$failed = 0;

		foreach ($normalized['userIds'] as $userId) {
			$user = $this->userManager->get($userId);
			if ($user === null) {
				$results[] = ['userId' => $userId, 'status' => 'failed', 'error' => 'user_not_found'];
				$failed++;
				continue;
			}
			if (!$user->isEnabled()) {
				$results[] = ['userId' => $userId, 'status' => 'failed', 'error' => 'user_disabled'];
				$failed++;
				continue;
			}

			if ($dryRun) {
				$results[] = ['userId' => $userId, 'status' => 'would_apply'];
				$applied++;
				continue;
			}

			try {
				$this->adminUserProfileUpdateService->updateProfile($userId, $payload, $performedBy);
				$results[] = ['userId' => $userId, 'status' => 'applied'];
				$applied++;
			} catch (AdminUserProfileUpdateException $e) {
				$results[] = [
					'userId' => $userId,
					'status' => 'failed',
					'error' => 'validation_failed',
					'message' => $e->userMessage,
				];
				$failed++;
			} catch (\Throwable $e) {
				$this->logger->error('batchProfile item failed: ' . $e->getMessage(), [
					'exception' => $e,
					'userId' => $userId,
				]);
				$results[] = ['userId' => $userId, 'status' => 'failed', 'error' => 'write_failed'];
				$failed++;
			}
		}

		$this->logger->info('admin_batch profile', [
			'actor' => $performedBy,
			'op' => 'profile',
			'dryRun' => $dryRun,
			'ok' => $applied,
			'fail' => $failed,
		]);

		return [
			'ok' => true,
			'dryRun' => $dryRun,
			'summary' => ['applied' => $applied, 'failed' => $failed],
			'results' => $results,
		];
	}

	/**
	 * @param list<string>|mixed $rawUserIds
	 * @param array<string, mixed> $vacationPolicy
	 * @return array<string, mixed>
	 */
	public function batchVacationPolicy(mixed $rawUserIds, array $vacationPolicy, string $performedBy, bool $dryRun = false): array
	{
		$normalized = AdminBatchUserIds::normalize($rawUserIds);
		if (!$normalized['ok']) {
			return $normalized;
		}
		if ($vacationPolicy === []) {
			return ['ok' => false, 'error' => 'vacation_policy_required', 'httpStatus' => 400];
		}

		$probeUserId = $this->firstExistingUserId($normalized['userIds']);
		if ($probeUserId !== null) {
			try {
				$this->adminUserProfileUpdateService->validateProfileFields($probeUserId, [
					'vacationPolicy' => $vacationPolicy,
				]);
			} catch (AdminUserProfileUpdateException $e) {
				return [
					'ok' => false,
					'error' => 'validation_failed',
					'message' => $e->userMessage,
					'httpStatus' => $e->httpStatus > 0 ? $e->httpStatus : 400,
				];
			}
		}

		$results = [];
		$applied = 0;
		$failed = 0;

		foreach ($normalized['userIds'] as $userId) {
			$user = $this->userManager->get($userId);
			if ($user === null) {
				$results[] = ['userId' => $userId, 'status' => 'failed', 'error' => 'user_not_found'];
				$failed++;
				continue;
			}
			if (!$user->isEnabled()) {
				$results[] = ['userId' => $userId, 'status' => 'failed', 'error' => 'user_disabled'];
				$failed++;
				continue;
			}

			if ($dryRun) {
				$results[] = ['userId' => $userId, 'status' => 'would_apply'];
				$applied++;
				continue;
			}

			try {
				$this->adminUserProfileUpdateService->applyVacationPolicy($userId, $vacationPolicy, $performedBy);
				$results[] = ['userId' => $userId, 'status' => 'applied'];
				$applied++;
			} catch (AdminUserProfileUpdateException $e) {
				$results[] = [
					'userId' => $userId,
					'status' => 'failed',
					'error' => 'validation_failed',
					'message' => $e->userMessage,
				];
				$failed++;
			} catch (\Throwable $e) {
				$this->logger->error('batchVacationPolicy item failed: ' . $e->getMessage(), [
					'exception' => $e,
					'userId' => $userId,
				]);
				$results[] = ['userId' => $userId, 'status' => 'failed', 'error' => 'write_failed'];
				$failed++;
			}
		}

		$this->logger->info('admin_batch vacation_policy', [
			'actor' => $performedBy,
			'op' => 'vacation_policy',
			'dryRun' => $dryRun,
			'ok' => $applied,
			'fail' => $failed,
		]);

		return [
			'ok' => true,
			'dryRun' => $dryRun,
			'summary' => ['applied' => $applied, 'failed' => $failed],
			'results' => $results,
		];
	}

	/**
	 * @param list<string> $userIds
	 */
	private function firstExistingUserId(array $userIds): ?string
	{
		foreach ($userIds as $candidate) {
			if ($this->userManager->get($candidate) !== null) {
				return $candidate;
			}
		}
		return null;
	}

	/**
	 * @param array<string, mixed>|null $workingTimeModel
	 * @param array{germanState?: string}|null $holidayRegion
	 * @return array<string, mixed>
	 */
	private function buildProfilePayload(?array $workingTimeModel, ?array $holidayRegion): array
	{
		$payload = [];
		$wtm = [];
		if ($workingTimeModel !== null) {
			if (isset($workingTimeModel['modelId'])) {
				$wtm['workingTimeModelId'] = $workingTimeModel['modelId'];
			} elseif (isset($workingTimeModel['workingTimeModelId'])) {
				$wtm['workingTimeModelId'] = $workingTimeModel['workingTimeModelId'];
			}
			if (isset($workingTimeModel['startDate'])) {
				$wtm['startDate'] = $workingTimeModel['startDate'];
			}
		}
		if ($holidayRegion !== null && array_key_exists('germanState', $holidayRegion)) {
			$wtm['germanState'] = (string)$holidayRegion['germanState'];
		}
		if ($wtm !== []) {
			$payload['workingTimeModel'] = $wtm;
		}
		return $payload;
	}
}
