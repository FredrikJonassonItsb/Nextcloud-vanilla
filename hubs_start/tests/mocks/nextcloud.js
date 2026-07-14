/**
 * Shared mock module for the @nextcloud/* packages used by the frontend.
 *
 * jest's moduleNameMapper points @nextcloud/l10n, @nextcloud/router,
 * @nextcloud/axios, @nextcloud/dialogs and @nextcloud/initial-state all at this
 * one file (see jest.config.js). Each named export below mirrors the real API
 * surface the app actually consumes, kept intentionally minimal.
 */

// --- @nextcloud/l10n ---------------------------------------------------------
// Identity translators: return the source string (with {placeholder}/vars
// substitution like the real t(), and %n substitution for n()).
const t = (app, text, vars) => String(text).replace(/\{([^{}]+)\}/g, (hela, nyckel) =>
	(vars && Object.prototype.hasOwnProperty.call(vars, nyckel)) ? String(vars[nyckel]) : hela)
const n = (app, singular, plural, count) =>
	(count === 1 ? singular : plural).replace(/%n/g, String(count))
const translate = t
const translatePlural = n

// --- @nextcloud/router -------------------------------------------------------
const generateUrl = (url, params = {}) =>
	'/index.php' + url.replace(/\{([^}]+)\}/g, (_, k) => encodeURIComponent(params[k] ?? `{${k}}`))
const generateOcsUrl = (url, params = {}) =>
	'/ocs/v2.php' + url.replace(/\{([^}]+)\}/g, (_, k) => encodeURIComponent(params[k] ?? `{${k}}`))

// --- @nextcloud/axios --------------------------------------------------------
// Default export is the axios-like client; every verb is a jest mock returning
// an empty success envelope so tests can override per-case with mockResolvedValue.
const emptyResponse = () => Promise.resolve({ data: {} })
const axios = {
	get: jest.fn(emptyResponse),
	post: jest.fn(emptyResponse),
	put: jest.fn(emptyResponse),
	patch: jest.fn(emptyResponse),
	delete: jest.fn(emptyResponse),
	request: jest.fn(emptyResponse),
}

// --- @nextcloud/dialogs ------------------------------------------------------
const showSuccess = jest.fn()
const showError = jest.fn()
const showWarning = jest.fn()
const showInfo = jest.fn()

// --- @nextcloud/initial-state ------------------------------------------------
// Tests can seed state by mutating this map before importing the module under test.
const __initialState = {}
const loadState = (app, key, fallback) => {
	const value = __initialState[`${app}-${key}`]
	return value === undefined ? fallback : value
}

module.exports = {
	// Mark as an ES module so `import axios from '@nextcloud/axios'` resolves to the
	// `default` export (the axios-like client) rather than the whole module object.
	// Named imports (t, generateUrl, loadState, showSuccess, …) are unaffected.
	__esModule: true,
	// l10n
	t,
	n,
	translate,
	translatePlural,
	// router
	generateUrl,
	generateOcsUrl,
	// axios
	default: axios,
	// dialogs
	showSuccess,
	showError,
	showWarning,
	showInfo,
	// initial-state
	loadState,
	__initialState,
}
