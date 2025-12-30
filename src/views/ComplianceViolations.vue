<template>
	<div class="timetracking-compliance-violations">
		<div class="timetracking-dashboard__header">
			<h2 class="timetracking-dashboard__title">{{ $t('arbeitszeitcheck', 'Compliance Violations') }}</h2>
			<p class="timetracking-dashboard__subtitle">{{ $t('arbeitszeitcheck', 'View and manage labor law compliance violations') }}</p>
		</div>

		<div class="timetracking-dashboard__content">
			<!-- Filters -->
			<div class="timetracking-filters">
				<div class="timetracking-filters__left">
					<NcTextField
						v-model="filters.startDate"
						type="text"
						placeholder="dd.mm.yyyy"
						pattern="\d{2}\.\d{2}\.\d{4}"
						:label="$t('arbeitszeitcheck', 'Start Date')"
						@blur="validateGermanDate('startDate')"
					/>
					<NcTextField
						v-model="filters.endDate"
						type="text"
						placeholder="dd.mm.yyyy"
						pattern="\d{2}\.\d{2}\.\d{4}"
						:label="$t('arbeitszeitcheck', 'End Date')"
						@blur="validateGermanDate('endDate')"
					/>
					<div class="timetracking-filter-select">
						<label class="timetracking-filter-select__label">{{ $t('arbeitszeitcheck', 'Violation Type') }}</label>
						<select v-model="filters.violationType" class="timetracking-filter-select__select">
							<option :value="null">{{ $t('arbeitszeitcheck', 'All Types') }}</option>
							<option value="insufficient_rest_period">{{ $t('arbeitszeitcheck', 'Insufficient Rest Period') }}</option>
							<option value="daily_hours_limit_exceeded">{{ $t('arbeitszeitcheck', 'Daily Hours Limit Exceeded') }}</option>
							<option value="weekly_hours_limit_exceeded">{{ $t('arbeitszeitcheck', 'Weekly Hours Limit Exceeded') }}</option>
							<option value="missing_break">{{ $t('arbeitszeitcheck', 'Missing Break') }}</option>
							<option value="excessive_working_hours">{{ $t('arbeitszeitcheck', 'Excessive Working Hours') }}</option>
							<option value="night_work">{{ $t('arbeitszeitcheck', 'Night Work') }}</option>
							<option value="sunday_work">{{ $t('arbeitszeitcheck', 'Sunday Work') }}</option>
							<option value="holiday_work">{{ $t('arbeitszeitcheck', 'Holiday Work') }}</option>
						</select>
					</div>
					<div class="timetracking-filter-select">
						<label class="timetracking-filter-select__label">{{ $t('arbeitszeitcheck', 'Severity') }}</label>
						<select v-model="filters.severity" class="timetracking-filter-select__select">
							<option :value="null">{{ $t('arbeitszeitcheck', 'All Severities') }}</option>
							<option value="error">{{ $t('arbeitszeitcheck', 'Critical') }}</option>
							<option value="warning">{{ $t('arbeitszeitcheck', 'Warning') }}</option>
							<option value="info">{{ $t('arbeitszeitcheck', 'Info') }}</option>
						</select>
					</div>
					<div class="timetracking-filter-select">
						<label class="timetracking-filter-select__label">{{ $t('arbeitszeitcheck', 'Status') }}</label>
						<select v-model="filters.resolved" class="timetracking-filter-select__select">
							<option :value="null">{{ $t('arbeitszeitcheck', 'All Statuses') }}</option>
							<option :value="false">{{ $t('arbeitszeitcheck', 'Active') }}</option>
							<option :value="true">{{ $t('arbeitszeitcheck', 'Resolved') }}</option>
						</select>
					</div>
				</div>
				<div class="timetracking-filters__actions">
					<NcButton
						type="secondary"
						:aria-label="$t('arbeitszeitcheck', 'Reset filters')"
						@click="resetFilters"
					>
						{{ $t('arbeitszeitcheck', 'Reset') }}
					</NcButton>
					<NcButton
						type="primary"
						:aria-label="$t('arbeitszeitcheck', 'Apply filters')"
						@click="applyFilters"
					>
						{{ $t('arbeitszeitcheck', 'Filter') }}
					</NcButton>
				</div>
			</div>

			<!-- Loading State -->
			<NcLoadingIcon v-if="isLoading" :size="64" />

			<!-- Violations Table -->
			<NcEmptyContent
				v-else-if="violations.length === 0"
				:title="$t('arbeitszeitcheck', 'No violations found')"
				:description="$t('arbeitszeitcheck', 'No compliance violations match the selected filters')"
			>
				<template #icon>
					<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
						<circle cx="12" cy="12" r="10"/>
						<path d="M9 12l2 2 4-4"/>
					</svg>
				</template>
			</NcEmptyContent>

			<table v-else class="timetracking-table">
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
					<tr v-for="violation in violations" :key="violation.id">
						<td>{{ formatDate(violation.date) }}</td>
						<td>{{ formatViolationType(violation.violationType) }}</td>
						<td>
							<span :class="getSeverityClass(violation.severity)">
								{{ formatSeverity(violation.severity) }}
							</span>
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
							<span v-else class="timetracking-text-muted">
								{{ formatDate(violation.resolvedAt) }}
							</span>
						</td>
					</tr>
				</tbody>
			</table>

			<!-- Pagination -->
			<div v-if="totalPages > 1" class="timetracking-pagination">
				<NcButton
					type="secondary"
					:disabled="currentPage <= 1"
					:aria-label="$t('arbeitszeitcheck', 'Previous page')"
					@click="goToPage(currentPage - 1)"
				>
					{{ $t('arbeitszeitcheck', 'Previous') }}
				</NcButton>
				<span class="timetracking-pagination__info">
					{{ $t('arbeitszeitcheck', 'Page {current} of {total}', { current: currentPage, total: totalPages }) }}
				</span>
				<NcButton
					type="secondary"
					:disabled="currentPage >= totalPages"
					:aria-label="$t('arbeitszeitcheck', 'Next page')"
					@click="goToPage(currentPage + 1)"
				>
					{{ $t('arbeitszeitcheck', 'Next') }}
				</NcButton>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcTextField, NcLoadingIcon, NcEmptyContent } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { formatDateGerman, parseGermanDate } from '../utils/dateUtils.js'

