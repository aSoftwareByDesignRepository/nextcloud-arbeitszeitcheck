<template>
	<div class="timetracking-audit-log">
		<div class="timetracking-dashboard__header">
			<h2 class="timetracking-dashboard__title">{{ $t('arbeitszeitcheck', 'Audit Log Viewer') }}</h2>
			<p class="timetracking-dashboard__subtitle">{{ $t('arbeitszeitcheck', 'View all system activity and data changes for compliance and security') }}</p>
		</div>

		<div class="timetracking-dashboard__content">
			<!-- Filters -->
			<div class="timetracking-section">
				<h3 class="timetracking-section-title">{{ $t('arbeitszeitcheck', 'Filters') }}</h3>
				<div class="timetracking-audit-log__filters">
					<div class="timetracking-form-group">
						<label for="start-date" class="timetracking-form-label">
							{{ $t('arbeitszeitcheck', 'Start Date') }}
						</label>
						<NcTextField
							id="start-date"
							v-model="filters.startDate"
							type="text"
							placeholder="dd.mm.yyyy"
							pattern="\d{2}\.\d{2}\.\d{4}"
							:label="$t('arbeitszeitcheck', 'Start Date')"
							@blur="validateGermanDate('startDate')" />
					</div>
					<div class="timetracking-form-group">
						<label for="end-date" class="timetracking-form-label">
							{{ $t('arbeitszeitcheck', 'End Date') }}
						</label>
						<NcTextField
							id="end-date"
							v-model="filters.endDate"
							type="text"
							placeholder="dd.mm.yyyy"
							pattern="\d{2}\.\d{2}\.\d{4}"
							:label="$t('arbeitszeitcheck', 'End Date')"
							@blur="validateGermanDate('endDate')" />
					</div>
					<div class="timetracking-form-group">
						<label for="user-filter" class="timetracking-form-label">
							{{ $t('arbeitszeitcheck', 'User') }}
						</label>
						<NcSelect
							id="user-filter"
							v-model="filters.userId"
							:options="userOptions"
							:clearable="true"
							:label="$t('arbeitszeitcheck', 'Filter by user')" />
					</div>
					<div class="timetracking-form-group">
						<label for="action-filter" class="timetracking-form-label">
							{{ $t('arbeitszeitcheck', 'Action') }}
						</label>
						<NcSelect
							id="action-filter"
							v-model="filters.action"
							:options="actionOptions"
							:clearable="true"
							:label="$t('arbeitszeitcheck', 'Filter by action')" />
					</div>
					<div class="timetracking-form-group">
						<label for="entity-type-filter" class="timetracking-form-label">
							{{ $t('arbeitszeitcheck', 'Entity Type') }}
						</label>
						<NcSelect
							id="entity-type-filter"
							v-model="filters.entityType"
							:options="entityTypeOptions"
							:clearable="true"
							:label="$t('arbeitszeitcheck', 'Filter by entity type')" />
					</div>
					<div class="timetracking-audit-log__filter-actions">
						<NcButton
							type="primary"
							@click="loadLogs"
							:disabled="isLoading">
							{{ $t('arbeitszeitcheck', 'Apply Filters') }}
						</NcButton>
						<NcButton
							type="secondary"
							@click="resetFilters">
							{{ $t('arbeitszeitcheck', 'Reset') }}
						</NcButton>
					</div>
				</div>
			</div>

			<!-- Statistics -->
			<div v-if="statistics" class="timetracking-section">
				<h3 class="timetracking-section-title">{{ $t('arbeitszeitcheck', 'Statistics') }}</h3>
				<div class="timetracking-audit-log__stats">
					<div class="timetracking-stat-item">
						<div class="timetracking-stat-item__label">{{ $t('arbeitszeitcheck', 'Total Logs') }}</div>
						<div class="timetracking-stat-item__value">{{ statistics.total_logs || 0 }}</div>
					</div>
					<div class="timetracking-stat-item">
						<div class="timetracking-stat-item__label">{{ $t('arbeitszeitcheck', 'Unique Users') }}</div>
						<div class="timetracking-stat-item__value">{{ statistics.unique_users || 0 }}</div>
					</div>
				</div>
			</div>

			<!-- Audit Logs Table -->
			<div class="timetracking-section">
				<div class="timetracking-section__header">
					<h3 class="timetracking-section-title">{{ $t('arbeitszeitcheck', 'Audit Logs') }}</h3>
					<div class="timetracking-section__actions">
						<NcButton
							type="secondary"
							@click="exportLogs"
							:disabled="isLoading || logs.length === 0">
							{{ $t('arbeitszeitcheck', 'Export') }}
						</NcButton>
					</div>
				</div>

				<NcLoadingIcon v-if="isLoading" :size="32" />
				
				<NcEmptyContent
					v-else-if="logs.length === 0"
					:title="$t('arbeitszeitcheck', 'No audit logs found')"
					:description="$t('arbeitszeitcheck', 'Try adjusting your filters or date range')">
					<template #icon>
						<span aria-hidden="true">📋</span>
					</template>
				</NcEmptyContent>

				<table v-else class="timetracking-table">
					<thead>
						<tr>
							<th>{{ $t('arbeitszeitcheck', 'Date & Time') }}</th>
							<th>{{ $t('arbeitszeitcheck', 'User') }}</th>
							<th>{{ $t('arbeitszeitcheck', 'Action') }}</th>
							<th>{{ $t('arbeitszeitcheck', 'Entity Type') }}</th>
							<th>{{ $t('arbeitszeitcheck', 'Entity ID') }}</th>
							<th>{{ $t('arbeitszeitcheck', 'Performed By') }}</th>
							<th>{{ $t('arbeitszeitcheck', 'Details') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="log in logs" :key="log.id">
							<td>{{ formatDateTime(log.created_at) }}</td>
							<td>{{ log.user_display_name }}</td>
							<td>
								<span :class="getActionClass(log.action)">
									{{ formatAction(log.action) }}
								</span>
							</td>
							<td>{{ formatEntityType(log.entity_type) }}</td>
							<td>{{ log.entity_id || '-' }}</td>
							<td>{{ log.performed_by_display_name }}</td>
							<td>
								<NcButton
									v-if="log.old_values || log.new_values"
									type="tertiary"
									@click="showLogDetails(log)"
									:aria-label="$t('arbeitszeitcheck', 'View details for log entry {id}', { id: log.id })">
									{{ $t('arbeitszeitcheck', 'View Details') }}
								</NcButton>
								<span v-else>-</span>
							</td>
						</tr>
					</tbody>
				</table>

				<!-- Pagination -->
				<div v-if="total > limit" class="timetracking-audit-log__pagination">
					<NcButton
						type="secondary"
						:disabled="offset === 0"
						@click="previousPage">
						{{ $t('arbeitszeitcheck', 'Previous') }}
					</NcButton>
					<span class="timetracking-audit-log__pagination-info">
						{{ $t('arbeitszeitcheck', 'Showing {start} to {end} of {total}', {
							start: offset + 1,
							end: Math.min(offset + limit, total),
							total: total
						}) }}
					</span>
					<NcButton
						type="secondary"
						:disabled="offset + limit >= total"
						@click="nextPage">
						{{ $t('arbeitszeitcheck', 'Next') }}
					</NcButton>
				</div>
			</div>
		</div>

		<!-- Log Details Modal -->
		<NcModal
			v-if="selectedLog"
			:name="$t('arbeitszeitcheck', 'Audit Log Details')"
			@close="selectedLog = null">
			<div class="timetracking-audit-log__details">
				<h3>{{ $t('arbeitszeitcheck', 'Log Entry Details') }}</h3>
				<div class="timetracking-audit-log__detail-item">
					<strong>{{ $t('arbeitszeitcheck', 'ID') }}:</strong> {{ selectedLog.id }}
				</div>
				<div class="timetracking-audit-log__detail-item">
					<strong>{{ $t('arbeitszeitcheck', 'Date & Time') }}:</strong> {{ formatDateTime(selectedLog.created_at) }}
				</div>
				<div class="timetracking-audit-log__detail-item">
					<strong>{{ $t('arbeitszeitcheck', 'User') }}:</strong> {{ selectedLog.user_display_name }}
				</div>
				<div class="timetracking-audit-log__detail-item">
					<strong>{{ $t('arbeitszeitcheck', 'Action') }}:</strong> {{ formatAction(selectedLog.action) }}
				</div>
				<div class="timetracking-audit-log__detail-item">
					<strong>{{ $t('arbeitszeitcheck', 'Entity Type') }}:</strong> {{ formatEntityType(selectedLog.entity_type) }}
				</div>
				<div class="timetracking-audit-log__detail-item">
					<strong>{{ $t('arbeitszeitcheck', 'Entity ID') }}:</strong> {{ selectedLog.entity_id || '-' }}
				</div>
				<div v-if="selectedLog.ip_address" class="timetracking-audit-log__detail-item">
					<strong>{{ $t('arbeitszeitcheck', 'IP Address') }}:</strong> {{ selectedLog.ip_address }}
				</div>
				<div v-if="selectedLog.user_agent" class="timetracking-audit-log__detail-item">
					<strong>{{ $t('arbeitszeitcheck', 'User Agent') }}:</strong> {{ selectedLog.user_agent }}
				</div>
				<div v-if="selectedLog.old_values" class="timetracking-audit-log__detail-item">
					<strong>{{ $t('arbeitszeitcheck', 'Old Values') }}:</strong>
					<pre class="timetracking-audit-log__json">{{ formatJson(selectedLog.old_values) }}</pre>
				</div>
				<div v-if="selectedLog.new_values" class="timetracking-audit-log__detail-item">
					<strong>{{ $t('arbeitszeitcheck', 'New Values') }}:</strong>
					<pre class="timetracking-audit-log__json">{{ formatJson(selectedLog.new_values) }}</pre>
				</div>
			</div>
		</NcModal>
	</div>
</template>

<script>
import { NcButton, NcSelect, NcTextField, NcLoadingIcon, NcEmptyContent, NcModal } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'AuditLogViewer',
	components: {
		NcButton,
		NcSelect,
		NcTextField,
		NcLoadingIcon,
		NcEmptyContent,
		NcModal
	},
	data() {
		return {
			isLoading: false,
			logs: [],
			statistics: null,
			total: 0,
			limit: 50,
			offset: 0,
			filters: {
				startDate: null,
				endDate: null,
				userId: null,
				action: null,
				entityType: null
			},
			userOptions: [],
			actionOptions: [],
			entityTypeOptions: [],
			selectedLog: null
		}
	},
	mounted() {
		this.initializeOptions()
		this.initializeDates()
		this.loadUsers()
		this.loadLogs()
		this.loadStatistics()
	},
	methods: {
		initializeOptions() {
			this.actionOptions = [
				{ value: 'clock_in', label: this.$t('arbeitszeitcheck', 'Clock In') },
				{ value: 'clock_out', label: this.$t('arbeitszeitcheck', 'Clock Out') },
				{ value: 'start_break', label: this.$t('arbeitszeitcheck', 'Start Break') },
				{ value: 'end_break', label: this.$t('arbeitszeitcheck', 'End Break') },
				{ value: 'time_entry_created', label: this.$t('arbeitszeitcheck', 'Time Entry Created') },
				{ value: 'time_entry_updated', label: this.$t('arbeitszeitcheck', 'Time Entry Updated') },
				{ value: 'time_entry_deleted', label: this.$t('arbeitszeitcheck', 'Time Entry Deleted') },
				{ value: 'absence_created', label: this.$t('arbeitszeitcheck', 'Absence Created') },
				{ value: 'absence_approved', label: this.$t('arbeitszeitcheck', 'Absence Approved') },
				{ value: 'absence_rejected', label: this.$t('arbeitszeitcheck', 'Absence Rejected') },
				{ value: 'settings_updated', label: this.$t('arbeitszeitcheck', 'Settings Updated') },
				{ value: 'violation_created', label: this.$t('arbeitszeitcheck', 'Violation Created') },
				{ value: 'violation_resolved', label: this.$t('arbeitszeitcheck', 'Violation Resolved') }
			]
			this.entityTypeOptions = [
				{ value: 'time_entry', label: this.$t('arbeitszeitcheck', 'Time Entry') },
				{ value: 'absence', label: this.$t('arbeitszeitcheck', 'Absence') },
				{ value: 'user_settings', label: this.$t('arbeitszeitcheck', 'User Settings') },
				{ value: 'compliance_violation', label: this.$t('arbeitszeitcheck', 'Compliance Violation') },
				{ value: 'working_time_model', label: this.$t('arbeitszeitcheck', 'Working Time Model') }
			]
		},
		validateGermanDate(field) {
			if (this.filters[field] && !parseGermanDate(this.filters[field])) {
				this.filters[field] = ''
			}
		},
		initializeDates() {
			const endDate = new Date()
			const startDate = new Date()
			startDate.setDate(startDate.getDate() - 30)
			
			this.filters.endDate = formatDateGerman(endDate)
			this.filters.startDate = formatDateGerman(startDate)
		},
		async loadUsers() {
			try {
				const response = await axios.get(generateUrl('/apps/arbeitszeitcheck/api/admin/users'))
				if (response.data.success && response.data.users) {
					this.userOptions = response.data.users.map(user => ({
						value: user.user_id,
						label: user.display_name || user.user_id
					}))
				}
			} catch (error) {
				console.error('Failed to load users:', error)
			}
		},
		async loadLogs() {
			this.isLoading = true
			try {
				const params = {
					limit: this.limit,
					offset: this.offset
				}
				
				if (this.filters.startDate) {
					params.start_date = this.filters.startDate
				}
				if (this.filters.endDate) {
					params.end_date = this.filters.endDate
				}
				if (this.filters.userId) {
					params.user_id = this.filters.userId
				}
				if (this.filters.action) {
					params.action = this.filters.action
				}
				if (this.filters.entityType) {
					params.entity_type = this.filters.entityType
				}
				
				const response = await axios.get(
					generateUrl('/apps/arbeitszeitcheck/api/admin/audit-logs'),
					{ params }
				)
				
				if (response.data.success) {
					this.logs = response.data.logs
					this.total = response.data.total
				} else {
					this.showNotification(
						response.data.error || this.$t('arbeitszeitcheck', 'Failed to load audit logs'),
						'error'
					)
				}
			} catch (error) {
				console.error('Error loading audit logs:', error)
				this.showNotification(
					this.$t('arbeitszeitcheck', 'Error loading audit logs. Please try again.'),
					'error'
				)
			} finally {
				this.isLoading = false
			}
		},
		async loadStatistics() {
			try {
				const params = {}
				if (this.filters.startDate) {
					params.start_date = this.filters.startDate
				}
				if (this.filters.endDate) {
					params.end_date = this.filters.endDate
				}
				
				const response = await axios.get(
					generateUrl('/apps/arbeitszeitcheck/api/admin/audit-logs/stats'),
					{ params }
				)
				
				if (response.data.success) {
					this.statistics = response.data.statistics
				}
			} catch (error) {
				console.error('Error loading statistics:', error)
			}
		},
		resetFilters() {
			this.filters = {
				startDate: null,
				endDate: null,
				userId: null,
				action: null,
				entityType: null
			}
			this.initializeDates()
			this.offset = 0
			this.loadLogs()
			this.loadStatistics()
		},
		previousPage() {
			if (this.offset > 0) {
				this.offset = Math.max(0, this.offset - this.limit)
				this.loadLogs()
			}
		},
		nextPage() {
			if (this.offset + this.limit < this.total) {
				this.offset += this.limit
				this.loadLogs()
			}
		},
		showLogDetails(log) {
			this.selectedLog = log
		},
		exportLogs() {
			const params = new URLSearchParams()
			if (this.filters.startDate) params.append('start_date', this.filters.startDate)
			if (this.filters.endDate) params.append('end_date', this.filters.endDate)
			if (this.filters.userId) params.append('user_id', this.filters.userId)
			if (this.filters.action) params.append('action', this.filters.action)
			if (this.filters.entityType) params.append('entity_type', this.filters.entityType)
			params.append('format', 'csv')
			
			const exportUrl = generateUrl('/apps/arbeitszeitcheck/api/admin/audit-logs/export') + '?' + params.toString()
			window.location.href = exportUrl
			
			this.showNotification(
				this.$t('arbeitszeitcheck', 'Exporting audit logs...'),
				'success'
			)
		},
		formatDateTime(dateString) {
			if (!dateString) return '-'
			const date = new Date(dateString)
			return date.toLocaleString([], {
				year: 'numeric',
				month: '2-digit',
				day: '2-digit',
				hour: '2-digit',
				minute: '2-digit'
			})
		},
		formatAction(action) {
			const option = this.actionOptions.find(opt => opt.value === action)
			return option ? option.label : action
		},
		formatEntityType(entityType) {
			const option = this.entityTypeOptions.find(opt => opt.value === entityType)
			return option ? option.label : entityType
		},
		getActionClass(action) {
			if (action.includes('deleted') || action.includes('rejected')) {
				return 'timetracking-audit-log__action--error'
			} else if (action.includes('created') || action.includes('approved')) {
				return 'timetracking-audit-log__action--success'
			} else if (action.includes('updated')) {
				return 'timetracking-audit-log__action--warning'
			}
			return ''
		},
		formatJson(obj) {
			if (!obj) return ''
			try {
				return JSON.stringify(obj, null, 2)
			} catch (e) {
				return String(obj)
			}
		},
		showNotification(message, type = 'info') {
			if (typeof OC !== 'undefined' && OC.Notification) {
				OC.Notification.showTemporary(message, {
					timeout: 5000,
					isHTML: false
				})
			} else {
				alert(message)
			}
		}
	}
}
</script>

<style scoped>
.timetracking-audit-log {
	padding: calc(var(--default-grid-baseline) * 2);
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
	gap: calc(var(--default-grid-baseline) * 2);
}

.timetracking-section {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: calc(var(--default-grid-baseline) * 2);
}

.timetracking-section__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: calc(var(--default-grid-baseline) * 2);
}

