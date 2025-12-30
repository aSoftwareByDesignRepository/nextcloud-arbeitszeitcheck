<template>
	<div class="timetracking-absences">
		<div class="timetracking-dashboard__header">
			<h2 class="timetracking-dashboard__title">{{ $t('arbeitszeitcheck', 'Absences') }}</h2>
			<p class="timetracking-dashboard__subtitle">{{ $t('arbeitszeitcheck', 'Manage your vacation, sick leave, and other absences') }}</p>
		</div>

		<div class="timetracking-content">
			<!-- Actions -->
			<div class="timetracking-actions">
				<NcButton type="primary" :aria-label="$t('arbeitszeitcheck', 'Request new absence')" @click="requestAbsence">
					{{ $t('arbeitszeitcheck', 'Request Absence') }}
				</NcButton>
				<NcButton type="secondary" :aria-label="$t('arbeitszeitcheck', 'Export absences')" @click="exportAbsences">
					{{ $t('arbeitszeitcheck', 'Export') }}
				</NcButton>
			</div>

			<!-- Absence Summary -->
			<div class="timetracking-absence-summary">
				<div class="timetracking-summary-item">
					<div class="timetracking-summary-item__label">{{ $t('arbeitszeitcheck', 'Vacation Days Used') }}</div>
					<div class="timetracking-summary-item__value">{{ vacationStats.used }}/{{ vacationStats.total }}</div>
				</div>
				<div class="timetracking-summary-item">
					<div class="timetracking-summary-item__label">{{ $t('arbeitszeitcheck', 'Sick Leave') }}</div>
					<div class="timetracking-summary-item__value">{{ sickLeaveStats.days }} {{ $t('arbeitszeitcheck', 'days') }}</div>
				</div>
				<div class="timetracking-summary-item">
					<div class="timetracking-summary-item__label">{{ $t('arbeitszeitcheck', 'Remaining Vacation') }}</div>
					<div class="timetracking-summary-item__value">{{ vacationStats.remaining }}</div>
				</div>
			</div>

			<!-- Absences Table -->
			<NcLoadingIcon v-if="isLoading" />
			<NcEmptyContent
				v-else-if="absences.length === 0"
				:title="$t('arbeitszeitcheck', 'No absences recorded')"
				:description="$t('arbeitszeitcheck', 'Your absence history will appear here')"
			>
				<template #icon>
					<span aria-hidden="true">🏖️</span>
				</template>
				<template #action>
					<NcButton type="primary" @click="requestAbsence">
						{{ $t('arbeitszeitcheck', 'Request Absence') }}
					</NcButton>
				</template>
			</NcEmptyContent>
			<table v-else class="timetracking-table">
				<thead>
					<tr>
						<th>{{ $t('arbeitszeitcheck', 'Type') }}</th>
						<th>{{ $t('arbeitszeitcheck', 'Start Date') }}</th>
						<th>{{ $t('arbeitszeitcheck', 'End Date') }}</th>
						<th>{{ $t('arbeitszeitcheck', 'Days') }}</th>
						<th>{{ $t('arbeitszeitcheck', 'Status') }}</th>
						<th>{{ $t('arbeitszeitcheck', 'Actions') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="absence in absences" :key="absence.id">
						<td>{{ getAbsenceTypeText(absence.type) }}</td>
						<td>{{ formatDate(absence.startDate) }}</td>
						<td>{{ formatDate(absence.endDate) }}</td>
						<td>{{ absence.days ? `${absence.days}d` : '-' }}</td>
						<td>
							<span :class="getAbsenceStatusClass(absence.status)">
								{{ getAbsenceStatusText(absence.status) }}
							</span>
						</td>
						<td>
							<NcButton
								v-if="absence.status === 'pending'"
								type="error"
								:aria-label="$t('arbeitszeitcheck', 'Cancel absence request')"
								@click="cancelAbsence(absence)"
							>
								{{ $t('arbeitszeitcheck', 'Cancel') }}
							</NcButton>
							<span v-else>-</span>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<!-- Request Absence Modal -->
		<NcModal
			v-if="showRequestModal"
			:name="$t('arbeitszeitcheck', 'Request Absence')"
			@close="closeRequestModal"
			:size="'large'"
		>
			<div class="timetracking-modal-content">
				<div class="timetracking-form-group">
					<label for="absence-type" class="timetracking-form-label">
						{{ $t('arbeitszeitcheck', 'Absence Type') }} <span class="timetracking-required">*</span>
					</label>
					<NcSelect
						id="absence-type"
						v-model="newAbsence.type"
						:options="absenceTypeOptions"
						:input-label="$t('arbeitszeitcheck', 'Absence Type')"
						:label-outside="true"
						:required="true"
					/>
				</div>

				<div class="timetracking-form-row">
					<div class="timetracking-form-group">
						<label for="absence-start-date" class="timetracking-form-label">
							{{ $t('arbeitszeitcheck', 'Start Date') }} <span class="timetracking-required">*</span>
						</label>
						<NcTextField
							id="absence-start-date"
							v-model="newAbsence.startDate"
							type="text"
							:label="$t('arbeitszeitcheck', 'Start Date')"
							:placeholder="$t('arbeitszeitcheck', 'dd.mm.yyyy')"
							:required="true"
							pattern="\d{2}\.\d{2}\.\d{4}"
							@blur="validateGermanDate('startDate')"
						/>
					</div>

					<div class="timetracking-form-group">
						<label for="absence-end-date" class="timetracking-form-label">
							{{ $t('arbeitszeitcheck', 'End Date') }} <span class="timetracking-required">*</span>
						</label>
						<NcTextField
							id="absence-end-date"
							v-model="newAbsence.endDate"
							type="text"
							:label="$t('arbeitszeitcheck', 'End Date')"
							:placeholder="$t('arbeitszeitcheck', 'dd.mm.yyyy')"
							:required="true"
							pattern="\d{2}\.\d{2}\.\d{4}"
							@blur="validateGermanDate('endDate')"
						/>
					</div>
				</div>

				<div class="timetracking-form-group">
					<label for="absence-reason" class="timetracking-form-label">
						{{ $t('arbeitszeitcheck', 'Reason (optional)') }}
					</label>
					<textarea
						id="absence-reason"
						v-model="newAbsence.reason"
						class="timetracking-textarea"
						rows="3"
						:placeholder="$t('arbeitszeitcheck', 'Optional reason or notes')"
					/>
				</div>

				<div class="timetracking-modal-actions">
					<NcButton
						type="primary"
						:disabled="isSubmittingAbsence || !isAbsenceFormValid"
						@click="submitAbsenceRequest"
					>
						<template #icon>
							<NcLoadingIcon v-if="isSubmittingAbsence" :size="20" />
						</template>
						{{ isSubmittingAbsence ? $t('arbeitszeitcheck', 'Submitting...') : $t('arbeitszeitcheck', 'Submit Request') }}
					</NcButton>
					<NcButton
						type="secondary"
						:disabled="isSubmittingAbsence"
						@click="closeRequestModal"
					>
						{{ $t('arbeitszeitcheck', 'Cancel') }}
					</NcButton>
				</div>
			</div>
		</NcModal>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcEmptyContent, NcModal, NcSelect, NcTextField } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { formatDateGerman, parseGermanDate, isoToGerman } from '../utils/dateUtils.js'

export default {
	name: 'Absences',
	components: {
		NcButton,
		NcLoadingIcon,
		NcEmptyContent,
		NcModal,
		NcSelect,
		NcTextField
	},
	data() {
		return {
			isLoading: false,
			absences: [],
			vacationStats: {
				used: 0,
				total: 25,
				remaining: 25
			},
			sickLeaveStats: {
				days: 0
			},
			showRequestModal: false,
			isSubmittingAbsence: false,
			newAbsence: {
				type: 'vacation',
				startDate: '',
				endDate: '',
				reason: ''
			},
			absenceTypeOptions: []
		}
	},
	computed: {
		isAbsenceFormValid() {
			if (!this.newAbsence.type || !this.newAbsence.startDate || !this.newAbsence.endDate) {
				return false
			}
			const startDateIso = parseGermanDate(this.newAbsence.startDate)
			const endDateIso = parseGermanDate(this.newAbsence.endDate)
			if (!startDateIso || !endDateIso) {
				return false
			}
			return new Date(startDateIso) <= new Date(endDateIso)
		}
	},
	mounted() {
		this.initializeAbsenceTypeOptions()
		this.loadAbsences()
		this.loadStats()
	},
	methods: {
		initializeAbsenceTypeOptions() {
			this.absenceTypeOptions = [
				{ value: 'vacation', label: this.$t('arbeitszeitcheck', 'Vacation') },
				{ value: 'sick_leave', label: this.$t('arbeitszeitcheck', 'Sick Leave') },
				{ value: 'personal_leave', label: this.$t('arbeitszeitcheck', 'Personal Leave') },
				{ value: 'parental_leave', label: this.$t('arbeitszeitcheck', 'Parental Leave') },
				{ value: 'special_leave', label: this.$t('arbeitszeitcheck', 'Special Leave') },
				{ value: 'unpaid_leave', label: this.$t('arbeitszeitcheck', 'Unpaid Leave') },
				{ value: 'home_office', label: this.$t('arbeitszeitcheck', 'Home Office') },
				{ value: 'business_trip', label: this.$t('arbeitszeitcheck', 'Business Trip') }
			]
		},
		async loadAbsences() {
			this.isLoading = true
			try {
				const response = await axios.get(generateUrl('/apps/arbeitszeitcheck/api/absences'))
				if (response.data.success) {
					this.absences = response.data.absences || []
				}
			} catch (error) {
				console.error('Failed to load absences:', error)
			} finally {
				this.isLoading = false
			}
		},

		async loadStats() {
			try {
				const response = await axios.get(generateUrl('/apps/arbeitszeitcheck/api/absences/stats'))
				if (response.data.success) {
					this.vacationStats = response.data.vacationStats || this.vacationStats
					this.sickLeaveStats = response.data.sickLeaveStats || this.sickLeaveStats
				}
			} catch (error) {
				console.error('Failed to load stats:', error)
			}
		},

		requestAbsence() {
			// Ensure options are initialized
			if (this.absenceTypeOptions.length === 0) {
				this.initializeAbsenceTypeOptions()
			}
			// Initialize form with today's date as default start date (German format)
			const today = new Date()
			this.newAbsence = {
				type: 'vacation',
				startDate: formatDateGerman(today),
				endDate: formatDateGerman(today),
				reason: ''
			}
			this.showRequestModal = true
		},

		closeRequestModal() {
			this.showRequestModal = false
			this.newAbsence = {
				type: 'vacation',
				startDate: '',
				endDate: '',
				reason: ''
			}
		},

		validateGermanDate(field) {
			const value = this.newAbsence[field]
			if (value && !parseGermanDate(value)) {
				if (typeof OC !== 'undefined' && OC.Notification) {
					OC.Notification.showTemporary(this.$t('arbeitszeitcheck', 'Invalid date format. Please use dd.mm.yyyy'), {
						timeout: 5000,
						isHTML: false
					})
				}
				this.newAbsence[field] = ''
			}
		},

		async submitAbsenceRequest() {
			if (!this.isAbsenceFormValid) {
				if (typeof OC !== 'undefined' && OC.Notification) {
					OC.Notification.showTemporary(this.$t('arbeitszeitcheck', 'Please fill in all required fields'), {
						timeout: 5000,
						isHTML: false
					})
				}
				return
			}

			// Convert German dates to ISO format for API
			const startDateIso = parseGermanDate(this.newAbsence.startDate)
			const endDateIso = parseGermanDate(this.newAbsence.endDate)

			if (!startDateIso || !endDateIso) {
				if (typeof OC !== 'undefined' && OC.Notification) {
					OC.Notification.showTemporary(this.$t('arbeitszeitcheck', 'Invalid date format. Please use dd.mm.yyyy'), {
						timeout: 5000,
						isHTML: false
					})
				}
				return
			}

			// Validate date range
			const startDate = new Date(startDateIso)
			const endDate = new Date(endDateIso)
			if (startDate > endDate) {
				if (typeof OC !== 'undefined' && OC.Notification) {
					OC.Notification.showTemporary(this.$t('arbeitszeitcheck', 'End date must be after start date'), {
						timeout: 5000,
						isHTML: false
					})
				}
				return
			}

			this.isSubmittingAbsence = true
			try {
				const response = await axios.post(
					generateUrl('/apps/arbeitszeitcheck/api/absences'),
					{
						type: this.newAbsence.type,
						start_date: startDateIso,
						end_date: endDateIso,
						reason: this.newAbsence.reason || null
					}
				)

				if (response.data.success) {
					this.closeRequestModal()
					await this.loadAbsences()
					await this.loadStats()
					if (typeof OC !== 'undefined' && OC.Notification) {
						OC.Notification.showTemporary(this.$t('arbeitszeitcheck', 'Absence request submitted successfully'), {
							timeout: 5000,
							isHTML: false
						})
					}
				} else {
					throw new Error(response.data.error || this.$t('arbeitszeitcheck', 'Failed to submit absence request'))
				}
			} catch (error) {
				const errorMessage = error.response?.data?.error || error.message || this.$t('arbeitszeitcheck', 'Failed to submit absence request')
				if (typeof OC !== 'undefined' && OC.Notification) {
					OC.Notification.showTemporary(errorMessage, {
						timeout: 5000,
						isHTML: false
					})
				}
			} finally {
				this.isSubmittingAbsence = false
			}
		},

		exportAbsences() {
			// Trigger download of absences export
			const exportUrl = generateUrl('/apps/arbeitszeitcheck/export/absences?format=csv')
			window.location.href = exportUrl
		},

		async cancelAbsence(absence) {
			try {
				const response = await axios.delete(generateUrl(`/apps/arbeitszeitcheck/api/absences/${absence.id}`))
				if (response.data.success) {
					this.loadAbsences()
					this.loadStats()
					if (typeof OC !== 'undefined' && OC.Notification) {
						OC.Notification.showTemporary(this.$t('arbeitszeitcheck', 'Absence request cancelled'), {
							timeout: 5000,
							isHTML: false
						})
					}
				}
			} catch (error) {
				console.error('Failed to cancel absence:', error)
				if (typeof OC !== 'undefined' && OC.Notification) {
					OC.Notification.showTemporary(this.$t('arbeitszeitcheck', 'Failed to cancel absence request'), {
						timeout: 5000,
						isHTML: false
					})
				}
			}
		},

		getAbsenceTypeText(type) {
			const types = {
				vacation: this.$t('arbeitszeitcheck', 'Vacation'),
				sick_leave: this.$t('arbeitszeitcheck', 'Sick Leave'),
				personal_leave: this.$t('arbeitszeitcheck', 'Personal Leave'),
				parental_leave: this.$t('arbeitszeitcheck', 'Parental Leave'),
				special_leave: this.$t('arbeitszeitcheck', 'Special Leave'),
				unpaid_leave: this.$t('arbeitszeitcheck', 'Unpaid Leave'),
				home_office: this.$t('arbeitszeitcheck', 'Home Office'),
				business_trip: this.$t('arbeitszeitcheck', 'Business Trip')
			}
			return types[type] || type
		},

		getAbsenceStatusText(status) {
			const statuses = {
				pending: this.$t('arbeitszeitcheck', 'Pending'),
				approved: this.$t('arbeitszeitcheck', 'Approved'),
				rejected: this.$t('arbeitszeitcheck', 'Rejected'),
				cancelled: this.$t('arbeitszeitcheck', 'Cancelled')
			}
			return statuses[status] || status
		},

		getAbsenceStatusClass(status) {
			switch (status) {
				case 'approved':
					return 'timetracking-status--success'
				case 'pending':
					return 'timetracking-status--warning'
				case 'rejected':
					return 'timetracking-status--error'
				case 'cancelled':
					return 'timetracking-status--inactive'
				default:
					return 'timetracking-status--inactive'
			}
		},

		formatDate(dateString) {
			if (!dateString) return ''
			return formatDateGerman(dateString)
		}
	}
}
</script>

<style scoped>
.timetracking-absences {
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

.timetracking-actions {
	display: flex;
	justify-content: flex-end;
	gap: var(--default-grid-baseline);
	margin-bottom: var(--default-grid-baseline);
}

.timetracking-absence-summary {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: var(--default-grid-baseline);
	margin-bottom: calc(var(--default-grid-baseline) * 2);
	padding: var(--default-grid-baseline);
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius);
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

.timetracking-form-group {
	margin-bottom: calc(var(--default-grid-baseline) * 2);
}

.timetracking-form-label {
	display: block;
	margin-bottom: calc(var(--default-grid-baseline) / 2);
	font-weight: 500;
	color: var(--color-main-text);
}

.timetracking-required {
	color: var(--color-error);
}

.timetracking-form-row {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: var(--default-grid-baseline);
}

.timetracking-modal-actions {
	display: flex;
	justify-content: flex-end;
	gap: var(--default-grid-baseline);
	margin-top: calc(var(--default-grid-baseline) * 2);
	padding-top: calc(var(--default-grid-baseline) * 2);
	border-top: 1px solid var(--color-border);
}

@media (max-width: 768px) {
	.timetracking-form-row {
		grid-template-columns: 1fr;
	}
	.timetracking-actions {
		justify-content: center;
	}

	.timetracking-absence-summary {
		grid-template-columns: 1fr;
	}
}
</style>