export default {
	name: 'ComplianceViolations',
	components: {
		NcButton,
		NcTextField,
		NcLoadingIcon,
		NcEmptyContent
	},
	data() {
		return {
			isLoading: false,
			violations: [],
			currentPage: 1,
			totalPages: 1,
			perPage: 25,
			isResolving: null,
			filters: {
				startDate: '',
				endDate: '',
				violationType: null,
				severity: null,
				resolved: null
			},
		}
	},
	mounted() {
		this.loadViolations()
	},
	methods: {
		validateGermanDate(field) {
			if (this.filters[field] && !parseGermanDate(this.filters[field])) {
				this.filters[field] = ''
			}
		},
		async loadViolations() {
			this.isLoading = true
			try {
				const offset = (this.currentPage - 1) * this.perPage
				const params = {
					limit: this.perPage,
					offset: offset
				}

				if (this.filters.startDate) {
					const startDateIso = parseGermanDate(this.filters.startDate)
					if (startDateIso) {
						params.startDate = startDateIso
					}
				}
				if (this.filters.endDate) {
					const endDateIso = parseGermanDate(this.filters.endDate)
					if (endDateIso) {
						params.endDate = endDateIso
					}
				}
				if (this.filters.violationType) {
					params.violationType = this.filters.violationType
				}
				if (this.filters.severity) {
					params.severity = this.filters.severity
				}
				if (this.filters.resolved !== null) {
					params.resolved = this.filters.resolved === 'true' || this.filters.resolved === true
				}

				const response = await axios.get(generateUrl('/apps/arbeitszeitcheck/api/compliance/violations'), { params })
				if (response.data.success) {
					this.violations = response.data.violations || []
					this.totalPages = Math.ceil((response.data.total || 0) / this.perPage)
				} else {
					throw new Error(response.data.error || this.$t('arbeitszeitcheck', 'Failed to load violations'))
				}
			} catch (error) {
				console.error('Failed to load violations:', error)
				this.showNotification(this.$t('arbeitszeitcheck', 'Failed to load violations'), 'error')
			} finally {
				this.isLoading = false
			}
		},
		async resolveViolation(violationId) {
			this.isResolving = violationId
			try {
				const response = await axios.post(generateUrl(`/apps/arbeitszeitcheck/api/compliance/violations/${violationId}/resolve`))
				if (response.data.success) {
					this.showNotification(this.$t('arbeitszeitcheck', 'Violation resolved successfully'), 'success')
					await this.loadViolations()
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
		applyFilters() {
			this.currentPage = 1
			this.loadViolations()
		},
		resetFilters() {
			this.filters = {
				startDate: '',
				endDate: '',
				violationType: null,
				severity: null,
				resolved: null
			}
			this.currentPage = 1
			this.loadViolations()
		},
		goToPage(page) {
			if (page >= 1 && page <= this.totalPages) {
				this.currentPage = page
				this.loadViolations()
			}
		},
		formatDate(dateString) {
			return formatDateGerman(dateString)
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
.timetracking-compliance-violations {
	padding: var(--default-grid-baseline);
}

.timetracking-filters {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 2);
	margin-bottom: calc(var(--default-grid-baseline) * 2);
	align-items: flex-end;
}

.timetracking-filters__left {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 2);
	flex: 1;
}

.timetracking-filters__actions {
	display: flex;
	gap: var(--default-grid-baseline);
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

.timetracking-text-muted {
	color: var(--color-text-maxcontrast);
	font-size: 14px;
}

.timetracking-pagination {
	display: flex;
	justify-content: center;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
	margin-top: calc(var(--default-grid-baseline) * 4);
	padding: calc(var(--default-grid-baseline) * 2);
}

.timetracking-pagination__info {
	color: var(--color-text-maxcontrast);
}

.timetracking-filter-select {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 0.5);
}

.timetracking-filter-select__label {
	font-size: 14px;
	color: var(--color-main-text);
	font-weight: 500;
}

.timetracking-filter-select__select {
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font-size: 14px;
	min-width: 180px;
}

.timetracking-filter-select__select:focus {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
}

@media (max-width: 768px) {
	.timetracking-filters {
		flex-direction: column;
	}

	.timetracking-filters__left {
		flex-direction: column;
		width: 100%;
	}

	.timetracking-filter-select__select {
		width: 100%;
		min-width: auto;
	}
}
</style>