.timetracking-section__actions {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 2);
}

.timetracking-section-title {
	font-size: 18px;
	font-weight: 600;
	color: var(--color-main-text);
	margin: 0;
}

.timetracking-audit-log__filters {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: calc(var(--default-grid-baseline) * 2);
	margin-bottom: calc(var(--default-grid-baseline) * 2);
}

.timetracking-form-group {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 0.5);
}

.timetracking-form-label {
	font-weight: 600;
	color: var(--color-main-text);
	font-size: 14px;
}

.timetracking-audit-log__filter-actions {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 2);
	align-items: flex-end;
}

.timetracking-audit-log__stats {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: calc(var(--default-grid-baseline) * 2);
}

.timetracking-stat-item {
	padding: calc(var(--default-grid-baseline) * 1.5);
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
}

.timetracking-stat-item__label {
	font-size: 14px;
	color: var(--color-text-maxcontrast);
	margin-bottom: calc(var(--default-grid-baseline) * 0.5);
}

.timetracking-stat-item__value {
	font-size: 24px;
	font-weight: bold;
	color: var(--color-main-text);
}

.timetracking-audit-log__pagination {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-top: calc(var(--default-grid-baseline) * 2);
	padding-top: calc(var(--default-grid-baseline) * 2);
	border-top: 1px solid var(--color-border);
}

