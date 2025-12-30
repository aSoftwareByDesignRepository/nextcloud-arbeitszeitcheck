<template>
	<div class="timetracking-working-time-models">
		<div class="timetracking-dashboard__header">
			<h2 class="timetracking-dashboard__title">{{ $t('arbeitszeitcheck', 'Working Time Models') }}</h2>
			<p class="timetracking-dashboard__subtitle">{{ $t('arbeitszeitcheck', 'Define and manage working time models for your organization') }}</p>
		</div>

		<div class="timetracking-dashboard__content">
			<!-- Actions Bar -->
			<div class="timetracking-section">
				<div class="timetracking-working-time-models__toolbar">
					<NcButton
						type="primary"
						@click="openCreateModal"
						:aria-label="$t('arbeitszeitcheck', 'Create new working time model')">
						<template #icon>
							<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
								<line x1="12" y1="5" x2="12" y2="19"/>
								<line x1="5" y1="12" x2="19" y2="12"/>
							</svg>
						</template>
						{{ $t('arbeitszeitcheck', 'Create Model') }}
					</NcButton>
				</div>
			</div>

			<!-- Models Table -->
			<div class="timetracking-section">
				<NcLoadingIcon v-if="isLoading" :size="32" />
				<NcEmptyContent
					v-else-if="models.length === 0"
					:title="$t('arbeitszeitcheck', 'No working time models')"
					:description="$t('arbeitszeitcheck', 'Create your first working time model to get started')">
					<template #icon>
						<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
							<circle cx="12" cy="12" r="10"/>
							<line x1="12" y1="8" x2="12" y2="12"/>
							<line x1="12" y1="16" x2="12.01" y2="16"/>
						</svg>
					</template>
					<template #action>
						<NcButton type="primary" @click="openCreateModal">
							{{ $t('arbeitszeitcheck', 'Create Model') }}
						</NcButton>
					</template>
				</NcEmptyContent>
				<table v-else class="timetracking-table">
					<thead>
						<tr>
							<th>{{ $t('arbeitszeitcheck', 'Name') }}</th>
							<th>{{ $t('arbeitszeitcheck', 'Type') }}</th>
							<th>{{ $t('arbeitszeitcheck', 'Weekly Hours') }}</th>
							<th>{{ $t('arbeitszeitcheck', 'Daily Hours') }}</th>
							<th>{{ $t('arbeitszeitcheck', 'Default') }}</th>
							<th>{{ $t('arbeitszeitcheck', 'Actions') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="model in models" :key="model.id">
							<td>
								<div class="timetracking-model-cell">
									<div class="timetracking-model-cell__name">{{ model.name }}</div>
									<div v-if="model.description" class="timetracking-model-cell__description">
										{{ model.description }}
									</div>
								</div>
							</td>
							<td>{{ formatType(model.type) }}</td>
							<td>{{ model.weeklyHours }}h</td>
							<td>{{ model.dailyHours }}h</td>
							<td>
								<span v-if="model.isDefault" class="timetracking-badge timetracking-badge--default">
									{{ $t('arbeitszeitcheck', 'Default') }}
								</span>
								<span v-else class="timetracking-text-muted">-</span>
							</td>
							<td>
								<NcButton
									type="tertiary"
									@click="editModel(model)"
									:aria-label="$t('arbeitszeitcheck', 'Edit working time model')">
									<template #icon>
										<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
											<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
											<path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
										</svg>
									</template>
									{{ $t('arbeitszeitcheck', 'Edit') }}
								</NcButton>
								<NcButton
									type="tertiary"
									@click="deleteModel(model)"
									:aria-label="$t('arbeitszeitcheck', 'Delete working time model')">
									<template #icon>
										<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
											<polyline points="3 6 5 6 21 6"/>
											<path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
										</svg>
									</template>
									{{ $t('arbeitszeitcheck', 'Delete') }}
								</NcButton>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>

		<!-- Create/Edit Modal -->
		<NcModal
			v-if="showModal"
			:name="editingModel ? $t('arbeitszeitcheck', 'Edit Working Time Model') : $t('arbeitszeitcheck', 'Create Working Time Model')"
			@close="closeModal"
			:size="'large'">
			<div class="timetracking-modal-content">
				<div class="timetracking-form-group">
					<label for="model-name" class="timetracking-form-label">
						{{ $t('arbeitszeitcheck', 'Name') }} <span class="timetracking-required">*</span>
					</label>
					<NcTextField
						id="model-name"
						v-model="form.name"
						:label="$t('arbeitszeitcheck', 'Name')"
						:required="true"
						:placeholder="$t('arbeitszeitcheck', 'e.g., Full-Time 40h')" />
				</div>

				<div class="timetracking-form-group">
					<label for="model-description" class="timetracking-form-label">
						{{ $t('arbeitszeitcheck', 'Description') }}
					</label>
					<textarea
						id="model-description"
						v-model="form.description"
						class="timetracking-textarea"
						rows="3"
						:placeholder="$t('arbeitszeitcheck', 'Optional description')"
					/>
				</div>

				<div class="timetracking-form-group">
					<label for="model-type" class="timetracking-form-label">
						{{ $t('arbeitszeitcheck', 'Type') }} <span class="timetracking-required">*</span>
					</label>
					<NcSelect
						id="model-type"
						v-model="form.type"
						:options="typeOptions"
						:label="$t('arbeitszeitcheck', 'Type')" />
				</div>

				<div class="timetracking-form-row">
					<div class="timetracking-form-group">
						<label for="model-weekly-hours" class="timetracking-form-label">
							{{ $t('arbeitszeitcheck', 'Weekly Hours') }} <span class="timetracking-required">*</span>
						</label>
						<NcTextField
							id="model-weekly-hours"
							v-model.number="form.weeklyHours"
							type="number"
							:min="1"
							:max="80"
							:step="0.5"
							:label="$t('arbeitszeitcheck', 'Weekly Hours')"
							:required="true" />
					</div>

					<div class="timetracking-form-group">
						<label for="model-daily-hours" class="timetracking-form-label">
							{{ $t('arbeitszeitcheck', 'Daily Hours') }} <span class="timetracking-required">*</span>
						</label>
						<NcTextField
							id="model-daily-hours"
							v-model.number="form.dailyHours"
							type="number"
							:min="1"
							:max="24"
							:step="0.5"
							:label="$t('arbeitszeitcheck', 'Daily Hours')"
							:required="true" />
					</div>
				</div>

				<div class="timetracking-form-group">
					<label class="timetracking-checkbox">
						<input
							v-model="form.isDefault"
							type="checkbox"
							class="checkbox" />
						{{ $t('arbeitszeitcheck', 'Set as default working time model') }}
					</label>
					<p class="timetracking-form-help">
						{{ $t('arbeitszeitcheck', 'New users will be assigned this model by default') }}
					</p>
				</div>

				<div class="timetracking-modal-actions">
					<NcButton
						type="secondary"
						@click="closeModal">
						{{ $t('arbeitszeitcheck', 'Cancel') }}
					</NcButton>
					<NcButton
						type="primary"
						:disabled="isSaving || !isFormValid"
						@click="saveModel">
						<template #icon>
							<NcLoadingIcon v-if="isSaving" :size="20" />
						</template>
						{{ isSaving ? $t('arbeitszeitcheck', 'Saving...') : $t('arbeitszeitcheck', 'Save') }}
					</NcButton>
				</div>
			</div>
		</NcModal>

		<!-- Delete Confirmation Modal -->
		<NcModal
			v-if="showDeleteModal"
			:name="$t('arbeitszeitcheck', 'Delete Working Time Model')"
			@close="closeDeleteModal">
			<div class="timetracking-modal-content">
				<p>{{ $t('arbeitszeitcheck', 'Are you sure you want to delete "{name}"?', { name: deletingModel?.name }) }}</p>
				<p class="timetracking-warning-text">
					{{ $t('arbeitszeitcheck', 'This action cannot be undone. Users assigned to this model will need to be reassigned.') }}
				</p>
				<div class="timetracking-modal-actions">
					<NcButton
						type="secondary"
						@click="closeDeleteModal">
						{{ $t('arbeitszeitcheck', 'Cancel') }}
					</NcButton>
					<NcButton
						type="primary"
						:disabled="isDeleting"
						@click="confirmDelete">
						<template #icon>
							<NcLoadingIcon v-if="isDeleting" :size="20" />
						</template>
						{{ isDeleting ? $t('arbeitszeitcheck', 'Deleting...') : $t('arbeitszeitcheck', 'Delete') }}
					</NcButton>
				</div>
			</div>
		</NcModal>
	</div>
</template>

<script>
import { NcButton, NcTextField, NcSelect, NcLoadingIcon, NcEmptyContent, NcModal } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'WorkingTimeModels',
	components: {
		NcButton,
		NcTextField,
		NcSelect,
		NcLoadingIcon,
		NcEmptyContent,
		NcModal
	},
	data() {
		return {
			isLoading: false,
			isSaving: false,
			isDeleting: false,
			models: [],
			showModal: false,
			showDeleteModal: false,
			editingModel: null,
			deletingModel: null,
			form: {
				name: '',
				description: '',
				type: 'full_time',
				weeklyHours: 40.0,
				dailyHours: 8.0,
				isDefault: false
			},
			typeOptions: [
				{ value: 'full_time', label: this.$t('arbeitszeitcheck', 'Full-Time') },
				{ value: 'part_time', label: this.$t('arbeitszeitcheck', 'Part-Time') },
				{ value: 'flexible', label: this.$t('arbeitszeitcheck', 'Flexible Hours') },
				{ value: 'trust_based', label: this.$t('arbeitszeitcheck', 'Trust-Based') },
				{ value: 'shift_work', label: this.$t('arbeitszeitcheck', 'Shift Work') }
			]
		}
	},
	computed: {
		isFormValid() {
			return this.form.name.trim() !== '' &&
				this.form.type !== '' &&
				this.form.weeklyHours > 0 &&
				this.form.dailyHours > 0 &&
				this.form.weeklyHours <= 80 &&
				this.form.dailyHours <= 24
		}
	},
	mounted() {
		this.loadModels()
	},
	methods: {
		async loadModels() {
			this.isLoading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/arbeitszeitcheck/api/admin/working-time-models')
				)
				if (response.data.success) {
					this.models = response.data.models
				} else {
					this.showNotification(
						this.$t('arbeitszeitcheck', 'Failed to load working time models'),
						'error'
					)
				}
			} catch (error) {
				console.error('Error loading working time models:', error)
				this.showNotification(
					this.$t('arbeitszeitcheck', 'Error loading working time models. Please try again.'),
					'error'
				)
			} finally {
				this.isLoading = false
			}
		},
		openCreateModal() {
			this.editingModel = null
			this.form = {
				name: '',
				description: '',
				type: 'full_time',
				weeklyHours: 40.0,
				dailyHours: 8.0,
				isDefault: false
			}
			this.showModal = true
		},
		async editModel(model) {
			this.editingModel = model
			try {
				const response = await axios.get(
					generateUrl(`/apps/arbeitszeitcheck/api/admin/working-time-models/${model.id}`)
				)
				if (response.data.success) {
					this.form = {
						name: response.data.model.name,
						description: response.data.model.description || '',
						type: response.data.model.type,
						weeklyHours: response.data.model.weeklyHours,
						dailyHours: response.data.model.dailyHours,
						isDefault: response.data.model.isDefault
					}
					this.showModal = true
				} else {
					this.showNotification(
						this.$t('arbeitszeitcheck', 'Failed to load working time model'),
						'error'
					)
				}
			} catch (error) {
				console.error('Error loading working time model:', error)
				this.showNotification(
					this.$t('arbeitszeitcheck', 'Error loading working time model. Please try again.'),
					'error'
				)
			}
		},
		async saveModel() {
			if (!this.isFormValid) {
				return
			}

			this.isSaving = true
			try {
				const url = this.editingModel
					? generateUrl(`/apps/arbeitszeitcheck/api/admin/working-time-models/${this.editingModel.id}`)
					: generateUrl('/apps/arbeitszeitcheck/api/admin/working-time-models')
				
				const method = this.editingModel ? 'put' : 'post'
				const response = await axios[method](url, this.form)
				
				if (response.data.success) {
					this.showNotification(
						this.$t('arbeitszeitcheck', 'Working time model saved successfully'),
						'success'
					)
					this.closeModal()
					this.loadModels()
				} else {
					this.showNotification(
						response.data.error || this.$t('arbeitszeitcheck', 'Failed to save working time model'),
						'error'
					)
				}
			} catch (error) {
				console.error('Error saving working time model:', error)
				const errorMessage = error.response?.data?.error || this.$t('arbeitszeitcheck', 'Error saving working time model. Please try again.')
				this.showNotification(errorMessage, 'error')
			} finally {
				this.isSaving = false
			}
		},
		deleteModel(model) {
			this.deletingModel = model
			this.showDeleteModal = true
		},
		async confirmDelete() {
			if (!this.deletingModel) {
				return
			}

			this.isDeleting = true
			try {
				const response = await axios.delete(
					generateUrl(`/apps/arbeitszeitcheck/api/admin/working-time-models/${this.deletingModel.id}`)
				)
				if (response.data.success) {
					this.showNotification(
						this.$t('arbeitszeitcheck', 'Working time model deleted successfully'),
						'success'
					)
					this.closeDeleteModal()
					this.loadModels()
				} else {
					this.showNotification(
						response.data.error || this.$t('arbeitszeitcheck', 'Failed to delete working time model'),
						'error'
					)
				}
			} catch (error) {
				console.error('Error deleting working time model:', error)
				const errorMessage = error.response?.data?.error || this.$t('arbeitszeitcheck', 'Error deleting working time model. Please try again.')
				this.showNotification(errorMessage, 'error')
			} finally {
				this.isDeleting = false
			}
		},
		closeModal() {
			this.showModal = false
			this.editingModel = null
			this.form = {
				name: '',
				description: '',
				type: 'full_time',
				weeklyHours: 40.0,
				dailyHours: 8.0,
				isDefault: false
			}
		},
		closeDeleteModal() {
			this.showDeleteModal = false
			this.deletingModel = null
		},
		formatType(type) {
			const typeMap = {
				'full_time': this.$t('arbeitszeitcheck', 'Full-Time'),
				'part_time': this.$t('arbeitszeitcheck', 'Part-Time'),
				'flexible': this.$t('arbeitszeitcheck', 'Flexible Hours'),
				'trust_based': this.$t('arbeitszeitcheck', 'Trust-Based'),
				'shift_work': this.$t('arbeitszeitcheck', 'Shift Work')
			}
			return typeMap[type] || type
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
.timetracking-working-time-models {
	padding: calc(var(--default-grid-baseline) * 2);
	max-width: 1400px;
	margin: 0 auto;
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

.timetracking-working-time-models__toolbar {
	display: flex;
	justify-content: flex-end;
}

.timetracking-model-cell {
	display: flex;
	flex-direction: column;
}

.timetracking-model-cell__name {
	font-weight: 600;
	color: var(--color-main-text);
}

.timetracking-model-cell__description {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin-top: calc(var(--default-grid-baseline) * 0.25);
}

.timetracking-badge {
	display: inline-block;
	padding: calc(var(--default-grid-baseline) * 0.25) calc(var(--default-grid-baseline) * 0.75);
	border-radius: var(--border-radius);
	font-size: 12px;
	font-weight: 600;
}

.timetracking-badge--default {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-text-dark);
}

.timetracking-text-muted {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.timetracking-modal-content {
	padding: calc(var(--default-grid-baseline) * 2);
}

.timetracking-form-group {
	margin-bottom: calc(var(--default-grid-baseline) * 2);
}

.timetracking-form-row {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: calc(var(--default-grid-baseline) * 2);
}

.timetracking-form-label {
	display: block;
	font-weight: 600;
	color: var(--color-main-text);
	margin-bottom: calc(var(--default-grid-baseline) * 0.5);
}

.timetracking-required {
	color: var(--color-error);
}

.timetracking-form-help {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin-top: calc(var(--default-grid-baseline) * 0.5);
	margin-bottom: 0;
}

.timetracking-checkbox {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 0.75);
	cursor: pointer;
}

.timetracking-checkbox input[type="checkbox"] {
	cursor: pointer;
}

.timetracking-modal-actions {
	display: flex;
	justify-content: flex-end;
	gap: calc(var(--default-grid-baseline) * 2);
	margin-top: calc(var(--default-grid-baseline) * 3);
	padding-top: calc(var(--default-grid-baseline) * 2);
	border-top: 1px solid var(--color-border);
}

.timetracking-warning-text {
	color: var(--color-warning);
	font-weight: 600;
	margin-top: calc(var(--default-grid-baseline) * 1.5);
}

@media (max-width: 768px) {
	.timetracking-form-row {
		grid-template-columns: 1fr;
	}
}
</style>
