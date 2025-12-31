<template>
	<div class="timetracking-timeline">
		<div class="timetracking-dashboard__header">
			<h2 class="timetracking-dashboard__title">{{ $t('arbeitszeitcheck', 'Time Entries') }}</h2>
			<p class="timetracking-dashboard__subtitle">{{ $t('arbeitszeitcheck', 'View your work periods in a chronological timeline') }}</p>
		</div>

		<div class="timetracking-dashboard__content">
			<!-- View Toggle Section -->
			<div class="timetracking-section">
				<div class="timetracking-view-toggle">
					<h3 class="timetracking-section-title">{{ $t('arbeitszeitcheck', 'Time Entries') }}</h3>
					<div class="timetracking-view-toggle__buttons">
						<NcButton
							:type="$route.path === '/time-entries' ? 'primary' : 'tertiary'"
							:aria-label="$t('arbeitszeitcheck', 'List view')"
							:aria-pressed="$route.path === '/time-entries'"
							@click="$router.push('/time-entries')"
						>
							{{ $t('arbeitszeitcheck', 'List') }}
						</NcButton>
						<NcButton
							:type="$route.path === '/calendar' ? 'primary' : 'tertiary'"
							:aria-label="$t('arbeitszeitcheck', 'Calendar view')"
							:aria-pressed="$route.path === '/calendar'"
							@click="$router.push('/calendar')"
						>
							{{ $t('arbeitszeitcheck', 'Calendar') }}
						</NcButton>
						<NcButton
							:type="$route.path === '/timeline' ? 'primary' : 'tertiary'"
							:aria-label="$t('arbeitszeitcheck', 'Timeline view')"
							:aria-pressed="$route.path === '/timeline'"
							@click="$router.push('/timeline')"
						>
							{{ $t('arbeitszeitcheck', 'Timeline') }}
						</NcButton>
					</div>
				</div>
				<p class="timetracking-section-help">
					{{ $t('arbeitszeitcheck', 'View your work periods in a chronological timeline') }}
				</p>
			</div>

			<!-- Actions Section -->
			<div class="timetracking-section">
				<h3 class="timetracking-section-title">{{ $t('arbeitszeitcheck', 'Actions') }}</h3>
				<p class="timetracking-section-help">
					{{ $t('arbeitszeitcheck', 'Add manual time entries or export your data') }}
				</p>
				<div class="timetracking-actions-grid">
					<NcButton 
						type="primary"
						:aria-label="$t('arbeitszeitcheck', 'Add manual time entry')"
						@click="openAddManualEntryModal"
					>
						{{ $t('arbeitszeitcheck', 'Add Manual Entry') }}
					</NcButton>
					<NcButton 
						type="secondary"
						:aria-label="$t('arbeitszeitcheck', 'Export time entries')"
						@click="exportEntries"
						:disabled="isExporting"
					>
						{{ isExporting ? $t('arbeitszeitcheck', 'Exporting...') : $t('arbeitszeitcheck', 'Export') }}
					</NcButton>
				</div>
			</div>

			<!-- Date Range Selection -->
			<div class="timetracking-section">
				<h3 class="timetracking-section-title">{{ $t('arbeitszeitcheck', 'Date Range') }}</h3>
				<p class="timetracking-section-help">
					{{ $t('arbeitszeitcheck', 'Select a date range to view your time entries in timeline format') }}
				</p>
				<div class="timetracking-timeline__filters">
				<div class="timetracking-form-group">
					<label for="timeline-start-date" class="timetracking-form-label">{{ $t('arbeitszeitcheck', 'Start Date') }}</label>
					<NcTextField
						id="timeline-start-date"
						v-model="startDate"
						type="date"
						:label="$t('arbeitszeitcheck', 'Start Date')"
						:max="maxDate"
					/>
				</div>
				<div class="timetracking-form-group">
					<label for="timeline-end-date" class="timetracking-form-label">{{ $t('arbeitszeitcheck', 'End Date') }}</label>
					<NcTextField
						id="timeline-end-date"
						v-model="endDate"
						type="date"
						:label="$t('arbeitszeitcheck', 'End Date')"
						:max="maxDate"
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
		</div><!-- Close timetracking-dashboard__content -->
		
		<div v-if="timelineDays.length > 0" class="timetracking-timeline__container">
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
								:class="getEntryStatusClass(entry.status)">
								<div 
									class="timetracking-timeline__entry-content"
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
											<span :class="getEntryStatusClass(entry.status)" class="timetracking-status-badge">
												{{ getEntryStatusText(entry.status) }}
											</span>
										</div>
									</div>
								</div>
								<div class="timetracking-entry-actions">
									<NcButton
										v-if="entry.status !== 'pending_approval'"
										type="secondary"
										size="small"
										:aria-label="$t('arbeitszeitcheck', 'Request correction')"
										@click.stop="requestCorrection(entry)"
									>
										{{ $t('arbeitszeitcheck', 'Request Correction') }}
									</NcButton>
									<NcButton
										type="secondary"
										size="small"
										:aria-label="$t('arbeitszeitcheck', 'Edit entry')"
										@click.stop="editEntry(entry)"
										:disabled="entry.status === 'pending_approval'"
									>
										{{ $t('arbeitszeitcheck', 'Edit') }}
									</NcButton>
									<NcButton
										type="tertiary"
										size="small"
										:aria-label="$t('arbeitszeitcheck', 'View entry details')"
										@click.stop="showEntryDetails(entry)"
									>
										{{ $t('arbeitszeitcheck', 'Details') }}
									</NcButton>
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
				:show-close="true"
				:size="'large'"
			>
				<div class="timetracking-modal-content">
					<!-- Help Text -->
					<div class="timetracking-form-help-section">
						<p class="timetracking-form-help">
							{{ $t('arbeitszeitcheck', 'View detailed information about this work or break period.') }}
						</p>
					</div>

					<!-- Period Information -->
					<div class="timetracking-section">
						<h4 class="timetracking-section-title">{{ $t('arbeitszeitcheck', 'Period Information') }}</h4>
						<div class="timetracking-info-grid">
							<div class="timetracking-info-item">
								<strong>{{ $t('arbeitszeitcheck', 'Type') }}</strong>
								<span>{{ getPeriodTypeLabel(selectedPeriod.type) }}</span>
							</div>
							<div class="timetracking-info-item">
								<strong>{{ $t('arbeitszeitcheck', 'Date') }}</strong>
								<span>{{ formatDate(selectedPeriod.startTime) }}</span>
							</div>
							<div class="timetracking-info-item">
								<strong>{{ $t('arbeitszeitcheck', 'Start Time') }}</strong>
								<span>{{ formatTime(selectedPeriod.startTime) }}</span>
							</div>
							<div class="timetracking-info-item">
								<strong>{{ $t('arbeitszeitcheck', 'End Time') }}</strong>
								<span>{{ selectedPeriod.endTime ? formatTime(selectedPeriod.endTime) : $t('arbeitszeitcheck', 'Ongoing') }}</span>
							</div>
							<div class="timetracking-info-item">
								<strong>{{ $t('arbeitszeitcheck', 'Duration') }}</strong>
								<span>{{ formatDuration(selectedPeriod.duration) }}</span>
							</div>
						</div>
					</div>

					<!-- Description -->
					<div v-if="selectedPeriod.description" class="timetracking-form-group">
						<label class="timetracking-form-label">
							{{ $t('arbeitszeitcheck', 'Description') }}
						</label>
						<div class="timetracking-textarea" style="min-height: auto; padding: calc(var(--default-grid-baseline) * 2); background: var(--color-background-dark);">
							{{ selectedPeriod.description }}
						</div>
					</div>

					<!-- Actions -->
					<div class="timetracking-modal-actions">
						<NcButton
							type="tertiary"
							@click="selectedPeriod = null"
						>
							{{ $t('arbeitszeitcheck', 'Close') }}
						</NcButton>
					</div>
				</div>
			</NcModal>

			<!-- Entry Details Modal -->
			<NcModal
				v-if="selectedEntry"
				:name="$t('arbeitszeitcheck', 'Time Entry Details')"
				@close="selectedEntry = null"
				:show-close="true"
				:size="'large'"
			>
				<div class="timetracking-modal-content">
					<!-- Help Text -->
					<div class="timetracking-form-help-section">
						<p class="timetracking-form-help">
							{{ $t('arbeitszeitcheck', 'View detailed information about this time entry. You can edit the entry or request a correction if needed.') }}
						</p>
					</div>

					<!-- Entry Information -->
					<div class="timetracking-section">
						<h4 class="timetracking-section-title">{{ $t('arbeitszeitcheck', 'Entry Information') }}</h4>
						<div class="timetracking-info-grid">
							<div class="timetracking-info-item">
								<strong>{{ $t('arbeitszeitcheck', 'Date') }}</strong>
								<span>{{ formatDate(selectedEntry.startTime) }}</span>
							</div>
							<div class="timetracking-info-item">
								<strong>{{ $t('arbeitszeitcheck', 'Start Time') }}</strong>
								<span>{{ formatTime(selectedEntry.startTime) }}</span>
							</div>
							<div v-if="selectedEntry.endTime" class="timetracking-info-item">
								<strong>{{ $t('arbeitszeitcheck', 'End Time') }}</strong>
								<span>{{ formatTime(selectedEntry.endTime) }}</span>
							</div>
							<div class="timetracking-info-item">
								<strong>{{ $t('arbeitszeitcheck', 'Duration') }}</strong>
								<span>{{ formatHours(selectedEntry.durationHours || 0) }}h</span>
							</div>
							<div v-if="selectedEntry.breakDurationHours" class="timetracking-info-item">
								<strong>{{ $t('arbeitszeitcheck', 'Break Time') }}</strong>
								<span>{{ formatHours(selectedEntry.breakDurationHours) }}h</span>
							</div>
							<div class="timetracking-info-item">
								<strong>{{ $t('arbeitszeitcheck', 'Status') }}</strong>
								<span :class="getEntryStatusClass(selectedEntry.status)" class="timetracking-status-badge">
									{{ getEntryStatusText(selectedEntry.status) }}
								</span>
							</div>
							<div v-if="selectedEntry.projectCheckProjectId" class="timetracking-info-item">
								<strong>{{ $t('arbeitszeitcheck', 'Project') }}</strong>
								<span>{{ selectedEntry.projectCheckProjectId }}</span>
							</div>
						</div>
					</div>

					<!-- Description -->
					<div v-if="selectedEntry.description" class="timetracking-form-group">
						<label class="timetracking-form-label">
							{{ $t('arbeitszeitcheck', 'Description') }}
						</label>
						<div class="timetracking-textarea" style="min-height: auto; padding: calc(var(--default-grid-baseline) * 2); background: var(--color-background-dark);">
							{{ selectedEntry.description }}
						</div>
					</div>

					<!-- Actions -->
					<div class="timetracking-modal-actions">
						<NcButton
							v-if="selectedEntry.status !== 'pending_approval'"
							type="secondary"
							:aria-label="$t('arbeitszeitcheck', 'Request correction')"
							@click="requestCorrection(selectedEntry)"
						>
							{{ $t('arbeitszeitcheck', 'Request Correction') }}
						</NcButton>
						<NcButton
							type="secondary"
							:aria-label="$t('arbeitszeitcheck', 'Edit entry')"
							@click="editEntry(selectedEntry)"
							:disabled="selectedEntry.status === 'pending_approval'"
						>
							{{ $t('arbeitszeitcheck', 'Edit') }}
						</NcButton>
						<NcButton
							type="tertiary"
							@click="selectedEntry = null"
						>
							{{ $t('arbeitszeitcheck', 'Close') }}
						</NcButton>
					</div>
				</div>
			</NcModal>

			<!-- Add Manual Entry Modal (same as TimeEntries/Calendar) -->
			<NcModal 
				v-if="showAddManualEntryModal"
				:name="$t('arbeitszeitcheck', 'Add Manual Time Entry')"
				@close="showAddManualEntryModal = false"
				:show-close="true"
				:size="'large'"
			>
				<div class="timetracking-modal-content">
					<!-- Help Text -->
					<div class="timetracking-form-help-section">
						<p class="timetracking-form-help">
							{{ $t('arbeitszeitcheck', 'Enter your working times including breaks. The system will automatically check compliance with German labor law (ArbZG).') }}
						</p>
					</div>

					<!-- Date -->
					<div class="timetracking-form-group">
						<NcTextField
							v-model="newEntry.date"
							type="date"
							:label="$t('arbeitszeitcheck', 'Date')"
							:required="true"
							@input="calculateWorkingTime"
						/>
						<p class="timetracking-form-help">
							{{ $t('arbeitszeitcheck', 'Select the date for this time entry') }}
						</p>
					</div>

					<!-- Start and End Times -->
					<div class="timetracking-time-inputs">
						<div class="timetracking-form-group">
							<NcTextField
								v-model="newEntry.startTime"
								type="time"
								:label="$t('arbeitszeitcheck', 'Start Time')"
								:required="true"
								@input="calculateWorkingTime"
							/>
							<p class="timetracking-form-help">
								{{ $t('arbeitszeitcheck', 'When did you start working?') }}
							</p>
						</div>
						<div class="timetracking-form-group">
							<NcTextField
								v-model="newEntry.endTime"
								type="time"
								:label="$t('arbeitszeitcheck', 'End Time')"
								:required="true"
								@input="calculateWorkingTime"
							/>
							<p class="timetracking-form-help">
								{{ $t('arbeitszeitcheck', 'When did you finish working?') }}
							</p>
						</div>
					</div>

					<!-- Breaks Section -->
					<div class="timetracking-breaks-section">
						<h4 class="timetracking-section-title">
							{{ $t('arbeitszeitcheck', 'Breaks') }}
						</h4>
						<p class="timetracking-form-help">
							{{ $t('arbeitszeitcheck', 'Add all breaks you took during this working day. German labor law requires at least 30 minutes break after 6 hours, and 45 minutes after 9 hours of work.') }}
						</p>
						
						<div v-for="(breakItem, index) in newEntry.breaks" :key="index" class="timetracking-break-item">
							<div class="timetracking-break-header">
								<span class="timetracking-break-number">{{ $t('arbeitszeitcheck', 'Break {number}', { number: index + 1 }) }}</span>
								<NcButton
									v-if="newEntry.breaks.length > 1"
									type="tertiary"
									:aria-label="$t('arbeitszeitcheck', 'Remove break')"
									@click="removeBreak(index)"
								>
									{{ $t('arbeitszeitcheck', 'Remove') }}
								</NcButton>
							</div>
							<div class="timetracking-time-inputs">
								<div class="timetracking-form-group">
									<NcTextField
										v-model="breakItem.startTime"
										type="time"
										:label="$t('arbeitszeitcheck', 'Break Start')"
										:required="true"
										@input="calculateWorkingTime"
									/>
									<p class="timetracking-form-help">
										{{ $t('arbeitszeitcheck', 'When did this break start?') }}
									</p>
								</div>
								<div class="timetracking-form-group">
									<NcTextField
										v-model="breakItem.endTime"
										type="time"
										:label="$t('arbeitszeitcheck', 'Break End')"
										:required="true"
										@input="calculateWorkingTime"
									/>
									<p class="timetracking-form-help">
										{{ $t('arbeitszeitcheck', 'When did this break end?') }}
									</p>
								</div>
							</div>
						</div>

						<NcButton
							type="secondary"
							@click="addBreak"
							:aria-label="$t('arbeitszeitcheck', 'Add another break')"
						>
							{{ $t('arbeitszeitcheck', 'Add Break') }}
						</NcButton>
					</div>

					<!-- Calculated Summary -->
					<div v-if="calculatedSummary.totalHours > 0" class="timetracking-summary-box">
						<h4 class="timetracking-section-title">{{ $t('arbeitszeitcheck', 'Summary') }}</h4>
						<div class="timetracking-summary-row">
							<span class="timetracking-summary-label">{{ $t('arbeitszeitcheck', 'Total Time') }}:</span>
							<span class="timetracking-summary-value">{{ formatHours(calculatedSummary.totalHours) }}</span>
						</div>
						<div class="timetracking-summary-row">
							<span class="timetracking-summary-label">{{ $t('arbeitszeitcheck', 'Break Time') }}:</span>
							<span class="timetracking-summary-value">{{ formatHours(calculatedSummary.breakHours) }}</span>
						</div>
						<div class="timetracking-summary-row">
							<span class="timetracking-summary-label">{{ $t('arbeitszeitcheck', 'Working Hours') }}:</span>
							<span class="timetracking-summary-value timetracking-summary-value--highlight">
								{{ formatHours(calculatedSummary.workingHours) }}
							</span>
						</div>
					</div>

					<!-- Compliance Warnings -->
					<div v-if="complianceWarnings.length > 0" class="timetracking-compliance-warnings">
						<div
							v-for="(warning, index) in complianceWarnings"
							:key="index"
							class="break-warning break-warning--warning"
							role="alert"
						>
							<div class="break-warning__icon" aria-hidden="true">⚠️</div>
							<div class="break-warning__content">
								<h4 class="break-warning__title">{{ warning.title }}</h4>
								<p class="break-warning__message">{{ warning.message }}</p>
							</div>
						</div>
					</div>

					<!-- Description -->
					<div class="timetracking-form-group">
						<label for="manual-entry-description" class="timetracking-form-label">
							{{ $t('arbeitszeitcheck', 'Description (optional)') }}
						</label>
						<textarea
							id="manual-entry-description"
							v-model="newEntry.description"
							class="timetracking-textarea"
							rows="3"
							maxlength="500"
							:placeholder="$t('arbeitszeitcheck', 'e.g., Project work, meeting preparation, etc.')"
						/>
					</div>

					<!-- Actions -->
					<div class="timetracking-modal-actions">
						<NcButton 
							type="primary" 
							@click="saveManualEntry"
							:disabled="isSavingEntry || !isFormValid || complianceWarnings.some(w => w.severity === 'error')"
						>
							{{ isSavingEntry ? $t('arbeitszeitcheck', 'Saving...') : $t('arbeitszeitcheck', 'Save Entry') }}
						</NcButton>
						<NcButton type="secondary" @click="showAddManualEntryModal = false">
							{{ $t('arbeitszeitcheck', 'Cancel') }}
						</NcButton>
					</div>
				</div>
			</NcModal>

			<!-- Edit Entry Modal (same as TimeEntries/Calendar) -->
			<NcModal 
				v-if="editingEntry"
				:name="$t('arbeitszeitcheck', 'Edit Time Entry')"
				@close="editingEntry = null"
				:show-close="true"
				:size="'large'"
			>
				<div class="timetracking-modal-content">
					<!-- Help Text -->
					<div class="timetracking-form-help-section">
						<p class="timetracking-form-help">
							{{ $t('arbeitszeitcheck', 'Edit your working times including breaks. The system will automatically check compliance with German labor law (ArbZG).') }}
						</p>
					</div>

					<!-- Date -->
					<div class="timetracking-form-group">
						<NcTextField
							v-model="editEntryData.date"
							type="date"
							:label="$t('arbeitszeitcheck', 'Date')"
							:required="true"
							@input="calculateEditWorkingTime"
						/>
						<p class="timetracking-form-help">
							{{ $t('arbeitszeitcheck', 'Select the date for this time entry') }}
						</p>
					</div>

					<!-- Start and End Times -->
					<div class="timetracking-time-inputs">
						<div class="timetracking-form-group">
							<NcTextField
								v-model="editEntryData.startTime"
								type="time"
								:label="$t('arbeitszeitcheck', 'Start Time')"
								:required="true"
								@input="calculateEditWorkingTime"
							/>
							<p class="timetracking-form-help">
								{{ $t('arbeitszeitcheck', 'When did you start working?') }}
							</p>
						</div>
						<div class="timetracking-form-group">
							<NcTextField
								v-model="editEntryData.endTime"
								type="time"
								:label="$t('arbeitszeitcheck', 'End Time')"
								:required="true"
								@input="calculateEditWorkingTime"
							/>
							<p class="timetracking-form-help">
								{{ $t('arbeitszeitcheck', 'When did you finish working?') }}
							</p>
						</div>
					</div>

					<!-- Breaks Section -->
					<div class="timetracking-breaks-section">
						<h4 class="timetracking-section-title">
							{{ $t('arbeitszeitcheck', 'Breaks') }}
						</h4>
						<p class="timetracking-form-help">
							{{ $t('arbeitszeitcheck', 'Add all breaks you took during this working day. German labor law requires at least 30 minutes break after 6 hours, and 45 minutes after 9 hours of work.') }}
						</p>
						
						<div v-for="(breakItem, index) in editEntryData.breaks" :key="index" class="timetracking-break-item">
							<div class="timetracking-break-header">
								<span class="timetracking-break-number">{{ $t('arbeitszeitcheck', 'Break {number}', { number: index + 1 }) }}</span>
								<NcButton
									v-if="editEntryData.breaks.length > 1"
									type="tertiary"
									:aria-label="$t('arbeitszeitcheck', 'Remove break')"
									@click="removeEditBreak(index)"
								>
									{{ $t('arbeitszeitcheck', 'Remove') }}
								</NcButton>
							</div>
							<div class="timetracking-time-inputs">
								<div class="timetracking-form-group">
									<NcTextField
										v-model="breakItem.startTime"
										type="time"
										:label="$t('arbeitszeitcheck', 'Break Start')"
										:required="true"
										@input="calculateEditWorkingTime"
									/>
									<p class="timetracking-form-help">
										{{ $t('arbeitszeitcheck', 'When did this break start?') }}
									</p>
								</div>
								<div class="timetracking-form-group">
									<NcTextField
										v-model="breakItem.endTime"
										type="time"
										:label="$t('arbeitszeitcheck', 'Break End')"
										:required="true"
										@input="calculateEditWorkingTime"
									/>
									<p class="timetracking-form-help">
										{{ $t('arbeitszeitcheck', 'When did this break end?') }}
									</p>
								</div>
							</div>
						</div>

						<NcButton
							type="secondary"
							@click="addEditBreak"
							:aria-label="$t('arbeitszeitcheck', 'Add another break')"
						>
							{{ $t('arbeitszeitcheck', 'Add Break') }}
						</NcButton>
					</div>

					<!-- Calculated Summary -->
					<div v-if="editSummary.totalHours > 0" class="timetracking-summary-box">
						<h4 class="timetracking-section-title">{{ $t('arbeitszeitcheck', 'Summary') }}</h4>
						<div class="timetracking-summary-row">
							<span class="timetracking-summary-label">{{ $t('arbeitszeitcheck', 'Total Time') }}:</span>
							<span class="timetracking-summary-value">{{ formatHours(editSummary.totalHours) }}</span>
						</div>
						<div class="timetracking-summary-row">
							<span class="timetracking-summary-label">{{ $t('arbeitszeitcheck', 'Break Time') }}:</span>
							<span class="timetracking-summary-value">{{ formatHours(editSummary.breakHours) }}</span>
						</div>
						<div class="timetracking-summary-row">
							<span class="timetracking-summary-label">{{ $t('arbeitszeitcheck', 'Working Hours') }}:</span>
							<span class="timetracking-summary-value timetracking-summary-value--highlight">
								{{ formatHours(editSummary.workingHours) }}
							</span>
						</div>
					</div>

					<!-- Compliance Warnings -->
					<div v-if="editComplianceWarnings.length > 0" class="timetracking-compliance-warnings">
						<div
							v-for="(warning, index) in editComplianceWarnings"
							:key="index"
							class="break-warning break-warning--warning"
							role="alert"
						>
							<div class="break-warning__icon" aria-hidden="true">⚠️</div>
							<div class="break-warning__content">
								<h4 class="break-warning__title">{{ warning.title }}</h4>
								<p class="break-warning__message">{{ warning.message }}</p>
							</div>
						</div>
					</div>

					<!-- Description -->
					<div class="timetracking-form-group">
						<label for="edit-entry-description" class="timetracking-form-label">
							{{ $t('arbeitszeitcheck', 'Description (optional)') }}
						</label>
						<textarea
							id="edit-entry-description"
							v-model="editEntryData.description"
							class="timetracking-textarea"
							rows="3"
							maxlength="500"
							:placeholder="$t('arbeitszeitcheck', 'e.g., Project work, meeting preparation, etc.')"
						/>
					</div>

					<!-- Actions -->
					<div class="timetracking-modal-actions">
						<NcButton 
							type="primary" 
							@click="saveEditedEntry"
							:disabled="isSavingEdit || !isEditFormValid || editComplianceWarnings.some(w => w.severity === 'error')"
						>
							{{ isSavingEdit ? $t('arbeitszeitcheck', 'Saving...') : $t('arbeitszeitcheck', 'Save Changes') }}
						</NcButton>
						<NcButton type="secondary" @click="editingEntry = null">
							{{ $t('arbeitszeitcheck', 'Cancel') }}
						</NcButton>
					</div>
				</div>
			</NcModal>

			<!-- Correction Request Modal (same as TimeEntries/Calendar) -->
			<NcModal 
				v-if="correctionEntry"
				:name="$t('arbeitszeitcheck', 'Request Time Entry Correction')"
				@close="correctionEntry = null"
				:show-close="true"
				:size="'large'"
			>
				<div class="timetracking-modal-content">
					<!-- Help Text -->
					<div class="timetracking-form-help-section">
						<p class="timetracking-form-help">
							{{ $t('arbeitszeitcheck', 'Request a correction for this time entry. Your manager will review and approve or reject your request.') }}
						</p>
					</div>

					<!-- Current Entry Info -->
					<div class="timetracking-info-grid">
						<div class="timetracking-info-item">
							<strong>{{ $t('arbeitszeitcheck', 'Current Date') }}</strong>
							<span>{{ formatDate(correctionEntry.startTime) }}</span>
						</div>
						<div class="timetracking-info-item">
							<strong>{{ $t('arbeitszeitcheck', 'Current Start Time') }}</strong>
							<span>{{ formatTime(correctionEntry.startTime) }}</span>
						</div>
						<div class="timetracking-info-item" v-if="correctionEntry.endTime">
							<strong>{{ $t('arbeitszeitcheck', 'Current End Time') }}</strong>
							<span>{{ formatTime(correctionEntry.endTime) }}</span>
						</div>
						<div class="timetracking-info-item">
							<strong>{{ $t('arbeitszeitcheck', 'Current Duration') }}</strong>
							<span>{{ formatHours(correctionEntry.durationHours || 0) }}h</span>
						</div>
					</div>

					<!-- Proposed Changes -->
					<h4 class="timetracking-section-title">{{ $t('arbeitszeitcheck', 'Proposed Changes') }}</h4>

					<!-- Date -->
					<div class="timetracking-form-group">
						<NcTextField
							v-model="correctionData.date"
							type="date"
							:label="$t('arbeitszeitcheck', 'Date')"
							:required="true"
							@input="calculateCorrectionWorkingTime"
						/>
						<p class="timetracking-form-help">
							{{ $t('arbeitszeitcheck', 'Select the corrected date') }}
						</p>
					</div>

					<!-- Start and End Times -->
					<div class="timetracking-time-inputs">
						<div class="timetracking-form-group">
							<NcTextField
								v-model="correctionData.startTime"
								type="time"
								:label="$t('arbeitszeitcheck', 'Start Time')"
								:required="true"
								@input="calculateCorrectionWorkingTime"
							/>
							<p class="timetracking-form-help">
								{{ $t('arbeitszeitcheck', 'Corrected start time') }}
							</p>
						</div>
						<div class="timetracking-form-group">
							<NcTextField
								v-model="correctionData.endTime"
								type="time"
								:label="$t('arbeitszeitcheck', 'End Time')"
								:required="true"
								@input="calculateCorrectionWorkingTime"
							/>
							<p class="timetracking-form-help">
								{{ $t('arbeitszeitcheck', 'Corrected end time') }}
							</p>
						</div>
					</div>

					<!-- Breaks Section -->
					<div class="timetracking-breaks-section">
						<h4 class="timetracking-section-title">
							{{ $t('arbeitszeitcheck', 'Breaks') }}
						</h4>
						<p class="timetracking-form-help">
							{{ $t('arbeitszeitcheck', 'Add all breaks for the corrected time entry.') }}
						</p>
						
						<div v-for="(breakItem, index) in correctionData.breaks" :key="index" class="timetracking-break-item">
							<div class="timetracking-break-header">
								<span class="timetracking-break-number">{{ $t('arbeitszeitcheck', 'Break {number}', { number: index + 1 }) }}</span>
								<NcButton
									v-if="correctionData.breaks.length > 1"
									type="tertiary"
									:aria-label="$t('arbeitszeitcheck', 'Remove break')"
									@click="removeCorrectionBreak(index)"
								>
									{{ $t('arbeitszeitcheck', 'Remove') }}
								</NcButton>
							</div>
							<div class="timetracking-time-inputs">
								<div class="timetracking-form-group">
									<NcTextField
										v-model="breakItem.startTime"
										type="time"
										:label="$t('arbeitszeitcheck', 'Break Start')"
										:required="true"
										@input="calculateCorrectionWorkingTime"
									/>
								</div>
								<div class="timetracking-form-group">
									<NcTextField
										v-model="breakItem.endTime"
										type="time"
										:label="$t('arbeitszeitcheck', 'Break End')"
										:required="true"
										@input="calculateCorrectionWorkingTime"
									/>
								</div>
							</div>
						</div>

						<NcButton
							type="secondary"
							@click="addCorrectionBreak"
							:aria-label="$t('arbeitszeitcheck', 'Add another break')"
						>
							{{ $t('arbeitszeitcheck', 'Add Break') }}
						</NcButton>
					</div>

					<!-- Calculated Summary -->
					<div v-if="correctionSummary.totalHours > 0" class="timetracking-summary-box">
						<h4 class="timetracking-section-title">{{ $t('arbeitszeitcheck', 'Summary') }}</h4>
						<div class="timetracking-summary-row">
							<span class="timetracking-summary-label">{{ $t('arbeitszeitcheck', 'Total Time') }}:</span>
							<span class="timetracking-summary-value">{{ formatHours(correctionSummary.totalHours) }}</span>
						</div>
						<div class="timetracking-summary-row">
							<span class="timetracking-summary-label">{{ $t('arbeitszeitcheck', 'Break Time') }}:</span>
							<span class="timetracking-summary-value">{{ formatHours(correctionSummary.breakHours) }}</span>
						</div>
						<div class="timetracking-summary-row">
							<span class="timetracking-summary-label">{{ $t('arbeitszeitcheck', 'Working Hours') }}:</span>
							<span class="timetracking-summary-value timetracking-summary-value--highlight">
								{{ formatHours(correctionSummary.workingHours) }}
							</span>
						</div>
					</div>

					<!-- Compliance Warnings -->
					<div v-if="correctionComplianceWarnings.length > 0" class="timetracking-compliance-warnings">
						<div
							v-for="(warning, index) in correctionComplianceWarnings"
							:key="index"
							class="break-warning break-warning--warning"
							role="alert"
						>
							<div class="break-warning__icon" aria-hidden="true">⚠️</div>
							<div class="break-warning__content">
								<h4 class="break-warning__title">{{ warning.title }}</h4>
								<p class="break-warning__message">{{ warning.message }}</p>
							</div>
						</div>
					</div>

					<!-- Justification -->
					<div class="timetracking-form-group">
						<label for="correction-justification" class="timetracking-form-label required">
							{{ $t('arbeitszeitcheck', 'Reason for Correction') }}
						</label>
						<textarea
							id="correction-justification"
							v-model="correctionData.justification"
							class="timetracking-textarea"
							rows="3"
							maxlength="500"
							:placeholder="$t('arbeitszeitcheck', 'Please explain why this correction is needed...')"
							required
						/>
						<p class="timetracking-form-help">
							{{ $t('arbeitszeitcheck', 'Explain why you need to correct this time entry') }}
						</p>
					</div>

					<!-- Description (optional) -->
					<div class="timetracking-form-group">
						<label for="correction-description" class="timetracking-form-label">
							{{ $t('arbeitszeitcheck', 'Description (optional)') }}
						</label>
						<textarea
							id="correction-description"
							v-model="correctionData.description"
							class="timetracking-textarea"
							rows="3"
							maxlength="500"
							:placeholder="$t('arbeitszeitcheck', 'Additional notes for the corrected entry')"
						/>
					</div>

					<!-- Actions -->
					<div class="timetracking-modal-actions">
						<NcButton 
							type="primary" 
							@click="submitCorrectionRequest"
							:disabled="isSubmittingCorrection || !isCorrectionFormValid || correctionComplianceWarnings.some(w => w.severity === 'error')"
						>
							{{ isSubmittingCorrection ? $t('arbeitszeitcheck', 'Submitting...') : $t('arbeitszeitcheck', 'Submit Request') }}
						</NcButton>
						<NcButton type="secondary" @click="correctionEntry = null">
							{{ $t('arbeitszeitcheck', 'Cancel') }}
						</NcButton>
					</div>
				</div>
			</NcModal>
	</div><!-- .timetracking-timeline -->
