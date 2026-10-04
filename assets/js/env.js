/**
 * ENVIRONMENT SETTINGS — the ONE place URLs live.
 *
 * Loaded by every storefront AND admin page before their own config.js, so
 * nothing else in the front end hardcodes a host name. To move between your
 * machine and the live site you change (at most) one line below; to add a
 * new deployment, add one entry to ENVIRONMENTS.
 *
 *   ACTIVE = 'auto'        pick by the address in the browser: localhost /
 *                          127.0.0.1 / *.local → 'local', anything else →
 *                          'production'. Nothing to edit when deploying.
 *   ACTIVE = 'local'       force the local (XAMPP) settings everywhere.
 *   ACTIVE = 'production'  force the live settings everywhere.
 *
 * What it publishes for the rest of the front end:
 *   window.SPICE_ENV          the environment name that was chosen
 *   window.SPICE_SITE_URL     where the shop's pages are served from
 *   window.SPICE_API_BASE     the API, ends in /api/v1 (read by api.js, ui.js)
 *   window.SPICE_UPLOADS_URL  where uploaded files (logo, images) are served
 *
 * The backend has its own, separate setting: APP_URL in
 * spice-api/backend/.env — a server-side file that stays per-server.
 */
(function () {
  var ACTIVE = 'auto';

  var API_PATH = '/spice-api/backend/public/api/v1';
  var UPLOADS_PATH = '/spice-api/backend/public/uploads';

  /**
   * One entry per deployment. Only `siteUrl` is required — apiBase and
   * uploadsUrl are derived from it unless you override them explicitly
   * (e.g. an API on a different host).
   */
  var ENVIRONMENTS = {
    local: {
      siteUrl: 'http://localhost/5star',
    },
    production: {
      siteUrl: 'https://5star.alimstech.com',
    },
  };

  function detect() {
    var host = window.location.hostname;
    var isLocal = host === 'localhost' || host === '127.0.0.1' || host === '[::1]' || /\.local$/.test(host);

    return isLocal ? 'local' : 'production';
  }

  var name = ACTIVE === 'auto' ? detect() : ACTIVE;
  var env = ENVIRONMENTS[name];

  if (!env) {
    throw new Error('env.js: unknown environment "' + name + '". Use auto, ' + Object.keys(ENVIRONMENTS).join(', ') + '.');
  }

  var site = env.siteUrl.replace(/\/$/, '');

  window.SPICE_ENV = name;
  window.SPICE_SITE_URL = site;
  window.SPICE_API_BASE = (env.apiBase || site + API_PATH).replace(/\/$/, '');
  window.SPICE_UPLOADS_URL = (env.uploadsUrl || site + UPLOADS_PATH).replace(/\/$/, '');
})();
