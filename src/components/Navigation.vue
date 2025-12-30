<template>
	<nav class="timetracking-navigation" role="navigation" aria-label="Main navigation">
		<ul class="timetracking-navigation__list">
			<li class="timetracking-navigation__item">
				<router-link
					to="/"
					class="timetracking-navigation__link"
					:class="{ 'timetracking-navigation__link--active': $route.path === '/' }"
					:aria-current="$route.path === '/' ? 'page' : undefined">
					{{ $t('arbeitszeitcheck', 'Dashboard') }}
				</router-link>
			</li>
			<li class="timetracking-navigation__item">
				<router-link
					to="/time-entries"
					class="timetracking-navigation__link"
					:class="{ 'timetracking-navigation__link--active': $route.path === '/time-entries' }"
					:aria-current="$route.path === '/time-entries' ? 'page' : undefined">
					{{ $t('arbeitszeitcheck', 'Time Entries') }}
				</router-link>
			</li>
			<li class="timetracking-navigation__item">
				<router-link
					to="/absences"
					class="timetracking-navigation__link"
					:class="{ 'timetracking-navigation__link--active': $route.path === '/absences' }"
					:aria-current="$route.path === '/absences' ? 'page' : undefined">
					{{ $t('arbeitszeitcheck', 'Absences') }}
				</router-link>
			</li>
			<li class="timetracking-navigation__item">
				<router-link
					to="/reports"
					class="timetracking-navigation__link"
					:class="{ 'timetracking-navigation__link--active': $route.path === '/reports' }"
					:aria-current="$route.path === '/reports' ? 'page' : undefined">
					{{ $t('arbeitszeitcheck', 'Reports') }}
				</router-link>
			</li>
			<li class="timetracking-navigation__item">
				<router-link
					to="/calendar"
					class="timetracking-navigation__link"
					:class="{ 'timetracking-navigation__link--active': $route.path === '/calendar' }"
					:aria-current="$route.path === '/calendar' ? 'page' : undefined">
					{{ $t('arbeitszeitcheck', 'Calendar') }}
				</router-link>
			</li>
			<li class="timetracking-navigation__item">
				<router-link
					to="/timeline"
					class="timetracking-navigation__link"
					:class="{ 'timetracking-navigation__link--active': $route.path === '/timeline' }"
					:aria-current="$route.path === '/timeline' ? 'page' : undefined">
					{{ $t('arbeitszeitcheck', 'Timeline') }}
				</router-link>
			</li>
		</ul>
	</nav>
</template>

<script>
export default {
	name: 'Navigation'
}
</script>

<style scoped>
.timetracking-navigation {
	margin-bottom: calc(var(--default-grid-baseline) * 2);
	padding-bottom: calc(var(--default-grid-baseline) * 2);
	border-bottom: 1px solid var(--color-border);
}

.timetracking-navigation__list {
	display: flex;
	list-style: none;
	margin: 0;
	padding: 0;
	gap: calc(var(--default-grid-baseline) * 2);
	flex-wrap: wrap;
}

.timetracking-navigation__item {
	margin: 0;
}

.timetracking-navigation__link {
	display: inline-block;
	padding: calc(var(--default-grid-baseline) * 1) calc(var(--default-grid-baseline) * 2);
	color: var(--color-text-maxcontrast);
	text-decoration: none;
	border-radius: var(--border-radius);
	transition: background-color 0.2s ease, color 0.2s ease;
	font-weight: 500;
}

.timetracking-navigation__link:hover,
.timetracking-navigation__link:focus {
	background-color: var(--color-background-hover);
	color: var(--color-main-text);
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
}

.timetracking-navigation__link--active {
	background-color: var(--color-primary-element);
	color: var(--color-primary-element-text);
	font-weight: 600;
}

.timetracking-navigation__link--active:hover,
.timetracking-navigation__link--active:focus {
	background-color: var(--color-primary-element-hover);
	color: var(--color-primary-element-text);
}

@media (max-width: 768px) {
	.timetracking-navigation__list {
		flex-direction: column;
		gap: calc(var(--default-grid-baseline) * 1);
	}

	.timetracking-navigation__link {
		width: 100%;
		padding: calc(var(--default-grid-baseline) * 1.5) calc(var(--default-grid-baseline) * 2);
	}
}
</style>
