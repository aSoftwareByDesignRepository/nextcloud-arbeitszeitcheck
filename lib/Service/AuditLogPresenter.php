<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Service;

use OCP\IDateTimeFormatter;
use OCP\IL10N;
use OCP\IUser;

/**
 * Human-readable labels and filter metadata for audit log entries.
 */
class AuditLogPresenter
{
	public const CATEGORY_CREATE = 'create';
	public const CATEGORY_UPDATE = 'update';
	public const CATEGORY_DELETE = 'delete';
	public const CATEGORY_APPROVE = 'approve';
	public const CATEGORY_REJECT = 'reject';
	public const CATEGORY_OTHER = 'other';

	/** @var array<string, string> */
	private const ACTION_LABELS = [
		'absence_approved' => 'Absence approved',
		'absence_auto_approved' => 'Absence auto-approved',
		'absence_cancelled' => 'Absence cancelled',
		'absence_created' => 'Absence created',
		'absence_deleted' => 'Absence deleted',
		'absence_manager_recorded' => 'Absence recorded by manager',
		'absence_rejected' => 'Absence rejected',
		'absence_shortened' => 'Absence shortened',
		'absence_substitute_approved' => 'Substitute approved',
		'absence_substitute_declined' => 'Substitute declined',
		'absence_updated' => 'Absence updated',
		'break_policy_updated' => 'Central break rules updated',
		'business_trip_approved' => 'Business trip approved',
		'business_trip_cancelled' => 'Business trip cancelled',
		'business_trip_feature_enabled' => 'Business trips feature enabled',
		'business_trip_legacy_absence_disabled' => 'Legacy business-trip absence creation disabled',
		'business_trip_rejected' => 'Business trip rejected',
		'business_trip_rule_assignment_created' => 'Business trip rule assignment created',
		'business_trip_rule_assignment_ended' => 'Business trip rule assignment ended',
		'business_trip_rule_set_activated' => 'Business trip rule set activated',
		'business_trip_rule_set_created' => 'Business trip rule set created',
		'business_trip_rule_set_retired' => 'Business trip rule set retired',
		'business_trip_rule_set_reviewed' => 'Business trip rule set legal review recorded',
		'business_trip_rule_set_updated' => 'Business trip rule set updated',
		'business_trip_submitted' => 'Business trip submitted',
		'clock_in' => 'Clock in',
		'clock_out' => 'Clock out',
		'compliance_violation_resolved' => 'Compliance issue resolved',
		'end_break' => 'Break ended',
		'gdpr_data_deletion_request' => 'Personal data deletion requested',
		'manager_bulk_decision' => 'Bulk decision applied',
		'month_closure_pdf_downloaded' => 'Month closure PDF downloaded',
		'month_closure_reopened' => 'Month closure reopened',
		'onboarding_completed' => 'Onboarding completed',
		'organization_time_capture_methods_updated' => 'Organisation time recording methods updated',
		'overtime_payout_processed' => 'Overtime payout processed',
		'paid_break_recompute' => 'Paid breaks recomputed',
		'paid_break_share_changed' => 'Paid break share adjusted',
		'settings_updated' => 'Settings updated',
		'stale_paused_repaired' => 'Paused entry repaired',
		'start_break' => 'Break started',
		'state_holiday_deleted' => 'Public holiday deleted',
		'tariff_rule_set_activated' => 'Tariff rule set activated',
		'tariff_rule_set_created' => 'Tariff rule set created',
		'tariff_rule_set_deleted' => 'Tariff rule set deleted',
		'tariff_rule_set_refs_cleaned' => 'Dangling tariff rule set references cleaned',
		'tariff_rule_set_retired' => 'Tariff rule set retired',
		'tariff_rule_set_updated' => 'Tariff rule set updated',
		'team_created' => 'Team created',
		'team_deleted' => 'Team deleted',
		'team_manager_added' => 'Team manager added',
		'team_manager_removed' => 'Team manager removed',
		'team_member_added' => 'Team member added',
		'team_member_removed' => 'Team member removed',
		'team_updated' => 'Team updated',
		'time_entry_auto_completed_daily_max' => 'Time entry auto-completed (daily maximum)',
		'time_entry_correction_approved' => 'Time entry correction approved',
		'time_entry_correction_auto_approved' => 'Time entry correction auto-approved',
		'time_entry_correction_cancelled' => 'Time entry correction cancelled',
		'time_entry_correction_rejected' => 'Time entry correction rejected',
		'time_entry_correction_requested' => 'Time entry correction requested',
		'time_entry_created' => 'Time entry created',
		'time_entry_deleted' => 'Time entry deleted',
		'time_entry_manager_corrected' => 'Time entry corrected by manager',
		'time_entry_manager_created' => 'Time entry created by manager',
		'time_entry_manual_create_requested' => 'Manual time entry requested',
		'time_entry_paused_completed' => 'Paused time entry completed',
		'time_entry_updated' => 'Time entry updated',
		'user_overtime_opening_balance_updated' => 'Overtime opening balance updated',
		'user_business_trip_profile_set' => 'Business trip profile updated',
		'user_overtime_tracking_from_updated' => 'Overtime tracking start updated',
		'user_time_capture_methods_updated' => 'Time capture methods updated',
		'user_working_time_model_created' => 'Working time model assigned',
		'user_working_time_model_ended' => 'Working time model assignment ended',
		'user_working_time_model_updated' => 'Working time model assignment updated',
		'vacation_balance_import' => 'Vacation balance imported',
		'vacation_rollover' => 'Vacation balance rolled over',
		'working_time_model_created' => 'Working time model created',
		'working_time_model_deleted' => 'Working time model deleted',
		'working_time_model_updated' => 'Working time model updated',
	];

