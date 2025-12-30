module.exports = {
	testEnvironment: 'jsdom',
	testMatch: [
		'<rootDir>/tests/**/*.test.js',
		'<rootDir>/tests/**/*.spec.js'
	],
	moduleNameMapping: {
		'^@/(.*)$': '<rootDir>/src/$1',
		'^@nextcloud/(.*)$': '<rootDir>/node_modules/@nextcloud/$1'
	},
	collectCoverageFrom: [
		'src/**/*.{js,vue}',
		'!src/main.js'
	],
	coverageDirectory: 'tests/coverage',
	coverageReporters: [
		'text',
		'html',
		'lcov'
	],
	transform: {
		'^.+\\.js$': 'babel-jest',
		'^.+\\.vue$': '@vue/vue3-jest'
	},
	moduleFileExtensions: [
		'js',
		'vue',
		'json'
	]
};