<template>
	<div class="timetracking-admin-dashboard">
		<div class="timetracking-dashboard__header">
			<h2 class="timetracking-dashboard__title">{{ $t('arbeitszeitcheck', 'Administration Dashboard') }}</h2>
			<p class="timetracking-dashboard__subtitle">{{ $t('arbeitszeitcheck', 'System-wide time tracking administration and monitoring') }}</p>
		</div>

		<div class="timetracking-dashboard__content">
			<!-- Statistics Cards -->
			<div class="timetracking-overview-cards">
				<div class="timetracking-overview-card">
					<div class="timetracking-overview-card__icon" aria-hidden="true">👥</div>
					<div class="timetracking-overview-card__content">
						<div class="timetracking-overview-card__value">{{ statistics.total_users || 0 }}</div>
						<div class="timetracking-overview-card__label">{{ $t('arbeitszeitcheck', 'Total Users') }}</div>
					</div>
				</div>

				<div class="timetracking-overview-card">
					<div class="timetracking-overview-card__icon" aria-hidden="true">✓</div>
					<div class="timetracking-overview-card__content">
						<div class="timetracking-overview-card__value">{{ statistics.active_users_today || 0 }}</div>
						<div class="timetracking-overview-card__label">{{ $t('arbeitszeitcheck', 'Active Today') }}</div>
					</div>
				</div>

				<div class="timetracking-overview-card" :class="getComplianceCardClass()">
					<div class="timetracking-overview-card__icon" aria-hidden="true">📊</div>
					<div class="timetracking-overview-card__content">
						<div class="timetracking-overview-card__value">{{ statistics.compliance_percentage || 100 }}%</div>
						<div class="timetracking-overview-card__label">{{ $t('arbeitszeitcheck', 'System Compliance') }}</div>
					</div>
				</div>

				<div class="timetracking-overview-card" :class="getViolationsCardClass()">
					<div class="timetracking-overview-card__icon" aria-hidden="true">⚠️</div>
					<div class="timetracking-overview-card__content">
						<div class="timetracking-overview-card__value">{{ statistics.unresolved_violations || 0 }}</div>
						<div class="timetracking-overview-card__label">{{ $t('arbeitszeitcheck', 'Unresolved Violations') }}</div>
					</div>
				</div>
			</div>

			<!-- Quick Actions -->
			<div class="timetracking-section">
				<div class="timetracking-section__header">
					<h3 class="timetracking-section-title">{{ $t('arbeitszeitcheck', 'Quick Actions') }}</h3>
				</div>
				<div class="timetracking-admin-actions">
					<NcButton
						type="primary"
						@click="navigateToUsers"
						:aria-label="$t('arbeitszeitcheck', 'Manage users and employee profiles')">
						<template #icon>
							<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
								<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
								<circle cx="9" cy="7" r="4"/>
								<path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
								<path d="M16 3.13a4 4 0 0 1 0 7.75"/>
							</svg>
						</template>
						{{ $t('arbeitszeitcheck', 'Manage Users') }}
					</NcButton>
					<NcButton
						type="secondary"
						@click="navigateToSettings"
						:aria-label="$t('arbeitszeitcheck', 'Configure system settings')">
						<template #icon>
							<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
								<circle cx="12" cy="12" r="3"/>
								<path d="M12 1v6m0 6v6M5.64 5.64l4.24 4.24m4.24 4.24l4.24 4.24M1 12h6m6 0h6M5.64 18.36l4.24-4.24m4.24-4.24l4.24-4.24"/>
							</svg>
						</template>
						{{ $t('arbeitszeitcheck', 'System Settings') }}
					</NcButton>
					<NcButton
						type="secondary"
						@click="navigateToCompliance"
						:aria-label="$t('arbeitszeitcheck', 'View compliance dashboard')">
						<template #icon>
							<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
								<path d="M9 11l3 3L22 4"/>
								<path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>
							</svg>
						</template>
						{{ $t('arbeitszeitcheck', 'Compliance Dashboard') }}
					</NcButton>
					<NcButton
						type="secondary"
						@click="navigateToAuditLog"
						:aria-label="$t('arbeitszeitcheck', 'View audit log')">
						<template #icon>
							<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
								<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
								<polyline points="14 2 14 8 20 8"/>
								<line x1="16" y1="13" x2="8" y2="13"/>
								<line x1="16" y1="17" x2="8" y2="17"/>
								<polyline points="10 9 9 9 8 9"/>
							</svg>
						</template>
						{{ $t('arbeitszeitcheck', 'Audit Log') }}
					</NcButton>
				</div>
			</div>

			<!-- System Information -->
			<div class="timetracking-section">
				<div class="timetracking-section__header">
					<h3 class="timetracking-section-title">{{ $t('arbeitszeitcheck', 'System Information') }}</h3>
				</div>
				<div class="timetracking-info-grid">
					<div class="timetracking-info-item">
						<div class="timetracking-info-item__label">{{ $t('arbeitszeitcheck', 'Compliant Users') }}</div>
						<div class="timetracking-info-item__value">{{ statistics.compliant_users || 0 }} / {{ statistics.total_users || 0 }}</div>
					</div>
					<div class="timetracking-info-item">
						<div class="timetracking-info-item__label">{{ $t('arbeitszeitcheck', 'Users with Violations') }}</div>
						<div class="timetracking-info-item__value">{{ (statistics.total_users || 0) - (statistics.compliant_users || 0) }}</div>
					</div>
				</div>
			</div>

			<NcLoadingIcon v-if="isLoading" :size="32" />
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'AdminDashboard',
	components: {
		NcButton,
		NcLoadingIcon
	},
	data() {
		return {
			isLoading: true,
			statistics: {
				total_users: 0,
				active_users_today: 0,
				unresolved_violations: 0,
				compliance_percentage: 100.0,
				compliant_users: 0
			}
		}
	},
	mounted() {
		this.loadStatistics()
	},
	methods: {
		async loadStatistics() {
			this.isLoading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/arbeitszeitcheck/api/admin/statistics')
				)
				if (response.data.success) {
					this.statistics = response.data.statistics
				} else {
					this.showNotification(
						this.$t('arbeitszeitcheck', 'Failed to load statistics'),
						'error'
					)
				}
			} catch (error) {
				console.error('Error loading admin statistics:', error)
				this.showNotification(
					this.$t('arbeitszeitcheck', 'Error loading statistics. Please try again.'),
					'error'
				)
			} finally {
				this.isLoading = false
			}
		},
		getComplianceCardClass() {
			const percentage = this.statistics.compliance_percentage || 100
			if (percentage >= 95) {
				return 'timetracking-overview-card--success'
			} else if (percentage >= 80) {
				return 'timetracking-overview-card--warning'
			} else {
				return 'timetracking-overview-card--error'
			}
		},
		getViolationsCardClass() {
			const violations = this.statistics.unresolved_violations || 0
			if (violations === 0) {
				return 'timetracking-overview-card--success'
			} else if (violations <= 10) {
				return 'timetracking-overview-card--warning'
			} else {
				return 'timetracking-overview-card--error'
			}
		},
		navigateToUsers() {
			window.location.href = generateUrl('/apps/arbeitszeitcheck/admin/users')
		},
		navigateToSettings() {
			window.location.href = generateUrl('/apps/arbeitszeitcheck/admin/settings')
		},
		navigateToCompliance() {
			window.location.href = generateUrl('/apps/arbeitszeitcheck/compliance')
		},
		navigateToAuditLog() {
			window.location.href = generateUrl('/apps/arbeitszeitcheck/admin/audit-log')
		},
		showNotification(message, type = 'info') {
			if (typeof OC !== 'undefined' && OC.Notification) {
				OC.Notification.showTemporary(message, {
					timeout: 5000,
					isHTML: false
				})
			} else {
				// Fallback if OC.Notification is not available
				alert(message)
			}
		}
	}
}
</script>

