<template>
	<NcModal
		v-if="showTour"
		:name="$t('arbeitszeitcheck', 'Welcome to ArbeitszeitCheck')"
		:show-close="false"
		:can-close="false"
		:size="'large'"
		@close="skipTour"
	>
		<div class="timetracking-onboarding">
			<div class="timetracking-onboarding__content">
				<!-- Step 1: Welcome -->
				<div v-if="currentStep === 0" class="timetracking-onboarding__step">
					<div class="timetracking-onboarding__icon" aria-hidden="true">
						⏰
					</div>
					<h2 class="timetracking-onboarding__title">
						{{ $t('arbeitszeitcheck', 'Welcome to ArbeitszeitCheck') }}
					</h2>
					<p class="timetracking-onboarding__description">
						{{ $t('arbeitszeitcheck', 'This quick tour will help you get started with time tracking. You can skip this tour at any time.') }}
					</p>
					<p class="timetracking-onboarding__description">
						{{ $t('arbeitszeitcheck', 'ArbeitszeitCheck helps you track your working hours while ensuring compliance with German labor law (ArbZG) and GDPR requirements.') }}
					</p>
				</div>

				<!-- Step 2: Clock In/Out -->
				<div v-if="currentStep === 1" class="timetracking-onboarding__step">
					<div class="timetracking-onboarding__icon" aria-hidden="true">
						▶️
					</div>
					<h2 class="timetracking-onboarding__title">
						{{ $t('arbeitszeitcheck', 'Clock In and Out') }}
					</h2>
					<p class="timetracking-onboarding__description">
						{{ $t('arbeitszeitcheck', 'Use the "Clock In" button to start tracking your work time. When you finish, click "Clock Out" to stop.') }}
					</p>
					<p class="timetracking-onboarding__description">
						{{ $t('arbeitszeitcheck', 'The system automatically tracks your working hours and ensures compliance with legal requirements.') }}
					</p>
				</div>

				<!-- Step 3: Breaks -->
				<div v-if="currentStep === 2" class="timetracking-onboarding__step">
					<div class="timetracking-onboarding__icon" aria-hidden="true">
						⏸️
					</div>
					<h2 class="timetracking-onboarding__title">
						{{ $t('arbeitszeitcheck', 'Take Breaks') }}
					</h2>
					<p class="timetracking-onboarding__description">
						{{ $t('arbeitszeitcheck', 'After 6 hours of work, you need at least 30 minutes of break time. After 9 hours, you need 45 minutes.') }}
					</p>
					<p class="timetracking-onboarding__description">
						{{ $t('arbeitszeitcheck', 'Use "Start Break" and "End Break" to record your breaks. The system will remind you if you forget.') }}
					</p>
				</div>

				<!-- Step 4: Dashboard -->
				<div v-if="currentStep === 3" class="timetracking-onboarding__step">
					<div class="timetracking-onboarding__icon" aria-hidden="true">
						📊
					</div>
					<h2 class="timetracking-onboarding__title">
						{{ $t('arbeitszeitcheck', 'Your Dashboard') }}
					</h2>
					<p class="timetracking-onboarding__description">
						{{ $t('arbeitszeitcheck', 'The dashboard shows your daily, weekly, and monthly working hours, overtime balance, and vacation days remaining.') }}
					</p>
					<p class="timetracking-onboarding__description">
						{{ $t('arbeitszeitcheck', 'You can also view your time entries in a calendar or timeline format.') }}
					</p>
				</div>

				<!-- Step 5: Absences -->
				<div v-if="currentStep === 4" class="timetracking-onboarding__step">
					<div class="timetracking-onboarding__icon" aria-hidden="true">
						📅
					</div>
					<h2 class="timetracking-onboarding__title">
						{{ $t('arbeitszeitcheck', 'Request Absences') }}
					</h2>
					<p class="timetracking-onboarding__description">
						{{ $t('arbeitszeitcheck', 'You can request vacation days, sick leave, or other absences through the Absences section.') }}
					</p>
					<p class="timetracking-onboarding__description">
						{{ $t('arbeitszeitcheck', 'Your manager will review and approve or reject your requests.') }}
					</p>
				</div>

				<!-- Step 6: Data Export -->
				<div v-if="currentStep === 5" class="timetracking-onboarding__step">
					<div class="timetracking-onboarding__icon" aria-hidden="true">
						⬇️
					</div>
					<h2 class="timetracking-onboarding__title">
						{{ $t('arbeitszeitcheck', 'Export Your Data') }}
					</h2>
					<p class="timetracking-onboarding__description">
						{{ $t('arbeitszeitcheck', 'Under GDPR, you have the right to access your personal data. You can export all your time tracking data at any time.') }}
					</p>
					<p class="timetracking-onboarding__description">
						{{ $t('arbeitszeitcheck', 'Go to Settings to export your data in JSON format.') }}
					</p>
				</div>

				<!-- Step 7: Complete -->
				<div v-if="currentStep === 6" class="timetracking-onboarding__step">
					<div class="timetracking-onboarding__icon" aria-hidden="true">
						✅
					</div>
					<h2 class="timetracking-onboarding__title">
						{{ $t('arbeitszeitcheck', "You're All Set!") }}
					</h2>
					<p class="timetracking-onboarding__description">
						{{ $t('arbeitszeitcheck', 'You now know the basics of ArbeitszeitCheck. Start by clocking in to begin tracking your time!') }}
					</p>
					<p class="timetracking-onboarding__description">
						{{ $t('arbeitszeitcheck', 'If you need help at any time, check the documentation or contact your administrator.') }}
					</p>
				</div>
			</div>

			<!-- Progress Indicator -->
			<div class="timetracking-onboarding__progress">
				<div
					v-for="(step, index) in totalSteps"
					:key="index"
					class="timetracking-onboarding__progress-dot"
					:class="{
						'timetracking-onboarding__progress-dot--active': index === currentStep,
						'timetracking-onboarding__progress-dot--completed': index < currentStep
					}"
					:aria-label="$t('arbeitszeitcheck', 'Step {step} of {total}', { step: index + 1, total: totalSteps })"
					role="button"
					tabindex="0"
					@click="goToStep(index)"
					@keydown.enter="goToStep(index)"
					@keydown.space.prevent="goToStep(index)"
				/>
			</div>

			<!-- Navigation Buttons -->
			<div class="timetracking-onboarding__actions">
				<NcButton
					type="tertiary"
					:aria-label="$t('arbeitszeitcheck', 'Skip tour')"
					@click="skipTour"
				>
					{{ $t('arbeitszeitcheck', 'Skip Tour') }}
				</NcButton>
				<div class="timetracking-onboarding__actions-right">
					<NcButton
						v-if="currentStep > 0"
						type="secondary"
						:aria-label="$t('arbeitszeitcheck', 'Previous step')"
						@click="previousStep"
					>
						{{ $t('arbeitszeitcheck', 'Previous') }}
					</NcButton>
					<NcButton
						v-if="currentStep < totalSteps - 1"
						type="primary"
						:aria-label="$t('arbeitszeitcheck', 'Next step')"
						@click="nextStep"
					>
						{{ $t('arbeitszeitcheck', 'Next') }}
					</NcButton>
					<NcButton
						v-else
						type="primary"
						:aria-label="$t('arbeitszeitcheck', 'Finish tour')"
						@click="finishTour"
					>
						{{ $t('arbeitszeitcheck', 'Get Started') }}
					</NcButton>
				</div>
			</div>
		</div>
	</NcModal>
