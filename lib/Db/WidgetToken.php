<?php

declare(strict_types=1);

/**
 * Scoped widget token entity (suite widget spec D-03).
 * Only the SHA-256 hash of the raw token is persisted.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getTokenHash()
 * @method void setTokenHash(string $tokenHash)
 * @method string getScope()
 * @method void setScope(string $scope)
 * @method string|null getDeviceLabel()
 * @method void setDeviceLabel(string|null $deviceLabel)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method \DateTime|null getExpiresAt()
 * @method void setExpiresAt(\DateTime|null $expiresAt)
 * @method \DateTime|null getRevokedAt()
 * @method void setRevokedAt(\DateTime|null $revokedAt)
 * @method \DateTime|null getLastUsedAt()
 * @method void setLastUsedAt(\DateTime|null $lastUsedAt)
 */
class WidgetToken extends Entity
{
	protected $userId;
	protected $tokenHash;
	protected $scope;
	protected $deviceLabel;
	protected $createdAt;
	protected $expiresAt;
	protected $revokedAt;
	protected $lastUsedAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('userId', 'string');
		$this->addType('tokenHash', 'string');
		$this->addType('scope', 'string');
		$this->addType('deviceLabel', 'string');
		$this->addType('createdAt', 'datetime');
		$this->addType('expiresAt', 'datetime');
		$this->addType('revokedAt', 'datetime');
		$this->addType('lastUsedAt', 'datetime');
	}
}
