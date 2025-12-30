<template>
	<div class="arbeitszeitcheck-app">
		<Navigation />
		<router-view />
		<OnboardingTour />
	</div>
</template>

<script>
import Navigation from './components/Navigation.vue'
import OnboardingTour from './components/OnboardingTour.vue'

export default {
	name: 'App',
	components: {
		Navigation,
		OnboardingTour
	},
	data() {
		return {}
	}
}
</script>

<style scoped>
.arbeitszeitcheck-app {
	min-height: 100%;
	padding: var(--default-grid-baseline);
	background-color: var(--color-main-background);
}
</style>