	/** @var array<string, string> */
	private const ENTITY_LABELS = [
		'absence' => 'Absence',
		'break_policy' => 'Central break rules',
		'bulk_decision' => 'Bulk decision',
		'business_trip' => 'Business trip',
		'business_trip_rule_assignment' => 'Business trip rule assignment',
		'business_trip_rule_set' => 'Business trip rule set',
		'compliance_violation' => 'Compliance issue',
		'feature_flag' => 'Feature flag',
		'month_closure' => 'Month closure',
		'overtime_payout' => 'Overtime payout',
		'state_holiday' => 'Public holiday',
		'tariff_rule_set' => 'Tariff rule set',
		'team' => 'Team',
		'team_manager' => 'Team manager',
		'team_member' => 'Team member',
		'time_entry' => 'Time entry',
		'user' => 'User (audit log entity)',
		'user_business_trip_profile' => 'Business trip profile',
		'user_settings' => 'User settings',
		'user_working_time_model' => 'Working time model assignment',
		'vacation_year_balance' => 'Vacation balance',
		'working_time_model' => 'Working time model',
	];

	/** @var array<string, list<string>> */
	private const CATEGORY_ACTIONS = [
		self::CATEGORY_CREATE => [
			'absence_created',
			'business_trip_rule_assignment_created',
			'business_trip_rule_set_created',
			'clock_in',
			'onboarding_completed',
			'start_break',
			'tariff_rule_set_created',
			'team_created',
			'team_manager_added',
			'team_member_added',
			'time_entry_created',
			'time_entry_manual_create_requested',
			'time_entry_manager_created',
			'user_business_trip_profile_set',
			'user_working_time_model_created',
			'vacation_balance_import',
			'vacation_rollover',
			'working_time_model_created',
		],
		self::CATEGORY_UPDATE => [
			'absence_shortened',
			'absence_updated',
			'break_policy_updated',
			'business_trip_feature_enabled',
			'business_trip_legacy_absence_disabled',
			'business_trip_rule_assignment_ended',
			'business_trip_rule_set_activated',
			'business_trip_rule_set_retired',
			'business_trip_rule_set_updated',
			'business_trip_submitted',
			'clock_out',
			'end_break',
			'overtime_payout_processed',
			'organization_time_capture_methods_updated',
			'paid_break_recompute',
			'paid_break_share_changed',
			'settings_updated',
			'tariff_rule_set_activated',
			'tariff_rule_set_retired',
			'tariff_rule_set_updated',
			'team_updated',
			'time_entry_manager_corrected',
			'time_entry_paused_completed',
			'time_entry_updated',
			'user_overtime_opening_balance_updated',
			'user_overtime_tracking_from_updated',
			'user_time_capture_methods_updated',
			'user_working_time_model_ended',
			'user_working_time_model_updated',
			'working_time_model_updated',
		],
		self::CATEGORY_DELETE => [
			'absence_deleted',
			'gdpr_data_deletion_request',
			'state_holiday_deleted',
			'tariff_rule_set_deleted',
			'team_deleted',
			'team_manager_removed',
			'team_member_removed',
			'time_entry_deleted',
			'working_time_model_deleted',
		],
		self::CATEGORY_APPROVE => [
			'absence_approved',
			'absence_auto_approved',
			'absence_substitute_approved',
			'business_trip_approved',
			'business_trip_rule_set_reviewed',
			'time_entry_correction_approved',
			'time_entry_correction_auto_approved',
		],
		self::CATEGORY_REJECT => [
			'absence_cancelled',
			'absence_rejected',
			'absence_substitute_declined',
			'business_trip_cancelled',
			'business_trip_rejected',
			'time_entry_correction_cancelled',
			'time_entry_correction_rejected',
		],
	];

