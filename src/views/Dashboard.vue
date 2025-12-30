<template>
	<div class="timetracking-dashboard">
		<div class="timetracking-dashboard__header">
			<h2 class="timetracking-dashboard__title">{{ $t('arbeitszeitcheck', 'Time Tracking Dashboard') }}</h2>
			<p class="timetracking-dashboard__subtitle">{{ $t('arbeitszeitcheck', 'Track your working hours legally and compliantly') }}</p>
		</div>

		<div class="timetracking-dashboard__content">
			<!-- Clock Section -->
			<div class="timetracking-clock-section">
				<h3 class="timetracking-section-title">{{ $t('arbeitszeitcheck', 'Quick Actions') }}</h3>
				<div class="timetracking-clock-buttons">
					<NcButton
						type="primary"
						:disabled="isLoading || currentStatus.status === 'active' || currentStatus.status === 'break'"
						:aria-label="$t('arbeitszeitcheck', 'Clock in to start tracking time')"
						@click="clockIn"
					>
						{{ $t('arbeitszeitcheck', 'Clock In') }}
					</NcButton>
					<NcButton
						type="secondary"
						:disabled="isLoading || currentStatus.status !== 'active'"
						:aria-label="$t('arbeitszeitcheck', 'Clock out to end tracking time')"
						@click="clockOut"
					>
						{{ $t('arbeitszeitcheck', 'Clock Out') }}
					</NcButton>
					<NcButton
						type="tertiary"
						:disabled="isLoading || currentStatus.status !== 'active'"
						:aria-label="$t('arbeitszeitcheck', 'Start break')"
						@click="startBreak"
					>
						{{ $t('arbeitszeitcheck', 'Start Break') }}
					</NcButton>
					<NcButton
						type="tertiary"
						:disabled="isLoading || currentStatus.status !== 'break'"
						:aria-label="$t('arbeitszeitcheck', 'End break')"
						@click="endBreak"
					>
						{{ $t('arbeitszeitcheck', 'End Break') }}
					</NcButton>
				</div>
			</div>

			<!-- Status Section -->
			<div class="timetracking-status-section">
				<h3 class="timetracking-section-title">{{ $t('arbeitszeitcheck', 'Current Status') }}</h3>
				<NcLoadingIcon v-if="isLoading" />
				<div v-else class="timetracking-status-display">
					<p :class="getStatusClass()">
						<strong>{{ $t('arbeitszeitcheck', 'Status') }}:</strong>
						<span :aria-live="currentStatus.status === 'active' || currentStatus.status === 'break' ? 'polite' : 'off'">
							{{ getStatusText() }}
						</span>
					</p>
					<p v-if="currentStatus.current_session_duration">
						<strong>{{ $t('arbeitszeitcheck', 'Current Session') }}:</strong>
						{{ formatDuration(currentStatus.current_session_duration) }}
					</p>
					<p v-if="currentStatus.working_today_hours !== undefined">
						<strong>{{ $t('arbeitszeitcheck', 'Today\'s Hours') }}:</strong>
						{{ formatHours(currentStatus.working_today_hours) }}
					</p>
				</div>
			</div>

			<!-- Today's Summary -->
			<div class="timetracking-today-section">
				<h3 class="timetracking-section-title">{{ $t('arbeitszeitcheck', 'Today\'s Summary') }}</h3>
				<div class="timetracking-today-summary">
					<div class="timetracking-summary-item">
						<div class="timetracking-summary-item__label">{{ $t('arbeitszeitcheck', 'Working Hours') }}</div>
						<div class="timetracking-summary-item__value">{{ formatHours(todayStats.workingHours) }}</div>
					</div>
					<div class="timetracking-summary-item">
						<div class="timetracking-summary-item__label">{{ $t('arbeitszeitcheck', 'Break Time') }}</div>
						<div class="timetracking-summary-item__value">{{ formatHours(todayStats.breakTime) }}</div>
					</div>
					<div class="timetracking-summary-item">
						<div class="timetracking-summary-item__label">{{ $t('arbeitszeitcheck', 'Overtime') }}</div>
						<div class="timetracking-summary-item__value">{{ formatHours(todayStats.overtime) }}</div>
					</div>
					<div class="timetracking-summary-item">
						<div class="timetracking-summary-item__label">{{ $t('arbeitszeitcheck', 'Compliance') }}</div>
						<div class="timetracking-summary-item__value" :class="todayStats.complianceStatus === 'good' ? 'timetracking-status--good' : 'timetracking-status--warning'">
							<span :aria-label="$t('arbeitszeitcheck', 'Compliance status: {status}', { status: todayStats.complianceStatus === 'good' ? $t('arbeitszeitcheck', 'good') : $t('arbeitszeitcheck', 'warning') })">
								{{ todayStats.complianceStatus === 'good' ? '✓' : '⚠' }}
							</span>
						</div>
					</div>
				</div>
			</div>

			<!-- Recent Entries -->
			<div class="timetracking-recent-section">
				<h3 class="timetracking-section-title">{{ $t('arbeitszeitcheck', 'Recent Time Entries') }}</h3>
				<NcEmptyContent
					v-if="recentEntries.length === 0"
					:title="$t('arbeitszeitcheck', 'No time entries yet')"
					:description="$t('arbeitszeitcheck', 'Start tracking your time by clicking the Clock In button above')"
				>
					<template #icon>
						<span aria-hidden="true">📋</span>
					</template>
				</NcEmptyContent>
				<table v-else class="timetracking-table">
					<thead>
						<tr>
							<th>{{ $t('arbeitszeitcheck', 'Date') }}</th>
							<th>{{ $t('arbeitszeitcheck', 'Start Time') }}</th>
							<th>{{ $t('arbeitszeitcheck', 'End Time') }}</th>
							<th>{{ $t('arbeitszeitcheck', 'Duration') }}</th>
							<th>{{ $t('arbeitszeitcheck', 'Status') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="entry in recentEntries" :key="entry.id">
							<td>{{ formatDate(entry.startTime) }}</td>
							<td>{{ formatTime(entry.startTime) }}</td>
							<td>{{ entry.endTime ? formatTime(entry.endTime) : '-' }}</td>
							<td>{{ entry.durationHours ? formatHours(entry.durationHours) : '-' }}</td>
							<td>
								<span :class="getEntryStatusClass(entry.status)">
									{{ getEntryStatusText(entry.status) }}
								</span>
							</td>
						</tr>
					</tbody>
				</table>
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
	name: 'Dashboard',
	components: {
		NcButton,
		NcLoadingIcon,
		NcEmptyContent
	},
	data() {
		return {
			isLoading: false,
			currentStatus: {
				status: 'clocked_out',
				current_entry: null,
				working_today_hours: 0,
				current_session_duration: null
			},
			todayStats: {
				workingHours: 0,
				breakTime: 0,
				overtime: 0,
				complianceStatus: 'good'
			},
			recentEntries: [],
			statusUpdateInterval: null
		}
	},
	mounted() {
		this.loadStatus()
		this.loadRecentEntries()
		// Update status every 30 seconds when active
		this.statusUpdateInterval = setInterval(() => {
			if (this.currentStatus.status === 'active' || this.currentStatus.status === 'break') {
				this.loadStatus()
			}
		}, 30000)
	},
	beforeUnmount() {
		if (this.statusUpdateInterval) {
			clearInterval(this.statusUpdateInterval)
		}
	},
	methods: {
		async loadStatus() {
			try {
				const response = await axios.get(generateUrl('/apps/arbeitszeitcheck/api/clock/status'))
				if (response.data.success) {
					this.currentStatus = response.data.status
					this.updateTodayStats()
				}
			} catch (error) {
				console.error('Failed to load status:', error)
			}
		},

		async loadRecentEntries() {
			try {
				const response = await axios.get(generateUrl('/apps/arbeitszeitcheck/api/time-entries?limit=10'))
				if (response.data.success) {
					this.recentEntries = response.data.entries || []
				}
			} catch (error) {
				console.error('Failed to load recent entries:', error)
			}
		},

		async clockIn() {
			this.isLoading = true
			try {
				const response = await axios.post(generateUrl('/apps/arbeitszeitcheck/api/clock/in'))
				if (response.data.success) {
					this.currentStatus = {
						status: 'active',
						current_entry: response.data.timeEntry,
						working_today_hours: this.currentStatus.working_today_hours,
						current_session_duration: 0
					}
					this.updateTodayStats()
					this.showNotification(this.$t('arbeitszeitcheck', 'Successfully clocked in'), 'success')
				}
			} catch (error) {
				this.showNotification(error.response?.data?.error || this.$t('arbeitszeitcheck', 'Failed to clock in'), 'error')
			} finally {
				this.isLoading = false
			}
		},

		async clockOut() {
			this.isLoading = true
			try {
				const response = await axios.post(generateUrl('/apps/arbeitszeitcheck/api/clock/out'))
				if (response.data.success) {
					this.currentStatus = {
						status: 'clocked_out',
						current_entry: null,
						working_today_hours: this.currentStatus.working_today_hours,
						current_session_duration: null
					}
					this.updateTodayStats()
					this.loadRecentEntries()
					this.showNotification(this.$t('arbeitszeitcheck', 'Successfully clocked out'), 'success')
				}
			} catch (error) {
				this.showNotification(error.response?.data?.error || this.$t('arbeitszeitcheck', 'Failed to clock out'), 'error')
			} finally {
				this.isLoading = false
			}
		},

		async startBreak() {
			this.isLoading = true
			try {
				const response = await axios.post(generateUrl('/apps/arbeitszeitcheck/api/break/start'))
				if (response.data.success) {
					this.currentStatus.status = 'break'
					this.currentStatus.current_entry = response.data.timeEntry
					this.showNotification(this.$t('arbeitszeitcheck', 'Break started'), 'info')
				}
			} catch (error) {
				this.showNotification(error.response?.data?.error || this.$t('arbeitszeitcheck', 'Failed to start break'), 'error')
			} finally {
				this.isLoading = false
			}
		},

		async endBreak() {
			this.isLoading = true
			try {
				const response = await axios.post(generateUrl('/apps/arbeitszeitcheck/api/break/end'))
				if (response.data.success) {
					this.currentStatus.status = 'active'
					this.currentStatus.current_entry = response.data.timeEntry
					this.showNotification(this.$t('arbeitszeitcheck', 'Break ended'), 'info')
				}
			} catch (error) {
				this.showNotification(error.response?.data?.error || this.$t('arbeitszeitcheck', 'Failed to end break'), 'error')
			} finally {
				this.isLoading = false
			}
		},

		updateTodayStats() {
			this.todayStats.workingHours = this.currentStatus.working_today_hours || 0
			this.todayStats.breakTime = 0
			this.todayStats.overtime = Math.max(0, this.todayStats.workingHours - 8)
			this.todayStats.complianceStatus = this.todayStats.workingHours <= 10 ? 'good' : 'warning'
		},

		getStatusClass() {
			switch (this.currentStatus.status) {
				case 'active':
					return 'timetracking-status--active'
				case 'break':
					return 'timetracking-status--break'
				default:
					return 'timetracking-status--inactive'
			}
		},

		getStatusText() {
			switch (this.currentStatus.status) {
				case 'active':
					return this.$t('arbeitszeitcheck', 'Working')
				case 'break':
					return this.$t('arbeitszeitcheck', 'On Break')
				default:
					return this.$t('arbeitszeitcheck', 'Clocked Out')
			}
		},

		getEntryStatusClass(status) {
			switch (status) {
				case 'completed':
					return 'timetracking-status--success'
				case 'pending_approval':
					return 'timetracking-status--warning'
				case 'rejected':
					return 'timetracking-status--error'
				default:
					return 'timetracking-status--inactive'
			}
		},

		getEntryStatusText(status) {
			switch (status) {
				case 'completed':
					return this.$t('arbeitszeitcheck', 'Completed')
				case 'pending_approval':
					return this.$t('arbeitszeitcheck', 'Pending Approval')
				case 'rejected':
					return this.$t('arbeitszeitcheck', 'Rejected')
				default:
					return status
			}
		},

		formatDuration(seconds) {
			const hours = Math.floor(seconds / 3600)
			const minutes = Math.floor((seconds % 3600) / 60)
			return `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}`
		},

		formatHours(hours) {
			return `${hours.toFixed(2)}h`
		},

		formatDate(dateString) {
			return formatDateGerman(dateString)
		},

		formatTime(dateString) {
			return new Date(dateString).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
		},

		showNotification(message, type) {
			// Use Nextcloud's notification system
			if (typeof OC !== 'undefined' && OC.Notification) {
				OC.Notification.showTemporary(message, {
					timeout: 5000,
					isHTML: false
				})
			} else {
				// Fallback for development
				console.log(`${type}: ${message}`)
			}
		}
	}
}
</script>

<style scoped>
.timetracking-dashboard {
	padding: var(--default-grid-baseline);
}

.timetracking-dashboard__header {
	margin-bottom: calc(var(--default-grid-baseline) * 2);
}

.timetracking-dashboard__title {
	font-size: 24px;
	font-weight: 600;
	color: var(--color-main-text);
	margin: 0 0 calc(var(--default-grid-baseline) / 2) 0;
}

.timetracking-dashboard__subtitle {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.timetracking-section-title {
	font-size: 18px;
	font-weight: 600;
	color: var(--color-main-text);
	margin: 0 0 var(--default-grid-baseline) 0;
}

.timetracking-clock-buttons {
	display: flex;
	gap: var(--default-grid-baseline);
	flex-wrap: wrap;
	margin-bottom: calc(var(--default-grid-baseline) * 2);
}

.timetracking-status-section,
.timetracking-today-section,
.timetracking-recent-section {
	margin-bottom: calc(var(--default-grid-baseline) * 2);
	padding: var(--default-grid-baseline);
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius);
}

.timetracking-status-display p {
	margin: calc(var(--default-grid-baseline) / 2) 0;
	color: var(--color-main-text);
}

.timetracking-today-summary {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
	gap: var(--default-grid-baseline);
}

.timetracking-summary-item {
	text-align: center;
}

.timetracking-summary-item__label {
	font-size: 14px;
	color: var(--color-text-maxcontrast);
	margin-bottom: calc(var(--default-grid-baseline) / 2);
}

.timetracking-summary-item__value {
	font-size: 24px;
	font-weight: 600;
	color: var(--color-main-text);
}

.timetracking-status--active {
	color: var(--color-success);
}

.timetracking-status--break {
	color: var(--color-warning);
}

.timetracking-status--inactive {
	color: var(--color-text-maxcontrast);
}

.timetracking-status--good {
	color: var(--color-success);
}

.timetracking-status--warning {
	color: var(--color-warning);
}

.timetracking-status--success {
	color: var(--color-success);
	font-weight: 500;
}

.timetracking-status--error {
	color: var(--color-error);
	font-weight: 500;
}

@media (max-width: 768px) {
	.timetracking-clock-buttons {
		flex-direction: column;
	}

	.timetracking-clock-buttons .timetracking-btn,
	.timetracking-clock-buttons .button-vue {
		width: 100%;
	}
}
</style>