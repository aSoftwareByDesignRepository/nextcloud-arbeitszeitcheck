<template>
	<div class="timetracking-reports">
		<div class="timetracking-dashboard__header">
			<h2 class="timetracking-dashboard__title">{{ $t('arbeitszeitcheck', 'Reports') }}</h2>
			<p class="timetracking-dashboard__subtitle">{{ $t('arbeitszeitcheck', 'Generate and view detailed reports on working hours, overtime, and absences') }}</p>
		</div>

		<div class="timetracking-dashboard__content">
			<!-- Report Type Selection -->
			<div class="timetracking-section">
				<h3 class="timetracking-section-title">{{ $t('arbeitszeitcheck', 'Report Type') }}</h3>
				<div class="timetracking-report-type-selector">
					<NcButton
						v-for="type in reportTypes"
						:key="type.value"
						:type="selectedReportType === type.value ? 'primary' : 'secondary'"
						@click="selectReportType(type.value)"
						:aria-label="$t('arbeitszeitcheck', 'Select {type} report', { type: type.label })"
						class="timetracking-report-type-button">
						{{ type.label }}
					</NcButton>
				</div>
			</div>

			<!-- Report Filters -->
			<div class="timetracking-section">
				<h3 class="timetracking-section-title">{{ $t('arbeitszeitcheck', 'Report Parameters') }}</h3>
				<div class="timetracking-report-filters">
					<!-- Date selection based on report type -->
					<div v-if="selectedReportType === 'daily'" class="timetracking-form-group">
						<label for="report-date" class="timetracking-form-label">{{ $t('arbeitszeitcheck', 'Date') }}</label>
						<NcTextField
							id="report-date"
							v-model="reportDate"
							type="text"
							placeholder="dd.mm.yyyy"
							pattern="\d{2}\.\d{2}\.\d{4}"
							:label="$t('arbeitszeitcheck', 'Date')"
							@blur="validateGermanDate('reportDate')"
						/>
					</div>

					<div v-if="selectedReportType === 'weekly'" class="timetracking-form-group">
						<label for="report-week-start" class="timetracking-form-label">{{ $t('arbeitszeitcheck', 'Week Start') }}</label>
						<NcTextField
							id="report-week-start"
							v-model="weekStartDate"
							type="text"
							placeholder="dd.mm.yyyy"
							pattern="\d{2}\.\d{2}\.\d{4}"
							:label="$t('arbeitszeitcheck', 'Week Start')"
							@blur="validateGermanDate('weekStartDate')"
						/>
					</div>

					<div v-if="selectedReportType === 'monthly'" class="timetracking-form-group">
						<label for="report-month" class="timetracking-form-label">{{ $t('arbeitszeitcheck', 'Month') }}</label>
						<NcTextField
							id="report-month"
							v-model="reportMonth"
							type="month"
							:label="$t('arbeitszeitcheck', 'Month')"
							:max="maxMonth"
						/>
					</div>

					<div v-if="selectedReportType === 'overtime' || selectedReportType === 'absence' || selectedReportType === 'team'" class="timetracking-date-range">
						<div class="timetracking-form-group">
							<label for="report-start-date" class="timetracking-form-label">{{ $t('arbeitszeitcheck', 'Start Date') }}</label>
							<NcTextField
								id="report-start-date"
								v-model="startDate"
								type="text"
								placeholder="dd.mm.yyyy"
								pattern="\d{2}\.\d{2}\.\d{4}"
								:label="$t('arbeitszeitcheck', 'Start Date')"
								@blur="validateGermanDate('startDate')"
							/>
						</div>
						<div class="timetracking-form-group">
							<label for="report-end-date" class="timetracking-form-label">{{ $t('arbeitszeitcheck', 'End Date') }}</label>
							<NcTextField
								id="report-end-date"
								v-model="endDate"
								type="text"
								placeholder="dd.mm.yyyy"
								pattern="\d{2}\.\d{2}\.\d{4}"
								:label="$t('arbeitszeitcheck', 'End Date')"
								@blur="validateGermanDate('endDate')"
							/>
						</div>
					</div>

					<!-- User selection (for admin) -->
					<div v-if="isAdmin" class="timetracking-form-group">
						<label for="report-user" class="timetracking-form-label">
							{{ selectedReportType === 'team' ? $t('arbeitszeitcheck', 'User') : $t('arbeitszeitcheck', 'User') }}
							<span v-if="selectedReportType !== 'team'">({{ $t('arbeitszeitcheck', 'Optional') }})</span>
							<span v-if="selectedReportType === 'team'" class="timetracking-help-text">
								{{ $t('arbeitszeitcheck', 'Note: Team reports currently support single user selection. Multiple users can be added in future updates.') }}
							</span>
						</label>
						<NcSelect
							id="report-user"
							v-model="selectedUserId"
							:options="userOptions"
							:placeholder="selectedReportType === 'team' ? $t('arbeitszeitcheck', 'Select user') : $t('arbeitszeitcheck', 'All users')"
							:clearable="selectedReportType !== 'team'"
						/>
					</div>

					<div class="timetracking-form-group">
						<NcButton
							type="primary"
							@click="loadReport"
							:disabled="isLoading || !canLoadReport"
							:aria-label="$t('arbeitszeitcheck', 'Generate report')">
							<template #icon>
								<NcLoadingIcon v-if="isLoading" :size="20" />
							</template>
							{{ isLoading ? $t('arbeitszeitcheck', 'Generating...') : $t('arbeitszeitcheck', 'Generate Report') }}
						</NcButton>
					</div>
				</div>
			</div>

			<!-- Loading State -->
			<div v-if="isLoading" class="timetracking-loading-container">
				<NcLoadingIcon :size="64" />
				<p>{{ $t('arbeitszeitcheck', 'Generating report...') }}</p>
			</div>

			<!-- Report Display -->
			<div v-else-if="reportData" class="timetracking-report-display">
				<!-- Report Header -->
				<div class="timetracking-report-header">
					<h3 class="timetracking-section-title">{{ getReportTitle() }}</h3>
					<div class="timetracking-report-header__actions">
						<NcButton
							type="secondary"
							@click="exportReport('csv')"
							:disabled="isExporting"
							:aria-label="$t('arbeitszeitcheck', 'Export as CSV')">
							{{ isExporting === 'csv' ? $t('arbeitszeitcheck', 'Exporting...') : $t('arbeitszeitcheck', 'Export CSV') }}
						</NcButton>
						<NcButton
							type="secondary"
							@click="exportReport('json')"
							:disabled="isExporting"
							:aria-label="$t('arbeitszeitcheck', 'Export as JSON')">
							{{ isExporting === 'json' ? $t('arbeitszeitcheck', 'Exporting...') : $t('arbeitszeitcheck', 'Export JSON') }}
						</NcButton>
					</div>
				</div>

				<!-- Daily Report -->
				<div v-if="selectedReportType === 'daily'" class="timetracking-report-content">
					<div class="timetracking-report-summary-cards">
						<div class="timetracking-report-card">
							<div class="timetracking-report-card__value">{{ reportData.active_users || 0 }}</div>
							<div class="timetracking-report-card__label">{{ $t('arbeitszeitcheck', 'Active Users') }}</div>
						</div>
						<div class="timetracking-report-card">
							<div class="timetracking-report-card__value">{{ formatHours(reportData.total_hours) }}</div>
							<div class="timetracking-report-card__label">{{ $t('arbeitszeitcheck', 'Total Hours') }}</div>
						</div>
						<div class="timetracking-report-card">
							<div class="timetracking-report-card__value">{{ formatHours(reportData.total_break_hours) }}</div>
							<div class="timetracking-report-card__label">{{ $t('arbeitszeitcheck', 'Break Hours') }}</div>
						</div>
						<div class="timetracking-report-card" :class="reportData.total_overtime >= 0 ? 'timetracking-report-card--success' : 'timetracking-report-card--warning'">
							<div class="timetracking-report-card__value">{{ formatHours(Math.abs(reportData.total_overtime)) }}</div>
							<div class="timetracking-report-card__label">{{ reportData.total_overtime >= 0 ? $t('arbeitszeitcheck', 'Overtime') : $t('arbeitszeitcheck', 'Undertime') }}</div>
						</div>
						<div v-if="reportData.violations_count > 0" class="timetracking-report-card timetracking-report-card--error">
							<div class="timetracking-report-card__value">{{ reportData.violations_count }}</div>
							<div class="timetracking-report-card__label">{{ $t('arbeitszeitcheck', 'Violations') }}</div>
						</div>
					</div>

					<!-- User Details -->
					<div v-if="reportData.users && reportData.users.length > 0" class="timetracking-report-users">
						<h4 class="timetracking-subsection-title">{{ $t('arbeitszeitcheck', 'User Details') }}</h4>
						<table class="timetracking-table">
							<thead>
								<tr>
									<th>{{ $t('arbeitszeitcheck', 'User') }}</th>
									<th>{{ $t('arbeitszeitcheck', 'Hours') }}</th>
									<th>{{ $t('arbeitszeitcheck', 'Break Hours') }}</th>
									<th>{{ $t('arbeitszeitcheck', 'Overtime') }}</th>
									<th>{{ $t('arbeitszeitcheck', 'Violations') }}</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="user in reportData.users" :key="user.user_id">
									<td>{{ user.display_name }}</td>
									<td>{{ formatHours(user.total_hours) }}</td>
									<td>{{ formatHours(user.break_hours) }}</td>
									<td :class="user.overtime_hours >= 0 ? '' : 'timetracking-text-warning'">
										{{ formatHours(user.overtime_hours) }}
									</td>
									<td>
										<span v-if="user.violations_count > 0" class="timetracking-badge timetracking-badge--error">
											{{ user.violations_count }}
										</span>
										<span v-else class="timetracking-badge timetracking-badge--success">0</span>
									</td>
								</tr>
							</tbody>
						</table>
					</div>
				</div>

				<!-- Weekly Report -->
				<div v-if="selectedReportType === 'weekly'" class="timetracking-report-content">
					<div class="timetracking-report-summary-cards">
						<div class="timetracking-report-card">
							<div class="timetracking-report-card__value">{{ reportData.active_users || 0 }}</div>
							<div class="timetracking-report-card__label">{{ $t('arbeitszeitcheck', 'Active Users') }}</div>
						</div>
						<div class="timetracking-report-card">
							<div class="timetracking-report-card__value">{{ formatHours(reportData.total_hours) }}</div>
							<div class="timetracking-report-card__label">{{ $t('arbeitszeitcheck', 'Total Hours') }}</div>
						</div>
						<div class="timetracking-report-card">
							<div class="timetracking-report-card__value">{{ formatHours(reportData.total_break_hours) }}</div>
							<div class="timetracking-report-card__label">{{ $t('arbeitszeitcheck', 'Break Hours') }}</div>
						</div>
						<div class="timetracking-report-card" :class="reportData.total_overtime >= 0 ? 'timetracking-report-card--success' : 'timetracking-report-card--warning'">
							<div class="timetracking-report-card__value">{{ formatHours(Math.abs(reportData.total_overtime)) }}</div>
							<div class="timetracking-report-card__label">{{ reportData.total_overtime >= 0 ? $t('arbeitszeitcheck', 'Overtime') : $t('arbeitszeitcheck', 'Undertime') }}</div>
						</div>
					</div>

					<!-- Daily Breakdown -->
					<div v-if="reportData.daily_breakdown && Object.keys(reportData.daily_breakdown).length > 0" class="timetracking-report-breakdown">
						<h4 class="timetracking-subsection-title">{{ $t('arbeitszeitcheck', 'Daily Breakdown') }}</h4>
						<table class="timetracking-table">
							<thead>
								<tr>
									<th>{{ $t('arbeitszeitcheck', 'Date') }}</th>
									<th>{{ $t('arbeitszeitcheck', 'Day') }}</th>
									<th>{{ $t('arbeitszeitcheck', 'Hours') }}</th>
									<th>{{ $t('arbeitszeitcheck', 'Active Users') }}</th>
									<th>{{ $t('arbeitszeitcheck', 'Violations') }}</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="(dayData, date) in reportData.daily_breakdown" :key="date">
									<td>{{ formatDate(date) }}</td>
									<td>{{ dayData.day_name }}</td>
									<td>{{ formatHours(dayData.total_hours) }}</td>
									<td>{{ dayData.active_users }}</td>
									<td>
										<span v-if="dayData.violations > 0" class="timetracking-badge timetracking-badge--error">
											{{ dayData.violations }}
										</span>
										<span v-else class="timetracking-badge timetracking-badge--success">0</span>
									</td>
								</tr>
							</tbody>
						</table>
					</div>
				</div>

				<!-- Monthly Report -->
				<div v-if="selectedReportType === 'monthly'" class="timetracking-report-content">
					<div class="timetracking-report-summary-cards">
						<div class="timetracking-report-card">
							<div class="timetracking-report-card__value">{{ reportData.active_users || 0 }}</div>
							<div class="timetracking-report-card__label">{{ $t('arbeitszeitcheck', 'Active Users') }}</div>
						</div>
						<div class="timetracking-report-card">
							<div class="timetracking-report-card__value">{{ formatHours(reportData.total_hours) }}</div>
							<div class="timetracking-report-card__label">{{ $t('arbeitszeitcheck', 'Total Hours') }}</div>
						</div>
						<div class="timetracking-report-card">
							<div class="timetracking-report-card__value">{{ formatHours(reportData.average_hours_per_user) }}</div>
							<div class="timetracking-report-card__label">{{ $t('arbeitszeitcheck', 'Average per User') }}</div>
						</div>
						<div class="timetracking-report-card">
							<div class="timetracking-report-card__value">{{ reportData.working_days || 0 }}</div>
							<div class="timetracking-report-card__label">{{ $t('arbeitszeitcheck', 'Working Days') }}</div>
						</div>
						<div class="timetracking-report-card" :class="reportData.total_overtime >= 0 ? 'timetracking-report-card--success' : 'timetracking-report-card--warning'">
							<div class="timetracking-report-card__value">{{ formatHours(Math.abs(reportData.total_overtime)) }}</div>
							<div class="timetracking-report-card__label">{{ reportData.total_overtime >= 0 ? $t('arbeitszeitcheck', 'Overtime') : $t('arbeitszeitcheck', 'Undertime') }}</div>
						</div>
					</div>

					<!-- User Breakdown -->
					<div v-if="reportData.users && reportData.users.length > 0" class="timetracking-report-users">
						<h4 class="timetracking-subsection-title">{{ $t('arbeitszeitcheck', 'User Breakdown') }}</h4>
						<table class="timetracking-table">
							<thead>
								<tr>
									<th>{{ $t('arbeitszeitcheck', 'User') }}</th>
									<th>{{ $t('arbeitszeitcheck', 'Total Hours') }}</th>
									<th>{{ $t('arbeitszeitcheck', 'Overtime') }}</th>
									<th>{{ $t('arbeitszeitcheck', 'Violations') }}</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="user in reportData.users" :key="user.user_id">
									<td>{{ user.display_name }}</td>
									<td>{{ formatHours(user.total_hours) }}</td>
									<td :class="user.overtime_hours >= 0 ? '' : 'timetracking-text-warning'">
										{{ formatHours(user.overtime_hours) }}
									</td>
									<td>
										<span v-if="user.violations_count > 0" class="timetracking-badge timetracking-badge--error">
											{{ user.violations_count }}
										</span>
										<span v-else class="timetracking-badge timetracking-badge--success">0</span>
									</td>
								</tr>
							</tbody>
						</table>
					</div>
				</div>

				<!-- Overtime Report -->
				<div v-if="selectedReportType === 'overtime'" class="timetracking-report-content">
					<div class="timetracking-report-summary-cards">
						<div class="timetracking-report-card">
							<div class="timetracking-report-card__value">{{ reportData.total_users || 0 }}</div>
							<div class="timetracking-report-card__label">{{ $t('arbeitszeitcheck', 'Total Users') }}</div>
						</div>
						<div class="timetracking-report-card timetracking-report-card--success">
							<div class="timetracking-report-card__value">{{ reportData.users_with_overtime || 0 }}</div>
							<div class="timetracking-report-card__label">{{ $t('arbeitszeitcheck', 'With Overtime') }}</div>
						</div>
						<div class="timetracking-report-card timetracking-report-card--warning">
							<div class="timetracking-report-card__value">{{ reportData.users_with_undertime || 0 }}</div>
							<div class="timetracking-report-card__label">{{ $t('arbeitszeitcheck', 'With Undertime') }}</div>
						</div>
						<div class="timetracking-report-card">
							<div class="timetracking-report-card__value">{{ formatHours(reportData.total_overtime) }}</div>
							<div class="timetracking-report-card__label">{{ $t('arbeitszeitcheck', 'Total Overtime') }}</div>
						</div>
						<div class="timetracking-report-card">
							<div class="timetracking-report-card__value">{{ formatHours(reportData.average_overtime) }}</div>
							<div class="timetracking-report-card__label">{{ $t('arbeitszeitcheck', 'Average Overtime') }}</div>
						</div>
					</div>

					<!-- User Overtime Details -->
					<div v-if="reportData.users && reportData.users.length > 0" class="timetracking-report-users">
						<h4 class="timetracking-subsection-title">{{ $t('arbeitszeitcheck', 'Overtime by User') }}</h4>
						<table class="timetracking-table">
							<thead>
								<tr>
									<th>{{ $t('arbeitszeitcheck', 'User') }}</th>
									<th>{{ $t('arbeitszeitcheck', 'Hours Worked') }}</th>
									<th>{{ $t('arbeitszeitcheck', 'Required Hours') }}</th>
									<th>{{ $t('arbeitszeitcheck', 'Overtime') }}</th>
									<th>{{ $t('arbeitszeitcheck', 'Balance') }}</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="user in reportData.users" :key="user.user_id">
									<td>{{ user.display_name }}</td>
									<td>{{ formatHours(user.total_hours_worked) }}</td>
									<td>{{ formatHours(user.required_hours) }}</td>
									<td :class="user.overtime_hours >= 0 ? 'timetracking-text-success' : 'timetracking-text-warning'">
										{{ formatHours(user.overtime_hours) }}
									</td>
									<td :class="user.cumulative_balance >= 0 ? 'timetracking-text-success' : 'timetracking-text-warning'">
										{{ formatHours(user.cumulative_balance) }}
									</td>
								</tr>
							</tbody>
						</table>
					</div>
				</div>

				<!-- Absence Report -->
				<div v-if="selectedReportType === 'absence'" class="timetracking-report-content">
					<div class="timetracking-report-summary-cards">
						<div class="timetracking-report-card">
							<div class="timetracking-report-card__value">{{ reportData.total_absences || 0 }}</div>
							<div class="timetracking-report-card__label">{{ $t('arbeitszeitcheck', 'Total Absences') }}</div>
						</div>
						<div class="timetracking-report-card">
							<div class="timetracking-report-card__value">{{ reportData.total_days || 0 }}</div>
							<div class="timetracking-report-card__label">{{ $t('arbeitszeitcheck', 'Total Days') }}</div>
						</div>
					</div>

					<!-- Absences by Type -->
					<div v-if="reportData.absences_by_type && Object.keys(reportData.absences_by_type).length > 0" class="timetracking-report-breakdown">
						<h4 class="timetracking-subsection-title">{{ $t('arbeitszeitcheck', 'Absences by Type') }}</h4>
						<table class="timetracking-table">
							<thead>
								<tr>
									<th>{{ $t('arbeitszeitcheck', 'Type') }}</th>
									<th>{{ $t('arbeitszeitcheck', 'Count') }}</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="(count, type) in reportData.absences_by_type" :key="type">
									<td>{{ formatAbsenceType(type) }}</td>
									<td>{{ count }}</td>
								</tr>
							</tbody>
						</table>
					</div>

					<!-- Absences by Status -->
					<div v-if="reportData.absences_by_status && Object.keys(reportData.absences_by_status).length > 0" class="timetracking-report-breakdown">
						<h4 class="timetracking-subsection-title">{{ $t('arbeitszeitcheck', 'Absences by Status') }}</h4>
						<table class="timetracking-table">
							<thead>
								<tr>
									<th>{{ $t('arbeitszeitcheck', 'Status') }}</th>
									<th>{{ $t('arbeitszeitcheck', 'Count') }}</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="(count, status) in reportData.absences_by_status" :key="status">
									<td>{{ formatAbsenceStatus(status) }}</td>
									<td>{{ count }}</td>
								</tr>
							</tbody>
						</table>
					</div>

					<!-- User Absence Details -->
					<div v-if="reportData.users && reportData.users.length > 0" class="timetracking-report-users">
						<h4 class="timetracking-subsection-title">{{ $t('arbeitszeitcheck', 'Absences by User') }}</h4>
						<table class="timetracking-table">
							<thead>
								<tr>
									<th>{{ $t('arbeitszeitcheck', 'User') }}</th>
									<th>{{ $t('arbeitszeitcheck', 'Total Days') }}</th>
									<th>{{ $t('arbeitszeitcheck', 'Absences') }}</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="user in reportData.users" :key="user.user_id">
									<td>{{ user.display_name }}</td>
									<td>{{ user.total_days }}</td>
									<td>{{ user.absences.length }}</td>
								</tr>
							</tbody>
						</table>
					</div>
				</div>

				<!-- Team Report -->
				<div v-if="selectedReportType === 'team'" class="timetracking-report-content">
					<div class="timetracking-report-summary-cards">
						<div class="timetracking-report-card">
							<div class="timetracking-report-card__value">{{ reportData.team_size || 0 }}</div>
							<div class="timetracking-report-card__label">{{ $t('arbeitszeitcheck', 'Team Size') }}</div>
						</div>
						<div class="timetracking-report-card">
							<div class="timetracking-report-card__value">{{ reportData.active_members || 0 }}</div>
							<div class="timetracking-report-card__label">{{ $t('arbeitszeitcheck', 'Active Members') }}</div>
						</div>
						<div class="timetracking-report-card">
							<div class="timetracking-report-card__value">{{ formatHours(reportData.total_hours) }}</div>
							<div class="timetracking-report-card__label">{{ $t('arbeitszeitcheck', 'Total Hours') }}</div>
						</div>
						<div class="timetracking-report-card">
							<div class="timetracking-report-card__value">{{ formatHours(reportData.average_hours_per_member) }}</div>
							<div class="timetracking-report-card__label">{{ $t('arbeitszeitcheck', 'Average per Member') }}</div>
						</div>
						<div class="timetracking-report-card" :class="reportData.total_overtime >= 0 ? 'timetracking-report-card--success' : 'timetracking-report-card--warning'">
							<div class="timetracking-report-card__value">{{ formatHours(Math.abs(reportData.total_overtime)) }}</div>
							<div class="timetracking-report-card__label">{{ reportData.total_overtime >= 0 ? $t('arbeitszeitcheck', 'Overtime') : $t('arbeitszeitcheck', 'Undertime') }}</div>
						</div>
						<div v-if="reportData.total_violations > 0" class="timetracking-report-card timetracking-report-card--error">
							<div class="timetracking-report-card__value">{{ reportData.total_violations }}</div>
							<div class="timetracking-report-card__label">{{ $t('arbeitszeitcheck', 'Violations') }}</div>
						</div>
					</div>

					<!-- Team Members -->
					<div v-if="reportData.members && reportData.members.length > 0" class="timetracking-report-users">
						<h4 class="timetracking-subsection-title">{{ $t('arbeitszeitcheck', 'Team Members') }}</h4>
						<table class="timetracking-table">
							<thead>
								<tr>
									<th>{{ $t('arbeitszeitcheck', 'Member') }}</th>
									<th>{{ $t('arbeitszeitcheck', 'Hours') }}</th>
									<th>{{ $t('arbeitszeitcheck', 'Required') }}</th>
									<th>{{ $t('arbeitszeitcheck', 'Overtime') }}</th>
									<th>{{ $t('arbeitszeitcheck', 'Break Hours') }}</th>
									<th>{{ $t('arbeitszeitcheck', 'Violations') }}</th>
									<th>{{ $t('arbeitszeitcheck', 'Absence Days') }}</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="member in reportData.members" :key="member.user_id">
									<td>{{ member.display_name }}</td>
									<td>{{ formatHours(member.total_hours) }}</td>
									<td>{{ formatHours(member.required_hours) }}</td>
									<td :class="member.overtime_hours >= 0 ? 'timetracking-text-success' : 'timetracking-text-warning'">
										{{ formatHours(member.overtime_hours) }}
									</td>
									<td>{{ formatHours(member.break_hours) }}</td>
									<td>
										<span v-if="member.violations_count > 0" class="timetracking-badge timetracking-badge--error">
											{{ member.violations_count }}
										</span>
										<span v-else class="timetracking-badge timetracking-badge--success">0</span>
									</td>
									<td>{{ member.absence_days }}</td>
								</tr>
							</tbody>
						</table>
					</div>
				</div>
			</div>

			<!-- Empty State -->
			<NcEmptyContent
				v-else-if="!isLoading"
				:title="$t('arbeitszeitcheck', 'No report generated')"
				:description="$t('arbeitszeitcheck', 'Select report type and parameters, then click Generate Report to view data')"
			>
				<template #icon>
					<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
						<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
						<polyline points="14 2 14 8 20 8"/>
						<line x1="16" y1="13" x2="8" y2="13"/>
						<line x1="16" y1="17" x2="8" y2="17"/>
						<polyline points="10 9 9 9 8 9"/>
					</svg>
				</template>
			</NcEmptyContent>
		</div>
	</div>
