<template>
	<div class="timetracking-admin-settings">
		<div class="timetracking-dashboard__header">
			<h2 class="timetracking-dashboard__title">{{ $t('arbeitszeitcheck', 'ArbeitszeitCheck Administration') }}</h2>
			<p class="timetracking-dashboard__subtitle">{{ $t('arbeitszeitcheck', 'Configure system-wide settings for time tracking and compliance') }}</p>
		</div>

		<div class="timetracking-dashboard__content">
			<NcLoadingIcon v-if="isLoading" :size="32" />
			
			<div v-else class="timetracking-admin-settings__sections">
				<!-- General Settings -->
				<div class="timetracking-section">
					<h3 class="timetracking-section-title">{{ $t('arbeitszeitcheck', 'General Settings') }}</h3>
					<div class="timetracking-admin-settings__form">
						<div class="timetracking-form-group">
							<NcTextField
								v-model.number="settings.defaultWorkingHours"
								type="number"
								:min="1"
								:max="24"
								:step="0.5"
								:label="$t('arbeitszeitcheck', 'Default Working Hours per Day')"
								:placeholder="$t('arbeitszeitcheck', '8')" />
						</div>
						<div class="timetracking-form-group">
							<label class="timetracking-checkbox">
								<input
									v-model="settings.autoComplianceCheck"
									type="checkbox"
									class="checkbox" />
								{{ $t('arbeitszeitcheck', 'Enable automatic compliance checking') }}
							</label>
							<p class="timetracking-form-help">
								{{ $t('arbeitszeitcheck', 'Automatically check for labor law compliance violations') }}
							</p>
						</div>
						<div class="timetracking-form-group">
							<label class="timetracking-checkbox">
								<input
									v-model="settings.requireBreakJustification"
									type="checkbox"
									class="checkbox" />
								{{ $t('arbeitszeitcheck', 'Require break time recording') }}
							</label>
							<p class="timetracking-form-help">
								{{ $t('arbeitszeitcheck', 'Employees must record break times for compliance') }}
							</p>
						</div>
					</div>
				</div>

				<!-- Working Time Models -->
				<div class="timetracking-section">
					<h3 class="timetracking-section-title">{{ $t('arbeitszeitcheck', 'Working Time Models') }}</h3>
					<p class="timetracking-admin-settings__help">
						{{ $t('arbeitszeitcheck', 'Define different working time models for your organization.') }}
					</p>
					<NcButton
						type="primary"
						@click="navigateToWorkingTimeModels"
						:aria-label="$t('arbeitszeitcheck', 'Manage working time models')">
						{{ $t('arbeitszeitcheck', 'Manage Working Time Models') }}
					</NcButton>
				</div>

				<!-- Compliance Settings -->
				<div class="timetracking-section">
					<h3 class="timetracking-section-title">{{ $t('arbeitszeitcheck', 'Compliance Settings') }}</h3>
					<div class="timetracking-admin-settings__form">
						<div class="timetracking-form-group">
							<NcCheckboxRadioSwitch
								v-model="settings.enableViolationNotifications"
								type="switch">
								{{ $t('arbeitszeitcheck', 'Enable automatic compliance violation notifications') }}
							</NcCheckboxRadioSwitch>
						</div>
						<div class="timetracking-form-group">
							<label for="max-daily-hours" class="timetracking-form-label">
								{{ $t('arbeitszeitcheck', 'Maximum Daily Working Hours') }}
							</label>
							<NcTextField
								id="max-daily-hours"
								v-model.number="settings.maxDailyHours"
								type="number"
								:min="1"
								:max="24"
								:step="0.5"
								:label="$t('arbeitszeitcheck', 'Maximum Daily Working Hours')" />
							<p class="timetracking-form-help">
								{{ $t('arbeitszeitcheck', 'Maximum hours allowed per day (German ArbZG)') }}
							</p>
						</div>
						<div class="timetracking-form-group">
							<label for="min-rest-period" class="timetracking-form-label">
								{{ $t('arbeitszeitcheck', 'Minimum Rest Period (hours)') }}
							</label>
							<NcTextField
								id="min-rest-period"
								v-model.number="settings.minRestPeriod"
								type="number"
								:min="1"
								:max="24"
								:step="0.5"
								:label="$t('arbeitszeitcheck', 'Minimum Rest Period (hours)')" />
							<p class="timetracking-form-help">
								{{ $t('arbeitszeitcheck', 'Minimum hours between work shifts') }}
							</p>
						</div>
						<div class="timetracking-form-group">
							<label for="german-state" class="timetracking-form-label">
								{{ $t('arbeitszeitcheck', 'German Federal State') }}
							</label>
							<NcSelect
								id="german-state"
								v-model="settings.germanState"
								:options="germanStateOptions"
								:label="$t('arbeitszeitcheck', 'German Federal State')" />
							<p class="timetracking-form-help">
								{{ $t('arbeitszeitcheck', 'Used for public holiday calculations') }}
							</p>
						</div>
					</div>
				</div>

				<!-- Data Retention -->
				<div class="timetracking-section">
					<h3 class="timetracking-section-title">{{ $t('arbeitszeitcheck', 'Data Retention') }}</h3>
					<div class="timetracking-form-group">
						<label for="retention-period" class="timetracking-form-label">
							{{ $t('arbeitszeitcheck', 'Data Retention Period (years)') }}
						</label>
						<NcTextField
							id="retention-period"
							v-model.number="settings.retentionPeriod"
							type="number"
							:min="1"
							:max="10"
							:label="$t('arbeitszeitcheck', 'Data Retention Period (years)')" />
						<p class="timetracking-form-help">
							{{ $t('arbeitszeitcheck', 'How long to keep time tracking data (GDPR compliance)') }}
						</p>
					</div>
				</div>

				<!-- System Maintenance -->
				<div class="timetracking-section">
					<h3 class="timetracking-section-title">{{ $t('arbeitszeitcheck', 'System Maintenance') }}</h3>
					<p class="timetracking-admin-settings__help">
						{{ $t('arbeitszeitcheck', 'Perform maintenance tasks and view system status.') }}
					</p>
					<div class="timetracking-admin-settings__actions-inline">
						<NcButton
							type="secondary"
							@click="runComplianceCheck"
							:disabled="isRunningComplianceCheck"
							:aria-label="$t('arbeitszeitcheck', 'Run compliance check for all users')">
							<template #icon>
								<NcLoadingIcon v-if="isRunningComplianceCheck" :size="20" />
							</template>
							{{ isRunningComplianceCheck ? $t('arbeitszeitcheck', 'Running...') : $t('arbeitszeitcheck', 'Run Compliance Check') }}
						</NcButton>
						<NcButton
							type="secondary"
							@click="navigateToCompliance"
							:aria-label="$t('arbeitszeitcheck', 'View compliance dashboard')">
							{{ $t('arbeitszeitcheck', 'View Compliance Dashboard') }}
						</NcButton>
					</div>
				</div>

				<!-- Reports & Analytics -->
				<div class="timetracking-section">
					<h3 class="timetracking-section-title">{{ $t('arbeitszeitcheck', 'Reports & Analytics') }}</h3>
					<p class="timetracking-admin-settings__help">
						{{ $t('arbeitszeitcheck', 'Generate compliance reports and analytics.') }}
					</p>
					<div class="timetracking-admin-settings__actions-inline">
						<NcButton
							type="primary"
							@click="navigateToComplianceReports"
							:aria-label="$t('arbeitszeitcheck', 'Generate compliance report')">
							{{ $t('arbeitszeitcheck', 'Compliance Report') }}
						</NcButton>
					</div>
				</div>

				<!-- Save Button -->
				<div class="timetracking-admin-settings__actions">
					<NcButton
						type="primary"
						:disabled="isSaving"
						@click="saveSettings">
						<template #icon>
							<NcLoadingIcon v-if="isSaving" :size="20" />
						</template>
						{{ isSaving ? $t('arbeitszeitcheck', 'Saving...') : $t('arbeitszeitcheck', 'Save Settings') }}
					</NcButton>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcTextField, NcSelect, NcCheckboxRadioSwitch, NcLoadingIcon } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'AdminSettings',
	components: {
		NcButton,
		NcTextField,
		NcSelect,
		NcCheckboxRadioSwitch,
		NcLoadingIcon
	},
	data() {
		return {
			isLoading: false,
			isSaving: false,
			isRunningComplianceCheck: false,
			settings: {
				defaultWorkingHours: 8,
				autoComplianceCheck: true,
				requireBreakJustification: true,
				enableViolationNotifications: true,
				maxDailyHours: 10,
				minRestPeriod: 11,
				germanState: 'NW',
				retentionPeriod: 2
			},
			germanStateOptions: [
				{ value: 'NW', label: this.$t('arbeitszeitcheck', 'North Rhine-Westphalia') },
				{ value: 'BY', label: this.$t('arbeitszeitcheck', 'Bavaria') },
				{ value: 'BW', label: this.$t('arbeitszeitcheck', 'Baden-Württemberg') },
				{ value: 'HE', label: this.$t('arbeitszeitcheck', 'Hesse') },
				{ value: 'NI', label: this.$t('arbeitszeitcheck', 'Lower Saxony') },
				{ value: 'RP', label: this.$t('arbeitszeitcheck', 'Rhineland-Palatinate') },
				{ value: 'SL', label: this.$t('arbeitszeitcheck', 'Saarland') },
				{ value: 'BE', label: this.$t('arbeitszeitcheck', 'Berlin') },
				{ value: 'BB', label: this.$t('arbeitszeitcheck', 'Brandenburg') },
				{ value: 'HB', label: this.$t('arbeitszeitcheck', 'Bremen') },
				{ value: 'HH', label: this.$t('arbeitszeitcheck', 'Hamburg') },
				{ value: 'MV', label: this.$t('arbeitszeitcheck', 'Mecklenburg-Vorpommern') },
				{ value: 'SN', label: this.$t('arbeitszeitcheck', 'Saxony') },
				{ value: 'ST', label: this.$t('arbeitszeitcheck', 'Saxony-Anhalt') },
				{ value: 'SH', label: this.$t('arbeitszeitcheck', 'Schleswig-Holstein') },
				{ value: 'TH', label: this.$t('arbeitszeitcheck', 'Thuringia') }
			]
		}
	},
	mounted() {
		this.initializeGermanStateOptions()
		this.loadSettings()
	},
	methods: {
		initializeGermanStateOptions() {
			this.germanStateOptions = [
				{ value: 'NW', label: this.$t('arbeitszeitcheck', 'North Rhine-Westphalia') },
				{ value: 'BY', label: this.$t('arbeitszeitcheck', 'Bavaria') },
				{ value: 'BW', label: this.$t('arbeitszeitcheck', 'Baden-Württemberg') },
				{ value: 'HE', label: this.$t('arbeitszeitcheck', 'Hesse') },
				{ value: 'NI', label: this.$t('arbeitszeitcheck', 'Lower Saxony') },
				{ value: 'RP', label: this.$t('arbeitszeitcheck', 'Rhineland-Palatinate') },
				{ value: 'SL', label: this.$t('arbeitszeitcheck', 'Saarland') },
				{ value: 'BE', label: this.$t('arbeitszeitcheck', 'Berlin') },
				{ value: 'BB', label: this.$t('arbeitszeitcheck', 'Brandenburg') },
				{ value: 'HB', label: this.$t('arbeitszeitcheck', 'Bremen') },
				{ value: 'HH', label: this.$t('arbeitszeitcheck', 'Hamburg') },
				{ value: 'MV', label: this.$t('arbeitszeitcheck', 'Mecklenburg-Vorpommern') },
				{ value: 'SN', label: this.$t('arbeitszeitcheck', 'Saxony') },
				{ value: 'ST', label: this.$t('arbeitszeitcheck', 'Saxony-Anhalt') },
				{ value: 'SH', label: this.$t('arbeitszeitcheck', 'Schleswig-Holstein') },
				{ value: 'TH', label: this.$t('arbeitszeitcheck', 'Thuringia') }
			]
		},
		async loadSettings() {
			this.isLoading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/arbeitszeitcheck/api/admin/settings')
				)
				if (response.data.success) {
					this.settings = { ...this.settings, ...response.data.settings }
				} else {
					this.showNotification(
						this.$t('arbeitszeitcheck', 'Failed to load settings'),
						'error'
					)
				}
			} catch (error) {
				console.error('Failed to load admin settings:', error)
				this.showNotification(
					this.$t('arbeitszeitcheck', 'Error loading settings. Please try again.'),
					'error'
				)
			} finally {
				this.isLoading = false
			}
		},
		async saveSettings() {
			this.isSaving = true
			try {
				const response = await axios.post(
					generateUrl('/apps/arbeitszeitcheck/api/admin/settings'),
					this.settings
				)
				if (response.data.success) {
					this.showNotification(
						this.$t('arbeitszeitcheck', 'Settings saved successfully'),
						'success'
					)
				} else {
					this.showNotification(
						response.data.error || this.$t('arbeitszeitcheck', 'Failed to save settings'),
						'error'
					)
				}
			} catch (error) {
				console.error('Failed to save settings:', error)
				const errorMessage = error.response?.data?.error || this.$t('arbeitszeitcheck', 'Error saving settings. Please try again.')
				this.showNotification(errorMessage, 'error')
			} finally {
				this.isSaving = false
			}
		},
		async runComplianceCheck() {
			this.isRunningComplianceCheck = true
			try {
				// Call compliance check endpoint
				const response = await axios.post(
					generateUrl('/apps/arbeitszeitcheck/api/compliance/run-check')
				)
				if (response.data.success) {
					this.showNotification(
						this.$t('arbeitszeitcheck', 'Compliance check completed successfully'),
						'success'
					)
				} else {
					this.showNotification(
						response.data.error || this.$t('arbeitszeitcheck', 'Failed to run compliance check'),
						'error'
					)
				}
			} catch (error) {
				console.error('Failed to run compliance check:', error)
				this.showNotification(
					error.response?.data?.error || this.$t('arbeitszeitcheck', 'Error running compliance check. Please try again.'),
					'error'
				)
			} finally {
				this.isRunningComplianceCheck = false
			}
		},
		navigateToWorkingTimeModels() {
			window.location.href = generateUrl('/apps/arbeitszeitcheck/admin/working-time-models')
		},
		navigateToCompliance() {
			window.location.href = generateUrl('/apps/arbeitszeitcheck/compliance')
		},
		navigateToComplianceReports() {
			window.location.href = generateUrl('/apps/arbeitszeitcheck/compliance/reports')
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
.timetracking-admin-settings {
	padding: var(--default-grid-baseline);
}

.timetracking-admin-settings__description {
	color: var(--color-text-maxcontrast);
	margin-bottom: calc(var(--default-grid-baseline) * 2);
}

.timetracking-admin-settings__sections {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
}

.timetracking-admin-settings__section {
	padding: var(--default-grid-baseline);
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius);
}

