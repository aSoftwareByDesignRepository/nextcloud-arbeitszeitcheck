<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Repair;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Db\WorkLocationDefaultPolicy;
use OCA\ArbeitszeitCheck\Db\WorkLocationDefaultPolicyMapper;
use OCA\ArbeitszeitCheck\Db\WorkLocationDefinition;
use OCA\ArbeitszeitCheck\Db\WorkLocationDefinitionMapper;
use OCA\ArbeitszeitCheck\Service\WorkLocationCatalog;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Seeds the built-in work locations and migrates the legacy per-user
 * `work_location_default` setting into effectively dated user-scope policies.
 *
 * Idempotent by existence checks: built-in rows are inserted only when their
 * code is absent (admin label customization is never overwritten), and a user
 * setting is converted only when that user has no user-scope policy at all.
 * The legacy setting is intentionally left in place — during the transition
 * the resolver still reads it as a fallback for users the migration skipped.
 */
final class SeedWorkLocationCatalog implements IRepairStep
{
	private const LEGACY_EFFECTIVE_FROM = '1970-01-01';

	public function __construct(
		private readonly WorkLocationDefinitionMapper $definitionMapper,
		private readonly WorkLocationDefaultPolicyMapper $policyMapper,
		private readonly UserSettingsMapper $userSettingsMapper,
	) {
	}

	public function getName(): string
	{
		return 'Seed work-location catalog and migrate legacy user defaults';
	}

	public function run(IOutput $output): void
	{
		$seeded = 0;
		foreach (WorkLocationCatalog::BUILTIN_SEEDS as $seed) {
			if ($this->definitionMapper->codeExists($seed['code'])) {
				continue;
			}
			$now = new \DateTime();
			$row = new WorkLocationDefinition();
			$row->setCode($seed['code']);
			$row->setBaseLabel($seed['baseLabel']);
			$row->setReportingCategory($seed['category']);
			$row->setColor($seed['color']);
			$row->setIcon($seed['icon']);
			$row->setActive(true);
			$row->setBuiltIn(true);
			$row->setVersion(1);
			$row->setCreatedAt($now);
			$row->setUpdatedAt($now);
			$this->definitionMapper->insert($row);
			$seeded++;
		}

		$migrated = 0;
		foreach ($this->userSettingsMapper->findUserIdsWithNonEmptySetting(Constants::SETTING_WORK_LOCATION_DEFAULT) as $userId) {
			if ($this->policyMapper->findByScope(WorkLocationDefaultPolicy::SCOPE_USER, $userId) !== []) {
				continue;
			}
			$code = trim($this->userSettingsMapper->getStringSetting($userId, Constants::SETTING_WORK_LOCATION_DEFAULT, ''));
			if ($code === '' || !$this->definitionMapper->codeExists($code)) {
				continue;
			}
			$policy = new WorkLocationDefaultPolicy();
			$policy->setScopeType(WorkLocationDefaultPolicy::SCOPE_USER);
			$policy->setScopeId($userId);
			$policy->setLocationCode($code);
			$policy->setPriority(0);
			$policy->setEffectiveFrom(new \DateTime(self::LEGACY_EFFECTIVE_FROM));
			$policy->setRevision(1);
			$policy->setCreatedBy('system:upgrade');
			$policy->setCreatedAt(new \DateTime());
			$policy->setUpdatedAt(new \DateTime());
			$this->policyMapper->insert($policy);
			$migrated++;
		}

		$output->info(sprintf(
			'ArbeitszeitCheck: work-location catalog seeded (%d new, %d user defaults migrated).',
			$seeded,
			$migrated,
		));
	}
}
