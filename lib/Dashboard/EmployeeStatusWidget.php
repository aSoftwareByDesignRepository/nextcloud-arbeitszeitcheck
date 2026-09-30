<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Dashboard;

use OCA\ArbeitszeitCheck\AppInfo\Application;
use OCA\ArbeitszeitCheck\Service\DashboardDeskletRenderService;
use OCA\ArbeitszeitCheck\Service\DashboardWidgetDataService;
use OCA\ArbeitszeitCheck\Support\DashboardWidgetAssetBootstrap;
use OCA\ArbeitszeitCheck\Support\TimeClientBootstrap;
use OCP\AppFramework\Services\IInitialState;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IButtonWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\IReloadableWidget;
use OCP\Dashboard\Model\WidgetButton;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserSession;

class EmployeeStatusWidget implements IAPIWidgetV2, IButtonWidget, IIconWidget, IReloadableWidget {
	use RegistersTimeClientTrait;

	private const INITIAL_STATE_DESKLET = 'desklet';
	/** In-request cache: Nextcloud may call getItemsV2 and getWidgetButtons in one request. */
	private ?string $cachedWidgetUserId = null;

	/** @var array<string, mixed>|null */
	private ?array $cachedWidgetData = null;

	public function __construct(
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urlGenerator,
		private readonly DashboardWidgetDataService $widgetDataService,
		private readonly TimeClientBootstrap $timeClientBootstrap,
		private readonly WidgetIconHelper $widgetIconHelper,
		private readonly IUserSession $userSession,
		private readonly IInitialState $initialState,
		private readonly DashboardDeskletRenderService $deskletRenderService,
	) {
	}

	public function getId(): string {
		return Application::APP_ID . '-employee-status';
	}

	public function getTitle(): string {
		return $this->l10n->t('My work status');
	}

	public function getOrder(): int {
		return 30;
	}

	public function getIconClass(): string {
		return 'icon-history';
	}

	public function getIconUrl(): string {
		return $this->widgetIconHelper->getAbsoluteIconUrl();
	}

	public function getUrl(): ?string {
		return $this->dashboardQuickActionsUrl();
	}

	public function load(): void {
		// Desklet needs time formatting only — not the full utils bundle (avoids
		// duplicate script execution when NC injects widget assets repeatedly).
		$this->registerTimeClientForWidget($this->timeClientBootstrap, false);
		$this->registerDeskletStylesForWidget();
		DashboardWidgetAssetBootstrap::registerDeskletAssets();

		$user = $this->userSession->getUser();
		if ($user !== null) {
			$desklet = $this->deskletRenderService->renderForUser($user->getUID());
			$desklet['widgetPanelId'] = $this->getId();
			$this->initialState->provideInitialState(
				self::INITIAL_STATE_DESKLET,
				$desklet,
			);
		}
	}

	public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
		$data    = $this->getEmployeeDataSafe($userId);
		$status  = (string)$data['status'];
		$url     = $this->dashboardQuickActionsUrl();
		$icon    = $this->getIconUrl();
		$ts      = (string)time();

		$items = [];

		// ── Item 1: current status with contextual session detail ──────────────
		$statusTitle = $this->statusLabel($status);

		$duration = $this->formatDuration((int)$data['currentSessionDuration']);
		$sessionStart = (string)$data['sessionStartFormatted'];
		$breakStart   = (string)$data['breakStartFormatted'];

		$subtitle1 = match ($status) {
			'active'  => $sessionStart !== ''
				? $this->l10n->t('Since %1$s · Session: %2$s', [$sessionStart, $duration])
				: $this->l10n->t('Session: %1$s', [$duration]),
			'break'   => $breakStart !== ''
				? $this->l10n->t('Break since %1$s · Worked: %2$s', [$breakStart, $duration])
				: $this->l10n->t('On break · Worked: %1$s', [$duration]),
			'paused'  => $this->l10n->t('Paused · Worked so far: %1$s', [$duration]),
			default   => $this->l10n->t('Not clocked in today'),
		};
		$items[] = new WidgetItem($statusTitle, $subtitle1, $url, $icon, $ts . '-status');