.timetracking-audit-log__pagination-info {
	color: var(--color-text-maxcontrast);
}

.timetracking-audit-log__action--success {
	color: var(--color-success);
}

.timetracking-audit-log__action--warning {
	color: var(--color-warning);
}

.timetracking-audit-log__action--error {
	color: var(--color-error);
}

.timetracking-audit-log__details {
	padding: calc(var(--default-grid-baseline) * 2);
}

.timetracking-audit-log__details h3 {
	margin: 0 0 calc(var(--default-grid-baseline) * 2) 0;
	color: var(--color-main-text);
}

.timetracking-audit-log__detail-item {
	margin-bottom: calc(var(--default-grid-baseline) * 1.5);
}

.timetracking-audit-log__detail-item strong {
	color: var(--color-main-text);
	margin-right: calc(var(--default-grid-baseline) * 0.5);
}

.timetracking-audit-log__json {
	background: var(--color-background-dark);
	padding: calc(var(--default-grid-baseline) * 1.5);
	border-radius: var(--border-radius);
	font-family: monospace;
	font-size: 12px;
	overflow-x: auto;
	margin-top: calc(var(--default-grid-baseline) * 0.5);
	color: var(--color-main-text);
}

@media (max-width: 768px) {
	.timetracking-audit-log__filters {
		grid-template-columns: 1fr;
	}

	.timetracking-audit-log__pagination {
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 2);
	}
}
</style>