</template>

<script>
import { NcButton, NcTextField, NcLoadingIcon, NcEmptyContent, NcModal } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { formatDateGerman, parseGermanDate } from '../utils/dateUtils.js'
import { getUserFriendlyError } from '../utils/errorMessages.js'

export default {
	name: 'Timeline',
	components: {
		NcButton,
		NcLoadingIcon,
		NcEmptyContent,
		NcModal,
		NcTextField
	},
	setup() {
		const route = useRoute()
		const router = useRouter()
		return {
			$route: route,
			$router: router
		}
	},
	data() {
		const today = new Date()
		const weekAgo = new Date(today)
		weekAgo.setDate(weekAgo.getDate() - 7)

		return {
			startDate: weekAgo.toISOString().split('T')[0],
			endDate: today.toISOString().split('T')[0],
			maxDate: today.toISOString().split('T')[0],
			isLoading: false,
			timeEntries: [],
			timelineDays: [],
			selectedPeriod: null,
			selectedEntry: null,
			showAddManualEntryModal: false,
			isSavingEntry: false,
			isSavingEdit: false,
			isExporting: false,
			editingEntry: null,
			correctionEntry: null,
			isSubmittingCorrection: false,
			newEntry: {
				date: new Date().toISOString().split('T')[0],
				startTime: '09:00',
				endTime: '17:00',
				breaks: [
					{ startTime: '12:00', endTime: '12:30' }
				],
				description: ''
			},
			calculatedSummary: {
				totalHours: 0,
				breakHours: 0,
				workingHours: 0
			},
			complianceWarnings: [],
			editEntryData: {
				date: '',
				startTime: '',
				endTime: '',
				breaks: [
					{ startTime: '12:00', endTime: '12:30' }
				],
				description: ''
			},
			editSummary: {
				totalHours: 0,
				breakHours: 0,
				workingHours: 0
			},
			editComplianceWarnings: [],
			correctionData: {
				justification: '',
				date: '',
				startTime: '',
				endTime: '',
				breaks: [
					{ startTime: '12:00', endTime: '12:30' }
				],
				description: ''
			},
			correctionSummary: {
				totalHours: 0,
				breakHours: 0,
				workingHours: 0
			},
			correctionComplianceWarnings: []
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
		async loadTimeline() {
			// Convert dates to ISO format for API
			let startDateIso, endDateIso
			
			// Check if dates are in ISO format (YYYY-MM-DD) or German format (dd.mm.yyyy)
			if (this.startDate.match(/^\d{4}-\d{2}-\d{2}$/)) {
				startDateIso = this.startDate
			} else {
				startDateIso = parseGermanDate(this.startDate)
			}
			
			if (this.endDate.match(/^\d{4}-\d{2}-\d{2}$/)) {
				endDateIso = this.endDate
			} else {
				endDateIso = parseGermanDate(this.endDate)
			}

			if (!startDateIso || !endDateIso) {
				this.showNotification(this.$t('arbeitszeitcheck', 'Please enter valid dates'), 'warning')
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
				const userMessage = getUserFriendlyError(error, this.$t.bind(this))
				this.showNotification(userMessage, 'error')
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
			// For timeline entries (visual display)
			if (status === 'active' || status === 'break' || status === 'pending_approval' || status === 'completed') {
				return {
					'timetracking-timeline__entry--active': status === 'active',
					'timetracking-timeline__entry--completed': status === 'completed',
					'timetracking-timeline__entry--pending': status === 'pending_approval',
					'timetracking-timeline__entry--break': status === 'break'
				}
			}
			// For status badges in modals and tables
			return {
				'timetracking-status-badge': true,
				'timetracking-status--success': status === 'completed',
				'timetracking-status--warning': status === 'pending_approval',
				'timetracking-status--error': status === 'rejected',
				'timetracking-status--inactive': !status || (status !== 'completed' && status !== 'pending_approval' && status !== 'rejected')
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
		},
		openAddManualEntryModal() {
			// Reset form
			const today = new Date()
			this.newEntry = {
				date: today.toISOString().split('T')[0],
				startTime: '09:00',
				endTime: '17:00',
				breaks: [
					{ startTime: '12:00', endTime: '12:30' }
				],
				description: ''
			}
			this.calculatedSummary = {
				totalHours: 0,
				breakHours: 0,
				workingHours: 0
			}
			this.complianceWarnings = []
			this.showAddManualEntryModal = true
		},
		addBreak() {
			this.newEntry.breaks.push({ startTime: '12:00', endTime: '12:30' })
			this.calculateWorkingTime()
		},
		removeBreak(index) {
			this.newEntry.breaks.splice(index, 1)
			this.calculateWorkingTime()
		},
		calculateWorkingTime() {
			// Reset summary
			this.calculatedSummary = {
				totalHours: 0,
				breakHours: 0,
				workingHours: 0
			}
			this.complianceWarnings = []

			if (!this.newEntry.date || !this.newEntry.startTime || !this.newEntry.endTime) {
				return
			}

			try {
				// Parse start and end times
				const dateStr = this.newEntry.date
				const startDateTime = new Date(`${dateStr}T${this.newEntry.startTime}`)
				const endDateTime = new Date(`${dateStr}T${this.newEntry.endTime}`)

				if (endDateTime <= startDateTime) {
					this.complianceWarnings.push({
						severity: 'error',
						title: this.$t('arbeitszeitcheck', 'Invalid Times'),
						message: this.$t('arbeitszeitcheck', 'End time must be after start time')
					})
					return
				}

				// Calculate total duration
				const totalMs = endDateTime.getTime() - startDateTime.getTime()
				const totalHours = totalMs / (1000 * 60 * 60)
				this.calculatedSummary.totalHours = totalHours

				// Calculate break duration
				let breakHours = 0
				for (const breakItem of this.newEntry.breaks) {
					if (breakItem.startTime && breakItem.endTime) {
						const breakStart = new Date(`${dateStr}T${breakItem.startTime}`)
						const breakEnd = new Date(`${dateStr}T${breakItem.endTime}`)
						
						if (breakEnd <= breakStart) {
							this.complianceWarnings.push({
								severity: 'error',
								title: this.$t('arbeitszeitcheck', 'Invalid Break Times'),
								message: this.$t('arbeitszeitcheck', 'Break end time must be after break start time')
							})
							continue
						}

						if (breakStart < startDateTime || breakEnd > endDateTime) {
							this.complianceWarnings.push({
								severity: 'error',
								title: this.$t('arbeitszeitcheck', 'Break Outside Working Hours'),
								message: this.$t('arbeitszeitcheck', 'Break times must be within your working hours')
							})
							continue
						}

						const breakMs = breakEnd.getTime() - breakStart.getTime()
						breakHours += breakMs / (1000 * 60 * 60)
					}
				}

				this.calculatedSummary.breakHours = breakHours
				this.calculatedSummary.workingHours = Math.max(0, totalHours - breakHours)

				// Check compliance with German labor law (ArbZG)
				const workingHours = this.calculatedSummary.workingHours

				// Check maximum working hours (8 hours normal, 10 hours max)
				if (workingHours > 10) {
					this.complianceWarnings.push({
						severity: 'error',
						title: this.$t('arbeitszeitcheck', 'Maximum Working Hours Exceeded'),
						message: this.$t('arbeitszeitcheck', 'German labor law (ArbZG) allows a maximum of 10 hours per day. You have entered {hours} hours.', { hours: workingHours.toFixed(2) })
					})
				} else if (workingHours > 8) {
					this.complianceWarnings.push({
						severity: 'warning',
						title: this.$t('arbeitszeitcheck', 'Extended Working Hours'),
						message: this.$t('arbeitszeitcheck', 'You have worked {hours} hours. The normal maximum is 8 hours per day. Extended hours must be compensated within 6 months.', { hours: workingHours.toFixed(2) })
					})
				}

				// Check break requirements (ArbZG)
				// After 9 hours: need at least 45 minutes
				if (workingHours >= 9 && breakHours < 0.75) {
					this.complianceWarnings.push({
						severity: 'error',
						title: this.$t('arbeitszeitcheck', 'Insufficient Break Time'),
						message: this.$t('arbeitszeitcheck', 'German labor law requires at least 45 minutes break after 9 hours of work. You have only {minutes} minutes.', { minutes: Math.round(breakHours * 60) })
					})
				}
				// After 6 hours: need at least 30 minutes
				else if (workingHours >= 6 && breakHours < 0.5) {
					this.complianceWarnings.push({
						severity: 'error',
						title: this.$t('arbeitszeitcheck', 'Insufficient Break Time'),
						message: this.$t('arbeitszeitcheck', 'German labor law requires at least 30 minutes break after 6 hours of work. You have only {minutes} minutes.', { minutes: Math.round(breakHours * 60) })
					})
				}

			} catch (error) {
				console.error('Error calculating working time:', error)
			}
		},
		get isFormValid() {
			return !!(
				this.newEntry.date &&
				this.newEntry.startTime &&
				this.newEntry.endTime &&
				this.calculatedSummary.workingHours > 0
			)
		},
		async saveManualEntry() {
			if (!this.newEntry.date || !this.newEntry.startTime || !this.newEntry.endTime) {
				this.showNotification(this.$t('arbeitszeitcheck', 'Date, start time, and end time are required'), 'error')
				return
			}

			// Check for errors in compliance warnings
			if (this.complianceWarnings.some(w => w.severity === 'error')) {
				this.showNotification(this.$t('arbeitszeitcheck', 'Please fix the errors before saving'), 'error')
				return
			}

			this.isSavingEntry = true
			try {
				// Build request with start/end times and breaks
				const dateStr = this.newEntry.date
				const startDateTime = `${dateStr}T${this.newEntry.startTime}:00`
				const endDateTime = `${dateStr}T${this.newEntry.endTime}:00`

				// Find the longest break (or first one if all are equal)
				// Note: Backend currently supports one break, so we send the longest one
				let breakStartTime = null
				let breakEndTime = null
				let longestBreakDuration = 0
				
				for (const breakItem of this.newEntry.breaks) {
					if (breakItem.startTime && breakItem.endTime) {
						const breakStart = new Date(`${dateStr}T${breakItem.startTime}`)
						const breakEnd = new Date(`${dateStr}T${breakItem.endTime}`)
						const breakDuration = breakEnd.getTime() - breakStart.getTime()
						
						if (breakDuration > longestBreakDuration) {
							longestBreakDuration = breakDuration
							breakStartTime = `${dateStr}T${breakItem.startTime}:00`
							breakEndTime = `${dateStr}T${breakItem.endTime}:00`
						}
					}
				}

				const response = await axios.post(
					generateUrl('/apps/arbeitszeitcheck/api/time-entries'),
					{
						date: dateStr,
						startTime: startDateTime,
						endTime: endDateTime,
						breakStartTime: breakStartTime,
						breakEndTime: breakEndTime,
						description: this.newEntry.description || null
					}
				)

				if (response.data.success) {
					this.showNotification(this.$t('arbeitszeitcheck', 'Time entry created successfully'), 'success')
					this.showAddManualEntryModal = false
					this.newEntry = {
						date: new Date().toISOString().split('T')[0],
						startTime: '09:00',
						endTime: '17:00',
						breaks: [
							{ startTime: '12:00', endTime: '12:30' }
						],
						description: ''
					}
					this.calculatedSummary = {
						totalHours: 0,
						breakHours: 0,
						workingHours: 0
					}
					this.complianceWarnings = []
					this.loadTimeline()
				} else {
					throw new Error(response.data.error || this.$t('arbeitszeitcheck', 'Failed to create entry'))
				}
			} catch (error) {
				const userMessage = getUserFriendlyError(error, this.$t.bind(this))
				this.showNotification(userMessage, 'error')
			} finally {
				this.isSavingEntry = false
			}
		},
		async exportEntries() {
			this.isExporting = true
			try {
				const exportUrl = generateUrl('/apps/arbeitszeitcheck/export/time-entries?format=csv')
				window.location.href = exportUrl
				this.showNotification(this.$t('arbeitszeitcheck', 'Export started'), 'success')
			} catch (error) {
				const userMessage = getUserFriendlyError(error, this.$t.bind(this))
				this.showNotification(userMessage, 'error')
			} finally {
				setTimeout(() => {
					this.isExporting = false
				}, 1000)
			}
		},
		editEntry(entry) {
			this.editingEntry = entry
			// Pre-populate form with entry data
			if (entry.startTime) {
				const startDate = new Date(entry.startTime)
				this.editEntryData.date = startDate.toISOString().split('T')[0]
				this.editEntryData.startTime = startDate.toTimeString().slice(0, 5)
			} else {
				this.editEntryData.date = ''
				this.editEntryData.startTime = ''
			}
			if (entry.endTime) {
				const endDate = new Date(entry.endTime)
				this.editEntryData.endTime = endDate.toTimeString().slice(0, 5)
			} else {
				// If no end time, set a default 8 hours later
				if (this.editEntryData.startTime) {
					const start = new Date(`2000-01-01T${this.editEntryData.startTime}`)
					start.setHours(start.getHours() + 8)
					this.editEntryData.endTime = start.toTimeString().slice(0, 5)
				} else {
					this.editEntryData.endTime = ''
				}
			}
			// Pre-populate breaks if available
			if (entry.breakStartTime && entry.breakEndTime) {
				const breakStart = new Date(entry.breakStartTime)
				const breakEnd = new Date(entry.breakEndTime)
				this.editEntryData.breaks = [{
					startTime: breakStart.toTimeString().slice(0, 5),
					endTime: breakEnd.toTimeString().slice(0, 5)
				}]
			} else {
				this.editEntryData.breaks = [
					{ startTime: '12:00', endTime: '12:30' }
				]
			}
			this.editEntryData.description = entry.description || ''
			// Calculate working time after populating
			this.$nextTick(() => {
				this.calculateEditWorkingTime()
			})
		},
		addEditBreak() {
			this.editEntryData.breaks.push({ startTime: '12:00', endTime: '12:30' })
			this.calculateEditWorkingTime()
		},
		removeEditBreak(index) {
			this.editEntryData.breaks.splice(index, 1)
			this.calculateEditWorkingTime()
		},
		calculateEditWorkingTime() {
			// Reset summary
			this.editSummary = {
				totalHours: 0,
				breakHours: 0,
				workingHours: 0
			}
			this.editComplianceWarnings = []

			if (!this.editEntryData.date || !this.editEntryData.startTime || !this.editEntryData.endTime) {
				return
			}

			try {
				// Parse date and times
				const dateStr = this.editEntryData.date
				const startTimeStr = this.editEntryData.startTime
				const endTimeStr = this.editEntryData.endTime

				const startDateTime = new Date(`${dateStr}T${startTimeStr}`)
				const endDateTime = new Date(`${dateStr}T${endTimeStr}`)

				if (endDateTime <= startDateTime) {
					// End time is on next day
					endDateTime.setDate(endDateTime.getDate() + 1)
				}

				// Calculate total time in hours
				const totalMs = endDateTime - startDateTime
				const totalHours = totalMs / (1000 * 60 * 60)

				// Calculate break time
				let breakHours = 0
				for (const breakItem of this.editEntryData.breaks) {
					if (breakItem.startTime && breakItem.endTime) {
						const breakStart = new Date(`${dateStr}T${breakItem.startTime}`)
						const breakEnd = new Date(`${dateStr}T${breakItem.endTime}`)
						if (breakEnd <= breakStart) {
							breakEnd.setDate(breakEnd.getDate() + 1)
						}
						const breakMs = breakEnd - breakStart
						breakHours += breakMs / (1000 * 60 * 60)
					}
				}

				// Calculate working hours
				const workingHours = Math.max(0, totalHours - breakHours)

				this.editSummary = {
					totalHours,
					breakHours,
					workingHours
				}

				// Compliance checks (German labor law - ArbZG)
				if (workingHours > 10) {
					this.editComplianceWarnings.push({
						severity: 'error',
						title: this.$t('arbeitszeitcheck', 'Maximum Working Hours Exceeded'),
						message: this.$t('arbeitszeitcheck', 'Working hours exceed the legal maximum of 10 hours per day. This requires special approval.')
					})
				} else if (workingHours > 8) {
					this.editComplianceWarnings.push({
						severity: 'warning',
						title: this.$t('arbeitszeitcheck', 'Extended Working Hours'),
						message: this.$t('arbeitszeitcheck', 'Working hours exceed the normal 8 hours per day. This requires special approval.')
					})
				}

				// Break requirements
				if (workingHours >= 9) {
					if (breakHours < 0.75) { // 45 minutes
						this.editComplianceWarnings.push({
							severity: 'error',
							title: this.$t('arbeitszeitcheck', 'Insufficient Break Time'),
							message: this.$t('arbeitszeitcheck', 'After 9 hours of work, at least 45 minutes of break time is required by German labor law (ArbZG).')
						})
					}
				} else if (workingHours >= 6) {
					if (breakHours < 0.5) { // 30 minutes
						this.editComplianceWarnings.push({
							severity: 'error',
							title: this.$t('arbeitszeitcheck', 'Insufficient Break Time'),
							message: this.$t('arbeitszeitcheck', 'After 6 hours of work, at least 30 minutes of break time is required by German labor law (ArbZG).')
						})
					}
				}
			} catch (error) {
				console.error('Error calculating edit working time:', error)
			}
		},
		get isEditFormValid() {
			return !!(
				this.editEntryData.date &&
				this.editEntryData.startTime &&
				this.editEntryData.endTime &&
				this.editSummary.workingHours > 0
			)
		},
		async saveEditedEntry() {
			if (!this.editingEntry || !this.isEditFormValid) {
				this.showNotification(this.$t('arbeitszeitcheck', 'Please fill in all required fields'), 'error')
				return
			}

			// Check for critical compliance errors
			if (this.editComplianceWarnings.some(w => w.severity === 'error')) {
				this.showNotification(this.$t('arbeitszeitcheck', 'Please fix compliance issues before saving'), 'error')
				return
			}

			this.isSavingEdit = true
			try {
				// Build datetime strings
				const startDateTime = `${this.editEntryData.date}T${this.editEntryData.startTime}:00`
				const endDateTime = `${this.editEntryData.date}T${this.editEntryData.endTime}:00`

				// Find longest break (backend only supports one break)
				let longestBreak = null
				let longestBreakDuration = 0
				for (const breakItem of this.editEntryData.breaks) {
					if (breakItem.startTime && breakItem.endTime) {
						const breakStart = new Date(`${this.editEntryData.date}T${breakItem.startTime}`)
						const breakEnd = new Date(`${this.editEntryData.date}T${breakItem.endTime}`)
						if (breakEnd <= breakStart) {
							breakEnd.setDate(breakEnd.getDate() + 1)
						}
						const duration = breakEnd - breakStart
						if (duration > longestBreakDuration) {
							longestBreakDuration = duration
							longestBreak = breakItem
						}
					}
				}

				const params = {
					startTime: startDateTime,
					endTime: endDateTime
				}

				if (longestBreak) {
					params.breakStartTime = `${this.editEntryData.date}T${longestBreak.startTime}:00`
					params.breakEndTime = `${this.editEntryData.date}T${longestBreak.endTime}:00`
				}

				if (this.editEntryData.description) {
					params.description = this.editEntryData.description
				}

				const response = await axios.put(
					generateUrl(`/apps/arbeitszeitcheck/api/time-entries/${this.editingEntry.id}`),
					params
				)

				if (response.data.success) {
					this.showNotification(this.$t('arbeitszeitcheck', 'Time entry updated successfully'), 'success')
					this.editingEntry = null
					this.editEntryData = {
						date: '',
						startTime: '',
						endTime: '',
						breaks: [
							{ startTime: '12:00', endTime: '12:30' }
						],
						description: ''
					}
					this.editSummary = {
						totalHours: 0,
						breakHours: 0,
						workingHours: 0
					}
					this.editComplianceWarnings = []
					this.loadTimeline()
				} else {
					throw new Error(response.data.error || this.$t('arbeitszeitcheck', 'Failed to update entry'))
				}
			} catch (error) {
				const userMessage = getUserFriendlyError(error, this.$t.bind(this))
				this.showNotification(userMessage, 'error')
			} finally {
				this.isSavingEdit = false
			}
		},
		requestCorrection(entry) {
			this.correctionEntry = entry
			// Reset correction data
			this.correctionData = {
				justification: '',
				date: '',
				startTime: '',
				endTime: '',
				breaks: [
					{ startTime: '12:00', endTime: '12:30' }
				],
				description: ''
			}
			// Pre-populate with current values if available
			if (entry.startTime) {
				const startDate = new Date(entry.startTime)
				this.correctionData.date = startDate.toISOString().split('T')[0]
				this.correctionData.startTime = startDate.toTimeString().slice(0, 5)
			}
			if (entry.endTime) {
				const endDate = new Date(entry.endTime)
				this.correctionData.endTime = endDate.toTimeString().slice(0, 5)
			}
			// If no end time, set a default 8 hours later
			if (!this.correctionData.endTime && this.correctionData.startTime) {
				const start = new Date(`2000-01-01T${this.correctionData.startTime}`)
				start.setHours(start.getHours() + 8)
				this.correctionData.endTime = start.toTimeString().slice(0, 5)
			}
			// Pre-populate breaks if available
			if (entry.breakStartTime && entry.breakEndTime) {
				const breakStart = new Date(entry.breakStartTime)
				const breakEnd = new Date(entry.breakEndTime)
				this.correctionData.breaks = [{
					startTime: breakStart.toTimeString().slice(0, 5),
					endTime: breakEnd.toTimeString().slice(0, 5)
				}]
			}
			if (entry.description) {
				this.correctionData.description = entry.description
			}
			// Calculate working time after populating
			this.$nextTick(() => {
				this.calculateCorrectionWorkingTime()
			})
		},
		addCorrectionBreak() {
			this.correctionData.breaks.push({ startTime: '12:00', endTime: '12:30' })
			this.calculateCorrectionWorkingTime()
		},
		removeCorrectionBreak(index) {
			this.correctionData.breaks.splice(index, 1)
			this.calculateCorrectionWorkingTime()
		},
		calculateCorrectionWorkingTime() {
			// Reset summary
			this.correctionSummary = {
				totalHours: 0,
				breakHours: 0,
				workingHours: 0
			}
			this.correctionComplianceWarnings = []

			if (!this.correctionData.date || !this.correctionData.startTime || !this.correctionData.endTime) {
				return
			}

			try {
				// Parse date and times
				const dateStr = this.correctionData.date
				const startTimeStr = this.correctionData.startTime
				const endTimeStr = this.correctionData.endTime

				const startDateTime = new Date(`${dateStr}T${startTimeStr}`)
				const endDateTime = new Date(`${dateStr}T${endTimeStr}`)

				if (endDateTime <= startDateTime) {
					// End time is on next day
					endDateTime.setDate(endDateTime.getDate() + 1)
				}

				// Calculate total time in hours
				const totalMs = endDateTime - startDateTime
				const totalHours = totalMs / (1000 * 60 * 60)

				// Calculate break time
				let breakHours = 0
				for (const breakItem of this.correctionData.breaks) {
					if (breakItem.startTime && breakItem.endTime) {
						const breakStart = new Date(`${dateStr}T${breakItem.startTime}`)
						const breakEnd = new Date(`${dateStr}T${breakItem.endTime}`)
						if (breakEnd <= breakStart) {
							breakEnd.setDate(breakEnd.getDate() + 1)
						}
						const breakMs = breakEnd - breakStart
						breakHours += breakMs / (1000 * 60 * 60)
					}
				}

				// Calculate working hours
				const workingHours = Math.max(0, totalHours - breakHours)

				this.correctionSummary = {
					totalHours,
					breakHours,
					workingHours
				}

				// Compliance checks (German labor law - ArbZG)
				if (workingHours > 10) {
					this.correctionComplianceWarnings.push({
						severity: 'error',
						title: this.$t('arbeitszeitcheck', 'Maximum Working Hours Exceeded'),
						message: this.$t('arbeitszeitcheck', 'Working hours exceed the legal maximum of 10 hours per day. This requires special approval.')
					})
				} else if (workingHours > 8) {
					this.correctionComplianceWarnings.push({
						severity: 'warning',
						title: this.$t('arbeitszeitcheck', 'Extended Working Hours'),
						message: this.$t('arbeitszeitcheck', 'Working hours exceed the normal 8 hours per day. This requires special approval.')
					})
				}

				// Break requirements
				if (workingHours >= 9) {
					if (breakHours < 0.75) { // 45 minutes
						this.correctionComplianceWarnings.push({
							severity: 'error',
							title: this.$t('arbeitszeitcheck', 'Insufficient Break Time'),
							message: this.$t('arbeitszeitcheck', 'After 9 hours of work, at least 45 minutes of break time is required by German labor law (ArbZG).')
						})
					}
				} else if (workingHours >= 6) {
					if (breakHours < 0.5) { // 30 minutes
						this.correctionComplianceWarnings.push({
							severity: 'error',
							title: this.$t('arbeitszeitcheck', 'Insufficient Break Time'),
							message: this.$t('arbeitszeitcheck', 'After 6 hours of work, at least 30 minutes of break time is required by German labor law (ArbZG).')
						})
					}
				}
			} catch (error) {
				console.error('Error calculating correction working time:', error)
			}
		},
		get isCorrectionFormValid() {
			return !!(
				this.correctionData.justification &&
				this.correctionData.date &&
				this.correctionData.startTime &&
				this.correctionData.endTime &&
				this.correctionSummary.workingHours > 0
			)
		},
		async submitCorrectionRequest() {
			if (!this.correctionEntry || !this.correctionData.justification) {
				this.showNotification(this.$t('arbeitszeitcheck', 'Reason for correction is required'), 'error')
				return
			}

			if (!this.isCorrectionFormValid) {
				this.showNotification(this.$t('arbeitszeitcheck', 'Please fill in all required fields'), 'error')
				return
			}

			// Check for critical compliance errors
			if (this.correctionComplianceWarnings.some(w => w.severity === 'error')) {
				this.showNotification(this.$t('arbeitszeitcheck', 'Please fix compliance issues before submitting'), 'error')
				return
			}

			this.isSubmittingCorrection = true
			try {
				// Build datetime strings
				const startDateTime = `${this.correctionData.date}T${this.correctionData.startTime}:00`
				const endDateTime = `${this.correctionData.date}T${this.correctionData.endTime}:00`

				// Find longest break (backend only supports one break)
				let longestBreak = null
				let longestBreakDuration = 0
				for (const breakItem of this.correctionData.breaks) {
					if (breakItem.startTime && breakItem.endTime) {
						const breakStart = new Date(`${this.correctionData.date}T${breakItem.startTime}`)
						const breakEnd = new Date(`${this.correctionData.date}T${breakItem.endTime}`)
						if (breakEnd <= breakStart) {
							breakEnd.setDate(breakEnd.getDate() + 1)
						}
						const duration = breakEnd - breakStart
						if (duration > longestBreakDuration) {
							longestBreakDuration = duration
							longestBreak = breakItem
						}
					}
				}

				const params = {
					justification: this.correctionData.justification,
					startTime: startDateTime,
					endTime: endDateTime
				}

				if (longestBreak) {
					params.breakStartTime = `${this.correctionData.date}T${longestBreak.startTime}:00`
					params.breakEndTime = `${this.correctionData.date}T${longestBreak.endTime}:00`
				}

				if (this.correctionData.description) {
					params.description = this.correctionData.description
				}

				const response = await axios.post(
					generateUrl(`/apps/arbeitszeitcheck/api/time-entries/${this.correctionEntry.id}/request-correction`),
					params
				)

				if (response.data.success) {
					this.showNotification(
						response.data.message || this.$t('arbeitszeitcheck', 'Correction request submitted successfully'),
						'success'
					)
					this.correctionEntry = null
					this.correctionData = {
						justification: '',
						date: '',
						startTime: '',
						endTime: '',
						breaks: [
							{ startTime: '12:00', endTime: '12:30' }
						],
						description: ''
					}
					this.correctionSummary = {
						totalHours: 0,
						breakHours: 0,
						workingHours: 0
					}
					this.correctionComplianceWarnings = []
					this.loadTimeline()
				} else {
					throw new Error(response.data.error || this.$t('arbeitszeitcheck', 'Failed to submit correction request'))
				}
			} catch (error) {
				const userMessage = getUserFriendlyError(error, this.$t.bind(this))
				this.showNotification(userMessage, 'error')
			} finally {
				this.isSubmittingCorrection = false
			}
		}
	}
}
</script>

<style scoped>
/* Timeline container uses global styles from main.css */
/* Additional width styles for timeline-specific containers */
.timetracking-timeline__container {
	width: 100% !important;
	max-width: 100% !important;
	box-sizing: border-box;
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
	width: 100% !important;
	max-width: 100% !important;
	box-sizing: border-box;
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
	width: 100% !important;
	max-width: 100% !important;
	box-sizing: border-box;
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
	width: 100% !important;
	max-width: 100% !important;
	box-sizing: border-box;
}

.timetracking-timeline__day {
	padding: calc(var(--default-grid-baseline) * 2);
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	transition: box-shadow 0.2s ease;
}

.timetracking-timeline__day:hover {
	box-shadow: 0 2px 8px var(--color-box-shadow, rgba(0, 0, 0, 0.1));
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
	color: var(--color-primary-text);
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
	box-shadow: 0 2px 8px var(--color-box-shadow, rgba(0, 0, 0, 0.2));
	outline: 2px solid var(--color-primary);
	outline-offset: 2px;
}

.timetracking-timeline__period--work {
	background: var(--color-primary);
	color: var(--color-primary-text);
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
	box-shadow: 0 2px 4px var(--color-box-shadow, rgba(0, 0, 0, 0.1));
	outline: 2px solid var(--color-primary);
	outline-offset: 2px;
}

.timetracking-timeline__entry--active {
	border-left: 4px solid var(--color-primary);
}

.timetracking-timeline__entry--completed {
	border-left: 4px solid var(--color-success);
}

.timetracking-timeline__entry--pending {
	border-left: 4px solid var(--color-warning);
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