		// ── Item 2: today's hours ───────────────────────────────────────────────
		$todayHours = number_format((float)$data['workingTodayHours'], 2);
		$items[] = new WidgetItem(
			$this->l10n->t('Today'),
			$this->l10n->t('%1$s h worked', [$todayHours]),
			$url, $icon, $ts . '-today'
		);

		// ── Item 3: this week (worked / required) ───────────────────────────────
		$weekWorked   = number_format((float)$data['weekHoursWorked'], 2);
		$weekRequired = number_format((float)$data['weekHoursRequired'], 2);
		$items[] = new WidgetItem(
			$this->l10n->t('This week'),
			$this->l10n->t('%1$s / %2$s h', [$weekWorked, $weekRequired]),
			$url, $icon, $ts . '-week'
		);

		// ── Item 4: cumulative overtime balance ─────────────────────────────────
		$balance     = isset($data['displayBalance'])
			? (float)$data['displayBalance']
			: (float)$data['cumulativeBalance'];
		$balanceStr  = ($balance >= 0 ? '+' : '') . number_format($balance, 2);
		$items[] = new WidgetItem(
			$this->l10n->t('Overtime balance'),
			$this->l10n->t('%1$s h', [$balanceStr]),
			$url, $icon, $ts . '-balance'
		);

		// ── Item 5: vacation summary ─────────────────────────────────────────────
		$vacationYearError = $data['vacationYearError'] ?? null;
		$vacationYearLabel = trim((string)($data['vacationYearLabel'] ?? ''));
		$vacationYear = (int)$data['vacationYear'];
		$vacationRemaining = number_format((float)$data['vacationRemaining'], 1);
		$vacationTotal = number_format((float)$data['vacationEntitlement'], 1);
		$vacationUnit = (string)($data['vacationUnit'] ?? 'days');
		$vacationTitle = $vacationYearLabel !== ''
			? $this->l10n->t('Vacation %1$s', [$vacationYearLabel])
			: $this->l10n->t('Vacation %1$s', [(string)$vacationYear]);
		if ($vacationYearError) {
			$items[] = new WidgetItem(
				$vacationTitle,
				$this->l10n->t('Ask your admin to set your hire date'),
				$url, $icon, $ts . '-vacation'
			);
		} else {
			$vacationSubtitle = $vacationUnit === 'hours'
				? $this->l10n->t('%1$s / %2$s hours remaining', [$vacationRemaining, $vacationTotal])
				: $this->l10n->t('%1$s / %2$s days remaining', [$vacationRemaining, $vacationTotal]);
			$items[] = new WidgetItem(
				$vacationTitle,
				$vacationSubtitle,
				$url, $icon, $ts . '-vacation'
			);
		}

		// ── Item 6: vacation pool split (annual + carryover) ───────────────────
		if (!$vacationYearError) {
			$carryover = number_format((float)$data['vacationCarryoverUsable'], 1);
			$annualPool = max(0.0, (float)$data['vacationRemaining'] - (float)$data['vacationCarryoverUsable']);
			$annual = number_format($annualPool, 1);
			$poolSubtitle = $vacationUnit === 'hours'
				? $this->l10n->t('Annual: %1$s h · Carryover: %2$s h', [$annual, $carryover])
				: $this->l10n->t('Annual: %1$s d · Carryover: %2$s d', [$annual, $carryover]);
			$items[] = new WidgetItem(
				$this->l10n->t('Vacation pool'),
				$poolSubtitle,
				$url, $icon, $ts . '-vacation-pool'
			);
		}

		// ── Item 7 (conditional): break compliance warning ──────────────────────
		if ((bool)$data['breakRequired'] && (int)$data['remainingBreakMinutes'] > 0) {
			$remaining = (int)$data['remainingBreakMinutes'];
			$items[] = new WidgetItem(
				$this->l10n->t('Break required (%s)', [(string)($data['lawLabelBreaks'] ?? 'ArbZG §4')]),
				$this->l10n->t('%1$s min still needed', [$remaining]),
				$url, $icon, $ts . '-break'
			);
		}