</template>

<script>
import { NcButton, NcTextField, NcSelect, NcLoadingIcon, NcEmptyContent } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { formatDateGerman, parseGermanDate } from '../utils/dateUtils.js'

export default {
	name: 'Reports',
	components: {
		NcButton,
		NcTextField,
		NcSelect,
		NcLoadingIcon,
		NcEmptyContent
	},
	data() {
		const today = new Date()
		const endDate = new Date()
		endDate.setDate(endDate.getDate() - 30)

		return {
			selectedReportType: 'daily',
			reportTypes: [
				{ value: 'daily', label: this.$t('arbeitszeitcheck', 'Daily') },
				{ value: 'weekly', label: this.$t('arbeitszeitcheck', 'Weekly') },
				{ value: 'monthly', label: this.$t('arbeitszeitcheck', 'Monthly') },
				{ value: 'overtime', label: this.$t('arbeitszeitcheck', 'Overtime') },
				{ value: 'absence', label: this.$t('arbeitszeitcheck', 'Absence') },
				{ value: 'team', label: this.$t('arbeitszeitcheck', 'Team') }
			],
			reportDate: formatDateGerman(today),
			weekStartDate: formatDateGerman(this.getWeekStart(today)),
			reportMonth: today.toISOString().slice(0, 7),
			startDate: formatDateGerman(endDate),
			endDate: formatDateGerman(today),
			selectedUserId: null,
			userOptions: [],
			isLoading: false,
			isExporting: null,
			reportData: null,
			isAdmin: false
		}
	},
	computed: {
		maxDate() {
			return new Date().toISOString().split('T')[0]
		},
		maxMonth() {
			return new Date().toISOString().slice(0, 7)
		},
		canLoadReport() {
			if (this.selectedReportType === 'daily') {
				return !!this.reportDate
			}
			if (this.selectedReportType === 'weekly') {
				return !!this.weekStartDate
			}
			if (this.selectedReportType === 'monthly') {
				return !!this.reportMonth
			}
			return !!(this.startDate && this.endDate)
		}
	},
	mounted() {
		this.checkAdminStatus()
		this.loadUsers()
	},
	methods: {
		async checkAdminStatus() {
			try {
				// Check if user is admin by trying to access admin endpoint
				const response = await axios.get(generateUrl('/apps/arbeitszeitcheck/api/admin/statistics'))
				this.isAdmin = response.data.success
			} catch (error) {
				// If 403 or 404, user is not admin
				this.isAdmin = false
			}
		},
		async loadUsers() {
			if (!this.isAdmin) {
				return
			}
			try {
				const response = await axios.get(generateUrl('/apps/arbeitszeitcheck/api/admin/users'), {
					params: { limit: 100 }
				})
				if (response.data.success && response.data.users) {
					this.userOptions = response.data.users.map(user => ({
						value: user.userId || user.user_id,
						label: user.displayName || user.display_name || user.userId || user.user_id
					}))
				}
			} catch (error) {
				console.error('Failed to load users:', error)
				// If error, user might not be admin - that's okay
			}
		},
		selectReportType(type) {
			this.selectedReportType = type
			this.reportData = null
		},
		getWeekStart(date) {
			const d = new Date(date)
			const day = d.getDay()
			const diff = d.getDate() - day + (day === 0 ? -6 : 1)
			return new Date(d.setDate(diff))
		},
		async loadReport() {
			if (!this.canLoadReport) {
				this.showNotification(this.$t('arbeitszeitcheck', 'Please fill in all required fields'), 'warning')
				return
			}

			this.isLoading = true
			this.reportData = null

			try {
				let url = ''
				const params = {}

				switch (this.selectedReportType) {
					case 'daily':
						url = generateUrl('/apps/arbeitszeitcheck/api/reports/daily')
						const dateIso = parseGermanDate(this.reportDate)
						if (!dateIso) {
							throw new Error(this.$t('arbeitszeitcheck', 'Please enter a valid date in dd.mm.yyyy format'))
						}
						params.date = dateIso
						if (this.selectedUserId) {
							params.userId = this.selectedUserId
						}
						break
					case 'weekly':
						url = generateUrl('/apps/arbeitszeitcheck/api/reports/weekly')
						const weekStartIso = parseGermanDate(this.weekStartDate)
						if (!weekStartIso) {
							throw new Error(this.$t('arbeitszeitcheck', 'Please enter a valid date in dd.mm.yyyy format'))
						}
						params.weekStart = weekStartIso
						if (this.selectedUserId) {
							params.userId = this.selectedUserId
						}
						break
					case 'monthly':
						url = generateUrl('/apps/arbeitszeitcheck/api/reports/monthly')
						params.month = this.reportMonth
						if (this.selectedUserId) {
							params.userId = this.selectedUserId
						}
						break
					case 'overtime':
						url = generateUrl('/apps/arbeitszeitcheck/api/reports/overtime')
						const overtimeStartIso = parseGermanDate(this.startDate)
						const overtimeEndIso = parseGermanDate(this.endDate)
						if (!overtimeStartIso || !overtimeEndIso) {
							throw new Error(this.$t('arbeitszeitcheck', 'Please enter valid dates in dd.mm.yyyy format'))
						}
						params.startDate = overtimeStartIso
						params.endDate = overtimeEndIso
						if (this.selectedUserId) {
							params.userId = this.selectedUserId
						}
						break
					case 'absence':
						url = generateUrl('/apps/arbeitszeitcheck/api/reports/absence')
						const absenceStartIso = parseGermanDate(this.startDate)
						const absenceEndIso = parseGermanDate(this.endDate)
						if (!absenceStartIso || !absenceEndIso) {
							throw new Error(this.$t('arbeitszeitcheck', 'Please enter valid dates in dd.mm.yyyy format'))
						}
						params.startDate = absenceStartIso
						params.endDate = absenceEndIso
						if (this.selectedUserId) {
							params.userId = this.selectedUserId
						}
						break
					case 'team':
						// Team reports require user IDs - for now, skip if no users selected
						if (!this.selectedUserId) {
							this.showNotification(this.$t('arbeitszeitcheck', 'Team reports require selecting team members'), 'warning')
							this.isLoading = false
							return
						}
						url = generateUrl('/apps/arbeitszeitcheck/api/reports/team')
						const teamStartIso = parseGermanDate(this.startDate)
						const teamEndIso = parseGermanDate(this.endDate)
						if (!teamStartIso || !teamEndIso) {
							throw new Error(this.$t('arbeitszeitcheck', 'Please enter valid dates in dd.mm.yyyy format'))
						}
						params.startDate = teamStartIso
						params.endDate = teamEndIso
						params.startDate = this.startDate
						params.endDate = this.endDate
						params.userIds = this.selectedUserId
						break
				}

				const response = await axios.get(url, { params })

				if (response.data.success) {
					this.reportData = response.data.report
				} else {
					throw new Error(response.data.error || this.$t('arbeitszeitcheck', 'Failed to load report'))
				}
			} catch (error) {
				console.error('Failed to load report:', error)
				this.showNotification(
					error.response?.data?.error || error.message || this.$t('arbeitszeitcheck', 'Failed to load report'),
					'error'
				)
			} finally {
				this.isLoading = false
			}
		},
		exportReport(format) {
			if (!this.reportData) {
				return
			}

			this.isExporting = format

			try {
				// Convert report data to exportable format
				const exportData = this.prepareExportData()
				const json = JSON.stringify(exportData, null, 2)
				const blob = new Blob([format === 'csv' ? this.convertToCsv(exportData) : json], {
					type: format === 'csv' ? 'text/csv' : 'application/json'
				})
				const url = URL.createObjectURL(blob)
				const link = document.createElement('a')
				link.href = url
				link.download = `report-${this.selectedReportType}-${new Date().toISOString().split('T')[0]}.${format}`
				document.body.appendChild(link)
				link.click()
				document.body.removeChild(link)
				URL.revokeObjectURL(url)

				this.showNotification(this.$t('arbeitszeitcheck', 'Report exported successfully'), 'success')
			} catch (error) {
				console.error('Failed to export report:', error)
				this.showNotification(this.$t('arbeitszeitcheck', 'Failed to export report'), 'error')
			} finally {
				setTimeout(() => {
					this.isExporting = null
				}, 1000)
			}
		},
		prepareExportData() {
			return {
				type: this.selectedReportType,
				generated_at: new Date().toISOString(),
				parameters: this.getReportParameters(),
				data: this.reportData
			}
		},
		getReportParameters() {
			const params = { report_type: this.selectedReportType }
			if (this.selectedReportType === 'daily') {
				params.date = this.reportDate
			} else if (this.selectedReportType === 'weekly') {
				params.week_start = this.weekStartDate
			} else if (this.selectedReportType === 'monthly') {
				params.month = this.reportMonth
			} else {
				params.start_date = this.startDate
				params.end_date = this.endDate
			}
			if (this.selectedUserId) {
				params.user_id = this.selectedUserId
			}
			return params
		},
		convertToCsv(data) {
			// Simple CSV conversion for report data
			const rows = []
			
			// Add header
			if (data.data.users && data.data.users.length > 0) {
				const headers = Object.keys(data.data.users[0])
				rows.push(headers.join(','))
				
				// Add data rows
				data.data.users.forEach(user => {
					const values = headers.map(header => {
						const value = user[header]
						if (value === null || value === undefined) {
							return ''
						}
						if (typeof value === 'object') {
							return JSON.stringify(value)
						}
						return String(value).replace(/"/g, '""')
					})
					rows.push(values.map(v => `"${v}"`).join(','))
				})
			}
			
			return rows.join('\n')
		},
		getReportTitle() {
			const typeLabels = {
				daily: this.$t('arbeitszeitcheck', 'Daily Report'),
				weekly: this.$t('arbeitszeitcheck', 'Weekly Report'),
				monthly: this.$t('arbeitszeitcheck', 'Monthly Report'),
				overtime: this.$t('arbeitszeitcheck', 'Overtime Report'),
				absence: this.$t('arbeitszeitcheck', 'Absence Report'),
				team: this.$t('arbeitszeitcheck', 'Team Report')
			}
			return typeLabels[this.selectedReportType] || this.$t('arbeitszeitcheck', 'Report')
		},
		formatHours(hours) {
			if (hours === null || hours === undefined) {
				return '0.00'
			}
			return parseFloat(hours).toFixed(2)
		},
		formatDate(dateString) {
			return formatDateGerman(dateString)
		},
		formatAbsenceType(type) {
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
		formatAbsenceStatus(status) {
			const statuses = {
				pending: this.$t('arbeitszeitcheck', 'Pending'),
				approved: this.$t('arbeitszeitcheck', 'Approved'),
				rejected: this.$t('arbeitszeitcheck', 'Rejected'),
				cancelled: this.$t('arbeitszeitcheck', 'Cancelled')
			}
			return statuses[status] || status
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
.timetracking-reports {
	padding: var(--default-grid-baseline);
}

.timetracking-section {
	margin-bottom: calc(var(--default-grid-baseline) * 4);
	padding: calc(var(--default-grid-baseline) * 2);
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.timetracking-section-title {
	font-size: 18px;
	font-weight: bold;
	color: var(--color-main-text);
	margin-bottom: calc(var(--default-grid-baseline) * 2);
	margin-top: 0;
}

.timetracking-subsection-title {
	font-size: 16px;
	font-weight: 600;
	color: var(--color-main-text);
	margin-bottom: calc(var(--default-grid-baseline) * 2);
	margin-top: calc(var(--default-grid-baseline) * 3);
}

.timetracking-report-type-selector {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 2);
	flex-wrap: wrap;
}

.timetracking-report-type-button {
	min-width: 120px;
}

.timetracking-report-filters {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
}

.timetracking-date-range {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 2);
	flex-wrap: wrap;
	align-items: flex-end;
}

.timetracking-form-group {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 0.5);
	flex: 1;
	min-width: 200px;
}

.timetracking-form-label {
	font-size: 14px;
	font-weight: 600;
	color: var(--color-main-text);
}

.timetracking-loading-container {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: calc(var(--default-grid-baseline) * 4);
	gap: calc(var(--default-grid-baseline) * 2);
}

.timetracking-report-display {
	padding: calc(var(--default-grid-baseline) * 2);
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.timetracking-report-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: calc(var(--default-grid-baseline) * 4);
	padding-bottom: calc(var(--default-grid-baseline) * 2);
	border-bottom: 1px solid var(--color-border);
}

.timetracking-report-header__actions {
	display: flex;
	gap: calc(var(--default-grid-baseline) * 2);
}

.timetracking-report-content {
	margin-top: calc(var(--default-grid-baseline) * 2);
}

.timetracking-report-summary-cards {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
	gap: calc(var(--default-grid-baseline) * 2);
	margin-bottom: calc(var(--default-grid-baseline) * 4);
}

.timetracking-report-card {
	background: var(--color-background-hover);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: calc(var(--default-grid-baseline) * 2);
	text-align: center;
	transition: box-shadow 0.2s ease;
}

.timetracking-report-card:hover {
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.timetracking-report-card--success {
	background: var(--color-success-background);
	border-color: var(--color-success);
}

.timetracking-report-card--warning {
	background: var(--color-warning-background);
	border-color: var(--color-warning);
}

.timetracking-report-card--error {
	background: var(--color-error-background);
	border-color: var(--color-error);
}

.timetracking-report-card--info {
	background: var(--color-primary-background);
	border-color: var(--color-primary);
}

.timetracking-report-card__value {
	font-size: 32px;
	font-weight: bold;
	color: var(--color-main-text);
	margin-bottom: calc(var(--default-grid-baseline) * 0.5);
}

.timetracking-report-card__label {
	font-size: 14px;
	color: var(--color-text-maxcontrast);
}

.timetracking-report-breakdown,
.timetracking-report-users {
	margin-top: calc(var(--default-grid-baseline) * 4);
	margin-bottom: calc(var(--default-grid-baseline) * 4);
}

.timetracking-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius);
	font-size: 12px;
	font-weight: 600;
}

.timetracking-badge--success {
	background: var(--color-success-background);
	color: var(--color-success-text);
}

.timetracking-badge--error {
	background: var(--color-error-background);
	color: var(--color-error-text);
}

.timetracking-text-success {
	color: var(--color-success);
}

.timetracking-text-warning {
	color: var(--color-warning);
}

@media (max-width: 768px) {
	.timetracking-date-range {
		flex-direction: column;
		align-items: stretch;
	}

	.timetracking-report-summary-cards {
		grid-template-columns: 1fr;
	}

	.timetracking-report-header {
		flex-direction: column;
		align-items: flex-start;
		gap: calc(var(--default-grid-baseline) * 2);
	}

	.timetracking-report-type-selector {
		flex-direction: column;
	}
}
</style>
