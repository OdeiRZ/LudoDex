// A JS constant rather than a literal template src="..." wherever this is
// used - the latter goes through the SFC compiler's asset-url transform,
// which (as of @vitejs/plugin-vue 6) resolves it against a file:// module
// URL instead of leaving a root-relative public path untouched, and
// Node's stricter file URL parsing throws on the result outright.
export const FALLBACK_ICON_URL = '/icons/icon-192.png'
