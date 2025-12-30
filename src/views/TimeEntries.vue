<template>
	<div class="timetracking-time-entries">
		<div class="timetracking-dashboard__header">
			<h2 class="timetracking-dashboard__title">{{ $t('arbeitszeitcheck', 'Time Entries') }}</h2>
			<p class="timetracking-dashboard__subtitle">{{ $t('arbeitszeitcheck', 'View and manage your time tracking records') }}</p>
		</div>

		<div class="timetracking-content">
			<!-- Filters and Actions -->
			<div class="timetracking-filters">
				<div class="timetracking-filters__search">
					<NcTextField
						v-model="searchQuery"
						type="text"
						:placeholder="$t('arbeitszeitcheck', 'Search time entries...')"
						:label="$t('arbeitszeitcheck', 'Search')"
					/>
				</div>
				<div class="timetracking-filters__actions">
					<NcButton 
						type="primary"
						:aria-label="$t('arbeitszeitcheck', 'Add manual time entry')"
						@click="showAddManualEntryModal = true"
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

			<!-- Time Entries Table -->
			<NcLoadingIcon v-if="isLoading" />
			<NcEmptyContent
				v-else-if="filteredEntries.length === 0"
				:title="$t('arbeitszeitcheck', 'No time entries found')"
				:description="$t('arbeitszeitcheck', 'Start tracking time from the dashboard to see entries here')"
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
						<th>{{ $t('arbeitszeitcheck', 'Break Time') }}</th>
						<th>{{ $t('arbeitszeitcheck', 'Working Hours') }}</th>
						<th>{{ $t('arbeitszeitcheck', 'Project') }}</th>
						<th>{{ $t('arbeitszeitcheck', 'Status') }}</th>
						<th>{{ $t('arbeitszeitcheck', 'Actions') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="entry in filteredEntries" :key="entry.id">
						<td>{{ formatDate(entry.startTime) }}</td>
						<td>{{ formatTime(entry.startTime) }}</td>
						<td>{{ entry.endTime ? formatTime(entry.endTime) : '-' }}</td>
						<td>{{ entry.breakDurationHours ? formatHours(entry.breakDurationHours) : '-' }}</td>
						<td>{{ entry.durationHours ? formatHours(entry.durationHours) : '-' }}</td>
						<td>{{ entry.projectCheckProjectId || '-' }}</td>
						<td>
							<span :class="getEntryStatusClass(entry.status)">
								{{ getEntryStatusText(entry.status) }}
							</span>
						</td>
						<td>
							<div class="timetracking-entry-actions">
								<NcButton
									v-if="entry.status !== 'pending_approval'"
									type="secondary"
									:aria-label="$t('arbeitszeitcheck', 'Request correction')"
									@click="requestCorrection(entry)"
								>
									{{ $t('arbeitszeitcheck', 'Request Correction') }}
								</NcButton>
								<NcButton
									type="secondary"
									:aria-label="$t('arbeitszeitcheck', 'Edit entry')"
									@click="editEntry(entry)"
									:disabled="entry.status === 'pending_approval'"
								>
									{{ $t('arbeitszeitcheck', 'Edit') }}
								</NcButton>
							</div>
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

		<!-- Edit Entry Modal -->
		<NcModal 
			v-if="editingEntry"
			:name="$t('arbeitszeitcheck', 'Edit Time Entry')"
			@close="editingEntry = null"
			:show-close="true"
		>
			<div class="timetracking-modal-content">
				<div class="timetracking-form-group">
					<NcTextField
						v-model="editEntryData.date"
						type="text"
						:label="$t('arbeitszeitcheck', 'Date')"
						:placeholder="$t('arbeitszeitcheck', 'dd.mm.yyyy')"
						:required="true"
						pattern="\d{2}\.\d{2}\.\d{4}"
						@blur="validateGermanDate('editEntryData.date')"
					/>
				</div>
				<div class="timetracking-form-group">
					<NcTextField
						v-model="editEntryData.hours"
						type="number"
						step="0.25"
						min="0.25"
						max="24"
						:label="$t('arbeitszeitcheck', 'Hours')"
						:required="true"
					/>
				</div>
				<div class="timetracking-form-group">
					<NcTextField
						v-model="editEntryData.description"
						type="text"
						:label="$t('arbeitszeitcheck', 'Description (optional)')"
					/>
				</div>
				<div class="timetracking-modal-actions">
					<NcButton 
						type="primary" 
						@click="saveEditedEntry"
						:disabled="isSavingEdit || !editEntryData.date || !editEntryData.hours"
					>
						{{ isSavingEdit ? $t('arbeitszeitcheck', 'Saving...') : $t('arbeitszeitcheck', 'Save') }}
					</NcButton>
					<NcButton type="secondary" @click="editingEntry = null">
						{{ $t('arbeitszeitcheck', 'Cancel') }}
					</NcButton>
				</div>
			</div>
		</NcModal>

		<!-- Correction Request Modal -->
		<NcModal 
			v-if="correctionEntry"
			:name="$t('arbeitszeitcheck', 'Request Time Entry Correction')"
			@close="correctionEntry = null"
			:show-close="true"
		>
			<div class="timetracking-modal-content">
				<div class="timetracking-correction-info">
					<h4 class="timetracking-section-title">{{ $t('arbeitszeitcheck', 'Current Entry') }}</h4>
					<div class="timetracking-info-row">
						<span class="timetracking-info-label">{{ $t('arbeitszeitcheck', 'Date') }}:</span>
						<span class="timetracking-info-value">{{ formatDate(correctionEntry.startTime) }}</span>
					</div>
					<div class="timetracking-info-row">
						<span class="timetracking-info-label">{{ $t('arbeitszeitcheck', 'Start Time') }}:</span>
						<span class="timetracking-info-value">{{ formatTime(correctionEntry.startTime) }}</span>
					</div>
					<div v-if="correctionEntry.endTime" class="timetracking-info-row">
						<span class="timetracking-info-label">{{ $t('arbeitszeitcheck', 'End Time') }}:</span>
						<span class="timetracking-info-value">{{ formatTime(correctionEntry.endTime) }}</span>
					</div>
					<div class="timetracking-info-row">
						<span class="timetracking-info-label">{{ $t('arbeitszeitcheck', 'Duration') }}:</span>
						<span class="timetracking-info-value">{{ formatHours(correctionEntry.durationHours || 0) }}h</span>
					</div>
				</div>

				<div class="timetracking-form-group">
					<label for="correction-justification" class="timetracking-form-label required">
						{{ $t('arbeitszeitcheck', 'Justification') }}
					</label>
					<textarea
						id="correction-justification"
						v-model="correctionData.justification"
						class="timetracking-textarea"
						rows="4"
						maxlength="1000"
						:placeholder="$t('arbeitszeitcheck', 'e.g., Forgot to clock out, incorrect break time, etc.')"
						required
					/>
					<p class="timetracking-form-help">
						{{ $t('arbeitszeitcheck', 'A justification is required for all correction requests') }}
					</p>
				</div>

				<div class="timetracking-form-group">
					<h4 class="timetracking-section-title">{{ $t('arbeitszeitcheck', 'Proposed Changes (Optional)') }}</h4>
					<p class="timetracking-form-help">
						{{ $t('arbeitszeitcheck', 'If you want to change specific values, enter them below. Leave blank to keep current values.') }}
					</p>
				</div>

				<div class="timetracking-form-group">
					<NcTextField
						v-model="correctionData.newDate"
						type="text"
						:label="$t('arbeitszeitcheck', 'New Date (optional)')"
						:placeholder="$t('arbeitszeitcheck', 'dd.mm.yyyy')"
						pattern="\d{2}\.\d{2}\.\d{4}"
						@blur="validateGermanDate('correctionData.newDate')"
					/>
				</div>
				<div class="timetracking-form-group">
					<NcTextField
						v-model="correctionData.newHours"
						type="number"
						step="0.25"
						min="0.25"
						max="24"
						:label="$t('arbeitszeitcheck', 'New Hours (optional)')"
					/>
				</div>
				<div class="timetracking-form-group">
					<label for="correction-new-description" class="timetracking-form-label">
						{{ $t('arbeitszeitcheck', 'New Description (optional)') }}
					</label>
					<textarea
						id="correction-new-description"
						v-model="correctionData.newDescription"
						class="timetracking-textarea"
						rows="3"
						maxlength="500"
					/>
				</div>

				<div class="timetracking-modal-actions">
					<NcButton 
						type="primary" 
						@click="submitCorrectionRequest"
						:disabled="isSubmittingCorrection || !correctionData.justification"
					>
						{{ isSubmittingCorrection ? $t('arbeitszeitcheck', 'Submitting...') : $t('arbeitszeitcheck', 'Submit Correction Request') }}
					</NcButton>
					<NcButton type="secondary" @click="correctionEntry = null">
						{{ $t('arbeitszeitcheck', 'Cancel') }}
					</NcButton>
				</div>
			</div>
		</NcModal>

		<!-- Add Manual Entry Modal -->
		<NcModal 
			v-if="showAddManualEntryModal"
			:name="$t('arbeitszeitcheck', 'Add Manual Time Entry')"
			@close="showAddManualEntryModal = false"
			:show-close="true"
		>
			<div class="timetracking-modal-content">
				<div class="timetracking-form-group">
					<NcTextField
						v-model="newEntry.date"
						type="text"
						:label="$t('arbeitszeitcheck', 'Date')"
						:placeholder="$t('arbeitszeitcheck', 'dd.mm.yyyy')"
						:required="true"
						pattern="\d{2}\.\d{2}\.\d{4}"
						@blur="validateGermanDate('newEntry.date')"
					/>
				</div>
				<div class="timetracking-form-group">
					<NcTextField
						v-model="newEntry.hours"
						type="number"
						step="0.25"
						min="0.25"
						max="24"
						:label="$t('arbeitszeitcheck', 'Hours')"
						:required="true"
					/>
				</div>
				<div class="timetracking-form-group">
					<NcTextField
						v-model="newEntry.description"
						type="text"
						:label="$t('arbeitszeitcheck', 'Description (optional)')"
					/>
				</div>
				<div class="timetracking-modal-actions">
					<NcButton 
						type="primary" 
						@click="saveManualEntry"
						:disabled="isSavingEntry || !newEntry.date || !newEntry.hours"
					>
						{{ isSavingEntry ? $t('arbeitszeitcheck', 'Saving...') : $t('arbeitszeitcheck', 'Save') }}
					</NcButton>
					<NcButton type="secondary" @click="showAddManualEntryModal = false">
						{{ $t('arbeitszeitcheck', 'Cancel') }}
					</NcButton>
				</div>
			</div>
		</NcModal>
	</div>
</template>

<script>
import { NcButton, NcTextField, NcLoadingIcon, NcEmptyContent, NcModal } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { formatDateGerman, parseGermanDate, isoToGerman } from '../utils/dateUtils.js'

export default {
	name: 'TimeEntries',
	components: {
		NcButton,
		NcTextField,
		NcLoadingIcon,
		NcEmptyContent,
		NcModal
	},
	data() {
		return {
			isLoading: false,
			entries: [],
			searchQuery: '',
			currentPage: 1,
			totalPages: 1,
			perPage: 25,
			editingEntry: null,
			showAddManualEntryModal: false,
			isSavingEntry: false,
			isSavingEdit: false,
			isExporting: false,
			newEntry: {
				date: formatDateGerman(new Date()),
				hours: '',
				description: ''
			},
			editEntryData: {
				date: '',
				hours: '',
				description: ''
			},
			correctionEntry: null,
			isSubmittingCorrection: false,
			correctionData: {
				justification: '',
				newDate: '',
				newHours: null,
				newDescription: ''
			}
		}
	},
	computed: {
		filteredEntries() {
			if (!this.searchQuery) {
				return this.entries
			}

			const query = (this.searchQuery || '').toLowerCase()
			return this.entries.filter(entry => {
				const description = (entry.description || '').toLowerCase()
				const dateStr = (this.formatDate(entry.startTime) || '').toLowerCase()
				const statusStr = (this.getEntryStatusText(entry.status) || '').toLowerCase()
				return description.includes(query) || dateStr.includes(query) || statusStr.includes(query)
			})
		}
	},
	mounted() {
		this.loadEntries()
	},
	methods: {
		async loadEntries() {
			this.isLoading = true
			try {
				// Calculate offset from page number
				const offset = (this.currentPage - 1) * this.perPage
				const response = await axios.get(generateUrl(`/apps/arbeitszeitcheck/api/time-entries?limit=${this.perPage}&offset=${offset}`))
				if (response.data.success) {
					this.entries = response.data.entries || []
					// Calculate total pages from total count (if available) or estimate from entries
					if (response.data.total !== undefined) {
						this.totalPages = Math.ceil(response.data.total / this.perPage)
					} else {
						// If we got a full page, there might be more
						this.totalPages = this.entries.length >= this.perPage ? this.currentPage + 1 : this.currentPage
					}
				}
			} catch (error) {
				console.error('Failed to load entries:', error)
				this.showNotification(this.$t('arbeitszeitcheck', 'Failed to load time entries'), 'error')
			} finally {
				this.isLoading = false
			}
		},

		editEntry(entry) {
			this.editingEntry = entry
			// Pre-populate edit form with current entry data (German format)
			if (entry.startTime) {
				this.editEntryData.date = formatDateGerman(entry.startTime)
			} else {
				this.editEntryData.date = ''
			}
			// Calculate hours from duration or use provided durationHours
			if (entry.durationHours !== null && entry.durationHours !== undefined) {
				this.editEntryData.hours = entry.durationHours.toString()
			} else if (entry.endTime && entry.startTime) {
				// Calculate from start and end times if duration not provided
				const start = new Date(entry.startTime)
				const end = new Date(entry.endTime)
				const diffMs = end.getTime() - start.getTime()
				const diffHours = diffMs / (1000 * 60 * 60)
				this.editEntryData.hours = diffHours.toFixed(2)
			} else {
				this.editEntryData.hours = ''
			}
			this.editEntryData.description = entry.description || ''
		},

		validateGermanDate(field) {
			let value
			if (field === 'editEntryData.date') {
				value = this.editEntryData.date
			} else if (field === 'newEntry.date') {
				value = this.newEntry.date
			} else if (field === 'correctionData.newDate') {
				value = this.correctionData.newDate
			}
			
			if (value && !parseGermanDate(value)) {
				if (typeof OC !== 'undefined' && OC.Notification) {
					OC.Notification.showTemporary(this.$t('arbeitszeitcheck', 'Invalid date format. Please use dd.mm.yyyy'), {
						timeout: 5000,
						isHTML: false
					})
				}
				if (field === 'editEntryData.date') {
					this.editEntryData.date = ''
				} else if (field === 'newEntry.date') {
					this.newEntry.date = ''
				} else if (field === 'correctionData.newDate') {
					this.correctionData.newDate = ''
				}
			}
		},

		async saveEditedEntry() {
			if (!this.editingEntry || !this.editEntryData.date || !this.editEntryData.hours) {
				this.showNotification(this.$t('arbeitszeitcheck', 'Date and hours are required'), 'error')
				return
			}

			// Convert German date to ISO format for API
			const dateIso = parseGermanDate(this.editEntryData.date)
			if (!dateIso) {
				this.showNotification(this.$t('arbeitszeitcheck', 'Invalid date format. Please use dd.mm.yyyy'), 'error')
				return
			}

			this.isSavingEdit = true
			try {
				const hours = parseFloat(this.editEntryData.hours)
				if (isNaN(hours) || hours <= 0) {
					throw new Error(this.$t('arbeitszeitcheck', 'Hours must be a positive number'))
				}

				const response = await axios.put(
					generateUrl(`/apps/arbeitszeitcheck/api/time-entries/${this.editingEntry.id}`),
					{
						date: dateIso,
						hours: hours,
						description: this.editEntryData.description || null
					}
				)

				if (response.data.success) {
					this.showNotification(this.$t('arbeitszeitcheck', 'Time entry updated successfully'), 'success')
					this.editingEntry = null
					this.editEntryData = {
						date: '',
						hours: '',
						description: ''
					}
					this.loadEntries()
				} else {
					throw new Error(response.data.error || this.$t('arbeitszeitcheck', 'Failed to update entry'))
				}
			} catch (error) {
				this.showNotification(
					error.response?.data?.error || error.message || this.$t('arbeitszeitcheck', 'Failed to update time entry'),
					'error'
				)
			} finally {
				this.isSavingEdit = false
			}
		},

		requestCorrection(entry) {
			this.correctionEntry = entry
			// Reset correction data
			this.correctionData = {
				justification: '',
				newDate: '',
				newHours: null,
				newDescription: ''
			}
			// Pre-populate with current values if available
			if (entry.startTime) {
				this.correctionData.newDate = formatDateGerman(entry.startTime)
			} else {
				this.correctionData.newDate = ''
			}
			if (entry.durationHours !== null && entry.durationHours !== undefined) {
				this.correctionData.newHours = entry.durationHours
			}
			if (entry.description) {
				this.correctionData.newDescription = entry.description
			}
		},

		async submitCorrectionRequest() {
			if (!this.correctionEntry || !this.correctionData.justification) {
				this.showNotification(this.$t('arbeitszeitcheck', 'Justification is required'), 'error')
				return
			}

			this.isSubmittingCorrection = true
			try {
				const params = {
					justification: this.correctionData.justification
				}

				// Only include proposed changes if they differ from current values
				if (this.correctionData.newDate) {
					const dateIso = parseGermanDate(this.correctionData.newDate)
					if (dateIso) {
						const currentDate = new Date(this.correctionEntry.startTime).toISOString().split('T')[0]
						if (dateIso !== currentDate) {
							params.newDate = dateIso
						}
					}
				}
				if (this.correctionData.newHours !== null && this.correctionData.newHours !== undefined) {
					const currentHours = this.correctionEntry.durationHours || 0
					if (Math.abs(this.correctionData.newHours - currentHours) > 0.01) {
						params.newHours = this.correctionData.newHours
					}
				}
				if (this.correctionData.newDescription) {
					const currentDescription = this.correctionEntry.description || ''
					if (this.correctionData.newDescription !== currentDescription) {
						params.newDescription = this.correctionData.newDescription
					}
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
						newDate: '',
						newHours: null,
						newDescription: ''
					}
					this.loadEntries()
				} else {
					throw new Error(response.data.error || this.$t('arbeitszeitcheck', 'Failed to submit correction request'))
				}
			} catch (error) {
				this.showNotification(
					error.response?.data?.error || error.message || this.$t('arbeitszeitcheck', 'Failed to submit correction request'),
					'error'
				)
			} finally {
				this.isSubmittingCorrection = false
			}
		},

		async saveManualEntry() {
			if (!this.newEntry.date || !this.newEntry.hours) {
				this.showNotification(this.$t('arbeitszeitcheck', 'Date and hours are required'), 'error')
				return
			}

			// Convert German date to ISO format for API
			const dateIso = parseGermanDate(this.newEntry.date)
			if (!dateIso) {
				this.showNotification(this.$t('arbeitszeitcheck', 'Invalid date format. Please use dd.mm.yyyy'), 'error')
				return
			}

			this.isSavingEntry = true
			try {
				const hours = parseFloat(this.newEntry.hours)
				if (isNaN(hours) || hours <= 0) {
					throw new Error(this.$t('arbeitszeitcheck', 'Hours must be a positive number'))
				}

				const response = await axios.post(
					generateUrl('/apps/arbeitszeitcheck/api/time-entries'),
					{
						date: dateIso,
						hours: hours,
						description: this.newEntry.description || null
					}
				)

				if (response.data.success) {
					this.showNotification(this.$t('arbeitszeitcheck', 'Time entry created successfully'), 'success')
					this.showAddManualEntryModal = false
					this.newEntry = {
						date: new Date().toISOString().split('T')[0],
						hours: '',
						description: ''
					}
					this.loadEntries()
				} else {
					throw new Error(response.data.error || this.$t('arbeitszeitcheck', 'Failed to create entry'))
				}
			} catch (error) {
				this.showNotification(
					error.response?.data?.error || error.message || this.$t('arbeitszeitcheck', 'Failed to create time entry'),
					'error'
				)
			} finally {
				this.isSavingEntry = false
			}
		},

		async exportEntries() {
			this.isExporting = true
			try {
				// Use the export endpoint to download CSV file
				const exportUrl = generateUrl('/apps/arbeitszeitcheck/export/time-entries?format=csv')
				window.location.href = exportUrl
				this.showNotification(this.$t('arbeitszeitcheck', 'Export started'), 'success')
			} catch (error) {
				this.showNotification(this.$t('arbeitszeitcheck', 'Failed to export entries'), 'error')
				console.error('Export error:', error)
			} finally {
				// Small delay to ensure download starts
				setTimeout(() => {
					this.isExporting = false
				}, 1000)
			}
		},

		showNotification(message, type) {
			if (typeof OC !== 'undefined' && OC.Notification) {
				OC.Notification.showTemporary(message, {
					timeout: 5000,
					isHTML: false
				})
			} else {
				console.log(`${type}: ${message}`)
			}
		},

		goToPage(page) {
			if (page >= 1 && page <= this.totalPages) {
				this.currentPage = page
				this.loadEntries()
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
			if (!status) return ''
			switch (status) {
				case 'completed':
					return this.$t('arbeitszeitcheck', 'Completed') || 'Completed'
				case 'pending_approval':
					return this.$t('arbeitszeitcheck', 'Pending Approval') || 'Pending Approval'
				case 'rejected':
					return this.$t('arbeitszeitcheck', 'Rejected') || 'Rejected'
				case 'active':
					return this.$t('arbeitszeitcheck', 'Active') || 'Active'
				case 'break':
					return this.$t('arbeitszeitcheck', 'Break') || 'Break'
				default:
					return String(status || '')
			}
		},

		formatDate(dateString) {
			if (!dateString) return ''
			const result = formatDateGerman(dateString)
			return result || ''
		},

		formatTime(dateString) {
			return new Date(dateString).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
		},

		formatHours(hours) {
			return `${hours.toFixed(2)}h`
		}
	}
}
</script>

<style scoped>
.timetracking-entry-actions {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 1);
	flex-wrap: wrap;
}

.timetracking-correction-info {
	margin-bottom: calc(var(--default-grid-baseline) * 2);
	padding: calc(var(--default-grid-baseline) * 1.5);
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
}

.timetracking-info-row {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: calc(var(--default-grid-baseline) * 0.5) 0;
	border-bottom: 1px solid var(--color-border);
}

.timetracking-info-row:last-child {
	border-bottom: none;
}

.timetracking-info-label {
	font-weight: 600;
	color: var(--color-main-text);
}

.timetracking-info-value {
	color: var(--color-text-maxcontrast);
}

.timetracking-form-label.required::after {
	content: ' *';
	color: var(--color-error, #e9322d);
}

.timetracking-time-entries {
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

.timetracking-content {
	background-color: var(--color-main-background);
	border-radius: var(--border-radius);
	padding: var(--default-grid-baseline);
}

.timetracking-filters {
	display: flex;
	justify-content: space-between;
	align-items: flex-end;
	margin-bottom: var(--default-grid-baseline);
	gap: var(--default-grid-baseline);
}

.timetracking-filters__search {
	flex: 1;
	max-width: 300px;
}

.timetracking-filters__actions {
	display: flex;
	gap: var(--default-grid-baseline);
}

.timetracking-pagination {
	display: flex;
	justify-content: center;
	align-items: center;
	gap: var(--default-grid-baseline);
	margin-top: var(--default-grid-baseline);
	padding-top: var(--default-grid-baseline);
	border-top: 1px solid var(--color-border);
}

.timetracking-pagination__info {
	color: var(--color-text-maxcontrast);
	padding: 0 var(--default-grid-baseline);
}

.timetracking-status--success {
	color: var(--color-success);
	font-weight: 500;
}

.timetracking-status--warning {
	color: var(--color-warning);
	font-weight: 500;
}

.timetracking-status--error {
	color: var(--color-error);
	font-weight: 500;
}

.timetracking-status--inactive {
	color: var(--color-text-maxcontrast);
}

.timetracking-modal-content {
	padding: var(--default-grid-baseline);
}

.timetracking-modal-message {
	margin-bottom: var(--default-grid-baseline);
	color: var(--color-main-text);
}

.timetracking-modal-actions {
	display: flex;
	justify-content: flex-end;
	gap: var(--default-grid-baseline);
	margin-top: calc(var(--default-grid-baseline) * 2);
}

.timetracking-form-group {
	margin-bottom: var(--default-grid-baseline);
}

.timetracking-entry-actions {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 1);
	flex-wrap: wrap;
}

.timetracking-correction-info {
	margin-bottom: calc(var(--default-grid-baseline) * 2);
	padding: calc(var(--default-grid-baseline) * 1.5);
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
}

.timetracking-info-row {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: calc(var(--default-grid-baseline) * 0.5) 0;
	border-bottom: 1px solid var(--color-border);
}

.timetracking-info-row:last-child {
	border-bottom: none;
}

.timetracking-info-label {
	font-weight: 600;
	color: var(--color-main-text);
}

.timetracking-info-value {
	color: var(--color-text-maxcontrast);
}

.timetracking-form-label.required::after {
	content: ' *';
	color: var(--color-error, #e9322d);
}

@media (max-width: 768px) {
	.timetracking-filters {
		flex-direction: column;
		align-items: stretch;
	}

	.timetracking-filters__search {
		max-width: none;
	}

	.timetracking-filters__actions {
		justify-content: center;
	}
}
</style>