.timetracking-admin-settings__section h3 {
	margin: 0 0 var(--default-grid-baseline) 0;
	color: var(--color-main-text);
}

.timetracking-admin-settings__form {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
}

.timetracking-checkbox {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) / 2);
	cursor: pointer;
}

.timetracking-checkbox input[type="checkbox"] {
	cursor: pointer;
}

.timetracking-admin-settings__help {
	color: var(--color-text-maxcontrast);
	margin-bottom: calc(var(--default-grid-baseline) * 1.5);
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

.timetracking-section-title {
	font-size: 18px;
	font-weight: 600;
	color: var(--color-main-text);
	margin: 0 0 calc(var(--default-grid-baseline) * 2) 0;
}

.timetracking-form-group {
	margin-bottom: calc(var(--default-grid-baseline) * 2);
}

.timetracking-form-label {
	display: block;
	font-weight: 600;
	color: var(--color-main-text);
	margin-bottom: calc(var(--default-grid-baseline) * 0.5);
}

.timetracking-form-help {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin-top: calc(var(--default-grid-baseline) * 0.5);
	margin-bottom: 0;
}

.timetracking-admin-settings__actions {
	display: flex;
	justify-content: flex-end;
	margin-top: calc(var(--default-grid-baseline) * 2);
}

.timetracking-admin-settings__actions-inline {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 2);
	flex-wrap: wrap;
}
</style>