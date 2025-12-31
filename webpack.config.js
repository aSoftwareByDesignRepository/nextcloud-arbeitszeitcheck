const path = require('path')
const webpack = require('webpack')
const MiniCssExtractPlugin = require('mini-css-extract-plugin')
const { VueLoaderPlugin } = require('vue-loader')
const NodePolyfillPlugin = require('node-polyfill-webpack-plugin')
const TerserPlugin = require('terser-webpack-plugin')

const appName = 'arbeitszeitcheck'
const appVersion = '1.0.0'
const buildMode = process.env.NODE_ENV || 'production'
const isDev = buildMode === 'development'

console.info('Building', appName, appVersion, 'in', buildMode, 'mode\n')

module.exports = {
	target: 'web',
	mode: buildMode,
	// CRITICAL: NO source maps for CSP compliance
	devtool: false,

	entry: {
		'arbeitszeitcheck-main': path.join(__dirname, 'src', 'main.js'),
		'admin-settings': path.join(__dirname, 'src', 'admin.js'),
		'settings': path.join(__dirname, 'src', 'settings.js'),
		'compliance-dashboard': path.join(__dirname, 'src', 'compliance-dashboard.js'),
		'compliance-violations': path.join(__dirname, 'src', 'compliance-violations.js'),
		'compliance-reports': path.join(__dirname, 'src', 'compliance-reports.js'),
		'manager-dashboard': path.join(__dirname, 'src', 'manager-dashboard.js'),
		'admin-dashboard': path.join(__dirname, 'src', 'admin-dashboard.js'),
		'admin-users': path.join(__dirname, 'src', 'admin-users.js'),
		'working-time-models': path.join(__dirname, 'src', 'working-time-models.js'),
		'audit-log-viewer': path.join(__dirname, 'src', 'audit-log-viewer.js')
	},

	output: {
		path: path.resolve(__dirname, 'js'),
		filename: '[name].js',
		chunkFilename: '[name].js',
		publicPath: '/apps/arbeitszeitcheck/js/',
		clean: false,
		assetModuleFilename: '../css/[name][ext]'
	},

	module: {
		rules: [
			{
				test: /\.vue$/,
				loader: 'vue-loader'
			},
			{
				test: /\.js$/,
				loader: 'babel-loader',
				exclude: /node_modules/
			},
			{
				test: /\.css$/,
				use: [
					MiniCssExtractPlugin.loader,
					'css-loader'
				]
			},
			{
				test: /\.scss$/,
				use: [
					MiniCssExtractPlugin.loader,
					'css-loader',
					'sass-loader'
				]
			},
			{
				test: /\.(png|jpe?g|gif|svg|woff2?|eot|ttf)$/,
				type: 'asset/inline'
			}
		]
	},

	plugins: [
		new VueLoaderPlugin(),
		
		new NodePolyfillPlugin({
			additionalAliases: ['process']
		}),
		
		new webpack.DefinePlugin({
			__VUE_OPTIONS_API__: JSON.stringify(true),
			__VUE_PROD_DEVTOOLS__: JSON.stringify(false),
			__VUE_PROD_HYDRATION_MISMATCH_DETAILS__: JSON.stringify(false),
			appName: JSON.stringify(appName),
			appVersion: JSON.stringify(appVersion)
		}),
		
		new MiniCssExtractPlugin({
			filename: '../css/[name].css',
			chunkFilename: '../css/[name].css',
			ignoreOrder: true
		}),
		
		new webpack.IgnorePlugin({
			resourceRegExp: /^\.[/\\]locale$/,
			contextRegExp: /moment[/\\]min$/
		})
	],

	resolve: {
		extensions: ['*', '.ts', '.js', '.vue'],
		symlinks: false,
		alias: {
			'vue$': 'vue/dist/vue.esm-bundler.js'
		}
	},

	optimization: {
		splitChunks: false,
		runtimeChunk: false,
		minimize: !isDev,
		minimizer: [
			new TerserPlugin({
				terserOptions: {
					compress: {
						drop_console: !isDev,
						drop_debugger: !isDev
					},
					output: {
						comments: false
					},
					mangle: true
				},
				extractComments: false
			})
		]
	},

	performance: {
		hints: false
	},

	stats: {
		preset: 'errors-only',
		modules: false,
		chunks: false,
		chunkModules: false,
		assets: false
	},

	cache: process.env.DOCKER_BUILD ? {
		type: 'filesystem',
		cacheDirectory: path.resolve(__dirname, '.webpack-cache'),
		buildDependencies: {
			config: [__filename]
		}
	} : true,

	parallelism: 1
}
