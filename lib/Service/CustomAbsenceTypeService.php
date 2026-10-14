<?php

declare(strict_types=1);

/**
 * Administration service for custom absence types
 * ("weitere betriebliche Fehlzeiten").
 *
 * Owns validation, slug generation, writes, audit logging, and the
 * delete-vs-deactivate decision. Read composition (built-ins + customs) lives
 * in {@see \OCA\ArbeitszeitCheck\Support\AbsenceTypeCatalog}; every write must
 * call {@see AbsenceTypeCatalog::resetCustomTypeCache()} so the request-scoped
 * cache never serves stale rows to later code in the same request.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\CustomAbsenceType;
use OCA\ArbeitszeitCheck\Db\CustomAbsenceTypeMapper;
use OCA\ArbeitszeitCheck\Support\AbsenceTypeCatalog;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\TTransactional;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

class CustomAbsenceTypeService
{
	use TTransactional;

	public const ENTITY_TYPE = 'custom_absence_type';

	public const ACTION_CREATED = 'custom_absence_type_created';
	public const ACTION_UPDATED = 'custom_absence_type_updated';
	public const ACTION_DEACTIVATED = 'custom_absence_type_deactivated';
	public const ACTION_DELETED = 'custom_absence_type_deleted';

	private const LABEL_MAX_LENGTH = 80;
	private const SLUG_MAX_LENGTH = 56;
	private const MAX_I18N_ENTRIES = 50;
	private const MAX_DAYS_LIMIT = 3650;
	private const LOCALE_KEY_PATTERN = '/^[a-z]{2,3}([_-][a-zA-Z0-9]{2,8})*$/';
	private const SLUG_PATTERN = '/^[a-z0-9](?:[a-z0-9_]*[a-z0-9])?$/';

	public function __construct(
		private readonly CustomAbsenceTypeMapper $mapper,
		private readonly AuditLogMapper $auditLogMapper,
		private readonly IDBConnection $db,
		private readonly IL10N $l10n,
		private readonly ILockingProvider $lockingProvider,
	) {
	}

	/**
	 * Every write mutates the catalog that absence create/update validates
	 * against — serialize it so a delete can never orphan an in-flight
	 * absence (deleteOrDeactivate's count→delete is only safe while no
	 * creatable-type check is concurrently in flight).
	 */
	private function atomicWithCatalogLock(callable $callback): mixed
	{
		$this->lockingProvider->acquireLock(
			DbLockKeys::absenceTypeCatalog(),
			ILockingProvider::LOCK_EXCLUSIVE,
			'Custom absence type catalog write',
		);
		try {
			return $this->atomic($callback, $this->db);
		} finally {
			$this->lockingProvider->releaseLock(
				DbLockKeys::absenceTypeCatalog(),
				ILockingProvider::LOCK_EXCLUSIVE,
			);
		}
	}

	/**
	 * All custom types (incl. inactive) with usage counts for the admin list.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function listWithUsage(?string $locale = null): array
	{
		$rows = [];
		foreach ($this->mapper->findAll() as $type) {
			$summary = $type->getSummary($locale);
			$summary['absenceCount'] = $this->mapper->countAbsenceUsage($type->getCode());
			$rows[] = $summary;
		}
		return $rows;
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function get(int $id, ?string $locale = null): array
	{
		$row = $this->mapper->find($id);
		$summary = $row->getSummary($locale);
		$summary['absenceCount'] = $this->mapper->countAbsenceUsage($row->getCode());
		return $summary;
	}

	/**
	 * @return array{data: ?array<string,mixed>, errors: array<string,string>, code: int}
	 */
	public function create(array $params, string $performedBy): array
	{
		$errors = $this->validate($params);
		if ($errors !== []) {
			return ['data' => null, 'errors' => $errors, 'code' => 400];
		}

		$type = new CustomAbsenceType();
		$explicitSlug = isset($params['slug']) && trim((string)$params['slug']) !== '';
		$code = $this->resolveCode($params, $explicitSlug);
		if ($code === null) {
			return ['data' => null, 'errors' => ['code' => $this->l10n->t('This identifier is already in use. Please choose a different one.')], 'code' => 409];
		}
		$type->setCode($code);
		$this->applyParams($type, $params, true);
		$type->setCreatedAt(new \DateTime());
		$type->setCreatedBy($performedBy);
		$type->setUpdatedAt(new \DateTime());
		$type->setUpdatedBy($performedBy);

		try {
			$saved = $this->atomicWithCatalogLock(function () use ($type, $performedBy) {
				if ($this->mapper->codeExists($type->getCode())) {
					throw new CustomAbsenceTypeCodeTakenException();
				}
				$inserted = $this->mapper->insert($type);
				$this->auditLogMapper->logAction(
					$performedBy,
					self::ACTION_CREATED,
					self::ENTITY_TYPE,
					$inserted->getId(),
					null,
					$inserted->getSummary(),
					$performedBy,
				);
				return $inserted;
			}, $this->db);
		} catch (LockedException $e) {
			return $this->catalogBusy();
		} catch (CustomAbsenceTypeCodeTakenException | DbException $e) {
			// Unique-index race: another request created the same code first.
			return ['data' => null, 'errors' => ['code' => $this->l10n->t('This identifier is already in use. Please choose a different one.')], 'code' => 409];
		}

		AbsenceTypeCatalog::resetCustomTypeCache();
		return ['data' => $saved->getSummary(), 'errors' => [], 'code' => 201];
	}

	/**
	 * @return array{data: ?array<string,mixed>, errors: array<string,string>, code: int}
	 */
	public function update(int $id, array $params, string $performedBy): array
	{
		try {
			$type = $this->mapper->find($id);
		} catch (DoesNotExistException $e) {
			return ['data' => null, 'errors' => [], 'code' => 404];
		}

		$errors = $this->validate($params, $type);
		if ($errors !== []) {
			return ['data' => null, 'errors' => $errors, 'code' => 400];
		}

		$oldValues = $type->getSummary();
		$this->applyParams($type, $params, false);
		$type->setUpdatedAt(new \DateTime());
		$type->setUpdatedBy($performedBy);

		try {
			$updated = $this->atomicWithCatalogLock(function () use ($type, $oldValues, $performedBy) {
				$updated = $this->mapper->update($type);
				$this->auditLogMapper->logAction(
					$performedBy,
					self::ACTION_UPDATED,
					self::ENTITY_TYPE,
					$updated->getId(),
					$oldValues,
					$updated->getSummary(),
					$performedBy,
				);
				return $updated;
			}, $this->db);
		} catch (LockedException $e) {
			return $this->catalogBusy();
		}

		AbsenceTypeCatalog::resetCustomTypeCache();
		return ['data' => $updated->getSummary(), 'errors' => [], 'code' => 200];
	}

	/**
	 * Hard-delete when no absence references the code; soft-delete (deactivate)
	 * otherwise so historical records keep a resolvable label and semantics.
	 *
	 * @return array{data: ?array<string,mixed>, errors: array<string,string>, code: int}
	 */
	public function deleteOrDeactivate(int $id, string $performedBy): array
	{
		try {
			$result = $this->atomicWithCatalogLock(function () use ($id, $performedBy) {
				$type = $this->mapper->find($id);
				$oldValues = $type->getSummary();
				$usage = $this->mapper->countAbsenceUsage($type->getCode());

				if ($usage === 0) {
					$this->mapper->delete($type);
					$this->auditLogMapper->logAction(
						$performedBy, self::ACTION_DELETED, self::ENTITY_TYPE, $id, $oldValues, null, $performedBy,
					);
					return ['mode' => 'deleted', 'usage' => 0];
				}

				$type->setActive(false);
				$type->setUpdatedAt(new \DateTime());
				$type->setUpdatedBy($performedBy);
				$this->mapper->update($type);
				$this->auditLogMapper->logAction(
					$performedBy, self::ACTION_DEACTIVATED, self::ENTITY_TYPE, $id, $oldValues, $type->getSummary(), $performedBy,
				);
				return ['mode' => 'deactivated', 'usage' => $usage];
			}, $this->db);
		} catch (DoesNotExistException $e) {
			return ['data' => null, 'errors' => [], 'code' => 404];
		} catch (LockedException $e) {
			return $this->catalogBusy();
		}

		AbsenceTypeCatalog::resetCustomTypeCache();
		return ['data' => $result, 'errors' => [], 'code' => 200];
	}

	/**
	 * Fail-fast lock contention must surface as a retryable 409, never a
	 * masked 500 — clients need to distinguish "busy, retry" from failure.
	 *
	 * @return array{data: ?array<string,mixed>, errors: array<string,string>, code: int, error: string}
	 */
	private function catalogBusy(): array
	{
		return [
			'data' => null,
			'errors' => [],
			'code' => 409,
			'error' => $this->l10n->t('Another change to the absence type catalog is in progress. Please wait a moment and try again.'),
		];
	}

	/**
	 * Field-level validation. Returns translated error map keyed by field.
	 *
	 * @return array<string,string>
	 */
	private function validate(array $params, ?CustomAbsenceType $existing = null): array
	{
		$errors = [];
		$isCreate = $existing === null;

		if ($isCreate || array_key_exists('label', $params)) {
			$label = $this->sanitizeLabel($params['label'] ?? '');
			if ($label === '') {
				$errors['label'] = $this->l10n->t('Please enter a name for the absence type.');
			} elseif (mb_strlen($label) > self::LABEL_MAX_LENGTH) {
				$errors['label'] = $this->l10n->t('The name must not exceed %d characters.', [self::LABEL_MAX_LENGTH]);
			} elseif (strpbrk($label, '<>') !== false) {
				// Labels are rendered HTML-escaped everywhere — rejecting the
				// brackets anyway is cheap stored-XSS defense-in-depth.
				$errors['label'] = $this->l10n->t('The name must not contain the characters < or >.');
			}
		}

		if ($isCreate && isset($params['slug']) && $params['slug'] !== '') {
			$slug = $this->sanitizeSlug((string)$params['slug']);
			if (!preg_match(self::SLUG_PATTERN, $slug)) {
				$errors['code'] = $this->l10n->t('The identifier may only contain lowercase letters, numbers, and underscores, and must not start or end with an underscore.');
			} elseif (mb_strlen($slug) > self::SLUG_MAX_LENGTH) {
				$errors['code'] = $this->l10n->t('The identifier must not exceed %d characters.', [self::SLUG_MAX_LENGTH]);
			}
		}

		if (array_key_exists('labelMap', $params)) {
			$mapErrors = $this->validateLabelMap($params['labelMap']);
			if ($mapErrors !== []) {
				$errors['labelMap'] = $mapErrors;
			}
		}

		if (array_key_exists('maxDays', $params)) {
			$maxDays = filter_var($params['maxDays'], FILTER_VALIDATE_INT);
			if ($maxDays === false || $maxDays < 1 || $maxDays > self::MAX_DAYS_LIMIT) {
				$errors['maxDays'] = $this->l10n->t('The maximum duration must be a whole number between 1 and %d days.', [self::MAX_DAYS_LIMIT]);
			}
		}

		if (array_key_exists('color', $params) && !in_array($params['color'], CustomAbsenceType::COLORS, true)) {
			$errors['color'] = $this->l10n->t('Please choose a valid color.');
		}

		if (array_key_exists('icon', $params) && !in_array($params['icon'], CustomAbsenceType::ICONS, true)) {
			$errors['icon'] = $this->l10n->t('Please choose a valid icon.');
		}

		return $errors;
	}

	private function applyParams(CustomAbsenceType $type, array $params, bool $isCreate): void
	{
		if ($isCreate || array_key_exists('label', $params)) {
			$type->setLabel($this->sanitizeLabel($params['label'] ?? ''));
		}
		if (array_key_exists('labelMap', $params)) {
			$type->setLabelMap($this->sanitizeLabelMap($params['labelMap']));
		}
		if ($isCreate || array_key_exists('sollCredit', $params)) {
			$type->setSollCredit($this->parseBool($params['sollCredit'] ?? false));
		}
		if (array_key_exists('maxDays', $params)) {
			$type->setMaxDays((int)$params['maxDays']);
		} elseif ($isCreate) {
			$type->setMaxDays(365);
		}
		if (array_key_exists('color', $params)) {
			$type->setColor($params['color']);
		} elseif ($isCreate) {
			$type->setColor('info');
		}
		if (array_key_exists('icon', $params)) {
			$type->setIcon($params['icon']);
		} elseif ($isCreate) {
			$type->setIcon('calendar');
		}
		if ($isCreate) {
			$type->setActive(true);
		} elseif (array_key_exists('active', $params)) {
			$type->setActive($this->parseBool($params['active']));
		}
	}

	/**
	 * Resolve the storage code. Explicitly requested slugs must not silently
	 * suffix — the admin expects either exactly that identifier or a clear
	 * error (null). Label-derived slugs auto-suffix instead.
	 */
	private function resolveCode(array $params, bool $explicitSlug): ?string
	{
		$slug = $explicitSlug
			? $this->sanitizeSlug((string)$params['slug'])
			: $this->slugifyLabel($this->sanitizeLabel($params['label'] ?? ''));

		if ($slug === '') {
			$slug = 'type';
		}
		$slug = mb_substr($slug, 0, self::SLUG_MAX_LENGTH);

		$code = CustomAbsenceType::CODE_PREFIX . $slug;
		if (!$this->mapper->codeExists($code)) {
			return $code;
		}
		if ($explicitSlug) {
			return null;
		}
		for ($i = 2; $i <= 99; $i++) {
			$candidate = CustomAbsenceType::CODE_PREFIX . mb_substr($slug, 0, self::SLUG_MAX_LENGTH - 2) . '_' . $i;
			if (!$this->mapper->codeExists($candidate)) {
				return $candidate;
			}
		}
		return $code;
	}

	private function sanitizeLabel(mixed $value): string
	{
		if (!is_string($value)) {
			return '';
		}
		// Strip control characters (incl. bidi/zero-width) — renderers escape
		// HTML, but control chars can corrupt CSV exports and layout.
		$clean = preg_replace('/[\x00-\x1F\x7F\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}]/u', '', $value);
		return trim(is_string($clean) ? $clean : '');
	}

	private function sanitizeSlug(string $slug): string
	{
		$slug = mb_strtolower(trim($slug));
		if (str_starts_with($slug, CustomAbsenceType::CODE_PREFIX)) {
			$slug = mb_substr($slug, mb_strlen(CustomAbsenceType::CODE_PREFIX));
		}
		return $slug;
	}

	private function slugifyLabel(string $label): string
	{
		$ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label);
		if (!is_string($ascii)) {
			$ascii = $label;
		}
		$ascii = mb_strtolower($ascii);
		$slug = preg_replace('/[^a-z0-9]+/', '_', $ascii);
		return trim(is_string($slug) ? $slug : '', '_');
	}

	/**
	 * @return array<string,string> Locale key => error message (flattened to one string per key).
	 */
	private function validateLabelMap(mixed $map): array
	{
		if (!is_array($map)) {
			return ['_' => $this->l10n->t('Translations must be provided as a list of locale and label pairs.')];
		}
		if (count($map) > self::MAX_I18N_ENTRIES) {
			return ['_' => $this->l10n->t('At most %d translations can be stored.', [self::MAX_I18N_ENTRIES])];
		}
		$errors = [];
		foreach ($map as $locale => $text) {
			if (!is_string($locale) || !preg_match(self::LOCALE_KEY_PATTERN, $locale)) {
				$errors['_'] = $this->l10n->t('Invalid locale identifier. Use codes like de, en, or pt_BR.');
				continue;
			}
			$clean = $this->sanitizeLabel($text);
			if ($clean === '' && $text !== '' && $text !== null) {
				$errors[$locale] = $this->l10n->t('The translation contains no usable characters.');
			} elseif (strpbrk($clean, '<>') !== false) {
				$errors[$locale] = $this->l10n->t('The name must not contain the characters < or >.');
			} elseif (mb_strlen($clean) > self::LABEL_MAX_LENGTH) {
				$errors[$locale] = $this->l10n->t('The name must not exceed %d characters.', [self::LABEL_MAX_LENGTH]);
			}
		}
		return $errors;
	}

	/**
	 * @return array<string,string>
	 */
	private function sanitizeLabelMap(mixed $map): array
	{
		if (!is_array($map)) {
			return [];
		}
		$clean = [];
		foreach ($map as $locale => $text) {
			if (!is_string($locale) || !preg_match(self::LOCALE_KEY_PATTERN, $locale)) {
				continue;
			}
			$label = $this->sanitizeLabel($text);
			if ($label !== '') {
				$clean[str_replace('-', '_', $locale)] = $label;
			}
		}
		return $clean;
	}

	private function parseBool(mixed $value): bool
	{
		// Form-encoded "false"/"0" strings must not be truthy (API security rule §4).
		return $value === true || $value === 1 || $value === '1' || $value === 'true';
	}
}

/**
 * Internal control-flow exception: the chosen code is already taken.
 * Surfaced to the caller as a 409 field error — never to the client as-is.
 */
class CustomAbsenceTypeCodeTakenException extends \RuntimeException
{
}
