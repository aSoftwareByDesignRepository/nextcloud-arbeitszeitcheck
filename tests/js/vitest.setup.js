// Minimal Nextcloud-ish globals for unit tests
// Keep very small: app code already has safe fallbacks when these are absent.

globalThis.OC = {
  requestToken: 'test-token',
  generateUrl: (url) => url,
}

globalThis.t = (_app, s) => s
globalThis.n = (_app, singular, plural, count) => (count === 1 ? singular : plural)

