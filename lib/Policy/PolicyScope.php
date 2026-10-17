<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Policy;

enum PolicyScope: string
{
	case ORG = 'org';
	case MODEL = 'model';
	case POLICY_COHORT = 'policy_cohort';
	case TEAM = 'team';
	case USER = 'user';

	public function precedence(): int
	{
		return match ($this) {
			self::ORG => 0,
			self::MODEL => 1,
			self::TEAM => 2,
			self::POLICY_COHORT => 3,
			self::USER => 4,
		};
	}
}
