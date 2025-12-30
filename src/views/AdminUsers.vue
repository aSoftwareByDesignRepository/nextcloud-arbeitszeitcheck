<template>
	<div class="timetracking-admin-users">
		<div class="timetracking-dashboard__header">
			<h2 class="timetracking-dashboard__title">{{ $t('arbeitszeitcheck', 'User Management') }}</h2>
			<p class="timetracking-dashboard__subtitle">{{ $t('arbeitszeitcheck', 'Manage employee profiles and working time models') }}</p>
		</div>

		<div class="timetracking-dashboard__content">
			<!-- Search and Actions Bar -->
			<div class="timetracking-section">
				<div class="timetracking-admin-users__toolbar">
					<NcTextField
						v-model="searchQuery"
						:placeholder="$t('arbeitszeitcheck', 'Search users...')"
						:label="$t('arbeitszeitcheck', 'Search')"
						@input="debouncedSearch"
						class="timetracking-admin-users__search">
						<template #icon>
							<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
								<circle cx="11" cy="11" r="8"/>
								<path d="m21 21-4.35-4.35"/>
							</svg>
						</template>
					</NcTextField>
					<div class="timetracking-admin-users__actions">
						<NcButton
							type="secondary"
							@click="exportUsers"
							:aria-label="$t('arbeitszeitcheck', 'Export users data')">
							<template #icon>
								<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
									<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
									<polyline points="7 10 12 15 17 10"/>
									<line x1="12" y1="15" x2="12" y2="3"/>
								</svg>
							</template>
							{{ $t('arbeitszeitcheck', 'Export Users') }}
						</NcButton>
					</div>
				</div>
			</div>

			<!-- Users Table -->
			<div class="timetracking-section">
				<NcLoadingIcon v-if="isLoading" :size="32" />
				<NcEmptyContent
					v-else-if="users.length === 0"
					:title="$t('arbeitszeitcheck', 'No users found')"
					:description="searchQuery ? $t('arbeitszeitcheck', 'Try adjusting your search query') : $t('arbeitszeitcheck', 'No users are registered in this Nextcloud instance')">
					<template #icon>
						<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
							<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
							<circle cx="9" cy="7" r="4"/>
							<path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
							<path d="M16 3.13a4 4 0 0 1 0 7.75"/>
						</svg>
					</template>
				</NcEmptyContent>
				<table v-else class="timetracking-table">
					<thead>
						<tr>
							<th>{{ $t('arbeitszeitcheck', 'User') }}</th>
							<th>{{ $t('arbeitszeitcheck', 'Email') }}</th>
							<th>{{ $t('arbeitszeitcheck', 'Working Time Model') }}</th>
							<th>{{ $t('arbeitszeitcheck', 'Vacation Days') }}</th>
							<th>{{ $t('arbeitszeitcheck', 'Status') }}</th>
							<th>{{ $t('arbeitszeitcheck', 'Actions') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="user in users" :key="user.userId">
							<td>
								<div class="timetracking-user-cell">
									<NcAvatar :user="user.userId" :size="32" />
									<div class="timetracking-user-cell__info">
										<div class="timetracking-user-cell__name">{{ user.displayName }}</div>
										<div class="timetracking-user-cell__id">{{ user.userId }}</div>
									</div>
								</div>
							</td>
							<td>
								<span v-if="user.email">{{ user.email }}</span>
								<span v-else class="timetracking-text-muted">-</span>
							</td>
							<td>
								<span v-if="user.workingTimeModel">{{ user.workingTimeModel.name }}</span>
								<span v-else class="timetracking-text-muted">{{ $t('arbeitszeitcheck', 'Not assigned') }}</span>
							</td>
							<td>
								<span v-if="user.vacationDaysPerYear !== null">{{ user.vacationDaysPerYear }}</span>
								<span v-else class="timetracking-text-muted">-</span>
							</td>
							<td>
								<span :class="user.enabled ? 'timetracking-status-badge--active' : 'timetracking-status-badge--inactive'">
									{{ user.enabled ? $t('arbeitszeitcheck', 'Active') : $t('arbeitszeitcheck', 'Disabled') }}
								</span>
							</td>
							<td>
								<NcButton
									type="tertiary"
									@click="editUser(user)"
									:aria-label="$t('arbeitszeitcheck', 'Edit user settings')">
									<template #icon>
										<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
											<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
											<path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
										</svg>
									</template>
									{{ $t('arbeitszeitcheck', 'Edit') }}
								</NcButton>
							</td>
						</tr>
					</tbody>
				</table>

				<!-- Pagination -->
				<div v-if="totalPages > 1" class="timetracking-pagination">
					<NcButton
						type="tertiary"
						:disabled="currentPage === 1"
						@click="goToPage(currentPage - 1)"
						:aria-label="$t('arbeitszeitcheck', 'Previous page')">
						{{ $t('arbeitszeitcheck', 'Previous') }}
					</NcButton>
					<span class="timetracking-pagination__info">
						{{ $t('arbeitszeitcheck', 'Page {current} of {total}', { current: currentPage, total: totalPages }) }}
					</span>
					<NcButton
						type="tertiary"
						:disabled="currentPage === totalPages"
						@click="goToPage(currentPage + 1)"
						:aria-label="$t('arbeitszeitcheck', 'Next page')">
						{{ $t('arbeitszeitcheck', 'Next') }}
					</NcButton>
				</div>
			</div>
		</div>

		<!-- Edit User Modal -->
		<NcModal
			v-if="editingUser"
			:name="$t('arbeitszeitcheck', 'Edit User Settings')"
			@close="closeEditModal"
			:size="'large'">
			<div class="timetracking-modal-content">
				<div v-if="isLoadingUserDetails" class="timetracking-modal-loading">
					<NcLoadingIcon :size="32" />
				</div>
				<div v-else-if="userDetails">
					<div class="timetracking-modal-section">
						<h3 class="timetracking-modal-section__title">{{ $t('arbeitszeitcheck', 'User Information') }}</h3>
						<div class="timetracking-form-group">
							<label class="timetracking-form-label">{{ $t('arbeitszeitcheck', 'Display Name') }}</label>
							<div class="timetracking-form-value">{{ userDetails.user.displayName }}</div>
						</div>
						<div class="timetracking-form-group">
							<label class="timetracking-form-label">{{ $t('arbeitszeitcheck', 'User ID') }}</label>
							<div class="timetracking-form-value">{{ userDetails.user.userId }}</div>
						</div>
						<div class="timetracking-form-group">
							<label class="timetracking-form-label">{{ $t('arbeitszeitcheck', 'Email') }}</label>
							<div class="timetracking-form-value">{{ userDetails.user.email || '-' }}</div>
						</div>
					</div>

					<div class="timetracking-modal-section">
						<h3 class="timetracking-modal-section__title">{{ $t('arbeitszeitcheck', 'Working Time Model') }}</h3>
						<div class="timetracking-form-group">
							<label for="working-time-model" class="timetracking-form-label">
								{{ $t('arbeitszeitcheck', 'Working Time Model') }}
							</label>
							<NcSelect
								id="working-time-model"
								v-model="editForm.workingTimeModelId"
								:options="workingTimeModelOptions"
								:placeholder="$t('arbeitszeitcheck', 'Select a working time model')"
								:label="$t('arbeitszeitcheck', 'Working Time Model')" />
						</div>
						<div class="timetracking-form-group">
							<label for="vacation-days" class="timetracking-form-label">
								{{ $t('arbeitszeitcheck', 'Vacation Days per Year') }}
							</label>
							<NcTextField
								id="vacation-days"
								v-model.number="editForm.vacationDaysPerYear"
								type="number"
								:min="0"
								:max="366"
								:label="$t('arbeitszeitcheck', 'Vacation Days per Year')" />
						</div>
						<div class="timetracking-form-group">
							<label for="start-date" class="timetracking-form-label">
								{{ $t('arbeitszeitcheck', 'Start Date') }}
							</label>
							<NcTextField
								id="start-date"
								v-model="editForm.startDate"
								type="text"
								placeholder="dd.mm.yyyy"
								pattern="\d{2}\.\d{2}\.\d{4}"
								:label="$t('arbeitszeitcheck', 'Start Date')"
								@blur="validateGermanDate('startDate')" />
						</div>
						<div class="timetracking-form-group">
							<label for="end-date" class="timetracking-form-label">
								{{ $t('arbeitszeitcheck', 'End Date') }} ({{ $t('arbeitszeitcheck', 'optional') }})
							</label>
							<NcTextField
								id="end-date"
								v-model="editForm.endDate"
								type="text"
								placeholder="dd.mm.yyyy"
								pattern="\d{2}\.\d{2}\.\d{4}"
								:label="$t('arbeitszeitcheck', 'End Date')"
								@blur="validateGermanDate('endDate')" />
						</div>
					</div>

					<div class="timetracking-modal-actions">
						<NcButton
							type="secondary"
							@click="closeEditModal">
							{{ $t('arbeitszeitcheck', 'Cancel') }}
						</NcButton>
						<NcButton
							type="primary"
							:disabled="isSaving"
							@click="saveUserSettings">
							<template #icon>
								<NcLoadingIcon v-if="isSaving" :size="20" />
							</template>
							{{ isSaving ? $t('arbeitszeitcheck', 'Saving...') : $t('arbeitszeitcheck', 'Save') }}
						</NcButton>
					</div>
				</div>
			</div>
		</NcModal>
	</div>
</template>

<script>
import { NcButton, NcTextField, NcSelect, NcLoadingIcon, NcEmptyContent, NcModal, NcAvatar } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { formatDateGerman, parseGermanDate } from '../utils/dateUtils.js'

export default {
	name: 'AdminUsers',
	components: {
		NcButton,
		NcTextField,
		NcSelect,
		NcLoadingIcon,
		NcEmptyContent,
		NcModal,
		NcAvatar
	},
	data() {
		return {
			isLoading: false,
			isLoadingUserDetails: false,
			isSaving: false,
			users: [],
			searchQuery: '',
			currentPage: 1,
			pageSize: 50,
			totalUsers: 0,
			editingUser: null,
			userDetails: null,
			workingTimeModels: [],
			editForm: {
				workingTimeModelId: null,
				vacationDaysPerYear: 25,
				startDate: formatDateGerman(new Date()),
				endDate: null
			},
			searchTimeout: null
		}
	},
	computed: {
		totalPages() {
			return Math.ceil(this.totalUsers / this.pageSize)
		},
		workingTimeModelOptions() {
			return this.workingTimeModels.map(model => ({
				value: model.id,
				label: model.name
			}))
		}
	},
	mounted() {
		this.loadUsers()
		this.loadWorkingTimeModels()
	},
	methods: {
		async loadUsers() {
			this.isLoading = true
			try {
				const response = await axios.get(
					generateUrl('/apps/arbeitszeitcheck/api/admin/users'),
					{
						params: {
							search: this.searchQuery || null,
							limit: this.pageSize,
							offset: (this.currentPage - 1) * this.pageSize
						}
					}
				)
				if (response.data.success) {
					this.users = response.data.users
					this.totalUsers = response.data.total
				} else {
					this.showNotification(
						this.$t('arbeitszeitcheck', 'Failed to load users'),
						'error'
					)
				}
			} catch (error) {
				console.error('Error loading users:', error)
				this.showNotification(
					this.$t('arbeitszeitcheck', 'Error loading users. Please try again.'),
					'error'
				)
			} finally {
				this.isLoading = false
			}
		},
		async loadWorkingTimeModels() {
			try {
				const response = await axios.get(
					generateUrl('/apps/arbeitszeitcheck/api/admin/working-time-models')
				)
				if (response.data.success) {
					this.workingTimeModels = response.data.models
				}
			} catch (error) {
				console.error('Error loading working time models:', error)
			}
		},
		debouncedSearch() {
			if (this.searchTimeout) {
				clearTimeout(this.searchTimeout)
			}
			this.searchTimeout = setTimeout(() => {
				this.currentPage = 1
				this.loadUsers()
			}, 300)
		},
		async editUser(user) {
			this.editingUser = user
			this.isLoadingUserDetails = true
			try {
				const response = await axios.get(
					generateUrl(`/apps/arbeitszeitcheck/api/admin/users/${user.userId}`)
				)
				if (response.data.success) {
					this.userDetails = response.data
					// Populate form with current values
					if (this.userDetails.user.userWorkingTimeModel) {
						this.editForm.workingTimeModelId = this.userDetails.user.userWorkingTimeModel.workingTimeModelId
						this.editForm.vacationDaysPerYear = this.userDetails.user.userWorkingTimeModel.vacationDaysPerYear
						this.editForm.startDate = this.userDetails.user.userWorkingTimeModel.startDate ? formatDateGerman(this.userDetails.user.userWorkingTimeModel.startDate) : formatDateGerman(new Date())
						this.editForm.endDate = this.userDetails.user.userWorkingTimeModel.endDate ? formatDateGerman(this.userDetails.user.userWorkingTimeModel.endDate) : null
					} else {
						// Default values
						this.editForm.workingTimeModelId = null
						this.editForm.vacationDaysPerYear = 25
						this.editForm.startDate = formatDateGerman(new Date())
						this.editForm.endDate = null
					}
				} else {
					this.showNotification(
						this.$t('arbeitszeitcheck', 'Failed to load user details'),
						'error'
					)
					this.closeEditModal()
				}
			} catch (error) {
				console.error('Error loading user details:', error)
				this.showNotification(
					this.$t('arbeitszeitcheck', 'Error loading user details. Please try again.'),
					'error'
				)
				this.closeEditModal()
			} finally {
				this.isLoadingUserDetails = false
			}
		},
		validateGermanDate(field) {
			if (this.editForm[field] && !parseGermanDate(this.editForm[field])) {
				this.editForm[field] = ''
			}
		},
		async saveUserSettings() {
			if (!this.editingUser) {
				return
			}

			// Convert German dates to ISO format for API
			const formData = {
				...this.editForm,
				startDate: parseGermanDate(this.editForm.startDate) || this.editForm.startDate,
				endDate: this.editForm.endDate ? (parseGermanDate(this.editForm.endDate) || this.editForm.endDate) : null
			}

			this.isSaving = true
			try {
				const response = await axios.put(
					generateUrl(`/apps/arbeitszeitcheck/api/admin/users/${this.editingUser.userId}/working-time-model`),
					formData
				)
				if (response.data.success) {
					this.showNotification(
						this.$t('arbeitszeitcheck', 'User settings saved successfully'),
						'success'
					)
					this.closeEditModal()
					this.loadUsers() // Refresh list
				} else {
					this.showNotification(
						response.data.error || this.$t('arbeitszeitcheck', 'Failed to save user settings'),
						'error'
					)
				}
			} catch (error) {
				console.error('Error saving user settings:', error)
				const errorMessage = error.response?.data?.error || this.$t('arbeitszeitcheck', 'Error saving user settings. Please try again.')
				this.showNotification(errorMessage, 'error')
			} finally {
				this.isSaving = false
			}
		},
		closeEditModal() {
			this.editingUser = null
			this.userDetails = null
			this.editForm = {
				workingTimeModelId: null,
				vacationDaysPerYear: 25,
				startDate: formatDateGerman(new Date()),
				endDate: null
			}
		},
		goToPage(page) {
			if (page >= 1 && page <= this.totalPages) {
				this.currentPage = page
				this.loadUsers()
			}
		},
		exportUsers() {
			// Export users data as CSV
			const exportUrl = generateUrl('/apps/arbeitszeitcheck/api/admin/users/export') + '?format=csv'
			window.location.href = exportUrl
			this.showNotification(
				this.$t('arbeitszeitcheck', 'Exporting users data...'),
				'success'
			)
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
.timetracking-admin-users {
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

.timetracking-admin-users__toolbar {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 2);
	align-items: center;
}

.timetracking-admin-users__search {
	flex: 1;
	max-width: 400px;
}

.timetracking-admin-users__actions {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 2);
}

.timetracking-user-cell {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 1.5);
}

.timetracking-user-cell__info {
	display: flex;
	flex-direction: column;
}

.timetracking-user-cell__name {
	font-weight: 600;
	color: var(--color-main-text);
}

.timetracking-user-cell__id {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.timetracking-text-muted {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.timetracking-status-badge--active {
	color: var(--color-success);
	font-weight: 600;
}

.timetracking-status-badge--inactive {
	color: var(--color-text-maxcontrast);
}

.timetracking-pagination {
	display: flex;
	justify-content: center;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
	margin-top: calc(var(--default-grid-baseline) * 2);
	padding-top: calc(var(--default-grid-baseline) * 2);
	border-top: 1px solid var(--color-border);
}

.timetracking-pagination__info {
	color: var(--color-text-maxcontrast);
}

.timetracking-modal-content {
	padding: calc(var(--default-grid-baseline) * 2);
}

.timetracking-modal-loading {
	display: flex;
	justify-content: center;
	align-items: center;
	min-height: 200px;
}

.timetracking-modal-section {
	margin-bottom: calc(var(--default-grid-baseline) * 3);
}

.timetracking-modal-section__title {
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

.timetracking-form-value {
	color: var(--color-main-text);
	padding: calc(var(--default-grid-baseline) * 0.75) 0;
}

.timetracking-modal-actions {
	display: flex;
	justify-content: flex-end;
	gap: calc(var(--default-grid-baseline) * 2);
	margin-top: calc(var(--default-grid-baseline) * 3);
	padding-top: calc(var(--default-grid-baseline) * 2);
	border-top: 1px solid var(--color-border);
}

@media (max-width: 768px) {
	.timetracking-admin-users__toolbar {
		flex-direction: column;
		align-items: stretch;
	}

	.timetracking-admin-users__search {
		max-width: 100%;
	}
}
</style>
