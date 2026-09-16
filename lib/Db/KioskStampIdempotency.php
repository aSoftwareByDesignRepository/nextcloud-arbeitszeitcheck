<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getTerminalId()
 * @method void setTerminalId(string $v)
 * @method string getClientRequestId()
 * @method void setClientRequestId(string $v)
 * @method string getResponseJson()
 * @method void setResponseJson(string $v)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $v)
 */
class KioskStampIdempotency extends Entity
{
	protected string $terminalId = '';
	protected string $clientRequestId = '';
	protected string $responseJson = '';
	protected int $createdAt = 0;

	public function __construct()
	{
		$this->addType('terminalId', 'string');
		$this->addType('clientRequestId', 'string');
		$this->addType('responseJson', 'string');
		$this->addType('createdAt', 'integer');
	}
}
