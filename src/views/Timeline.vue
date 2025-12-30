<template>
	<div class="timetracking-timeline">
		<div class="timetracking-dashboard__header">
			<h2 class="timetracking-dashboard__title">{{ $t('arbeitszeitcheck', 'Timeline View') }}</h2>
			<p class="timetracking-dashboard__subtitle">{{ $t('arbeitszeitcheck', 'View your work periods in a chronological timeline') }}</p>
		</div>

		<div class="timetracking-dashboard__content">
			<!-- Date Range Selection -->
			<div class="timetracking-timeline__filters">
				<div class="timetracking-form-group">
					<label for="timeline-start-date" class="timetracking-form-label">{{ $t('arbeitszeitcheck', 'Start Date') }}</label>
					<NcTextField
						id="timeline-start-date"
						v-model="startDate"
						type="text"
						placeholder="dd.mm.yyyy"
						pattern="\d{2}\.\d{2}\.\d{4}"
						:label="$t('arbeitszeitcheck', 'Start Date')"
						@blur="validateGermanDate('startDate')"
					/>
				</div>
				<div class="timetracking-form-group">
					<label for="timeline-end-date" class="timetracking-form-label">{{ $t('arbeitszeitcheck', 'End Date') }}</label>
					<NcTextField
						id="timeline-end-date"
						v-model="endDate"
						type="text"
						placeholder="dd.mm.yyyy"
						pattern="\d{2}\.\d{2}\.\d{4}"
						:label="$t('arbeitszeitcheck', 'End Date')"
						@blur="validateGermanDate('endDate')"
					/>
				</div>
				<div class="timetracking-form-group">
					<NcButton
						type="primary"
						@click="loadTimeline"
						:disabled="isLoading"
						:aria-label="$t('arbeitszeitcheck', 'Load timeline')">
						{{ isLoading ? $t('arbeitszeitcheck', 'Loading...') : $t('arbeitszeitcheck', 'Load Timeline') }}
					</NcButton>
				</div>
				<div class="timetracking-form-group">
					<NcButton
						type="secondary"
						@click="setQuickRange('today')"
						:aria-label="$t('arbeitszeitcheck', 'Show today')">
						{{ $t('arbeitszeitcheck', 'Today') }}
					</NcButton>
				</div>
				<div class="timetracking-form-group">
					<NcButton
						type="secondary"
						@click="setQuickRange('week')"
						:aria-label="$t('arbeitszeitcheck', 'Show this week')">
						{{ $t('arbeitszeitcheck', 'This Week') }}
					</NcButton>
				</div>
				<div class="timetracking-form-group">
					<NcButton
						type="secondary"
						@click="setQuickRange('month')"
						:aria-label="$t('arbeitszeitcheck', 'Show this month')">
						{{ $t('arbeitszeitcheck', 'This Month') }}
					</NcButton>
				</div>
			</div>

			<!-- Loading State -->
			<div v-if="isLoading" class="timetracking-loading-container">
				<NcLoadingIcon :size="64" />
				<p>{{ $t('arbeitszeitcheck', 'Loading timeline...') }}</p>
			</div>

			<!-- Timeline Display -->
			<div v-else-if="timelineDays.length > 0" class="timetracking-timeline__container">
				<!-- Summary Statistics -->
				<div class="timetracking-timeline__summary">
					<div class="timetracking-timeline__summary-item">
						<span class="timetracking-timeline__summary-label">{{ $t('arbeitszeitcheck', 'Total Hours') }}:</span>
						<span class="timetracking-timeline__summary-value">{{ formatHours(totalHours) }}</span>
					</div>
					<div class="timetracking-timeline__summary-item">
						<span class="timetracking-timeline__summary-label">{{ $t('arbeitszeitcheck', 'Total Breaks') }}:</span>
						<span class="timetracking-timeline__summary-value">{{ formatHours(totalBreaks) }}</span>
					</div>
					<div class="timetracking-timeline__summary-item">
						<span class="timetracking-timeline__summary-label">{{ $t('arbeitszeitcheck', 'Work Days') }}:</span>
						<span class="timetracking-timeline__summary-value">{{ workDaysCount }}</span>
					</div>
					<div class="timetracking-timeline__summary-item">
						<span class="timetracking-timeline__summary-label">{{ $t('arbeitszeitcheck', 'Average Daily Hours') }}:</span>
						<span class="timetracking-timeline__summary-value">{{ formatHours(avgDailyHours) }}</span>
					</div>
				</div>

				<!-- Timeline Days -->
				<div class="timetracking-timeline__days">
					<div
						v-for="day in timelineDays"
						:key="day.date"
						class="timetracking-timeline__day"
						:class="{
							'timetracking-timeline__day--today': day.isToday,
							'timetracking-timeline__day--weekend': day.isWeekend,
							'timetracking-timeline__day--has-entries': day.entries && day.entries.length > 0
						}">
						<!-- Day Header -->
						<div class="timetracking-timeline__day-header">
							<div class="timetracking-timeline__day-date">
								{{ formatDayDate(day.date) }}
							</div>
							<div class="timetracking-timeline__day-info">
								<span v-if="day.isWeekend" class="timetracking-timeline__day-badge timetracking-timeline__day-badge--weekend">
									{{ $t('arbeitszeitcheck', 'Weekend') }}
								</span>
								<span v-if="day.isToday" class="timetracking-timeline__day-badge timetracking-timeline__day-badge--today">
									{{ $t('arbeitszeitcheck', 'Today') }}
								</span>
								<span v-if="day.totalHours > 0" class="timetracking-timeline__day-hours">
									{{ formatHours(day.totalHours) }}
								</span>
							</div>
						</div>

						<!-- Timeline Bar -->
						<div class="timetracking-timeline__bar-container">
							<div class="timetracking-timeline__bar">
								<div
									v-for="period in day.periods"
									:key="period.id"
									class="timetracking-timeline__period"
									:class="getPeriodClass(period.type)"
									:style="getPeriodStyle(period)"
									:title="getPeriodTooltip(period)"
									:aria-label="getPeriodAriaLabel(period)"
									@click="showPeriodDetails(period)"
									@keydown.enter="showPeriodDetails(period)"
									@keydown.space.prevent="showPeriodDetails(period)"
									tabindex="0"
									role="button">
									<div class="timetracking-timeline__period-label">
										{{ getPeriodLabel(period) }}
									</div>
									<div class="timetracking-timeline__period-time">
										{{ formatTime(period.startTime) }} - {{ formatTime(period.endTime) }}
									</div>
									<div class="timetracking-timeline__period-duration">
										{{ formatDuration(period.duration) }}
									</div>
								</div>
								<div v-if="day.periods.length === 0" class="timetracking-timeline__empty">
									{{ $t('arbeitszeitcheck', 'No time entries') }}
								</div>
							</div>
						</div>

						<!-- Day Entries List -->
						<div v-if="day.entries && day.entries.length > 0" class="timetracking-timeline__entries">
							<div
								v-for="entry in day.entries"
								:key="entry.id"
								class="timetracking-timeline__entry"
								:class="getEntryStatusClass(entry.status)"
								@click="showEntryDetails(entry)"
								@keydown.enter="showEntryDetails(entry)"
								@keydown.space.prevent="showEntryDetails(entry)"
								tabindex="0"
								role="button"
								:aria-label="getEntryAriaLabel(entry)">
								<div class="timetracking-timeline__entry-time">
									<span class="timetracking-timeline__entry-start">{{ formatTime(entry.startTime) }}</span>
									<span v-if="entry.endTime" class="timetracking-timeline__entry-end">{{ formatTime(entry.endTime) }}</span>
									<span v-else class="timetracking-timeline__entry-end timetracking-timeline__entry-end--ongoing">
										{{ $t('arbeitszeitcheck', 'ongoing') }}
									</span>
								</div>
								<div class="timetracking-timeline__entry-details">
									<div class="timetracking-timeline__entry-duration">
										{{ formatHours(entry.durationHours || 0) }}
									</div>
									<div v-if="entry.breakDurationHours" class="timetracking-timeline__entry-break">
										{{ $t('arbeitszeitcheck', 'Break') }}: {{ formatHours(entry.breakDurationHours) }}
									</div>
									<div v-if="entry.description" class="timetracking-timeline__entry-description">
										{{ entry.description }}
									</div>
									<div class="timetracking-timeline__entry-status">
										{{ getEntryStatusText(entry.status) }}
									</div>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>

			<!-- Empty State -->
			<NcEmptyContent
				v-else
				:title="$t('arbeitszeitcheck', 'No time entries found')"
				:description="$t('arbeitszeitcheck', 'Select a date range and click Load Timeline to view your work periods')">
				<template #icon>
					<span aria-hidden="true">📅</span>
				</template>
			</NcEmptyContent>

			<!-- Period Details Modal -->
			<NcModal
				v-if="selectedPeriod"
				:name="$t('arbeitszeitcheck', 'Period Details')"
				@close="selectedPeriod = null"
				:show-close="true">
				<div class="timetracking-timeline__period-details">
					<div class="timetracking-timeline__detail-item">
						<strong>{{ $t('arbeitszeitcheck', 'Type') }}:</strong>
						{{ getPeriodTypeLabel(selectedPeriod.type) }}
					</div>
					<div class="timetracking-timeline__detail-item">
						<strong>{{ $t('arbeitszeitcheck', 'Date') }}:</strong>
						{{ formatDate(selectedPeriod.startTime) }}
					</div>
					<div class="timetracking-timeline__detail-item">
						<strong>{{ $t('arbeitszeitcheck', 'Start Time') }}:</strong>
						{{ formatTime(selectedPeriod.startTime) }}
					</div>
					<div class="timetracking-timeline__detail-item">
						<strong>{{ $t('arbeitszeitcheck', 'End Time') }}:</strong>
						{{ selectedPeriod.endTime ? formatTime(selectedPeriod.endTime) : $t('arbeitszeitcheck', 'Ongoing') }}
					</div>
					<div class="timetracking-timeline__detail-item">
						<strong>{{ $t('arbeitszeitcheck', 'Duration') }}:</strong>
						{{ formatDuration(selectedPeriod.duration) }}
					</div>
					<div v-if="selectedPeriod.description" class="timetracking-timeline__detail-item">
						<strong>{{ $t('arbeitszeitcheck', 'Description') }}:</strong>
						{{ selectedPeriod.description }}
					</div>
				</div>
			</NcModal>

			<!-- Entry Details Modal -->
			<NcModal
				v-if="selectedEntry"
				:name="$t('arbeitszeitcheck', 'Time Entry Details')"
				@close="selectedEntry = null"
				:show-close="true">
				<div class="timetracking-timeline__entry-details-modal">
					<div class="timetracking-timeline__detail-item">
						<strong>{{ $t('arbeitszeitcheck', 'Date') }}:</strong>
						{{ formatDate(selectedEntry.startTime) }}
					</div>
					<div class="timetracking-timeline__detail-item">
						<strong>{{ $t('arbeitszeitcheck', 'Start Time') }}:</strong>
						{{ formatTime(selectedEntry.startTime) }}
					</div>
					<div v-if="selectedEntry.endTime" class="timetracking-timeline__detail-item">
						<strong>{{ $t('arbeitszeitcheck', 'End Time') }}:</strong>
						{{ formatTime(selectedEntry.endTime) }}
					</div>
					<div class="timetracking-timeline__detail-item">
						<strong>{{ $t('arbeitszeitcheck', 'Duration') }}:</strong>
						{{ formatHours(selectedEntry.durationHours || 0) }}
					</div>
					<div v-if="selectedEntry.breakDurationHours" class="timetracking-timeline__detail-item">
						<strong>{{ $t('arbeitszeitcheck', 'Break Time') }}:</strong>
						{{ formatHours(selectedEntry.breakDurationHours) }}
					</div>
					<div class="timetracking-timeline__detail-item">
						<strong>{{ $t('arbeitszeitcheck', 'Status') }}:</strong>
						<span :class="getEntryStatusClass(selectedEntry.status)">
							{{ getEntryStatusText(selectedEntry.status) }}
						</span>
					</div>
					<div v-if="selectedEntry.description" class="timetracking-timeline__detail-item">
						<strong>{{ $t('arbeitszeitcheck', 'Description') }}:</strong>
						{{ selectedEntry.description }}
					</div>
					<div v-if="selectedEntry.projectCheckProjectId" class="timetracking-timeline__detail-item">
						<strong>{{ $t('arbeitszeitcheck', 'Project') }}:</strong>
						{{ selectedEntry.projectCheckProjectId }}
					</div>
				</div>
			</NcModal>
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcEmptyContent, NcModal, NcTextField } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { formatDateGerman, parseGermanDate } from '../utils/dateUtils.js'

