/* Kaleta - light / dark mode of the admin: set before the page renders so it does not flicker.
 * A separate file because of the admin's Content-Security-Policy (no inline scripts). */
try { var t = localStorage.getItem('kaleta-tema'); if (t) { document.documentElement.setAttribute('data-tema', t); } } catch (e) {}
