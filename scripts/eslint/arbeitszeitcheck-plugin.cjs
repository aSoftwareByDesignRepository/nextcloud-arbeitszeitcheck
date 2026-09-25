/**
 * Local ESLint rules for ArbeitszeitCheck.
 *
 * Prevents subdirectory 404 regressions (issue #21): raw /apps/arbeitszeitcheck/
 * paths must flow through URL helpers before navigation or fetch.
 *
 * @license AGPL-3.0-or-later
 */

'use strict'

const APP_PATH_RE = /\/apps\/arbeitszeitcheck\//

/** Function names that accept canonical app-relative paths and resolve webroot. */
const APPROVED_CALLEE_NAMES = new Set([
	'resolveUrl',
	'buildAppUrl',
	'buildApiUrl',
	'generateAppUrl',
	'generateUrl',
	'validationResolveUrl',
	'azcGenerateUrl',
	'triggerDownload',
	'openDownload',
	'toDownloadHref',
	'ajax',
	'callApi',
	'apiPut',
	'apiFetch',
	'_buildApiPath',
	'buildEmployeeExportUrl',
	'buildAuditExportUrl',
	'resolveRequestUrl',
])

/** Files that define or match path fragments (not navigational URLs). */
const EXEMPT_FILE_SUFFIXES = [
	'/js/common/utils.js',
	'/js/dashboard-widgets.js',
]

const NAVIGATION_MESSAGE =
	'Do not navigate with raw /apps/arbeitszeitcheck paths. Use ArbeitszeitCheckUtils.buildAppUrl(...), resolveUrl(...), triggerDownload(...), or Utils.ajax(...).'

const RAW_PATH_MESSAGE =
	'Raw /apps/arbeitszeitcheck string outside approved URL helpers. Wrap with buildAppUrl(...), resolveUrl(...), Utils.ajax(...), OC.generateUrl(...), or a server linkToRoute URL.'

function isExemptFile(filename) {
	return EXEMPT_FILE_SUFFIXES.some((suffix) => filename.replace(/\\/g, '/').endsWith(suffix))
}

function stringContainsAppPath(value) {
	return typeof value === 'string' && APP_PATH_RE.test(value)
}

function isApprovedCallee(callee) {
	if (!callee) {
		return false
	}
	if (callee.type === 'Identifier') {
		return APPROVED_CALLEE_NAMES.has(callee.name)
	}
	if (callee.type === 'MemberExpression' && !callee.computed && callee.property.type === 'Identifier') {
		return APPROVED_CALLEE_NAMES.has(callee.property.name)
	}
	return false
}

function isInsideApprovedCall(node) {
	let current = node.parent
	while (current) {
		if (current.type === 'CallExpression' && isApprovedCallee(current.callee)) {
			return true
		}
		current = current.parent
	}
	return false
}

function reportRawAppPath(context, node, value) {
	if (!stringContainsAppPath(value)) {
		return
	}
	if (isExemptFile(context.getFilename())) {
		return
	}
	if (isInsideApprovedCall(node)) {
		return
	}
	context.report({ node, message: RAW_PATH_MESSAGE })
}

const noRawAppUrl = {
	meta: {
		type: 'problem',
		docs: {
			description: 'Disallow raw /apps/arbeitszeitcheck paths outside approved URL helpers',
		},
		schema: [],
	},
	create(context) {
		return {
			Literal(node) {
				if (typeof node.value === 'string') {
					reportRawAppPath(context, node, node.value)
				}
			},
			TemplateElement(node) {
				reportRawAppPath(context, node, node.value.raw)
			},
		}
	},
}

const noRawAppNavigation = {
	meta: {
		type: 'problem',
		docs: {
			description: 'Disallow direct navigation to raw /apps/arbeitszeitcheck paths',
		},
		schema: [],
	},
	create(context) {
		function expressionHasRawAppPath(node) {
			if (!node) {
				return false
			}
			if (node.type === 'Literal') {
				return stringContainsAppPath(node.value)
			}
			if (node.type === 'TemplateLiteral') {
				return node.quasis.some((q) => stringContainsAppPath(q.value.raw))
			}
			if (node.type === 'BinaryExpression' && node.operator === '+') {
				return expressionHasRawAppPath(node.left) || expressionHasRawAppPath(node.right)
			}
			if (node.type === 'LogicalExpression') {
				return expressionHasRawAppPath(node.left) || expressionHasRawAppPath(node.right)
			}
			if (node.type === 'CallExpression' && isApprovedCallee(node.callee)) {
				return false
			}
			return false
		}

		function checkNavigation(node, urlNode) {
			if (isExemptFile(context.getFilename())) {
				return
			}
			if (!expressionHasRawAppPath(urlNode)) {
				return
			}
			if (urlNode.type === 'CallExpression' && isApprovedCallee(urlNode.callee)) {
				return
			}
			context.report({ node, message: NAVIGATION_MESSAGE })
		}

		return {
			AssignmentExpression(node) {
				const left = node.left
				if (left.type !== 'MemberExpression') {
					return
				}
				const prop = left.property
				if (prop.type !== 'Identifier' || prop.name !== 'href') {
					return
				}
				// window.location.href = ...
				const obj = left.object
				if (
					obj.type === 'MemberExpression'
					&& obj.object.type === 'Identifier'
					&& obj.object.name === 'window'
					&& obj.property.type === 'Identifier'
					&& obj.property.name === 'location'
				) {
					checkNavigation(node, node.right)
					return
				}
				// element.href = ...
				checkNavigation(node, node.right)
			},
			CallExpression(node) {
				const callee = node.callee
				if (callee.type === 'MemberExpression' && callee.property.type === 'Identifier') {
					if (callee.property.name === 'assign' && node.arguments[0]) {
						const obj = callee.object
						if (
							obj.type === 'MemberExpression'
							&& obj.object.type === 'Identifier'
							&& obj.object.name === 'window'
							&& obj.property.type === 'Identifier'
							&& obj.property.name === 'location'
						) {
							checkNavigation(node, node.arguments[0])
						}
					}
					if (callee.property.name === 'open') {
						const root = callee.object
						if (root.type === 'Identifier' && root.name === 'window' && node.arguments[0]) {
							checkNavigation(node, node.arguments[0])
						}
					}
				}
			},
		}
	},
}

module.exports = {
	rules: {
		'no-raw-app-url': noRawAppUrl,
		'no-raw-app-navigation': noRawAppNavigation,
	},
}
