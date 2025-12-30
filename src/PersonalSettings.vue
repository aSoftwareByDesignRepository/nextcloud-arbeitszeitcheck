<template>
	<div class="timetracking-personal-settings">
		<h2>{{ $t('arbeitszeitcheck', 'Time Tracking Settings') }}</h2>
		<p class="timetracking-personal-settings__description">
			{{ $t('arbeitszeitcheck', 'Configure your personal time tracking preferences') }}
		</p>

		<div class="timetracking-personal-settings__sections">
			<!-- Vacation Settings -->
			<div class="timetracking-personal-settings__section">
				<h3>{{ $t('arbeitszeitcheck', 'Vacation Days') }}</h3>
				<div class="timetracking-personal-settings__form">
					<NcTextField
						v-model="settings.vacationDaysPerYear"
						type="number"
						:label="$t('arbeitszeitcheck', 'Vacation days per year')"
					/>
				</div>
			</div>

			<!-- Notification Settings -->
			<div class="timetracking-personal-settings__section">
				<h3>{{ $t('arbeitszeitcheck', 'Notifications') }}</h3>
				<div class="timetracking-personal-settings__form">
					<label class="timetracking-checkbox">
						<input
							v-model="settings.notificationsEnabled"
							type="checkbox"
							class="checkbox"
						/>
						{{ $t('arbeitszeitcheck', 'Enable notifications') }}
					</label>
					<label class="timetracking-checkbox">
						<input
							v-model="settings.breakRemindersEnabled"
							type="checkbox"
							class="checkbox"
						/>
						{{ $t('arbeitszeitcheck', 'Break reminders') }}
					</label>
				</div>
			</div>

			<!-- Data Export -->
			<div class="timetracking-personal-settings__section">
				<h3>{{ $t('arbeitszeitcheck', 'Data Export') }}</h3>
				<div class="timetracking-personal-settings__form">
					<NcButton type="secondary" @click="exportData">
						{{ $t('arbeitszeitcheck', 'Export My Data (GDPR)') }}
					</NcButton>
				</div>
			</div>

			<!-- Save Button -->
			<div class="timetracking-personal-settings__actions">
				<NcButton type="primary" @click="saveSettings">
					{{ $t('arbeitszeitcheck', 'Save Settings') }}
				</NcButton>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcTextField } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'PersonalSettings',
	components: {
		NcButton,
		NcTextField
	},
	data() {
		return {
			settings: {
				vacationDaysPerYear: 25,
				notificationsEnabled: true,
				breakRemindersEnabled: true
			}
		}
	},
	mounted() {
		this.loadSettings()
	},
	methods: {
		async loadSettings() {
			try {
				const response = await axios.get(generateUrl('/apps/arbeitszeitcheck/settings'))
				if (response.data.success) {
					this.settings = { ...this.settings, ...response.data.settings }
				}
			} catch (error) {
				console.error('Failed to load settings:', error)
			}
		},
		async saveSettings() {
			try {
				const response = await axios.post(generateUrl('/apps/arbeitszeitcheck/settings'), this.settings)
				if (response.data.success) {
					if (typeof OC !== 'undefined' && OC.Notification) {
						OC.Notification.showTemporary(this.$t('arbeitszeitcheck', 'Settings saved successfully'), {
							timeout: 5000,
							isHTML: false
						})
					}
				}
			} catch (error) {
				console.error('Failed to save settings:', error)
				if (typeof OC !== 'undefined' && OC.Notification) {
					OC.Notification.showTemporary(this.$t('arbeitszeitcheck', 'Failed to save settings'), {
						timeout: 5000,
						isHTML: false
					})
				}
			}
		},
		exportData() {
			// Trigger GDPR data export
			window.location.href = generateUrl('/apps/arbeitszeitcheck/gdpr/export')
		}
	}
}
</script>

<style scoped>
.timetracking-personal-settings {
	padding: var(--default-grid-baseline);
}

.timetracking-personal-settings__description {
	color: var(--color-text-maxcontrast);
	margin-bottom: calc(var(--default-grid-baseline) * 2);
}

.timetracking-personal-settings__sections {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
}

.timetracking-personal-settings__section {
	padding: var(--default-grid-baseline);
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius);
}

.timetracking-personal-settings__section h3 {
	margin: 0 0 var(--default-grid-baseline) 0;
	color: var(--color-main-text);
}

.timetracking-personal-settings__form {
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

.timetracking-personal-settings__actions {
	display: flex;
	justify-content: flex-end;
}
</style>