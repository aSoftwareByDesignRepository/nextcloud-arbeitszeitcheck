<template>
	<div class="timetracking-compliance-reports">
		<div class="timetracking-dashboard__header">
			<h2 class="timetracking-dashboard__title">{{ $t('arbeitszeitcheck', 'Compliance Reports') }}</h2>
			<p class="timetracking-dashboard__subtitle">{{ $t('arbeitszeitcheck', 'Generate and export compliance reports') }}</p>
		</div>

		<div class="timetracking-dashboard__content">
			<!-- Date Range Selection -->
			<div class="timetracking-report-filters">
				<h3 class="timetracking-section-title">{{ $t('arbeitszeitcheck', 'Report Period') }}</h3>
				<div class="timetracking-date-range">
					<NcTextField
						v-model="startDate"
						type="text"
						placeholder="dd.mm.yyyy"
						pattern="\d{2}\.\d{2}\.\d{4}"
						:label="$t('arbeitszeitcheck', 'Start Date')"
						@blur="validateGermanDate('startDate')"
					/>
					<NcTextField
						v-model="endDate"
						type="text"
						placeholder="dd.mm.yyyy"
						pattern="\d{2}\.\d{2}\.\d{4}"
						:label="$t('arbeitszeitcheck', 'End Date')"
						@blur="validateGermanDate('endDate')"
					/>
					<NcButton
						type="primary"
						:aria-label="$t('arbeitszeitcheck', 'Load report')"
						@click="loadReport"
						:disabled="isLoading || !startDate || !endDate"
					>
						{{ $t('arbeitszeitcheck', 'Load Report') }}
					</NcButton>
				</div>
			</div>

			<!-- Loading State -->
			<div v-if="isLoading" class="timetracking-loading-container">
				<NcLoadingIcon :size="64" />
				<p>{{ $t('arbeitszeitcheck', 'Generating compliance report...') }}</p>
			</div>

			<!-- Report Preview -->
			<div v-else-if="reportData" class="timetracking-report-preview">
				<h3 class="timetracking-section-title">{{ $t('arbeitszeitcheck', 'Report Summary') }}</h3>
				
				<div class="timetracking-report-summary-cards">
					<div class="timetracking-report-card">
						<div class="timetracking-report-card__value">{{ reportData.total_violations || 0 }}</div>
						<div class="timetracking-report-card__label">{{ $t('arbeitszeitcheck', 'Total Violations') }}</div>
					</div>
					<div class="timetracking-report-card timetracking-report-card--error">
						<div class="timetracking-report-card__value">{{ (reportData.violations_by_severity && reportData.violations_by_severity.error) || 0 }}</div>
						<div class="timetracking-report-card__label">{{ $t('arbeitszeitcheck', 'Critical') }}</div>
					</div>
					<div class="timetracking-report-card timetracking-report-card--warning">
						<div class="timetracking-report-card__value">{{ (reportData.violations_by_severity && reportData.violations_by_severity.warning) || 0 }}</div>
						<div class="timetracking-report-card__label">{{ $t('arbeitszeitcheck', 'Warnings') }}</div>
					</div>
					<div class="timetracking-report-card timetracking-report-card--info">
						<div class="timetracking-report-card__value">{{ (reportData.violations_by_severity && reportData.violations_by_severity.info) || 0 }}</div>
						<div class="timetracking-report-card__label">{{ $t('arbeitszeitcheck', 'Info') }}</div>
					</div>
				</div>

				<!-- Violation Types Breakdown -->
				<div v-if="reportData.violations_by_type && Object.keys(reportData.violations_by_type).length > 0" class="timetracking-violation-types">
					<h4 class="timetracking-subsection-title">{{ $t('arbeitszeitcheck', 'Violations by Type') }}</h4>
					<table class="timetracking-table">
						<thead>
							<tr>
								<th>{{ $t('arbeitszeitcheck', 'Type') }}</th>
								<th>{{ $t('arbeitszeitcheck', 'Count') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="(count, type) in reportData.violations_by_type" :key="type">
								<td>{{ formatViolationType(type) }}</td>
								<td>{{ count }}</td>
							</tr>
						</tbody>
					</table>
				</div>

				<!-- Export Options -->
				<div class="timetracking-export-options">
					<h4 class="timetracking-subsection-title">{{ $t('arbeitszeitcheck', 'Export Report') }}</h4>
					<p class="timetracking-export-description">
						{{ $t('arbeitszeitcheck', 'Download the compliance report in your preferred format') }}
					</p>
					<div class="timetracking-export-buttons">
						<NcButton
							type="primary"
							:aria-label="$t('arbeitszeitcheck', 'Export as CSV')"
							@click="exportReport('csv')"
							:disabled="isExporting"
						>
							{{ isExporting === 'csv' ? $t('arbeitszeitcheck', 'Exporting...') : $t('arbeitszeitcheck', 'Export CSV') }}
						</NcButton>
						<NcButton
							type="primary"
							:aria-label="$t('arbeitszeitcheck', 'Export as JSON')"
							@click="exportReport('json')"
							:disabled="isExporting"
						>
							{{ isExporting === 'json' ? $t('arbeitszeitcheck', 'Exporting...') : $t('arbeitszeitcheck', 'Export JSON') }}
						</NcButton>
						<NcButton
							type="primary"
							:aria-label="$t('arbeitszeitcheck', 'Export as PDF')"
							@click="exportReport('pdf')"
							:disabled="isExporting"
						>
							{{ isExporting === 'pdf' ? $t('arbeitszeitcheck', 'Exporting...') : $t('arbeitszeitcheck', 'Export PDF') }}
						</NcButton>
					</div>
				</div>
			</div>

			<!-- Empty State -->
			<NcEmptyContent
				v-else-if="!isLoading"
				:title="$t('arbeitszeitcheck', 'No report loaded')"
				:description="$t('arbeitszeitcheck', 'Select a date range and click Load Report to generate a compliance report')"
			>
				<template #icon>
					<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
						<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
						<polyline points="14 2 14 8 20 8"/>
						<line x1="16" y1="13" x2="8" y2="13"/>
						<line x1="16" y1="17" x2="8" y2="17"/>
						<polyline points="10 9 9 9 8 9"/>
					</svg>
				</template>
			</NcEmptyContent>
		</div>
	</div>
</template>

<script>
import { NcButton, NcTextField, NcLoadingIcon, NcEmptyContent } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { formatDateGerman, parseGermanDate } from '../utils/dateUtils.js'

export default {
	name: 'ComplianceReports',
	components: {
		NcButton,
		NcTextField,
		NcLoadingIcon,
		NcEmptyContent
	},
	data() {
		// Set default date range to last 30 days
		const endDate = new Date()
		const startDate = new Date()
		startDate.setDate(startDate.getDate() - 30)

		return {
			startDate: formatDateGerman(startDate),
			endDate: formatDateGerman(endDate),
			isLoading: false,
			isExporting: null,
			reportData: null
		}
	},
	mounted() {
		// Optionally load report automatically on mount
		// this.loadReport()
	},
	methods: {
		validateGermanDate(field) {
			if (this[field] && !parseGermanDate(this[field])) {
				this[field] = ''
			}
		},
		async loadReport() {
			if (!this.startDate || !this.endDate) {
				this.showNotification(this.$t('arbeitszeitcheck', 'Please select both start and end dates'), 'warning')
				return
			}

			// Convert German dates to ISO format for API
			const startDateIso = parseGermanDate(this.startDate)
			const endDateIso = parseGermanDate(this.endDate)

			if (!startDateIso || !endDateIso) {
				this.showNotification(this.$t('arbeitszeitcheck', 'Please enter valid dates in dd.mm.yyyy format'), 'warning')
				return
			}

			this.isLoading = true
			try {
				const response = await axios.get(generateUrl('/apps/arbeitszeitcheck/api/compliance/report'), {
					params: {
						startDate: startDateIso,
						endDate: endDateIso
					}
				})
				if (response.data.success) {
					this.reportData = response.data.report
				} else {
					throw new Error(response.data.error || this.$t('arbeitszeitcheck', 'Failed to load report'))
				}
			} catch (error) {
				console.error('Failed to load compliance report:', error)
				this.showNotification(
					error.response?.data?.error || error.message || this.$t('arbeitszeitcheck', 'Failed to load compliance report'),
					'error'
				)
			} finally {
				this.isLoading = false
			}
		},
		exportReport(format) {
			if (!this.startDate || !this.endDate) {
				this.showNotification(this.$t('arbeitszeitcheck', 'Please select both start and end dates'), 'warning')
				return
			}

			this.isExporting = format
			try {
				const exportUrl = generateUrl('/apps/arbeitszeitcheck/export/compliance', {
					format: format,
					startDate: this.startDate,
					endDate: this.endDate
				})
				window.location.href = exportUrl
				this.showNotification(this.$t('arbeitszeitcheck', 'Report export started'), 'success')
			} catch (error) {
				console.error('Failed to export report:', error)
				this.showNotification(this.$t('arbeitszeitcheck', 'Failed to export report'), 'error')
			} finally {
				// Reset export state after a short delay to allow download to start
				setTimeout(() => {
					this.isExporting = null
				}, 1000)
			}
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
.timetracking-compliance-reports {
	padding: var(--default-grid-baseline);
}

.timetracking-report-filters {
	margin-bottom: calc(var(--default-grid-baseline) * 4);
	padding: calc(var(--default-grid-baseline) * 2);
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.timetracking-date-range {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 2);
	align-items: flex-end;
	flex-wrap: wrap;
}

.timetracking-loading-container {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: calc(var(--default-grid-baseline) * 4);
	gap: calc(var(--default-grid-baseline) * 2);
}

.timetracking-report-preview {
	padding: calc(var(--default-grid-baseline) * 2);
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.timetracking-section-title {
	font-size: 18px;
	font-weight: bold;
	color: var(--color-main-text);
	margin-bottom: calc(var(--default-grid-baseline) * 2);
	margin-top: 0;
}

.timetracking-subsection-title {
	font-size: 16px;
	font-weight: 600;
	color: var(--color-main-text);
	margin-bottom: calc(var(--default-grid-baseline) * 2);
	margin-top: calc(var(--default-grid-baseline) * 3);
}

.timetracking-report-summary-cards {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
	gap: calc(var(--default-grid-baseline) * 2);
	margin-bottom: calc(var(--default-grid-baseline) * 4);
}

.timetracking-report-card {
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: calc(var(--default-grid-baseline) * 2);
	text-align: center;
	transition: box-shadow 0.2s ease;
}

.timetracking-report-card:hover {
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.timetracking-report-card--error {
	background: var(--color-error-background);
	border-color: var(--color-error);
}

.timetracking-report-card--warning {
	background: var(--color-warning-background);
	border-color: var(--color-warning);
}

.timetracking-report-card--info {
	background: var(--color-primary-background);
	border-color: var(--color-primary);
}

.timetracking-report-card--success {
	background: var(--color-success-background);
	border-color: var(--color-success);
}

.timetracking-report-card__value {
	font-size: 32px;
	font-weight: bold;
	color: var(--color-main-text);
	margin-bottom: calc(var(--default-grid-baseline) * 0.5);
}

.timetracking-report-card__label {
	font-size: 14px;
	color: var(--color-text-maxcontrast);
}

.timetracking-violation-types {
	margin-top: calc(var(--default-grid-baseline) * 4);
	margin-bottom: calc(var(--default-grid-baseline) * 4);
}

.timetracking-export-options {
	margin-top: calc(var(--default-grid-baseline) * 4);
	padding-top: calc(var(--default-grid-baseline) * 3);
	border-top: 1px solid var(--color-border);
}

.timetracking-export-description {
	color: var(--color-text-maxcontrast);
	margin-bottom: calc(var(--default-grid-baseline) * 2);
}

.timetracking-export-buttons {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 2);
	flex-wrap: wrap;
}

@media (max-width: 768px) {
	.timetracking-date-range {
		flex-direction: column;
		align-items: stretch;
	}

	.timetracking-report-summary-cards {
		grid-template-columns: 1fr;
	}

	.timetracking-export-buttons {
		flex-direction: column;
	}
}
</style>