<style scoped>
.timetracking-admin-dashboard {
	padding: calc(var(--default-grid-baseline) * 2);
	max-width: 1200px;
	margin: 0 auto;
}

.timetracking-dashboard__header {
	margin-bottom: calc(var(--default-grid-baseline) * 3);
}

.timetracking-dashboard__title {
	font-size: 24px;
	font-weight: bold;
	color: var(--color-main-text);
	margin: 0 0 calc(var(--default-grid-baseline) * 0.5) 0;
}

.timetracking-dashboard__subtitle {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.timetracking-dashboard__content {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.timetracking-overview-cards {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
	gap: calc(var(--default-grid-baseline) * 2);
}

.timetracking-overview-card {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: calc(var(--default-grid-baseline) * 2);
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.timetracking-overview-card__icon {
	font-size: 32px;
	line-height: 1;
}

.timetracking-overview-card__content {
	flex: 1;
}

.timetracking-overview-card__value {
	font-size: 28px;
	font-weight: bold;
	color: var(--color-main-text);
	margin-bottom: calc(var(--default-grid-baseline) * 0.5);
}

.timetracking-overview-card__label {
	font-size: 14px;
	color: var(--color-text-maxcontrast);
}

.timetracking-overview-card--success {
	border-color: var(--color-success);
}

.timetracking-overview-card--warning {
	border-color: var(--color-warning);
}

.timetracking-overview-card--error {
	border-color: var(--color-error);
}

.timetracking-section {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: calc(var(--default-grid-baseline) * 2);
}

.timetracking-section__header {
	margin-bottom: calc(var(--default-grid-baseline) * 2);
}

.timetracking-section-title {
	font-size: 18px;
	font-weight: 600;
	color: var(--color-main-text);
	margin: 0;
}

.timetracking-admin-actions {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 2);
}

.timetracking-info-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: calc(var(--default-grid-baseline) * 2);
}

.timetracking-info-item {
	padding: calc(var(--default-grid-baseline) * 1.5);
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
}

.timetracking-info-item__label {
	font-size: 14px;
	color: var(--color-text-maxcontrast);
	margin-bottom: calc(var(--default-grid-baseline) * 0.5);
}

.timetracking-info-item__value {
	font-size: 20px;
	font-weight: 600;
	color: var(--color-main-text);
}

@media (max-width: 768px) {
	.timetracking-overview-cards {
		grid-template-columns: 1fr;
	}

	.timetracking-admin-actions {
		flex-direction: column;
	}

	.timetracking-admin-actions .timetracking-btn {
		width: 100%;
	}
}
</style>
