<template>
	<div class="timetracking-compliance-dashboard">
		<div class="timetracking-dashboard__header">
			<h2 class="timetracking-dashboard__title">{{ $t('arbeitszeitcheck', 'Compliance Dashboard') }}</h2>
			<p class="timetracking-dashboard__subtitle">{{ $t('arbeitszeitcheck', 'Monitor labor law compliance and violations') }}</p>
		</div>

		<div class="timetracking-dashboard__content">
			<!-- Loading State -->
			<div v-if="isLoading" class="timetracking-loading-container">
				<NcLoadingIcon :size="64" />
				<p>{{ $t('arbeitszeitcheck', 'Loading compliance status...') }}</p>
			</div>

			<!-- Compliance Status Section -->
			<div v-else class="timetracking-compliance-status">
				<div class="timetracking-compliance-status__cards">
					<div class="timetracking-compliance-card" :class="{'timetracking-compliance-card--compliant': complianceStatus.compliant, 'timetracking-compliance-card--non-compliant': !complianceStatus.compliant}">
						<div class="timetracking-compliance-card__icon" :aria-label="$t('arbeitszeitcheck', 'Compliance status')">
							<span v-if="complianceStatus.compliant">✓</span>
							<span v-else>⚠</span>
						</div>
						<div class="timetracking-compliance-card__content">
							<div class="timetracking-compliance-card__value">
								{{ complianceStatus.compliant ? $t('arbeitszeitcheck', 'Compliant') : $t('arbeitszeitcheck', 'Non-Compliant') }}
							</div>
							<div class="timetracking-compliance-card__label">{{ $t('arbeitszeitcheck', 'Compliance Status') }}</div>
						</div>
					</div>

					<div class="timetracking-compliance-card">
						<div class="timetracking-compliance-card__icon" aria-label="Total violations">
							{{ complianceStatus.violation_count }}
						</div>
						<div class="timetracking-compliance-card__content">
							<div class="timetracking-compliance-card__value">{{ complianceStatus.violation_count }}</div>
							<div class="timetracking-compliance-card__label">{{ $t('arbeitszeitcheck', 'Total Violations') }}</div>
						</div>
					</div>

					<div class="timetracking-compliance-card timetracking-compliance-card--error">
						<div class="timetracking-compliance-card__icon" aria-label="Critical violations">
							{{ complianceStatus.critical_violations }}
						</div>
						<div class="timetracking-compliance-card__content">
							<div class="timetracking-compliance-card__value">{{ complianceStatus.critical_violations }}</div>
							<div class="timetracking-compliance-card__label">{{ $t('arbeitszeitcheck', 'Critical Violations') }}</div>
						</div>
					</div>

					<div class="timetracking-compliance-card timetracking-compliance-card--warning">
						<div class="timetracking-compliance-card__icon" aria-label="Warning violations">
							{{ complianceStatus.warning_violations }}
						</div>
						<div class="timetracking-compliance-card__content">
							<div class="timetracking-compliance-card__value">{{ complianceStatus.warning_violations }}</div>
							<div class="timetracking-compliance-card__label">{{ $t('arbeitszeitcheck', 'Warnings') }}</div>
						</div>
					</div>

					<div class="timetracking-compliance-card timetracking-compliance-card--info">
						<div class="timetracking-compliance-card__icon" aria-label="Info violations">
							{{ complianceStatus.info_violations }}
						</div>
						<div class="timetracking-compliance-card__content">
							<div class="timetracking-compliance-card__value">{{ complianceStatus.info_violations }}</div>
							<div class="timetracking-compliance-card__label">{{ $t('arbeitszeitcheck', 'Info Violations') }}</div>
						</div>
					</div>
				</div>

				<!-- Recent Violations -->
				<div v-if="recentViolations.length > 0" class="timetracking-recent-violations">
					<h3 class="timetracking-section-title">{{ $t('arbeitszeitcheck', 'Recent Violations') }}</h3>
					<table class="timetracking-table">
						<thead>
							<tr>
								<th>{{ $t('arbeitszeitcheck', 'Date') }}</th>
								<th>{{ $t('arbeitszeitcheck', 'Type') }}</th>
								<th>{{ $t('arbeitszeitcheck', 'Severity') }}</th>
								<th>{{ $t('arbeitszeitcheck', 'Description') }}</th>
								<th>{{ $t('arbeitszeitcheck', 'Status') }}</th>
								<th>{{ $t('arbeitszeitcheck', 'Actions') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="violation in recentViolations" :key="violation.id">
								<td>{{ formatDate(violation.date) }}</td>
								<td>{{ formatViolationType(violation.violationType) }}</td>
								<td>
									<span :class="getSeverityClass(violation.severity)">{{ formatSeverity(violation.severity) }}</span>
								</td>
								<td>{{ violation.description }}</td>
								<td>
									<span v-if="violation.resolved" class="timetracking-badge timetracking-badge--success">
										{{ $t('arbeitszeitcheck', 'Resolved') }}
									</span>
									<span v-else class="timetracking-badge timetracking-badge--error">
										{{ $t('arbeitszeitcheck', 'Active') }}
									</span>
								</td>
								<td>
									<NcButton
										v-if="!violation.resolved"
										type="tertiary"
										:aria-label="$t('arbeitszeitcheck', 'Resolve violation')"
										@click="resolveViolation(violation.id)"
										:disabled="isResolving === violation.id"
									>
										{{ isResolving === violation.id ? $t('arbeitszeitcheck', 'Resolving...') : $t('arbeitszeitcheck', 'Resolve') }}
									</NcButton>
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<!-- Empty State -->
				<NcEmptyContent
					v-else-if="!isLoading && complianceStatus.compliant"
					:title="$t('arbeitszeitcheck', 'No violations found')"
					:description="$t('arbeitszeitcheck', 'You are fully compliant with all labor law requirements')"
				>
					<template #icon>
						<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
							<circle cx="12" cy="12" r="10"/>
							<path d="M9 12l2 2 4-4"/>
						</svg>
					</template>
				</NcEmptyContent>

				<!-- Action Buttons -->
				<div class="timetracking-compliance-actions">
					<NcButton
						type="primary"
						:aria-label="$t('arbeitszeitcheck', 'View all violations')"
						@click="navigateToViolations"
					>
						{{ $t('arbeitszeitcheck', 'View All Violations') }}
					</NcButton>
					<NcButton
						type="secondary"
						:aria-label="$t('arbeitszeitcheck', 'Generate compliance report')"
						@click="generateReport"
						:disabled="isGeneratingReport"
					>
						{{ isGeneratingReport ? $t('arbeitszeitcheck', 'Generating...') : $t('arbeitszeitcheck', 'Generate Report') }}
					</NcButton>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcEmptyContent } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { formatDateGerman } from '../utils/dateUtils.js'

export default {
	name: 'ComplianceDashboard',
	components: {
		NcButton,
		NcLoadingIcon,
		NcEmptyContent
	},
	data() {
		return {
			isLoading: true,
			complianceStatus: {
				compliant: true,
				violation_count: 0,
				critical_violations: 0,
				warning_violations: 0,
				info_violations: 0
			},
			recentViolations: [],
			isResolving: null,
			isGeneratingReport: false
		}
	},
	mounted() {
		this.loadComplianceStatus()
		this.loadRecentViolations()
	},
	methods: {
		async loadComplianceStatus() {
			this.isLoading = true
			try {
				const response = await axios.get(generateUrl('/apps/arbeitszeitcheck/api/compliance/status'))
				if (response.data.success) {
					this.complianceStatus = response.data.status
				} else {
					this.showNotification(this.$t('arbeitszeitcheck', 'Failed to load compliance status'), 'error')
				}
			} catch (error) {
				console.error('Failed to load compliance status:', error)
				this.showNotification(this.$t('arbeitszeitcheck', 'Failed to load compliance status'), 'error')
			} finally {
				this.isLoading = false
			}
		},
		async loadRecentViolations() {
			try {
				const response = await axios.get(generateUrl('/apps/arbeitszeitcheck/api/compliance/violations'), {
					params: {
						limit: 5,
						offset: 0,
						resolved: false
					}
				})
				if (response.data.success) {
					this.recentViolations = response.data.violations || []
				}
			} catch (error) {
				console.error('Failed to load recent violations:', error)
			}
		},
		async resolveViolation(violationId) {
			this.isResolving = violationId
			try {
				const response = await axios.post(generateUrl(`/apps/arbeitszeitcheck/api/compliance/violations/${violationId}/resolve`))
				if (response.data.success) {
					this.showNotification(this.$t('arbeitszeitcheck', 'Violation resolved successfully'), 'success')
					await this.loadComplianceStatus()
					await this.loadRecentViolations()
				} else {
					throw new Error(response.data.error || this.$t('arbeitszeitcheck', 'Failed to resolve violation'))
				}
			} catch (error) {
				this.showNotification(
					error.response?.data?.error || error.message || this.$t('arbeitszeitcheck', 'Failed to resolve violation'),
					'error'
				)
			} finally {
				this.isResolving = null
			}
		},
		async generateReport() {
			this.isGeneratingReport = true
			try {
				const endDate = new Date()
				const startDate = new Date()
				startDate.setDate(startDate.getDate() - 30) // Last 30 days

				const response = await axios.get(generateUrl('/apps/arbeitszeitcheck/export/compliance'), {
					params: {
						format: 'csv',
						startDate: startDate.toISOString().split('T')[0],
						endDate: endDate.toISOString().split('T')[0]
					},
					responseType: 'blob'
				})

				// Create download link
				const url = window.URL.createObjectURL(new Blob([response.data]))
				const link = document.createElement('a')
				link.href = url
				link.setAttribute('download', `compliance-report-${endDate.toISOString().split('T')[0]}.csv`)
				document.body.appendChild(link)
				link.click()
				link.remove()

				this.showNotification(this.$t('arbeitszeitcheck', 'Report generated successfully'), 'success')
			} catch (error) {
				console.error('Failed to generate report:', error)
				this.showNotification(this.$t('arbeitszeitcheck', 'Failed to generate report'), 'error')
			} finally {
				this.isGeneratingReport = false
			}
		},
		navigateToViolations() {
			window.location.href = generateUrl('/apps/arbeitszeitcheck/compliance/violations')
		},
		formatDate(dateString) {
			return formatDateGerman(dateString) || '-'
		},
		formatViolationType(type) {
			const types = {
				'insufficient_rest_period': this.$t('arbeitszeitcheck', 'Insufficient Rest Period'),
				'daily_hours_limit_exceeded': this.$t('arbeitszeitcheck', 'Daily Hours Limit Exceeded'),
				'weekly_hours_limit_exceeded': this.$t('arbeitszeitcheck', 'Weekly Hours Limit Exceeded'),
				'missing_break': this.$t('arbeitszeitcheck', 'Missing Break'),
				'excessive_working_hours': this.$t('arbeitszeitcheck', 'Excessive Working Hours'),
				'night_work': this.$t('arbeitszeitcheck', 'Night Work'),
				'sunday_work': this.$t('arbeitszeitcheck', 'Sunday Work'),
				'holiday_work': this.$t('arbeitszeitcheck', 'Holiday Work')
			}
			return types[type] || type
		},
		formatSeverity(severity) {
			const severities = {
				'error': this.$t('arbeitszeitcheck', 'Critical'),
				'warning': this.$t('arbeitszeitcheck', 'Warning'),
				'info': this.$t('arbeitszeitcheck', 'Info')
			}
			return severities[severity] || severity
		},
		getSeverityClass(severity) {
			return {
				'timetracking-badge': true,
				'timetracking-badge--error': severity === 'error',
				'timetracking-badge--warning': severity === 'warning',
				'timetracking-badge--info': severity === 'info'
			}
		},
		showNotification(message, type = 'info') {
			if (typeof OC !== 'undefined' && OC.Notification) {
				OC.Notification.showTemporary(message, {
					timeout: 5000,
					isHTML: false
				})
			} else {
				console.log(`${type}: ${message}`)
			}
		}
	}
}
</script>

<style scoped>
.timetracking-compliance-dashboard {
	padding: var(--default-grid-baseline);
}

.timetracking-loading-container {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: calc(var(--default-grid-baseline) * 4);
	gap: calc(var(--default-grid-baseline) * 2);
}

.timetracking-compliance-status__cards {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: calc(var(--default-grid-baseline) * 2);
	margin-bottom: calc(var(--default-grid-baseline) * 4);
}

.timetracking-compliance-card {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: calc(var(--default-grid-baseline) * 2);
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
	transition: box-shadow 0.2s ease;
}

.timetracking-compliance-card:hover {
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.timetracking-compliance-card__icon {
	font-size: 32px;
	font-weight: bold;
	width: 48px;
	height: 48px;
	display: flex;
	align-items: center;
	justify-content: center;
	border-radius: var(--border-radius);
	background: var(--color-background-dark);
}

.timetracking-compliance-card--compliant .timetracking-compliance-card__icon {
	background: var(--color-success);
	color: var(--color-main-background);
}

.timetracking-compliance-card--non-compliant .timetracking-compliance-card__icon {
	background: var(--color-error);
	color: var(--color-main-background);
}

.timetracking-compliance-card--error .timetracking-compliance-card__icon {
	background: var(--color-error);
	color: var(--color-main-background);
}

.timetracking-compliance-card--warning .timetracking-compliance-card__icon {
	background: var(--color-warning);
	color: var(--color-main-background);
}

.timetracking-compliance-card--info .timetracking-compliance-card__icon {
	background: var(--color-primary);
	color: var(--color-main-background);
}

.timetracking-compliance-card__content {
	flex: 1;
}

.timetracking-compliance-card__value {
	font-size: 24px;
	font-weight: bold;
	color: var(--color-main-text);
	margin-bottom: calc(var(--default-grid-baseline) * 0.5);
}

.timetracking-compliance-card__label {
	font-size: 14px;
	color: var(--color-text-maxcontrast);
}

.timetracking-recent-violations {
	margin-top: calc(var(--default-grid-baseline) * 4);
	margin-bottom: calc(var(--default-grid-baseline) * 4);
}

.timetracking-section-title {
	font-size: 18px;
	font-weight: bold;
	color: var(--color-main-text);
	margin-bottom: calc(var(--default-grid-baseline) * 2);
}

.timetracking-badge {
	display: inline-block;
	padding: 4px 8px;
	border-radius: var(--border-radius);
	font-size: 12px;
	font-weight: 500;
}

.timetracking-badge--success {
	background: var(--color-success-background);
	color: var(--color-success);
}

.timetracking-badge--error {
	background: var(--color-error-background);
	color: var(--color-error);
}

.timetracking-badge--warning {
	background: var(--color-warning-background);
	color: var(--color-warning);
}

.timetracking-badge--info {
	background: var(--color-primary-background);
	color: var(--color-primary);
}

.timetracking-compliance-actions {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 2);
	margin-top: calc(var(--default-grid-baseline) * 4);
}

@media (max-width: 768px) {
	.timetracking-compliance-status__cards {
		grid-template-columns: 1fr;
	}

	.timetracking-compliance-actions {
		flex-direction: column;
	}
}
</style>