</template>

<script>
import { NcModal, NcButton } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
// Using simple emoji/unicode icons instead of external icon library for compatibility

export default {
	name: 'OnboardingTour',
	components: {
		NcModal,
		NcButton
	},
	data() {
		return {
			showTour: false,
			currentStep: 0,
			totalSteps: 7
		}
	},
	mounted() {
		this.checkIfShouldShowTour()
	},
	methods: {
		async checkIfShouldShowTour() {
			try {
				const response = await axios.get(generateUrl('/apps/arbeitszeitcheck/api/settings/onboarding-completed'))
				if (response.data && response.data.success && !response.data.completed) {
					this.showTour = true
				}
			} catch (error) {
				// Silently fail - this is expected if the table doesn't exist yet or there's a 500 error
				// Don't show tour if there's any error (400, 500, etc.)
				// This prevents console errors from appearing
			}
		},
		async markTourCompleted() {
			try {
				await axios.post(generateUrl('/apps/arbeitszeitcheck/api/settings/onboarding-completed'), {
					completed: true
				})
			} catch (error) {
				console.error('Error marking tour as completed:', error)
			}
		},
		nextStep() {
			if (this.currentStep < this.totalSteps - 1) {
				this.currentStep++
			}
		},
		previousStep() {
			if (this.currentStep > 0) {
				this.currentStep--
			}
		},
		goToStep(step) {
			if (step >= 0 && step < this.totalSteps) {
				this.currentStep = step
			}
		},
		async skipTour() {
			await this.markTourCompleted()
			this.showTour = false
		},
		async finishTour() {
			await this.markTourCompleted()
			this.showTour = false
		}
	}
}
</script>

