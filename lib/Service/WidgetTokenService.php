<?php

declare(strict_types=1);

/**
 * Scoped widget token service (suite widget spec D-03, E.6 contract):
 * per-device, revocable, narrowly-scoped, deny-by-default, replay-aware.
 * Raw tokens are returned once at issue; only SHA-256 hashes persist.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\WidgetToken;
use OCA\ArbeitszeitCheck\Db\WidgetTokenMapper;
use OCA\ArbeitszeitCheck\Exception\WidgetTokenLimitException;
use OCP\AppFramework\Db\DoesNotExistException;

class WidgetTokenService
{
	public const SCOPE_CLOCK = 'azc-clock';
	public const ALLOWED_SCOPES = [self::SCOPE_CLOCK];

	/** Bound token validity — a forgotten token is not a forever key (E.6). */
	public const TTL_DAYS = 180;

	/**
	 * Per-user cap on simultaneously active (non-revoked, non-expired) tokens.
	 * Per-device issuance needs only a handful; the bound keeps a buggy or
	 * hostile session from minting unbounded rows on its own account.
	 */
	public const MAX_ACTIVE_TOKENS_PER_USER = 16;

	public function __construct(
		private WidgetTokenMapper $mapper,
	) {
	}

	/**
	 * Issue a scoped token. Returns [entity, rawToken] — the raw token is
	 * shown to the client exactly once and never stored.
	 */
	public function issue(string $userId, string $scope, ?string $deviceLabel = null): array
	{
		if (!\in_array($scope, self::ALLOWED_SCOPES, true)) {
			throw new \InvalidArgumentException('Unknown token scope'); // deny-by-default
		}
		$label = $deviceLabel !== null ? mb_substr($deviceLabel, 0, 128) : null;
		if ($label !== null && $label !== '') {
			// E.6-2: one active token per (user, device, scope) — reissuing on
			// a device revokes its prior token so a device can't stockpile
			// credentials; the per-user cap still bounds label-less issues.
			foreach ($this->mapper->findActiveByDevice($userId, $label) as $old) {
				if ($old->getScope() === $scope) {
					$this->mapper->revoke((int)$old->getId(), $userId);
				}
			}
		}
		if ($this->mapper->countActiveByUser($userId) >= self::MAX_ACTIVE_TOKENS_PER_USER) {
			throw new WidgetTokenLimitException('Active widget-token limit reached');
		}
		// E.6 token format `widget_<rnd>`: the stored SHA-256 hash covers the
		// full prefixed token — verify() hashes the presented token the same
		// way, so the stored-hash contract is unchanged.
		$raw = 'widget_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
		$t = new WidgetToken();
		$t->setUserId($userId);
		$t->setTokenHash(hash('sha256', $raw));
		$t->setScope($scope);
		$t->setDeviceLabel($label);
		$t->setCreatedAt(new \DateTime());
		$t->setExpiresAt((new \DateTime())->modify('+' . self::TTL_DAYS . ' days'));
		return [$this->mapper->insert($t), $raw];
	}

	/**
	 * Verify a raw token for a scope. Returns the entity on success —
	 * updates last_used_at for audit trail. Throws on any failure
	 * (unknown, revoked, expired, wrong scope) — deny-by-default.
	 */
	public function verify(string $rawToken, string $scope): WidgetToken
	{
		$t = $this->mapper->findByHash(hash('sha256', $rawToken));
		if ($t === null) {
			throw new DoesNotExistException('Unknown token');
		}
		if ($t->getRevokedAt() !== null) {
			throw new \RuntimeException('Token revoked');
		}
		$exp = $t->getExpiresAt();
		if ($exp !== null && $exp < new \DateTime()) {
			throw new \RuntimeException('Token expired');
		}
		if ($t->getScope() !== $scope) {
			throw new \RuntimeException('Scope mismatch');
		}
		$this->mapper->touchUsed($t->getId());
		return $t;
	}

	public function revoke(int $id, string $userId): bool
	{
		return $this->mapper->revoke($id, $userId);
	}

	/** @return array<int, array<string, mixed>> — public-safe view (no hashes) */
	public function listForUser(string $userId): array
	{
		return array_map(fn (WidgetToken $t) => [
			'id' => $t->getId(),
			'scope' => $t->getScope(),
			'deviceLabel' => $t->getDeviceLabel(),
			'createdAt' => $t->getCreatedAt()?->format('c'),
			'expiresAt' => $t->getExpiresAt()?->format('c'),
			'revoked' => $t->getRevokedAt() !== null,
			'lastUsedAt' => $t->getLastUsedAt()?->format('c'),
		], $this->mapper->findByUser($userId));
	}
}
