<template>
	<div class="timetracking-manager-dashboard">
		<div class="timetracking-dashboard__header">
			<h2 class="timetracking-dashboard__title">{{ $t('arbeitszeitcheck', 'Manager Dashboard') }}</h2>
			<p class="timetracking-dashboard__subtitle">{{ $t('arbeitszeitcheck', 'Oversee team time tracking and manage approvals') }}</p>
		</div>

		<div class="timetracking-dashboard__content">
			<!-- Overview Cards -->
			<div class="timetracking-overview-cards">
				<div class="timetracking-overview-card">
					<div class="timetracking-overview-card__icon" aria-hidden="true">👥</div>
					<div class="timetracking-overview-card__content">
						<div class="timetracking-overview-card__value">{{ teamOverview.total || 0 }}</div>
						<div class="timetracking-overview-card__label">{{ $t('arbeitszeitcheck', 'Team Members') }}</div>
					</div>
				</div>

				<div class="timetracking-overview-card">
					<div class="timetracking-overview-card__icon" aria-hidden="true">⏰</div>
					<div class="timetracking-overview-card__content">
						<div class="timetracking-overview-card__value">{{ pendingApprovals.length }}</div>
						<div class="timetracking-overview-card__label">{{ $t('arbeitszeitcheck', 'Pending Approvals') }}</div>
					</div>
				</div>

				<div class="timetracking-overview-card timetracking-overview-card--warning">
					<div class="timetracking-overview-card__icon" aria-hidden="true">⚠️</div>
					<div class="timetracking-overview-card__content">
						<div class="timetracking-overview-card__value">{{ complianceOverview.membersWithViolations || 0 }}</div>
						<div class="timetracking-overview-card__label">{{ $t('arbeitszeitcheck', 'Compliance Issues') }}</div>
					</div>
				</div>

				<div class="timetracking-overview-card">
					<div class="timetracking-overview-card__icon" aria-hidden="true">📊</div>
					<div class="timetracking-overview-card__content">
						<div class="timetracking-overview-card__value">{{ formatHours(teamHoursSummary.totalHours || 0) }}</div>
						<div class="timetracking-overview-card__label">{{ $t('arbeitszeitcheck', 'Team Hours This Week') }}</div>
					</div>
				</div>
			</div>

			<!-- Team Overview Table -->
			<div class="timetracking-section">
				<div class="timetracking-section__header">
					<h3 class="timetracking-section-title">{{ $t('arbeitszeitcheck', 'Team Overview') }}</h3>
				</div>

				<NcLoadingIcon v-if="isLoadingTeam" :size="32" />
				<NcEmptyContent
					v-else-if="teamMembers.length === 0"
					:title="$t('arbeitszeitcheck', 'No team members found')"
					:description="$t('arbeitszeitcheck', 'Team members are determined by Nextcloud groups. Add users to groups to see them here.')"
				>
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
							<th>{{ $t('arbeitszeitcheck', 'Employee') }}</th>
							<th>{{ $t('arbeitszeitcheck', 'Today') }}</th>
							<th>{{ $t('arbeitszeitcheck', 'This Week') }}</th>
							<th>{{ $t('arbeitszeitcheck', 'Overtime') }}</th>
							<th>{{ $t('arbeitszeitcheck', 'Status') }}</th>
							<th>{{ $t('arbeitszeitcheck', 'Compliance') }}</th>
							<th>{{ $t('arbeitszeitcheck', 'Actions') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="member in teamMembers" :key="member.userId">
							<td>
								<div class="timetracking-employee-name">{{ member.displayName }}</div>
							</td>
							<td>{{ formatHours(member.todayHours) }}</td>
							<td>{{ formatHours(member.weekHours) }}</td>
							<td>
								<span :class="member.overtimeHours > 0 ? 'timetracking-overtime--positive' : ''">
									{{ formatHours(member.overtimeHours) }}
								</span>
							</td>
							<td>
								<span :class="getStatusClass(member.currentStatus)">
									{{ getStatusText(member.currentStatus) }}
								</span>
							</td>
							<td>
								<span :class="getComplianceClass(member.complianceStatus)">
									{{ getComplianceText(member.complianceStatus) }}
								</span>
							</td>
							<td>
								<NcButton
									type="tertiary"
									:aria-label="$t('arbeitszeitcheck', 'View employee details')"
									@click="viewEmployee(member.userId)"
								>
									{{ $t('arbeitszeitcheck', 'View') }}
								</NcButton>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<!-- Pending Approvals -->
			<div class="timetracking-section">
				<div class="timetracking-section__header">
					<h3 class="timetracking-section-title">{{ $t('arbeitszeitcheck', 'Pending Approvals') }}</h3>
				</div>

				<NcLoadingIcon v-if="isLoadingApprovals" :size="32" />
				<NcEmptyContent
					v-else-if="pendingApprovals.length === 0"
					:title="$t('arbeitszeitcheck', 'No pending approvals')"
					:description="$t('arbeitszeitcheck', 'All requests have been processed')"
				>
					<template #icon>
						<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
							<circle cx="12" cy="12" r="10"/>
							<path d="M9 12l2 2 4-4"/>
						</svg>
					</template>
				</NcEmptyContent>
				<div v-else class="timetracking-approvals-list">
					<div v-for="approval in pendingApprovals" :key="`${approval.type}-${approval.id}`" class="timetracking-approval-item">
						<div class="timetracking-approval-item__content">
							<div class="timetracking-approval-item__header">
								<span class="timetracking-approval-item__employee">{{ approval.displayName }}</span>
								<span v-if="approval.type === 'absence'" class="timetracking-approval-badge timetracking-approval-badge--absence">
									{{ $t('arbeitszeitcheck', 'Absence Request') }}
								</span>
								<span v-else-if="approval.type === 'time_entry'" class="timetracking-approval-badge timetracking-approval-badge--time-entry">
									{{ $t('arbeitszeitcheck', 'Time Entry Correction') }}
								</span>
							</div>
							<div class="timetracking-approval-item__details">
								<div v-if="approval.summary" class="timetracking-approval-details">
									<!-- Absence Request Details -->
									<template v-if="approval.type === 'absence'">
										<div v-if="approval.summary.type" class="timetracking-approval-detail">
											<span class="timetracking-approval-detail__label">{{ $t('arbeitszeitcheck', 'Type') }}:</span>
											<span class="timetracking-approval-detail__value">{{ formatAbsenceType(approval.summary.type) }}</span>
										</div>
										<div v-if="approval.summary.startDate" class="timetracking-approval-detail">
											<span class="timetracking-approval-detail__label">{{ $t('arbeitszeitcheck', 'Start Date') }}:</span>
											<span class="timetracking-approval-detail__value">{{ formatDate(approval.summary.startDate) }}</span>
										</div>
										<div v-if="approval.summary.endDate" class="timetracking-approval-detail">
											<span class="timetracking-approval-detail__label">{{ $t('arbeitszeitcheck', 'End Date') }}:</span>
											<span class="timetracking-approval-detail__value">{{ formatDate(approval.summary.endDate) }}</span>
										</div>
										<div v-if="approval.summary.days" class="timetracking-approval-detail">
											<span class="timetracking-approval-detail__label">{{ $t('arbeitszeitcheck', 'Days') }}:</span>
											<span class="timetracking-approval-detail__value">{{ approval.summary.days }}</span>
										</div>
										<div v-if="approval.summary.reason" class="timetracking-approval-detail">
											<span class="timetracking-approval-detail__label">{{ $t('arbeitszeitcheck', 'Reason') }}:</span>
											<span class="timetracking-approval-detail__value">{{ approval.summary.reason }}</span>
										</div>
									</template>
									<!-- Time Entry Correction Details -->
									<template v-else-if="approval.type === 'time_entry'">
										<div v-if="approval.summary.date" class="timetracking-approval-detail">
											<span class="timetracking-approval-detail__label">{{ $t('arbeitszeitcheck', 'Date') }}:</span>
											<span class="timetracking-approval-detail__value">{{ formatDate(approval.summary.date) }}</span>
										</div>
										<div v-if="approval.summary.startTime" class="timetracking-approval-detail">
											<span class="timetracking-approval-detail__label">{{ $t('arbeitszeitcheck', 'Start Time') }}:</span>
											<span class="timetracking-approval-detail__value">{{ approval.summary.startTime }}</span>
										</div>
										<div v-if="approval.summary.endTime" class="timetracking-approval-detail">
											<span class="timetracking-approval-detail__label">{{ $t('arbeitszeitcheck', 'End Time') }}:</span>
											<span class="timetracking-approval-detail__value">{{ approval.summary.endTime }}</span>
										</div>
										<div v-if="approval.summary.durationHours" class="timetracking-approval-detail">
											<span class="timetracking-approval-detail__label">{{ $t('arbeitszeitcheck', 'Duration') }}:</span>
											<span class="timetracking-approval-detail__value">{{ formatHours(approval.summary.durationHours) }}</span>
										</div>
										<div v-if="approval.summary.justification" class="timetracking-approval-detail">
											<span class="timetracking-approval-detail__label">{{ $t('arbeitszeitcheck', 'Justification') }}:</span>
											<span class="timetracking-approval-detail__value">{{ approval.summary.justification }}</span>
										</div>
										<div v-if="approval.summary.original && Object.keys(approval.summary.original).length > 0" class="timetracking-approval-detail">
											<span class="timetracking-approval-detail__label">{{ $t('arbeitszeitcheck', 'Original') }}:</span>
											<span class="timetracking-approval-detail__value">
												{{ formatDate(approval.summary.original.date) }} - {{ formatHours(approval.summary.original.hours) }}
											</span>
										</div>
										<div v-if="approval.summary.proposed && Object.keys(approval.summary.proposed).length > 0" class="timetracking-approval-detail">
											<span class="timetracking-approval-detail__label">{{ $t('arbeitszeitcheck', 'Proposed') }}:</span>
											<span class="timetracking-approval-detail__value">
												{{ approval.summary.proposed.date ? formatDate(approval.summary.proposed.date) : formatDate(approval.summary.date) }} - 
												{{ approval.summary.proposed.hours ? formatHours(approval.summary.proposed.hours) : formatHours(approval.summary.durationHours) }}
											</span>
										</div>
										<div v-if="approval.summary.description" class="timetracking-approval-detail">
											<span class="timetracking-approval-detail__label">{{ $t('arbeitszeitcheck', 'Description') }}:</span>
											<span class="timetracking-approval-detail__value">{{ approval.summary.description }}</span>
										</div>
									</template>
								</div>
							</div>
							<div class="timetracking-approval-item__date">
								{{ formatDate(approval.requestedAt) }}
							</div>
						</div>
						<div class="timetracking-approval-item__actions">
							<NcButton
								type="primary"
								:aria-label="approval.type === 'absence' ? $t('arbeitszeitcheck', 'Approve absence request') : $t('arbeitszeitcheck', 'Approve time entry correction')"
								@click="approveRequest(approval.type, approval.id)"
								:disabled="isProcessing === approval.id"
							>
								{{ isProcessing === approval.id ? $t('arbeitszeitcheck', 'Processing...') : $t('arbeitszeitcheck', 'Approve') }}
							</NcButton>
							<NcButton
								type="error"
								:aria-label="approval.type === 'absence' ? $t('arbeitszeitcheck', 'Reject absence request') : $t('arbeitszeitcheck', 'Reject time entry correction')"
								@click="rejectRequest(approval.type, approval.id)"
								:disabled="isProcessing === approval.id"
							>
								{{ isProcessing === approval.id ? $t('arbeitszeitcheck', 'Processing...') : $t('arbeitszeitcheck', 'Reject') }}
							</NcButton>
						</div>
					</div>
				</div>
			</div>

			<!-- Compliance Overview -->
			<div class="timetracking-section">
				<div class="timetracking-section__header">
					<h3 class="timetracking-section-title">{{ $t('arbeitszeitcheck', 'Compliance Overview') }}</h3>
					<NcButton
						type="secondary"
						:aria-label="$t('arbeitszeitcheck', 'View compliance report')"
						@click="viewComplianceReport"
					>
						{{ $t('arbeitszeitcheck', 'View Report') }}
					</NcButton>
				</div>

				<NcLoadingIcon v-if="isLoadingCompliance" :size="32" />
				<div v-else class="timetracking-compliance-summary">
					<div class="timetracking-compliance-item">
						<span class="timetracking-compliance-item__label">{{ $t('arbeitszeitcheck', 'Compliant Members') }}:</span>
						<span class="timetracking-compliance-item__value timetracking-compliance-item__value--success">
							{{ complianceOverview.compliantMembers || 0 }}
						</span>
					</div>
					<div class="timetracking-compliance-item">
						<span class="timetracking-compliance-item__label">{{ $t('arbeitszeitcheck', 'Members with Warnings') }}:</span>
						<span class="timetracking-compliance-item__value timetracking-compliance-item__value--warning">
							{{ complianceOverview.membersWithWarnings || 0 }}
						</span>
					</div>
					<div class="timetracking-compliance-item">
						<span class="timetracking-compliance-item__label">{{ $t('arbeitszeitcheck', 'Members with Violations') }}:</span>
						<span class="timetracking-compliance-item__value timetracking-compliance-item__value--error">
							{{ complianceOverview.membersWithViolations || 0 }}
						</span>
					</div>
					<div class="timetracking-compliance-item">
						<span class="timetracking-compliance-item__label">{{ $t('arbeitszeitcheck', 'Total Violations') }}:</span>
						<span class="timetracking-compliance-item__value">
							{{ complianceOverview.totalViolations || 0 }}
						</span>
					</div>
				</div>
			</div>

			<!-- Quick Actions -->
			<div class="timetracking-section">
				<div class="timetracking-section__header">
					<h3 class="timetracking-section-title">{{ $t('arbeitszeitcheck', 'Quick Actions') }}</h3>
				</div>
				<div class="timetracking-quick-actions">
					<NcButton
						type="primary"
						:aria-label="$t('arbeitszeitcheck', 'Generate team report')"
						@click="generateTeamReport"
					>
						{{ $t('arbeitszeitcheck', 'Generate Team Report') }}
					</NcButton>
					<NcButton
						type="primary"
						:aria-label="$t('arbeitszeitcheck', 'Export time data')"
						@click="exportTimeData"
					>
						{{ $t('arbeitszeitcheck', 'Export Time Data') }}
					</NcButton>
					<NcButton
						type="primary"
						:aria-label="$t('arbeitszeitcheck', 'Compliance audit')"
						@click="viewComplianceReport"
					>
						{{ $t('arbeitszeitcheck', 'Compliance Audit') }}
					</NcButton>
				</div>
			</div>
		</div>

		<!-- Employee Detail Modal -->
		<NcModal
			v-if="selectedEmployee"
			:name="$t('arbeitszeitcheck', 'Employee Details')"
			@close="closeEmployeeModal"
			:size="'large'"
		>
			<div class="timetracking-modal-content">
				<NcLoadingIcon v-if="isLoadingEmployeeDetails" :size="32" />
				<div v-else-if="employeeDetails">
					<div class="timetracking-employee-detail-header">
						<h3 class="timetracking-employee-detail-name">{{ employeeDetails.displayName }}</h3>
						<p class="timetracking-employee-detail-id">{{ employeeDetails.userId }}</p>
					</div>

					<div class="timetracking-employee-detail-stats">
						<div class="timetracking-detail-stat">
							<div class="timetracking-detail-stat__label">{{ $t('arbeitszeitcheck', 'Today\'s Hours') }}</div>
							<div class="timetracking-detail-stat__value">{{ formatHours(employeeDetails.todayHours || 0) }}</div>
						</div>
						<div class="timetracking-detail-stat">
							<div class="timetracking-detail-stat__label">{{ $t('arbeitszeitcheck', 'This Week') }}</div>
							<div class="timetracking-detail-stat__value">{{ formatHours(employeeDetails.weekHours || 0) }}</div>
						</div>
						<div class="timetracking-detail-stat">
							<div class="timetracking-detail-stat__label">{{ $t('arbeitszeitcheck', 'Overtime') }}</div>
							<div class="timetracking-detail-stat__value" :class="employeeDetails.overtimeHours > 0 ? 'timetracking-overtime--positive' : ''">
								{{ formatHours(employeeDetails.overtimeHours || 0) }}
							</div>
						</div>
						<div class="timetracking-detail-stat">
							<div class="timetracking-detail-stat__label">{{ $t('arbeitszeitcheck', 'Current Status') }}</div>
							<div class="timetracking-detail-stat__value">
								<span :class="getStatusClass(employeeDetails.currentStatus)">
									{{ getStatusText(employeeDetails.currentStatus) }}
								</span>
							</div>
						</div>
						<div class="timetracking-detail-stat">
							<div class="timetracking-detail-stat__label">{{ $t('arbeitszeitcheck', 'Compliance') }}</div>
							<div class="timetracking-detail-stat__value">
								<span :class="getComplianceClass(employeeDetails.complianceStatus)">
									{{ getComplianceText(employeeDetails.complianceStatus) }}
								</span>
							</div>
						</div>
						<div class="timetracking-detail-stat">
							<div class="timetracking-detail-stat__label">{{ $t('arbeitszeitcheck', 'Pending Absences') }}</div>
							<div class="timetracking-detail-stat__value">{{ employeeDetails.pendingAbsences || 0 }}</div>
						</div>
					</div>

					<div class="timetracking-modal-actions">
						<NcButton
							type="primary"
							@click="viewEmployeeReports"
							:aria-label="$t('arbeitszeitcheck', 'View employee reports')"
						>
							{{ $t('arbeitszeitcheck', 'View Reports') }}
						</NcButton>
						<NcButton
							type="secondary"
							@click="closeEmployeeModal"
						>
							{{ $t('arbeitszeitcheck', 'Close') }}
						</NcButton>
					</div>
				</div>
			</div>
		</NcModal>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcEmptyContent, NcModal } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { formatDateGerman } from '../utils/dateUtils.js'

export default {
	name: 'ManagerDashboard',
	components: {
		NcButton,
		NcLoadingIcon,
		NcEmptyContent,
		NcModal
	},
	data() {
		return {
			isLoadingTeam: false,
			isLoadingApprovals: false,
			isLoadingCompliance: false,
			isProcessing: null,
			teamOverview: {
				total: 0
			},
			teamMembers: [],
			pendingApprovals: [],
			complianceOverview: {
				totalMembers: 0,
				compliantMembers: 0,
				membersWithWarnings: 0,
				membersWithViolations: 0,
				totalViolations: 0,
				unresolvedViolations: 0
			},
			teamHoursSummary: {
				totalHours: 0,
				averageHours: 0,
				totalOvertime: 0
			},
			selectedEmployee: null,
			isLoadingEmployeeDetails: false,
			employeeDetails: null
		}
	},
	mounted() {
		this.loadDashboardData()
	},
	methods: {
		async loadDashboardData() {
			await Promise.all([
				this.loadTeamOverview(),
				this.loadPendingApprovals(),
				this.loadComplianceData(),
				this.loadTeamHours()
			])
		},
		async loadTeamOverview() {
			this.isLoadingTeam = true
			try {
				const response = await axios.get(generateUrl('/apps/arbeitszeitcheck/api/manager/team-overview'))
				if (response.data.success) {
					this.teamOverview = { total: response.data.total }
					this.teamMembers = response.data.teamMembers || []
				} else {
					throw new Error(response.data.error || this.$t('arbeitszeitcheck', 'Failed to load team overview'))
				}
			} catch (error) {
				console.error('Failed to load team overview:', error)
				this.showNotification(this.$t('arbeitszeitcheck', 'Failed to load team overview'), 'error')
			} finally {
				this.isLoadingTeam = false
			}
		},
		async loadPendingApprovals() {
			this.isLoadingApprovals = true
			try {
				const response = await axios.get(generateUrl('/apps/arbeitszeitcheck/api/manager/pending-approvals'))
				if (response.data.success) {
					this.pendingApprovals = response.data.pendingApprovals || []
				} else {
					throw new Error(response.data.error || this.$t('arbeitszeitcheck', 'Failed to load pending approvals'))
				}
			} catch (error) {
				console.error('Failed to load pending approvals:', error)
				this.showNotification(this.$t('arbeitszeitcheck', 'Failed to load pending approvals'), 'error')
			} finally {
				this.isLoadingApprovals = false
			}
		},
		async loadComplianceData() {
			this.isLoadingCompliance = true
			try {
				const response = await axios.get(generateUrl('/apps/arbeitszeitcheck/api/manager/team-compliance'))
				if (response.data.success) {
					this.complianceOverview = response.data.compliance || {}
				} else {
					throw new Error(response.data.error || this.$t('arbeitszeitcheck', 'Failed to load compliance data'))
				}
			} catch (error) {
				console.error('Failed to load compliance data:', error)
				this.showNotification(this.$t('arbeitszeitcheck', 'Failed to load compliance data'), 'error')
			} finally {
				this.isLoadingCompliance = false
			}
		},
		async loadTeamHours() {
			try {
				const response = await axios.get(generateUrl('/apps/arbeitszeitcheck/api/manager/team-hours'), {
					params: { period: 'week' }
				})
				if (response.data.success) {
					this.teamHoursSummary = response.data.summary || {}
				}
			} catch (error) {
				console.error('Failed to load team hours:', error)
				// Don't show error notification for team hours as it's not critical
			}
		},
		async approveRequest(type, id) {
			this.isProcessing = id
			try {
				if (type === 'absence') {
					const response = await axios.post(generateUrl(`/apps/arbeitszeitcheck/api/manager/absences/${id}/approve`))
					if (response.data.success) {
						this.showNotification(this.$t('arbeitszeitcheck', 'Absence request approved'), 'success')
						await this.loadPendingApprovals()
						await this.loadTeamOverview()
					} else {
						throw new Error(response.data.error || this.$t('arbeitszeitcheck', 'Failed to approve request'))
					}
				} else if (type === 'time_entry') {
					const response = await axios.post(generateUrl(`/apps/arbeitszeitcheck/api/manager/time-entries/${id}/approve-correction`))
					if (response.data.success) {
						this.showNotification(this.$t('arbeitszeitcheck', 'Time entry correction approved'), 'success')
						await this.loadPendingApprovals()
						await this.loadTeamOverview()
					} else {
						throw new Error(response.data.error || this.$t('arbeitszeitcheck', 'Failed to approve correction'))
					}
				}
			} catch (error) {
				this.showNotification(
					error.response?.data?.error || error.message || this.$t('arbeitszeitcheck', 'Failed to approve request'),
					'error'
				)
			} finally {
				this.isProcessing = null
			}
		},
		async rejectRequest(type, id) {
			const reason = prompt(this.$t('arbeitszeitcheck', 'Reason for rejection (optional):'))
			if (reason === null) {
				return // User cancelled
			}

			this.isProcessing = id
			try {
				if (type === 'absence') {
					const response = await axios.post(generateUrl(`/apps/arbeitszeitcheck/api/manager/absences/${id}/reject`), {
						comment: reason || null
					})
					if (response.data.success) {
						this.showNotification(this.$t('arbeitszeitcheck', 'Absence request rejected'), 'success')
						await this.loadPendingApprovals()
						await this.loadTeamOverview()
					} else {
						throw new Error(response.data.error || this.$t('arbeitszeitcheck', 'Failed to reject request'))
					}
				} else if (type === 'time_entry') {
					const response = await axios.post(generateUrl(`/apps/arbeitszeitcheck/api/manager/time-entries/${id}/reject-correction`), {
						reason: reason || null
					})
					if (response.data.success) {
						this.showNotification(this.$t('arbeitszeitcheck', 'Time entry correction rejected'), 'success')
						await this.loadPendingApprovals()
						await this.loadTeamOverview()
					} else {
						throw new Error(response.data.error || this.$t('arbeitszeitcheck', 'Failed to reject correction'))
					}
				}
			} catch (error) {
				this.showNotification(
					error.response?.data?.error || error.message || this.$t('arbeitszeitcheck', 'Failed to reject request'),
					'error'
				)
			} finally {
				this.isProcessing = null
			}
		},
		async viewEmployee(userId) {
			// Find employee in team members list
			const employee = this.teamMembers.find(m => m.userId === userId)
			if (employee) {
				this.selectedEmployee = userId
				this.employeeDetails = {
					userId: employee.userId,
					displayName: employee.displayName,
					todayHours: employee.todayHours,
					weekHours: employee.weekHours,
					overtimeHours: employee.overtimeHours,
					currentStatus: employee.currentStatus,
					complianceStatus: employee.complianceStatus,
					pendingAbsences: employee.pendingAbsences
				}
			} else {
				// If not in current list, fetch details
				this.selectedEmployee = userId
				this.isLoadingEmployeeDetails = true
				try {
					// Fetch employee details from team overview (which includes this user)
					const response = await axios.get(generateUrl('/apps/arbeitszeitcheck/api/manager/team-overview'), {
						params: { limit: 1000 } // Get all team members to find this one
					})
					if (response.data.success) {
						const employee = response.data.teamMembers.find(m => m.userId === userId)
						if (employee) {
							this.employeeDetails = {
								userId: employee.userId,
								displayName: employee.displayName,
								todayHours: employee.todayHours,
								weekHours: employee.weekHours,
								overtimeHours: employee.overtimeHours,
								currentStatus: employee.currentStatus,
								complianceStatus: employee.complianceStatus,
								pendingAbsences: employee.pendingAbsences
							}
						} else {
							this.showNotification(this.$t('arbeitszeitcheck', 'Employee not found in your team'), 'error')
							this.closeEmployeeModal()
						}
					}
				} catch (error) {
					this.showNotification(this.$t('arbeitszeitcheck', 'Failed to load employee details'), 'error')
					this.closeEmployeeModal()
				} finally {
					this.isLoadingEmployeeDetails = false
				}
			}
		},

		closeEmployeeModal() {
			this.selectedEmployee = null
			this.employeeDetails = null
			this.isLoadingEmployeeDetails = false
		},

		viewEmployeeReports() {
			if (this.selectedEmployee) {
				// Navigate to reports page
				window.location.href = generateUrl('/apps/arbeitszeitcheck/reports')
			}
		},
		viewComplianceReport() {
			window.location.href = generateUrl('/apps/arbeitszeitcheck/compliance/reports')
		},
		generateTeamReport() {
			// Generate and download team report
			const exportUrl = generateUrl('/apps/arbeitszeitcheck/export/time-entries', {
				format: 'csv'
			})
			window.location.href = exportUrl
			this.showNotification(this.$t('arbeitszeitcheck', 'Team report export started'), 'success')
		},
		exportTimeData() {
			const exportUrl = generateUrl('/apps/arbeitszeitcheck/export/time-entries', {
				format: 'csv'
			})
			window.location.href = exportUrl
			this.showNotification(this.$t('arbeitszeitcheck', 'Time data export started'), 'success')
		},
		formatHours(hours) {
			return `${hours.toFixed(2)}h`
		},
		formatDate(dateString) {
			return formatDateGerman(dateString) || '-'
		},
		getStatusClass(status) {
			return {
				'timetracking-status-badge': true,
				'timetracking-status-badge--active': status === 'active',
				'timetracking-status-badge--break': status === 'break',
				'timetracking-status-badge--inactive': status === 'clocked_out'
			}
		},
		getStatusText(status) {
			const statuses = {
				'active': this.$t('arbeitszeitcheck', 'Working'),
				'break': this.$t('arbeitszeitcheck', 'On Break'),
				'clocked_out': this.$t('arbeitszeitcheck', 'Clocked Out')
			}
			return statuses[status] || status
		},
		getComplianceClass(status) {
			return {
				'timetracking-compliance-badge': true,
				'timetracking-compliance-badge--good': status === 'good',
				'timetracking-compliance-badge--warning': status === 'warning'
			}
		},
		getComplianceText(status) {
			const statuses = {
				'good': this.$t('arbeitszeitcheck', 'Compliant'),
				'warning': this.$t('arbeitszeitcheck', 'Warning')
			}
			return statuses[status] || status
		},
		formatApprovalType(type) {
			const types = {
				'absence': this.$t('arbeitszeitcheck', 'Absence Request'),
				'time_entry': this.$t('arbeitszeitcheck', 'Time Entry Correction')
			}
			return types[type] || type
		},
		formatAbsenceType(type) {
			const types = {
				'vacation': this.$t('arbeitszeitcheck', 'Vacation'),
				'sick_leave': this.$t('arbeitszeitcheck', 'Sick Leave'),
				'special_leave': this.$t('arbeitszeitcheck', 'Special Leave'),
				'unpaid_leave': this.$t('arbeitszeitcheck', 'Unpaid Leave'),
				'other': this.$t('arbeitszeitcheck', 'Other')
			}
			return types[type] || type
		},
		showNotification(message, type = 'info') {
			if (typeof OC !== 'undefined' && OC.Notification) {
				OC.Notification.showTemporary(message, {
					timeout: 5000,
					isHTML: false
				})
			} else {
				// Fallback for development - use console.error for errors, console.log for others
				if (type === 'error') {
					console.error(message)
				} else {
					console.log(`${type}: ${message}`)
				}
			}
		}
	}
}
</script>

<style scoped>
.timetracking-manager-dashboard {
	padding: var(--default-grid-baseline);
}

.timetracking-overview-cards {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: calc(var(--default-grid-baseline) * 2);
	margin-bottom: calc(var(--default-grid-baseline) * 4);
}

.timetracking-overview-card {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: calc(var(--default-grid-baseline) * 2);
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
	transition: box-shadow 0.2s ease;
}

.timetracking-overview-card:hover {
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.timetracking-overview-card--warning {
	border-color: var(--color-warning);
}

.timetracking-overview-card__icon {
	font-size: 32px;
	width: 48px;
	height: 48px;
	display: flex;
	align-items: center;
	justify-content: center;
}

.timetracking-overview-card__content {
	flex: 1;
}

.timetracking-overview-card__value {
	font-size: 24px;
	font-weight: bold;
	color: var(--color-main-text);
	margin-bottom: calc(var(--default-grid-baseline) * 0.5);
}

.timetracking-overview-card__label {
	font-size: 14px;
	color: var(--color-text-maxcontrast);
}

.timetracking-section {
	margin-bottom: calc(var(--default-grid-baseline) * 4);
	padding: calc(var(--default-grid-baseline) * 2);
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.timetracking-section__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: calc(var(--default-grid-baseline) * 2);
}

.timetracking-section-title {
	font-size: 18px;
	font-weight: bold;
	color: var(--color-main-text);
	margin: 0;
}

.timetracking-employee-name {
	font-weight: 500;
	color: var(--color-main-text);
}

.timetracking-overtime--positive {
	color: var(--color-warning);
	font-weight: 500;
}

.timetracking-status-badge {
	display: inline-block;
	padding: 4px 8px;
	border-radius: var(--border-radius);
	font-size: 12px;
	font-weight: 500;
}

.timetracking-status-badge--active {
	background: var(--color-success-background);
	color: var(--color-success);
}

.timetracking-status-badge--break {
	background: var(--color-warning-background);
	color: var(--color-warning);
}

.timetracking-status-badge--inactive {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.timetracking-compliance-badge {
	display: inline-block;
	padding: 4px 8px;
	border-radius: var(--border-radius);
	font-size: 12px;
	font-weight: 500;
}

.timetracking-compliance-badge--good {
	background: var(--color-success-background);
	color: var(--color-success);
}

.timetracking-compliance-badge--warning {
	background: var(--color-warning-background);
	color: var(--color-warning);
}

.timetracking-approvals-list {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
}

.timetracking-approval-item {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	padding: calc(var(--default-grid-baseline) * 2);
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	gap: calc(var(--default-grid-baseline) * 2);
}

.timetracking-approval-item__content {
	flex: 1;
}

.timetracking-approval-item__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: calc(var(--default-grid-baseline) * 0.5);
}

.timetracking-approval-item__employee {
	font-weight: 600;
	color: var(--color-main-text);
}

.timetracking-approval-badge {
	font-size: 12px;
	font-weight: 500;
	padding: 4px 8px;
	border-radius: var(--border-radius);
}

.timetracking-approval-badge--absence {
	background: var(--color-primary);
	color: var(--color-primary-text, #ffffff);
}

.timetracking-approval-badge--time-entry {
	background: var(--color-warning, #eca700);
	color: var(--color-main-background);
}

.timetracking-approval-item__details {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 2);
	margin-bottom: calc(var(--default-grid-baseline) * 0.5);
}

.timetracking-approval-detail {
	font-size: 14px;
}

.timetracking-approval-detail__label {
	color: var(--color-text-maxcontrast);
	margin-right: 4px;
}

.timetracking-approval-detail__value {
	color: var(--color-main-text);
	font-weight: 500;
}

.timetracking-approval-item__date {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.timetracking-approval-badge {
	font-size: 12px;
	font-weight: 500;
	padding: 4px 8px;
	border-radius: var(--border-radius);
	margin-left: calc(var(--default-grid-baseline) * 1);
}

.timetracking-approval-badge--absence {
	background: var(--color-primary);
	color: var(--color-primary-text, #ffffff);
}

.timetracking-approval-badge--time-entry {
	background: var(--color-warning, #eca700);
	color: var(--color-main-background);
}

.timetracking-approval-item__actions {
	display: flex;
	gap: var(--default-grid-baseline);
}

.timetracking-compliance-summary {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
}

.timetracking-compliance-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: calc(var(--default-grid-baseline) * 1.5);
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
}

.timetracking-compliance-item__label {
	color: var(--color-main-text);
	font-weight: 500;
}

.timetracking-compliance-item__value {
	font-weight: bold;
	color: var(--color-main-text);
}

.timetracking-compliance-item__value--success {
	color: var(--color-success);
}

.timetracking-compliance-item__value--warning {
	color: var(--color-warning);
}

.timetracking-compliance-item__value--error {
	color: var(--color-error);
}

.timetracking-quick-actions {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 2);
	flex-wrap: wrap;
}

@media (max-width: 768px) {
	.timetracking-overview-cards {
		grid-template-columns: 1fr;
	}

	.timetracking-section__header {
		flex-direction: column;
		align-items: flex-start;
		gap: calc(var(--default-grid-baseline) * 2);
	}

	.timetracking-approval-item {
		flex-direction: column;
	}

	.timetracking-approval-item__actions {
		width: 100%;
		justify-content: stretch;
	}

	.timetracking-approval-item__actions .timetracking-btn {
		flex: 1;
	}

	.timetracking-quick-actions {
		flex-direction: column;
	}
}

.timetracking-modal-content {
	padding: var(--default-grid-baseline);
}

.timetracking-employee-detail-header {
	margin-bottom: calc(var(--default-grid-baseline) * 2);
	padding-bottom: calc(var(--default-grid-baseline) * 2);
	border-bottom: 1px solid var(--color-border);
}

.timetracking-employee-detail-name {
	font-size: 20px;
	font-weight: 600;
	color: var(--color-main-text);
	margin: 0 0 calc(var(--default-grid-baseline) / 2) 0;
}

.timetracking-employee-detail-id {
	font-size: 14px;
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.timetracking-employee-detail-stats {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
	gap: calc(var(--default-grid-baseline) * 2);
	margin-bottom: calc(var(--default-grid-baseline) * 2);
	padding: calc(var(--default-grid-baseline) * 2);
	background-color: var(--color-background-hover);
	border-radius: var(--border-radius);
}

.timetracking-detail-stat {
	text-align: center;
}

.timetracking-detail-stat__label {
	font-size: 14px;
	color: var(--color-text-maxcontrast);
	margin-bottom: calc(var(--default-grid-baseline) / 2);
}

.timetracking-detail-stat__value {
	font-size: 20px;
	font-weight: 600;
	color: var(--color-main-text);
}

.timetracking-modal-actions {
	display: flex;
	justify-content: flex-end;
	gap: var(--default-grid-baseline);
	margin-top: calc(var(--default-grid-baseline) * 2);
	padding-top: calc(var(--default-grid-baseline) * 2);
	border-top: 1px solid var(--color-border);
}

.timetracking-overtime--positive {
	color: var(--color-success);
}
</style>