<style scoped>
.timetracking-onboarding {
	padding: calc(var(--default-grid-baseline) * 3);
}

.timetracking-onboarding__content {
	min-height: 300px;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	text-align: center;
}

.timetracking-onboarding__step {
	width: 100%;
	max-width: 600px;
}

.timetracking-onboarding__icon {
	margin-bottom: calc(var(--default-grid-baseline) * 2);
	font-size: 64px;
	line-height: 1;
}

.timetracking-onboarding__title {
	font-size: 24px;
	font-weight: bold;
	color: var(--color-main-text);
	margin: 0 0 calc(var(--default-grid-baseline) * 2) 0;
}

.timetracking-onboarding__description {
	font-size: 16px;
	color: var(--color-text-maxcontrast);
	line-height: 1.6;
	margin: 0 0 calc(var(--default-grid-baseline) * 1.5) 0;
}

.timetracking-onboarding__progress {
	display: flex;
	justify-content: center;
	gap: calc(var(--default-grid-baseline) * 1);
	margin: calc(var(--default-grid-baseline) * 3) 0;
}

.timetracking-onboarding__progress-dot {
	width: 12px;
	height: 12px;
	border-radius: 50%;
	background: var(--color-background-dark);
	border: 2px solid var(--color-border);
	cursor: pointer;
	transition: all 0.2s ease;
}

.timetracking-onboarding__progress-dot:hover,
.timetracking-onboarding__progress-dot:focus {
	background: var(--color-primary-element-light);
	border-color: var(--color-primary);
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
}

.timetracking-onboarding__progress-dot--active {
	background: var(--color-primary);
	border-color: var(--color-primary);
	transform: scale(1.2);
}

.timetracking-onboarding__progress-dot--completed {
	background: var(--color-success);
	border-color: var(--color-success);
}

.timetracking-onboarding__actions {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-top: calc(var(--default-grid-baseline) * 3);
	padding-top: calc(var(--default-grid-baseline) * 2);
	border-top: 1px solid var(--color-border);
}

.timetracking-onboarding__actions-right {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 1);
}

@media (max-width: 768px) {
	.timetracking-onboarding {
		padding: calc(var(--default-grid-baseline) * 2);
	}

	.timetracking-onboarding__title {
		font-size: 20px;
	}

	.timetracking-onboarding__description {
		font-size: 14px;
	}

	.timetracking-onboarding__actions {
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 1);
	}

	.timetracking-onboarding__actions-right {
		width: 100%;
		flex-direction: column;
	}

	.timetracking-onboarding__actions-right .nc-button {
		width: 100%;
	}
}
</style>
