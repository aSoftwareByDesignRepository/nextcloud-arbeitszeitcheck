<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Controller;

use OCA\ArbeitszeitCheck\ConfigTransfer\TransferException;

/**
 * Request-boundary scalar coercion for API controllers (api-security §2).
 *
 * `(string)["x"]` → "Array" would store junk and `(int)["x"]` → 1 would
 * silently forge an id/revision match, so anything non-scalar is refused
 * through the app's own exception taxonomy — here the transfer family,
 * whose controllers are the pack-driven consumers of this trait.
 */
trait ApiTrait
{
	protected function strParam(string $name, string $default = ''): string
	{
		$raw = $this->request->getParam($name, null);
		if ($raw === null) {
			return $default;
		}
		if (!is_string($raw)) {
			throw new TransferException('TRANSFER_VALUE_INVALID', ['field' => $name]);
		}
		return $raw;
	}
}