export default {
	name: 'Timeline',
	components: {
		NcButton,
		NcLoadingIcon,
		NcEmptyContent,
		NcModal,
		NcTextField
	},
	data() {
		const today = new Date()
		const weekAgo = new Date(today)
		weekAgo.setDate(weekAgo.getDate() - 7)

		return {
			startDate: formatDateGerman(weekAgo),
			endDate: formatDateGerman(today),
			maxDate: formatDateGerman(today),
			isLoading: false,
			timeEntries: [],
			timelineDays: [],
			selectedPeriod: null,
			selectedEntry: null
		}
	},
	computed: {
		totalHours() {
			return this.timelineDays.reduce((sum, day) => sum + day.totalHours, 0)
		},
		totalBreaks() {
			return this.timelineDays.reduce((sum, day) => {
				return sum + (day.entries.reduce((entrySum, entry) => {
					return entrySum + (entry.breakDurationHours || 0)
				}, 0))
			}, 0)
		},
		workDaysCount() {
			return this.timelineDays.filter(day => day.totalHours > 0).length
		},
		avgDailyHours() {
			return this.workDaysCount > 0 ? this.totalHours / this.workDaysCount : 0
		}
	},
	mounted() {
		this.loadTimeline()
	},
	methods: {
		validateGermanDate(field) {
			if (this[field] && !parseGermanDate(this[field])) {
				this[field] = ''
			}
		},
		async loadTimeline() {
			// Convert German dates to ISO format for API
			const startDateIso = parseGermanDate(this.startDate)
			const endDateIso = parseGermanDate(this.endDate)

			if (!startDateIso || !endDateIso) {
				this.showNotification(this.$t('arbeitszeitcheck', 'Please enter valid dates in dd.mm.yyyy format'), 'warning')
				return
			}

			this.isLoading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/arbeitszeitcheck/api/time-entries'),
					{
						params: {
							start_date: startDateIso,
							end_date: endDateIso,
							limit: 1000
						}
					}
				)

				if (response.data.success) {
					this.timeEntries = response.data.entries || []
					this.buildTimeline()
				}
			} catch (error) {
				console.error('Failed to load timeline:', error)
				this.showNotification(
					this.$t('arbeitszeitcheck', 'Failed to load timeline'),
					'error'
				)
			} finally {
				this.isLoading = false
			}
		},
		buildTimeline() {
			const days = {}
			const today = new Date()
			today.setHours(0, 0, 0, 0)

			// Initialize all days in range - parse German dates to ISO first
			const startDateIso = parseGermanDate(this.startDate) || this.startDate
			const endDateIso = parseGermanDate(this.endDate) || this.endDate
			const start = new Date(startDateIso)
			const end = new Date(endDateIso)
			end.setHours(23, 59, 59)

			for (let d = new Date(start); d <= end; d.setDate(d.getDate() + 1)) {
				const dateStr = d.toISOString().split('T')[0]
				const isToday = d.getTime() === today.getTime()
				const isWeekend = d.getDay() === 0 || d.getDay() === 6

				days[dateStr] = {
					date: dateStr,
					isToday,
					isWeekend,
					entries: [],
					periods: [],
					totalHours: 0
				}
			}

			// Group entries by day and create periods
			this.timeEntries.forEach(entry => {
				const entryDate = new Date(entry.startTime)
				const dateStr = entryDate.toISOString().split('T')[0]

				if (days[dateStr]) {
					days[dateStr].entries.push(entry)
					days[dateStr].totalHours += entry.durationHours || 0

					// Create work period
					if (entry.startTime) {
						const startTime = new Date(entry.startTime)
						const endTime = entry.endTime ? new Date(entry.endTime) : new Date()
						const duration = (endTime - startTime) / 1000 / 60 // minutes

						days[dateStr].periods.push({
							id: `entry-${entry.id}`,
							type: 'work',
							startTime: entry.startTime,
							endTime: entry.endTime,
							duration,
							entry: entry
						})

						// Create break period if exists
						if (entry.breakDurationHours && entry.breakDurationHours > 0) {
							// Estimate break time (simplified - in real implementation, use actual break times)
							const breakStart = new Date(startTime.getTime() + (duration / 2) * 60000)
							const breakEnd = new Date(breakStart.getTime() + entry.breakDurationHours * 3600000)

							days[dateStr].periods.push({
								id: `break-${entry.id}`,
								type: 'break',
								startTime: breakStart.toISOString(),
								endTime: breakEnd.toISOString(),
								duration: entry.breakDurationHours * 60,
								entry: entry
							})
						}
					}
				}
			})

			// Sort periods by start time for each day
			Object.values(days).forEach(day => {
				day.periods.sort((a, b) => {
					return new Date(a.startTime) - new Date(b.startTime)
				})
			})

			// Convert to array and sort by date
			this.timelineDays = Object.values(days).sort((a, b) => {
				return new Date(a.date) - new Date(b.date)
			})
		},
		setQuickRange(range) {
			const today = new Date()
			today.setHours(0, 0, 0, 0)

			switch (range) {
				case 'today':
					this.startDate = today.toISOString().split('T')[0]
					this.endDate = today.toISOString().split('T')[0]
					break
				case 'week':
					const weekStart = new Date(today)
					weekStart.setDate(weekStart.getDate() - weekStart.getDay() + 1) // Monday
					if (weekStart.getDay() === 0) {
						weekStart.setDate(weekStart.getDate() - 7)
					}
					this.startDate = weekStart.toISOString().split('T')[0]
					this.endDate = today.toISOString().split('T')[0]
					break
				case 'month':
					const monthStart = new Date(today.getFullYear(), today.getMonth(), 1)
					this.startDate = monthStart.toISOString().split('T')[0]
					this.endDate = today.toISOString().split('T')[0]
					break
			}
			this.loadTimeline()
		},
		getPeriodStyle(period) {
			const dayStart = new Date(period.startTime)
			dayStart.setHours(0, 0, 0, 0)
			const dayEnd = new Date(dayStart)
			dayEnd.setHours(23, 59, 59)

			const periodStart = new Date(period.startTime)
			const periodEnd = period.endTime ? new Date(period.endTime) : new Date()

			const dayDuration = dayEnd - dayStart
			const periodDuration = periodEnd - periodStart

			const leftPercent = ((periodStart - dayStart) / dayDuration) * 100
			const widthPercent = (periodDuration / dayDuration) * 100

			return {
				left: `${Math.max(0, leftPercent)}%`,
				width: `${Math.min(100, widthPercent)}%`
			}
		},
		getPeriodClass(type) {
			return {
				'timetracking-timeline__period--work': type === 'work',
				'timetracking-timeline__period--break': type === 'break'
			}
		},
		getPeriodLabel(period) {
			return period.type === 'work'
				? this.$t('arbeitszeitcheck', 'Work')
				: this.$t('arbeitszeitcheck', 'Break')
		},
		getPeriodTypeLabel(type) {
			return type === 'work'
				? this.$t('arbeitszeitcheck', 'Work Period')
				: this.$t('arbeitszeitcheck', 'Break Period')
		},
		getPeriodTooltip(period) {
			return `${this.getPeriodLabel(period)}: ${this.formatTime(period.startTime)} - ${period.endTime ? this.formatTime(period.endTime) : this.$t('arbeitszeitcheck', 'ongoing')} (${this.formatDuration(period.duration)})`
		},
		getPeriodAriaLabel(period) {
			return `${this.getPeriodLabel(period)} ${this.$t('arbeitszeitcheck', 'period')}: ${this.formatTime(period.startTime)} ${period.endTime ? `- ${this.formatTime(period.endTime)}` : this.$t('arbeitszeitcheck', 'ongoing')}, ${this.formatDuration(period.duration)}`
		},
		showPeriodDetails(period) {
			this.selectedPeriod = period
		},
		showEntryDetails(entry) {
			this.selectedEntry = entry
			this.selectedPeriod = null
		},
		getEntryStatusClass(status) {
			return {
				'timetracking-timeline__entry--active': status === 'active',
				'timetracking-timeline__entry--completed': status === 'completed',
				'timetracking-timeline__entry--pending': status === 'pending_approval',
				'timetracking-timeline__entry--break': status === 'break'
			}
		},
		getEntryStatusText(status) {
			const statuses = {
				active: this.$t('arbeitszeitcheck', 'Active'),
				completed: this.$t('arbeitszeitcheck', 'Completed'),
				pending_approval: this.$t('arbeitszeitcheck', 'Pending'),
				break: this.$t('arbeitszeitcheck', 'Break')
			}
			return statuses[status] || status
		},
		getEntryAriaLabel(entry) {
			return `${this.$t('arbeitszeitcheck', 'Time entry')}: ${this.formatTime(entry.startTime)} ${entry.endTime ? `- ${this.formatTime(entry.endTime)}` : this.$t('arbeitszeitcheck', 'ongoing')}, ${this.formatHours(entry.durationHours || 0)} ${this.$t('arbeitszeitcheck', 'hours')}`
		},
		formatDate(dateString) {
			return formatDateGerman(dateString)
		},
		formatDayDate(dateString) {
			if (!dateString) return ''
			const date = new Date(dateString)
			const dayNames = [
				this.$t('arbeitszeitcheck', 'Sunday'),
				this.$t('arbeitszeitcheck', 'Monday'),
				this.$t('arbeitszeitcheck', 'Tuesday'),
				this.$t('arbeitszeitcheck', 'Wednesday'),
				this.$t('arbeitszeitcheck', 'Thursday'),
				this.$t('arbeitszeitcheck', 'Friday'),
				this.$t('arbeitszeitcheck', 'Saturday')
			]
			return `${dayNames[date.getDay()]}, ${date.toLocaleDateString()}`
		},
		formatTime(dateString) {
			if (!dateString) return ''
			const date = new Date(dateString)
			return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
		},
		formatHours(hours) {
			if (hours === null || hours === undefined) return '0.00'
			return parseFloat(hours).toFixed(2)
		},
		formatDuration(minutes) {
			if (!minutes) return '0m'
			const hours = Math.floor(minutes / 60)
			const mins = Math.floor(minutes % 60)
			if (hours > 0) {
				return `${hours}h ${mins}m`
			}
			return `${mins}m`
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
.timetracking-timeline {
	padding: var(--default-grid-baseline);
}

.timetracking-timeline__filters {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 2);
	margin-bottom: calc(var(--default-grid-baseline) * 3);
	padding: calc(var(--default-grid-baseline) * 2);
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.timetracking-form-group {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 0.5);
}

