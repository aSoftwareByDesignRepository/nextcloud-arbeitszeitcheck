<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $v)
 * @method string getClientRequestId()
 * @method void setClientRequestId(string $v)
 * @method string getResponseJson()
 * @method void setResponseJson(string $v)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $v)
 */
class MobileStampIdempotency extends Entity
{
	protected string $userId = '';
	protected string $clientRequestId = '';
	protected string $responseJson = '';
	protected int $createdAt = 0;

	public function __construct()
	{
		$this->addType('userId', 'string');
		$this->addType('clientRequestId', 'string');
		$this->addType('responseJson', 'string');
		$this->addType('createdAt', 'integer');
	}
}