		return new WidgetItems($items, '');
	}

	public function getWidgetButtons(string $userId): array {
		$data = $this->getEmployeeDataSafe($userId);
		$status = (string)$data['status'];
		$timeCapture = is_array($data['timeCapture'] ?? null) ? $data['timeCapture'] : [];
		$clockStampingEnabled = (bool)($timeCapture['clockStampingEnabled'] ?? true);

		return [
			new WidgetButton(
				WidgetButton::TYPE_NEW,
				$this->dashboardQuickActionsUrl(),
				$this->primaryActionLabel($status, $clockStampingEnabled)
			),
			new WidgetButton(
				WidgetButton::TYPE_MORE,
				$this->urlGenerator->getAbsoluteURL($this->urlGenerator->linkToRoute('arbeitszeitcheck.page.timeEntries')),
				$this->l10n->t('Open time entries')
			),
		];
	}

	public function getReloadInterval(): int {
		return 30;
	}

	private function statusLabel(string $status): string {
		return match ($status) {
			'active' => $this->l10n->t('Working'),
			'break' => $this->l10n->t('On Break'),
			'paused' => $this->l10n->t('Paused'),
			default => $this->l10n->t('Clocked Out'),
		};
	}

	private function formatDuration(int $seconds): string {
		$seconds = max(0, $seconds);
		$hours = intdiv($seconds, 3600);
		$minutes = intdiv($seconds % 3600, 60);
		return sprintf('%02d:%02d', $hours, $minutes);
	}

	private function dashboardQuickActionsUrl(): string {
		return $this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->linkToRoute('arbeitszeitcheck.page.dashboard') . '#dashboard-status-heading'
		);
	}

	private function primaryActionLabel(string $status, bool $clockStampingEnabled): string {
		if (!$clockStampingEnabled) {
			return match ($status) {
				'paused' => $this->l10n->t('Open dashboard to finish session'),
				default => $this->l10n->t('Open time tracking'),
			};
		}

		return match ($status) {
			'active' => $this->l10n->t('Start Break'),
			'break' => $this->l10n->t('End Break'),
			'paused' => $this->l10n->t('Resume after break'),
			default => $this->l10n->t('Clock In'),
		};
	}

	/**
	 * @return array<string, mixed>
	 */
	private function getEmployeeDataSafe(string $userId): array {
		if ($this->cachedWidgetUserId === $userId && $this->cachedWidgetData !== null) {
			return $this->cachedWidgetData;
		}
		try {
			$this->cachedWidgetData = $this->widgetDataService->getEmployeeWidgetData($userId, true);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Employee dashboard widget: failed to load data', [
				'exception' => $e,
			]);
			$this->cachedWidgetData = $this->fallbackEmployeeWidgetData();
		}
		$this->cachedWidgetUserId = $userId;
		return $this->cachedWidgetData;
	}

	/**
	 * Safe defaults so the Nextcloud dashboard never receives an empty/ broken widget.
	 *
	 * @return array<string, mixed>
	 */
	private function fallbackEmployeeWidgetData(): array {
		$y = (int)(new \DateTimeImmutable('now', $this->timeClientBootstrap->storageTimeZone()))->format('Y');
		return [
			'userId' => '',
			'status' => 'clocked_out',
			'workingTodayHours' => 0.0,
			'currentSessionDuration' => 0,
			'sessionStartFormatted' => '',
			'breakStartFormatted' => '',
			'weekHoursWorked' => 0.0,
			'weekHoursRequired' => 0.0,
			'weeklyContractHours' => 40.0,
			'cumulativeBalance' => 0.0,
			'breakRequired' => false,
			'remainingBreakMinutes' => 0,
			'breakWarningLevel' => 'none',
			'vacationYear' => $y,
			'vacationYearLabel' => (string)$y,
			'vacationYearError' => null,
			'vacationRemaining' => 0.0,
			'vacationEntitlement' => 0.0,
			'vacationUsed' => 0.0,
			'vacationUnit' => 'days',
			'vacationCarryover' => 0.0,
			'vacationCarryoverUsable' => 0.0,
			'timeCapture' => [
				'clockStampingEnabled' => true,
				'manualTimeEntryEnabled' => true,
			],
			'atDailyMaximum' => false,
			'projectCheck' => [
				'available' => false,
				'linkingEnabled' => false,
				'projects' => [],
			],
		];
	}
}