.timetracking-form-label {
	font-size: 14px;
	font-weight: 500;
	color: var(--color-main-text);
}

.timetracking-timeline__summary {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
	gap: calc(var(--default-grid-baseline) * 2);
	margin-bottom: calc(var(--default-grid-baseline) * 3);
	padding: calc(var(--default-grid-baseline) * 2);
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
}

.timetracking-timeline__summary-item {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 0.5);
}

.timetracking-timeline__summary-label {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.timetracking-timeline__summary-value {
	font-size: 20px;
	font-weight: bold;
	color: var(--color-main-text);
}

.timetracking-timeline__days {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.timetracking-timeline__day {
	padding: calc(var(--default-grid-baseline) * 2);
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	transition: box-shadow 0.2s ease;
}

.timetracking-timeline__day:hover {
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.timetracking-timeline__day--today {
	border: 2px solid var(--color-primary);
	background: var(--color-primary-element-light, rgba(0, 130, 201, 0.05));
}

.timetracking-timeline__day--weekend {
	background: var(--color-background-hover);
}

.timetracking-timeline__day-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: calc(var(--default-grid-baseline) * 2);
	padding-bottom: calc(var(--default-grid-baseline) * 1);
	border-bottom: 1px solid var(--color-border);
}

.timetracking-timeline__day-date {
	font-size: 18px;
	font-weight: 600;
	color: var(--color-main-text);
}

.timetracking-timeline__day-info {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 1);
	align-items: center;
}

.timetracking-timeline__day-badge {
	padding: 2px 8px;
	border-radius: var(--border-radius);
	font-size: 12px;
	font-weight: 500;
}

.timetracking-timeline__day-badge--today {
	background: var(--color-primary);
	color: var(--color-primary-text, #ffffff);
}

.timetracking-timeline__day-badge--weekend {
	background: var(--color-text-maxcontrast);
	color: var(--color-main-background);
}

.timetracking-timeline__day-hours {
	font-size: 16px;
	font-weight: 600;
	color: var(--color-primary);
}

.timetracking-timeline__bar-container {
	margin-bottom: calc(var(--default-grid-baseline) * 2);
}

.timetracking-timeline__bar {
	position: relative;
	height: 60px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	overflow: hidden;
}

.timetracking-timeline__period {
	position: absolute;
	top: 0;
	height: 100%;
	border-radius: var(--border-radius);
	cursor: pointer;
	transition: all 0.2s ease;
	display: flex;
	flex-direction: column;
	justify-content: center;
	align-items: center;
	padding: 4px;
	box-sizing: border-box;
	min-width: 80px;
}

.timetracking-timeline__period:hover,
.timetracking-timeline__period:focus {
	transform: scale(1.05);
	z-index: 10;
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
	outline: 2px solid var(--color-primary);
	outline-offset: 2px;
}

.timetracking-timeline__period--work {
	background: var(--color-primary);
	color: var(--color-primary-text, #ffffff);
}

.timetracking-timeline__period--break {
	background: var(--color-text-maxcontrast);
	color: var(--color-main-background);
}

.timetracking-timeline__period-label {
	font-size: 11px;
	font-weight: 600;
	text-transform: uppercase;
	margin-bottom: 2px;
}

.timetracking-timeline__period-time {
	font-size: 10px;
	opacity: 0.9;
}

.timetracking-timeline__period-duration {
	font-size: 10px;
	font-weight: 600;
	margin-top: 2px;
}

.timetracking-timeline__empty {
	display: flex;
	align-items: center;
	justify-content: center;
	height: 100%;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.timetracking-timeline__entries {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 1);
}

.timetracking-timeline__entry {
	padding: calc(var(--default-grid-baseline) * 1.5);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	cursor: pointer;
	transition: all 0.2s ease;
	display: flex;
	gap: calc(var(--default-grid-baseline) * 2);
}

.timetracking-timeline__entry:hover,
.timetracking-timeline__entry:focus {
	background: var(--color-background-hover);
	box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
	outline: 2px solid var(--color-primary);
	outline-offset: 2px;
}

.timetracking-timeline__entry--active {
	border-left: 4px solid var(--color-primary);
}

.timetracking-timeline__entry--completed {
	border-left: 4px solid var(--color-success, #46ba61);
}

.timetracking-timeline__entry--pending {
	border-left: 4px solid var(--color-warning, #eca700);
}

.timetracking-timeline__entry-time {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 120px;
}

.timetracking-timeline__entry-start,
.timetracking-timeline__entry-end {
	font-size: 14px;
	font-weight: 600;
	color: var(--color-main-text);
}

.timetracking-timeline__entry-end--ongoing {
	color: var(--color-primary);
}

.timetracking-timeline__entry-details {
	flex: 1;
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 0.5);
}

.timetracking-timeline__entry-duration {
	font-size: 16px;
	font-weight: 600;
	color: var(--color-primary);
}

.timetracking-timeline__entry-break {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.timetracking-timeline__entry-description {
	font-size: 14px;
	color: var(--color-main-text);
}

.timetracking-timeline__entry-status {
	font-size: 12px;
	font-weight: 500;
	color: var(--color-text-maxcontrast);
}

.timetracking-timeline__period-details,
.timetracking-timeline__entry-details-modal {
	padding: calc(var(--default-grid-baseline) * 2);
}

.timetracking-timeline__detail-item {
	margin-bottom: calc(var(--default-grid-baseline) * 1.5);
	padding-bottom: calc(var(--default-grid-baseline) * 1.5);
	border-bottom: 1px solid var(--color-border);
}

.timetracking-timeline__detail-item:last-child {
	border-bottom: none;
	margin-bottom: 0;
	padding-bottom: 0;
}

.timetracking-timeline__detail-item strong {
	color: var(--color-main-text);
	margin-right: calc(var(--default-grid-baseline) * 1);
}

.timetracking-loading-container {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: calc(var(--default-grid-baseline) * 8);
	gap: calc(var(--default-grid-baseline) * 2);
}

@media (max-width: 768px) {
	.timetracking-timeline__filters {
		flex-direction: column;
	}

	.timetracking-timeline__bar {
		height: 40px;
	}

	.timetracking-timeline__period {
		min-width: 60px;
		font-size: 10px;
	}

	.timetracking-timeline__period-label,
	.timetracking-timeline__period-time,
	.timetracking-timeline__period-duration {
		font-size: 8px;
	}

	.timetracking-timeline__entry {
		flex-direction: column;
	}

	.timetracking-timeline__entry-time {
		min-width: auto;
	}
}
</style>