	public function __construct(
		private readonly IL10N $l10n,
		private readonly IDateTimeFormatter $dateTimeFormatter,
	) {
	}

	public function formatAction(string $action): string
	{
		$key = trim($action);
		if ($key === '') {
			return $this->l10n->t('Unknown action');
		}
		if (isset(self::ACTION_LABELS[$key])) {
			return $this->l10n->t(self::ACTION_LABELS[$key]);
		}

		$humanized = ucfirst(str_replace('_', ' ', $key));

		return $this->l10n->t('%s (custom action)', [$humanized]);
	}

	public function formatEntityType(string $entityType): string
	{
		$key = trim($entityType);
		if ($key === '') {
			return $this->l10n->t('Unknown record');
		}
		if (isset(self::ENTITY_LABELS[$key])) {
			return $this->l10n->t(self::ENTITY_LABELS[$key]);
		}

		$humanized = ucfirst(str_replace('_', ' ', $key));

		return $this->l10n->t('%s (custom record type)', [$humanized]);
	}

	public function formatActor(?string $userId, ?IUser $user = null): string
	{
		$id = trim((string)$userId);
		if ($id === '') {
			return $this->l10n->t('Unknown');
		}
		if ($user !== null) {
			return $user->getDisplayName();
		}
		if ($id === 'system') {
			return $this->l10n->t('System');
		}
		if ($this->isInternalTestAccount($id)) {
			return $this->l10n->t('Internal test account');
		}

		return $id;
	}

	public function formatActorWithId(?string $userId, ?IUser $user = null): string
	{
		$id = trim((string)$userId);
		if ($id === '') {
			return $this->formatActor($userId, $user);
		}
		if ($user !== null && $user->getUID() !== $user->getDisplayName()) {
			return $user->getDisplayName() . ' (' . $id . ')';
		}

		return $this->formatActor($userId, $user);
	}

	public function formatCreatedAt(?\DateTimeInterface $createdAt): string
	{
		if ($createdAt === null) {
			return '-';
		}

		return $this->dateTimeFormatter->formatDateTime($createdAt->getTimestamp(), 'medium', 'medium');
	}

	/**
	 * @return array<string, string> value => label
	 */
	public function getActionCategoryFilterOptions(): array
	{
		return [
			'' => $this->l10n->t('All actions'),
			self::CATEGORY_CREATE => $this->l10n->t('Created'),
			self::CATEGORY_UPDATE => $this->l10n->t('Updated'),
			self::CATEGORY_DELETE => $this->l10n->t('Deleted'),
			self::CATEGORY_APPROVE => $this->l10n->t('Approved'),
			self::CATEGORY_REJECT => $this->l10n->t('Rejected or cancelled'),
			self::CATEGORY_OTHER => $this->l10n->t('Other'),
		];
	}

	/**
	 * @return array<string, string> value => label
	 */
	public function getEntityTypeFilterOptions(): array
	{
		$allLabel = $this->l10n->t('All record types');
		$options = [];
		foreach (self::ENTITY_LABELS as $value => $label) {
			$options[$value] = $this->l10n->t($label);
		}
		asort($options);

		return ['' => $allLabel] + $options;
	}

	/**
	 * @return list<string>|null null when category is empty/invalid
	 */
	public function resolveCategoryActions(?string $category): ?array
	{
		$category = trim((string)$category);
		if ($category === '') {
			return null;
		}

		if ($category === self::CATEGORY_OTHER) {
			$known = [];
			foreach (self::CATEGORY_ACTIONS as $actions) {
				foreach ($actions as $action) {
					$known[$action] = true;
				}
			}
			$other = [];
			foreach (array_keys(self::ACTION_LABELS) as $action) {
				if (!isset($known[$action])) {
					$other[] = $action;
				}
			}

			return $other;
		}

		return self::CATEGORY_ACTIONS[$category] ?? null;
	}

	public function isValidActionCategory(?string $category): bool
	{
		$category = trim((string)$category);
		if ($category === '') {
			return true;
		}

		return $category === self::CATEGORY_OTHER || isset(self::CATEGORY_ACTIONS[$category]);
	}

	private function isInternalTestAccount(string $userId): bool
	{
		return str_starts_with($userId, '__') && str_ends_with($userId, '__');
	}
}
