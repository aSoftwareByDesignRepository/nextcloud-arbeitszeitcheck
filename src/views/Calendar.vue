<template>
	<div class="timetracking-calendar">
		<div class="timetracking-dashboard__header">
			<h2 class="timetracking-dashboard__title">{{ $t('arbeitszeitcheck', 'Calendar View') }}</h2>
			<p class="timetracking-dashboard__subtitle">{{ $t('arbeitszeitcheck', 'View your time entries in a calendar format') }}</p>
		</div>

		<div class="timetracking-dashboard__content">
			<!-- Calendar Navigation -->
			<div class="timetracking-calendar__navigation">
				<NcButton
					type="tertiary"
					@click="previousMonth"
					:aria-label="$t('arbeitszeitcheck', 'Previous month')">
					<template #icon>
						<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
							<polyline points="15 18 9 12 15 6"/>
						</svg>
					</template>
				</NcButton>
				<h3 class="timetracking-calendar__month-title">
					{{ formatMonthYear(currentMonth) }}
				</h3>
				<NcButton
					type="tertiary"
					@click="nextMonth"
					:aria-label="$t('arbeitszeitcheck', 'Next month')">
					<template #icon>
						<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
							<polyline points="9 18 15 12 9 6"/>
						</svg>
					</template>
				</NcButton>
				<NcButton
					type="secondary"
					@click="goToToday"
					:aria-label="$t('arbeitszeitcheck', 'Go to today')">
					{{ $t('arbeitszeitcheck', 'Today') }}
				</NcButton>
			</div>

			<!-- View Toggle -->
			<div class="timetracking-calendar__view-toggle">
				<NcButton
					:type="viewMode === 'month' ? 'primary' : 'secondary'"
					@click="viewMode = 'month'"
					:aria-label="$t('arbeitszeitcheck', 'Month view')">
					{{ $t('arbeitszeitcheck', 'Month') }}
				</NcButton>
				<NcButton
					:type="viewMode === 'week' ? 'primary' : 'secondary'"
					@click="viewMode = 'week'"
					:aria-label="$t('arbeitszeitcheck', 'Week view')">
					{{ $t('arbeitszeitcheck', 'Week') }}
				</NcButton>
			</div>

			<!-- Loading State -->
			<div v-if="isLoading" class="timetracking-loading-container">
				<NcLoadingIcon :size="64" />
				<p>{{ $t('arbeitszeitcheck', 'Loading calendar...') }}</p>
			</div>

			<!-- Month View -->
			<div v-else-if="viewMode === 'month'" class="timetracking-calendar__month-view">
				<!-- Weekday Headers -->
				<div class="timetracking-calendar__weekdays">
					<div
						v-for="day in weekdays"
						:key="day"
						class="timetracking-calendar__weekday"
						:aria-label="day">
						{{ day }}
					</div>
				</div>

				<!-- Calendar Days -->
				<div class="timetracking-calendar__days">
					<div
						v-for="day in calendarDays"
						:key="day.date"
						class="timetracking-calendar__day"
						:class="{
							'timetracking-calendar__day--other-month': !day.isCurrentMonth,
							'timetracking-calendar__day--today': day.isToday,
							'timetracking-calendar__day--weekend': day.isWeekend,
							'timetracking-calendar__day--has-entries': day.entries && day.entries.length > 0
						}"
						:aria-label="formatDayLabel(day)"
						@click="selectDay(day)"
						@keydown.enter="selectDay(day)"
						@keydown.space.prevent="selectDay(day)"
						tabindex="0"
						role="button">
						<div class="timetracking-calendar__day-number">
							{{ day.dayNumber }}
						</div>
						<div v-if="day.entries && day.entries.length > 0" class="timetracking-calendar__day-entries">
							<div
								v-for="entry in day.entries"
								:key="entry.id"
								class="timetracking-calendar__day-entry"
								:class="getEntryStatusClass(entry.status)"
								:title="getEntryTooltip(entry)">
								<span class="timetracking-calendar__entry-hours">
									{{ formatHours(entry.durationHours || 0) }}h
								</span>
							</div>
							<div v-if="day.entries.length > 2" class="timetracking-calendar__day-more">
								+{{ day.entries.length - 2 }}
							</div>
						</div>
						<div v-if="day.totalHours > 0" class="timetracking-calendar__day-total">
							{{ formatHours(day.totalHours) }}h
						</div>
					</div>
				</div>
			</div>

			<!-- Week View -->
			<div v-else-if="viewMode === 'week'" class="timetracking-calendar__week-view">
				<div class="timetracking-calendar__week-header">
					<div
						v-for="day in weekDays"
						:key="day.date"
						class="timetracking-calendar__week-day-header"
						:class="{
							'timetracking-calendar__week-day-header--today': day.isToday,
							'timetracking-calendar__week-day-header--weekend': day.isWeekend
						}">
						<div class="timetracking-calendar__week-day-name">
							{{ day.dayName }}
						</div>
						<div class="timetracking-calendar__week-day-date">
							{{ day.dayNumber }}
						</div>
						<div v-if="day.totalHours > 0" class="timetracking-calendar__week-day-total">
							{{ formatHours(day.totalHours) }}h
						</div>
					</div>
				</div>
				<div class="timetracking-calendar__week-content">
					<div
						v-for="day in weekDays"
						:key="day.date"
						class="timetracking-calendar__week-day-content"
						:class="{
							'timetracking-calendar__week-day-content--today': day.isToday,
							'timetracking-calendar__week-day-content--weekend': day.isWeekend
						}">
						<div v-if="day.entries && day.entries.length > 0" class="timetracking-calendar__week-entries">
							<div
								v-for="entry in day.entries"
								:key="entry.id"
								class="timetracking-calendar__week-entry"
								:class="getEntryStatusClass(entry.status)"
								@click="showEntryDetails(entry)"
								@keydown.enter="showEntryDetails(entry)"
								@keydown.space.prevent="showEntryDetails(entry)"
								tabindex="0"
								role="button"
								:aria-label="getEntryAriaLabel(entry)">
								<div class="timetracking-calendar__week-entry-time">
									{{ formatTime(entry.startTime) }}
									<span v-if="entry.endTime"> - {{ formatTime(entry.endTime) }}</span>
								</div>
								<div class="timetracking-calendar__week-entry-duration">
									{{ formatHours(entry.durationHours || 0) }}h
								</div>
								<div v-if="entry.description" class="timetracking-calendar__week-entry-description">
									{{ entry.description }}
								</div>
							</div>
						</div>
						<NcEmptyContent
							v-else
							:title="$t('arbeitszeitcheck', 'No entries')"
							:description="$t('arbeitszeitcheck', 'No time entries for this day')">
							<template #icon>
								<span aria-hidden="true">📅</span>
							</template>
						</NcEmptyContent>
					</div>
				</div>
			</div>

			<!-- Selected Day Details Modal -->
			<NcModal
				v-if="selectedDay && selectedDay.entries && selectedDay.entries.length > 0"
				:name="$t('arbeitszeitcheck', 'Time Entries for {date}', { date: formatDate(selectedDay.date) })"
				@close="selectedDay = null"
				:show-close="true">
				<div class="timetracking-calendar__day-details">
					<h3 class="timetracking-section-title">
						{{ formatDate(selectedDay.date) }}
					</h3>
					<div class="timetracking-calendar__day-summary">
						<div class="timetracking-calendar__summary-item">
							<span class="timetracking-calendar__summary-label">{{ $t('arbeitszeitcheck', 'Total Hours') }}:</span>
							<span class="timetracking-calendar__summary-value">{{ formatHours(selectedDay.totalHours) }}</span>
						</div>
						<div class="timetracking-calendar__summary-item">
							<span class="timetracking-calendar__summary-label">{{ $t('arbeitszeitcheck', 'Entries') }}:</span>
							<span class="timetracking-calendar__summary-value">{{ selectedDay.entries.length }}</span>
						</div>
					</div>
					<table class="timetracking-table">
						<thead>
							<tr>
								<th>{{ $t('arbeitszeitcheck', 'Start Time') }}</th>
								<th>{{ $t('arbeitszeitcheck', 'End Time') }}</th>
								<th>{{ $t('arbeitszeitcheck', 'Duration') }}</th>
								<th>{{ $t('arbeitszeitcheck', 'Break Time') }}</th>
								<th>{{ $t('arbeitszeitcheck', 'Status') }}</th>
								<th>{{ $t('arbeitszeitcheck', 'Actions') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="entry in selectedDay.entries" :key="entry.id">
								<td>{{ formatTime(entry.startTime) }}</td>
								<td>{{ entry.endTime ? formatTime(entry.endTime) : '-' }}</td>
								<td>{{ formatHours(entry.durationHours || 0) }}</td>
								<td>{{ formatHours(entry.breakDurationHours || 0) }}</td>
								<td>
									<span :class="getEntryStatusClass(entry.status)">
										{{ getEntryStatusText(entry.status) }}
									</span>
								</td>
								<td>
									<NcButton
										type="secondary"
										:aria-label="$t('arbeitszeitcheck', 'View entry details')"
										@click="showEntryDetails(entry)">
										{{ $t('arbeitszeitcheck', 'Details') }}
									</NcButton>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</NcModal>

			<!-- Entry Details Modal -->
			<NcModal
				v-if="selectedEntry"
				:name="$t('arbeitszeitcheck', 'Time Entry Details')"
				@close="selectedEntry = null"
				:show-close="true">
				<div class="timetracking-calendar__entry-details">
					<div class="timetracking-calendar__detail-item">
						<strong>{{ $t('arbeitszeitcheck', 'Date') }}:</strong>
						{{ formatDate(selectedEntry.startTime) }}
					</div>
					<div class="timetracking-calendar__detail-item">
						<strong>{{ $t('arbeitszeitcheck', 'Start Time') }}:</strong>
						{{ formatTime(selectedEntry.startTime) }}
					</div>
					<div v-if="selectedEntry.endTime" class="timetracking-calendar__detail-item">
						<strong>{{ $t('arbeitszeitcheck', 'End Time') }}:</strong>
						{{ formatTime(selectedEntry.endTime) }}
					</div>
					<div class="timetracking-calendar__detail-item">
						<strong>{{ $t('arbeitszeitcheck', 'Duration') }}:</strong>
						{{ formatHours(selectedEntry.durationHours || 0) }}
					</div>
					<div v-if="selectedEntry.breakDurationHours" class="timetracking-calendar__detail-item">
						<strong>{{ $t('arbeitszeitcheck', 'Break Time') }}:</strong>
						{{ formatHours(selectedEntry.breakDurationHours) }}
					</div>
					<div class="timetracking-calendar__detail-item">
						<strong>{{ $t('arbeitszeitcheck', 'Status') }}:</strong>
						<span :class="getEntryStatusClass(selectedEntry.status)">
							{{ getEntryStatusText(selectedEntry.status) }}
						</span>
					</div>
					<div v-if="selectedEntry.description" class="timetracking-calendar__detail-item">
						<strong>{{ $t('arbeitszeitcheck', 'Description') }}:</strong>
						{{ selectedEntry.description }}
					</div>
					<div v-if="selectedEntry.projectCheckProjectId" class="timetracking-calendar__detail-item">
						<strong>{{ $t('arbeitszeitcheck', 'Project') }}:</strong>
						{{ selectedEntry.projectCheckProjectId }}
					</div>
				</div>
			</NcModal>
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcModal, NcEmptyContent } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { formatDateGerman } from '../utils/dateUtils.js'

export default {
	name: 'Calendar',
	components: {
		NcButton,
		NcLoadingIcon,
		NcModal,
		NcEmptyContent
	},
	data() {
		const today = new Date()
		return {
			currentMonth: new Date(today.getFullYear(), today.getMonth(), 1),
			viewMode: 'month',
			isLoading: false,
			timeEntries: [],
			selectedDay: null,
			selectedEntry: null,
			weekdays: [
				this.$t('arbeitszeitcheck', 'Mon'),
				this.$t('arbeitszeitcheck', 'Tue'),
				this.$t('arbeitszeitcheck', 'Wed'),
				this.$t('arbeitszeitcheck', 'Thu'),
				this.$t('arbeitszeitcheck', 'Fri'),
				this.$t('arbeitszeitcheck', 'Sat'),
				this.$t('arbeitszeitcheck', 'Sun')
			]
		}
	},
	computed: {
		calendarDays() {
			const year = this.currentMonth.getFullYear()
			const month = this.currentMonth.getMonth()
			const firstDay = new Date(year, month, 1)
			const lastDay = new Date(year, month + 1, 0)
			const startDate = new Date(firstDay)
			startDate.setDate(startDate.getDate() - startDate.getDay() + 1) // Start from Monday
			if (startDate.getDay() === 0) {
				startDate.setDate(startDate.getDate() - 7) // If Sunday, go back a week
			}

			const days = []
			const today = new Date()
			today.setHours(0, 0, 0, 0)

			for (let i = 0; i < 42; i++) {
				const date = new Date(startDate)
				date.setDate(startDate.getDate() + i)
				const dateStr = date.toISOString().split('T')[0]

				const dayEntries = this.timeEntries.filter(entry => {
					const entryDate = new Date(entry.startTime)
					entryDate.setHours(0, 0, 0, 0)
					return entryDate.getTime() === date.getTime()
				})

				const totalHours = dayEntries.reduce((sum, entry) => {
					return sum + (entry.durationHours || 0)
				}, 0)

				days.push({
					date: dateStr,
					dayNumber: date.getDate(),
					isCurrentMonth: date.getMonth() === month,
					isToday: date.getTime() === today.getTime(),
					isWeekend: date.getDay() === 0 || date.getDay() === 6,
					entries: dayEntries,
					totalHours: totalHours
				})
			}

			return days
		},
		weekDays() {
			const year = this.currentMonth.getFullYear()
			const month = this.currentMonth.getMonth()
			const date = this.currentMonth.getDate()
			const currentDate = new Date(year, month, date)

			// Find Monday of current week
			const day = currentDate.getDay()
			const diff = currentDate.getDate() - day + (day === 0 ? -6 : 1)
			const monday = new Date(currentDate.setDate(diff))
			monday.setHours(0, 0, 0, 0)

			const days = []
			const today = new Date()
			today.setHours(0, 0, 0, 0)

			for (let i = 0; i < 7; i++) {
				const date = new Date(monday)
				date.setDate(monday.getDate() + i)
				const dateStr = date.toISOString().split('T')[0]

				const dayEntries = this.timeEntries.filter(entry => {
					const entryDate = new Date(entry.startTime)
					entryDate.setHours(0, 0, 0, 0)
					return entryDate.getTime() === date.getTime()
				})

				const totalHours = dayEntries.reduce((sum, entry) => {
					return sum + (entry.durationHours || 0)
				}, 0)

				const dayNames = [
					this.$t('arbeitszeitcheck', 'Monday'),
					this.$t('arbeitszeitcheck', 'Tuesday'),
					this.$t('arbeitszeitcheck', 'Wednesday'),
					this.$t('arbeitszeitcheck', 'Thursday'),
					this.$t('arbeitszeitcheck', 'Friday'),
					this.$t('arbeitszeitcheck', 'Saturday'),
					this.$t('arbeitszeitcheck', 'Sunday')
				]

				days.push({
					date: dateStr,
					dayNumber: date.getDate(),
					dayName: dayNames[date.getDay() === 0 ? 6 : date.getDay() - 1],
					isToday: date.getTime() === today.getTime(),
					isWeekend: date.getDay() === 0 || date.getDay() === 6,
					entries: dayEntries,
					totalHours: totalHours
				})
			}

			return days
		}
	},
	mounted() {
		this.loadTimeEntries()
	},
	watch: {
		currentMonth() {
			this.loadTimeEntries()
		},
		viewMode() {
			this.loadTimeEntries()
		}
	},
	methods: {
		async loadTimeEntries() {
			this.isLoading = true
			try {
				const startDate = new Date(this.currentMonth.getFullYear(), this.currentMonth.getMonth(), 1)
				const endDate = new Date(this.currentMonth.getFullYear(), this.currentMonth.getMonth() + 1, 0)
				endDate.setHours(23, 59, 59)

				const response = await axios.get(
					generateUrl('/apps/arbeitszeitcheck/api/time-entries'),
					{
						params: {
							start_date: startDate.toISOString().split('T')[0],
							end_date: endDate.toISOString().split('T')[0],
							limit: 1000
						}
					}
				)

				if (response.data.success) {
					this.timeEntries = response.data.entries || []
				}
			} catch (error) {
				console.error('Failed to load time entries:', error)
				this.showNotification(
					this.$t('arbeitszeitcheck', 'Failed to load time entries'),
					'error'
				)
			} finally {
				this.isLoading = false
			}
		},
		previousMonth() {
			const newMonth = new Date(this.currentMonth)
			newMonth.setMonth(newMonth.getMonth() - 1)
			this.currentMonth = newMonth
		},
		nextMonth() {
			const newMonth = new Date(this.currentMonth)
			newMonth.setMonth(newMonth.getMonth() + 1)
			this.currentMonth = newMonth
		},
		goToToday() {
			const today = new Date()
			this.currentMonth = new Date(today.getFullYear(), today.getMonth(), 1)
		},
		selectDay(day) {
			if (day.entries && day.entries.length > 0) {
				this.selectedDay = day
			}
		},
		showEntryDetails(entry) {
			this.selectedEntry = entry
			this.selectedDay = null
		},
		formatMonthYear(date) {
			const monthNames = [
				this.$t('arbeitszeitcheck', 'January'),
				this.$t('arbeitszeitcheck', 'February'),
				this.$t('arbeitszeitcheck', 'March'),
				this.$t('arbeitszeitcheck', 'April'),
				this.$t('arbeitszeitcheck', 'May'),
				this.$t('arbeitszeitcheck', 'June'),
				this.$t('arbeitszeitcheck', 'July'),
				this.$t('arbeitszeitcheck', 'August'),
				this.$t('arbeitszeitcheck', 'September'),
				this.$t('arbeitszeitcheck', 'October'),
				this.$t('arbeitszeitcheck', 'November'),
				this.$t('arbeitszeitcheck', 'December')
			]
			return `${monthNames[date.getMonth()]} ${date.getFullYear()}`
		},
		formatDate(dateString) {
			return formatDateGerman(dateString)
		},
		formatTime(dateString) {
			if (!dateString) {
				return ''
			}
			const date = new Date(dateString)
			return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
		},
		formatHours(hours) {
			if (hours === null || hours === undefined) {
				return '0.00'
			}
			return parseFloat(hours).toFixed(2)
		},
		formatDayLabel(day) {
			const date = new Date(day.date)
			const label = `${date.toLocaleDateString()}`
			if (day.entries && day.entries.length > 0) {
				return `${label} - ${day.entries.length} ${this.$t('arbeitszeitcheck', 'entries')}, ${this.formatHours(day.totalHours)} ${this.$t('arbeitszeitcheck', 'hours')}`
			}
			return label
		},
		getEntryStatusClass(status) {
			return {
				'timetracking-calendar__entry--active': status === 'active',
				'timetracking-calendar__entry--completed': status === 'completed',
				'timetracking-calendar__entry--pending': status === 'pending_approval',
				'timetracking-calendar__entry--break': status === 'break'
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
		getEntryTooltip(entry) {
			return `${this.formatTime(entry.startTime)} - ${entry.endTime ? this.formatTime(entry.endTime) : this.$t('arbeitszeitcheck', 'ongoing')}: ${this.formatHours(entry.durationHours || 0)}h`
		},
		getEntryAriaLabel(entry) {
			return `${this.$t('arbeitszeitcheck', 'Time entry')}: ${this.formatTime(entry.startTime)} ${entry.endTime ? `- ${this.formatTime(entry.endTime)}` : this.$t('arbeitszeitcheck', 'ongoing')}, ${this.formatHours(entry.durationHours || 0)} ${this.$t('arbeitszeitcheck', 'hours')}`
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
.timetracking-calendar {
	padding: var(--default-grid-baseline);
}

.timetracking-calendar__navigation {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline) * 2);
	margin-bottom: calc(var(--default-grid-baseline) * 3);
	padding: calc(var(--default-grid-baseline) * 2);
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.timetracking-calendar__month-title {
	font-size: 20px;
	font-weight: bold;
	color: var(--color-main-text);
	margin: 0;
	flex: 1;
	text-align: center;
}

.timetracking-calendar__view-toggle {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 1);
	margin-bottom: calc(var(--default-grid-baseline) * 2);
}

.timetracking-calendar__weekdays {
	display: grid;
	grid-template-columns: repeat(7, 1fr);
	gap: calc(var(--default-grid-baseline) * 0.5);
	margin-bottom: calc(var(--default-grid-baseline) * 1);
}

.timetracking-calendar__weekday {
	padding: calc(var(--default-grid-baseline) * 1);
	text-align: center;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	font-size: 14px;
}

.timetracking-calendar__days {
	display: grid;
	grid-template-columns: repeat(7, 1fr);
	gap: calc(var(--default-grid-baseline) * 0.5);
}

.timetracking-calendar__day {
	min-height: 100px;
	padding: calc(var(--default-grid-baseline) * 1);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	cursor: pointer;
	transition: background-color 0.2s ease, box-shadow 0.2s ease;
	position: relative;
}

.timetracking-calendar__day:hover,
.timetracking-calendar__day:focus {
	background: var(--color-background-hover);
	box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
}

.timetracking-calendar__day--other-month {
	opacity: 0.4;
	background: var(--color-background-dark);
}

.timetracking-calendar__day--today {
	border: 2px solid var(--color-primary);
	background: var(--color-primary-element-light, rgba(var(--color-primary-rgb, 0, 130, 201), 0.1));
}

.timetracking-calendar__day--weekend {
	background: var(--color-background-hover);
}

.timetracking-calendar__day--has-entries {
	border-left: 4px solid var(--color-primary);
}

.timetracking-calendar__day-number {
	font-weight: 600;
	color: var(--color-main-text);
	margin-bottom: calc(var(--default-grid-baseline) * 0.5);
}

.timetracking-calendar__day-entries {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 0.25);
	margin-top: calc(var(--default-grid-baseline) * 0.5);
}

.timetracking-calendar__day-entry {
	padding: 2px 4px;
	border-radius: var(--border-radius);
	font-size: 11px;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.timetracking-calendar__entry--active {
	background: var(--color-primary-element-light, rgba(var(--color-primary-rgb, 0, 130, 201), 0.1));
	color: var(--color-primary-text, #ffffff);
}

.timetracking-calendar__entry--completed {
	background: var(--color-success-background, rgba(70, 186, 97, 0.1));
	color: var(--color-success, #46ba61);
}

.timetracking-calendar__entry--pending {
	background: var(--color-warning-background, rgba(236, 167, 0, 0.1));
	color: var(--color-warning, #eca700);
}

.timetracking-calendar__entry--break {
	background: var(--color-text-maxcontrast);
	color: var(--color-main-background);
}

.timetracking-calendar__entry-hours {
	font-weight: 600;
}

.timetracking-calendar__day-more {
	font-size: 10px;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.timetracking-calendar__day-total {
	position: absolute;
	bottom: calc(var(--default-grid-baseline) * 0.5);
	right: calc(var(--default-grid-baseline) * 0.5);
	font-size: 12px;
	font-weight: 600;
	color: var(--color-primary);
}

.timetracking-calendar__week-view {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 1);
}

.timetracking-calendar__week-header {
	display: grid;
	grid-template-columns: repeat(7, 1fr);
	gap: calc(var(--default-grid-baseline) * 1);
	margin-bottom: calc(var(--default-grid-baseline) * 2);
}

.timetracking-calendar__week-day-header {
	padding: calc(var(--default-grid-baseline) * 1.5);
	text-align: center;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
}

.timetracking-calendar__week-day-header--today {
	border: 2px solid var(--color-primary);
	background: var(--color-primary-element-light, rgba(var(--color-primary-rgb, 0, 130, 201), 0.1));
}

.timetracking-calendar__week-day-header--weekend {
	background: var(--color-background-hover);
}

.timetracking-calendar__week-day-name {
	font-weight: 600;
	color: var(--color-main-text);
	margin-bottom: calc(var(--default-grid-baseline) * 0.5);
}

.timetracking-calendar__week-day-date {
	font-size: 18px;
	font-weight: bold;
	color: var(--color-main-text);
}

.timetracking-calendar__week-day-total {
	font-size: 12px;
	color: var(--color-primary);
	margin-top: calc(var(--default-grid-baseline) * 0.5);
}

.timetracking-calendar__week-content {
	display: grid;
	grid-template-columns: repeat(7, 1fr);
	gap: calc(var(--default-grid-baseline) * 1);
	min-height: 400px;
}

.timetracking-calendar__week-day-content {
	padding: calc(var(--default-grid-baseline) * 1);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	min-height: 400px;
}

.timetracking-calendar__week-day-content--today {
	border: 2px solid var(--color-primary);
	background: var(--color-primary-element-light, rgba(var(--color-primary-rgb, 0, 130, 201), 0.1));
}

.timetracking-calendar__week-day-content--weekend {
	background: var(--color-background-hover);
}

.timetracking-calendar__week-entries {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 1);
}

.timetracking-calendar__week-entry {
	padding: calc(var(--default-grid-baseline) * 1);
	border-radius: var(--border-radius);
	border: 1px solid var(--color-border);
	cursor: pointer;
	transition: background-color 0.2s ease, box-shadow 0.2s ease;
}

.timetracking-calendar__week-entry:hover,
.timetracking-calendar__week-entry:focus {
	background: var(--color-background-hover);
	box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
	outline: 2px solid var(--color-primary);
	outline-offset: 2px;
}

.timetracking-calendar__week-entry-time {
	font-weight: 600;
	color: var(--color-main-text);
	margin-bottom: calc(var(--default-grid-baseline) * 0.25);
}

.timetracking-calendar__week-entry-duration {
	font-size: 14px;
	color: var(--color-primary);
	font-weight: 600;
	margin-bottom: calc(var(--default-grid-baseline) * 0.25);
}

.timetracking-calendar__week-entry-description {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.timetracking-calendar__day-details,
.timetracking-calendar__entry-details {
	padding: calc(var(--default-grid-baseline) * 2);
}

.timetracking-calendar__day-summary {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 4);
	margin-bottom: calc(var(--default-grid-baseline) * 3);
	padding: calc(var(--default-grid-baseline) * 2);
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
}

.timetracking-calendar__summary-item {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 0.5);
}

.timetracking-calendar__summary-label {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.timetracking-calendar__summary-value {
	font-size: 18px;
	font-weight: bold;
	color: var(--color-main-text);
}

.timetracking-calendar__detail-item {
	margin-bottom: calc(var(--default-grid-baseline) * 1.5);
	padding-bottom: calc(var(--default-grid-baseline) * 1.5);
	border-bottom: 1px solid var(--color-border);
}

.timetracking-calendar__detail-item:last-child {
	border-bottom: none;
	margin-bottom: 0;
	padding-bottom: 0;
}

.timetracking-calendar__detail-item strong {
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
	.timetracking-calendar__navigation {
		flex-wrap: wrap;
	}

	.timetracking-calendar__month-title {
		width: 100%;
		order: -1;
	}

	.timetracking-calendar__days {
		grid-template-columns: repeat(7, 1fr);
		gap: 2px;
	}

	.timetracking-calendar__day {
		min-height: 60px;
		padding: 4px;
		font-size: 12px;
	}

	.timetracking-calendar__day-entry {
		font-size: 10px;
	}

	.timetracking-calendar__week-content {
		grid-template-columns: 1fr;
		min-height: auto;
	}

	.timetracking-calendar__week-day-content {
		min-height: auto;
	}

	.timetracking-calendar__week-header {
		display: none;
	}
}
</style>
