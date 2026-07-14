import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Modal from '@typo3/backend/modal.js';
import Notification from '@typo3/backend/notification.js';
import Severity from '@typo3/backend/severity.js';
import { refreshCharts, initCharts } from './dashboard-charts.js';
import { initPeriodDropdownForms } from './period-filter.js';
import { mountProviderList } from './provider-drawer.js';
import { observeBrowserAutocomplete } from './disable-browser-autocomplete.js';

const routes = {
  status: 'nst3af_credits_status',
  toggle: 'nst3af_credits_toggle',
  activate: 'nst3af_credits_activate',
  dashboard: 'nst3af_credits_dashboard',
  estimate: 'nst3af_credits_estimate',
};

const LL = {
  brandT3planet: 'credits.js.brand.t3planet',
  sectionProviders: 'credits.js.section.providers',
  errorMissingAjaxRoute: 'credits.js.error.missingAjaxRoute',
  errorHttpRequestFailed: 'credits.js.error.httpRequestFailed',
  errorTitleStatus: 'credits.js.error.title.status',
  errorTitleEnable: 'credits.js.error.title.enable',
  errorTitleSwitchOwnKeys: 'credits.js.error.title.switchOwnKeys',
  errorTitleActivate: 'credits.js.error.title.activate',
  checkoutHintBody: 'credits.js.checkout.hint.body',
  checkoutInvalidUrl: 'credits.js.checkout.invalidUrl',
  checkoutOpenNewTab: 'credits.js.checkout.openNewTab',
  modalSwitchTitle: 'credits.js.modal.switchTitle',
  modalToCreditsBody: 'credits.js.modal.toCredits.body',
  modalToCreditsOk: 'credits.js.modal.toCredits.ok',
  modalToOwnKeysBody: 'credits.js.modal.toOwnKeys.body',
  modalToOwnKeysOk: 'credits.js.modal.toOwnKeys.ok',
  notificationCreditsActive: 'credits.js.notification.creditsActive',
  notificationCreditsSelected: 'credits.js.notification.creditsSelected',
  notificationOwnKeysAgain: 'credits.js.notification.ownKeysAgain',
  notificationActivateReload: 'credits.js.notification.activateReload',
  notificationActivateIncomplete: 'credits.js.notification.activateIncomplete',
};

/**
 * @param {string} key XLF trans-unit id (exposed as TYPO3.lang[key])
 * @param {string} fallback English default if label missing
 */
function ll(key, fallback) {
  const v = typeof TYPO3 !== 'undefined' && TYPO3.lang ? TYPO3.lang[key] : undefined;
  return typeof v === 'string' && v !== '' ? v : fallback;
}

/**
 * Replaces %s placeholders in order (same pattern as many TYPO3 JS labels).
 * @param {string} key
 * @param {string} fallback
 * @param {Array<string|number>} args
 */
function llFormat(key, fallback, ...args) {
  let s = ll(key, fallback);
  for (const a of args) {
    s = s.replace('%s', String(a));
  }
  return s;
}

/** @type {WeakSet<HTMLElement>} */
const initializedRoots = new WeakSet();

function ajaxUrl(route) {
  const url = TYPO3?.settings?.ajaxUrls?.[route];
  if (!url) {
    throw new Error(
      llFormat(
        LL.errorMissingAjaxRoute,
        'Missing backend AJAX route "%s". Flush all caches and reload.',
        route,
      ),
    );
  }
  return url;
}

/**
 * @param {unknown} err
 */
async function parseAjaxError(err) {
  if (err && typeof err.resolve === 'function' && typeof err.raw === 'function') {
    const response = err.raw();
    const httpStatus = response?.status ?? null;
    try {
      const body = await err.resolve();
      if (body && typeof body === 'object') {
        const userMessage =
          body.userMessage || body.message || body.error_code || body.error || null;
        if (userMessage) {
          return { userMessage: String(userMessage), errorCode: body.error_code || body.error || null, httpStatus };
        }
      }
    } catch {
      // ignore
    }
    if (httpStatus) {
      return {
        userMessage: llFormat(
          LL.errorHttpRequestFailed,
          'Request failed (HTTP %s).',
          httpStatus,
        ),
        errorCode: null,
        httpStatus,
      };
    }
  }
  if (err instanceof Error && err.message) {
    return { userMessage: err.message, errorCode: null, httpStatus: null };
  }
  return { userMessage: String(err), errorCode: null, httpStatus: null };
}

/**
 * @param {string} route
 * @param {Record<string, unknown>} [body]
 */
async function post(route, body = {}) {
  try {
    const response = await new AjaxRequest(ajaxUrl(route)).post(body);
    return await response.resolve();
  } catch (err) {
    const parsed = await parseAjaxError(err);
    const error = new Error(parsed.userMessage);
    error.creditsError = parsed;
    throw error;
  }
}

async function get(route, query = {}) {
  try {
    const request = new AjaxRequest(ajaxUrl(route));
    Object.entries(query).forEach(([key, value]) => {
      if (value !== undefined && value !== null && value !== '') {
        request.withQueryArguments({ [key]: String(value) });
      }
    });
    const response = await request.get();
    return await response.resolve();
  } catch (err) {
    const parsed = await parseAjaxError(err);
    const error = new Error(parsed.userMessage);
    error.creditsError = parsed;
    throw error;
  }
}

function qs(root, selector) {
  return root.querySelector(selector);
}

/**
 * @param {HTMLElement} root
 */
function isCreditsFeatureAvailable(root) {
  return root.dataset.creditsFeatureAvailable !== '0';
}

function findProviderPagePanels() {
  const list = document.querySelector('[data-aiu-provider-list]');
  if (!list) {
    return { creditsPanel: null, ownKeysPanel: null };
  }
  return {
    creditsPanel: list.querySelector('[data-aiu-credits-panel]'),
    ownKeysPanel: list.querySelector('[data-aiu-ownkeys-panel]'),
  };
}

/**
 * @param {boolean} creditMode
 */
function applyContentPanels(creditMode) {
  const { creditsPanel, ownKeysPanel } = findProviderPagePanels();
  creditsPanel?.classList.toggle('is-hidden', !creditMode);
  creditsPanel?.setAttribute('aria-hidden', creditMode ? 'false' : 'true');
  ownKeysPanel?.classList.toggle('is-hidden', creditMode);
  ownKeysPanel?.setAttribute('aria-hidden', creditMode ? 'true' : 'false');
  applyDashboardViews(creditMode);
}

function applyDashboardViews(creditMode) {
  document.querySelectorAll('[data-aiu-dashboard-view]').forEach((panel) => {
    if (!(panel instanceof HTMLElement)) {
      return;
    }
    const view = panel.getAttribute('data-aiu-dashboard-view');
    const show = view === 'credits' ? creditMode : !creditMode;
    panel.classList.toggle('is-hidden', !show);
    panel.setAttribute('aria-hidden', show ? 'false' : 'true');
  });
  document.querySelectorAll('.aiu-dashboard__mode-btn').forEach((button) => {
    if (!(button instanceof HTMLButtonElement)) {
      return;
    }
    const mode = button.getAttribute('data-aiu-providers-mode');
    const active = mode === 'credits' ? creditMode : !creditMode;
    button.classList.toggle('active', active);
    button.classList.toggle('is-active', active);
    button.setAttribute('aria-pressed', active ? 'true' : 'false');
  });
  document.querySelectorAll('[data-aiu-checklist-view]').forEach((panel) => {
    if (!(panel instanceof HTMLElement)) {
      return;
    }
    const view = panel.getAttribute('data-aiu-checklist-view');
    const show = view === 'credits' ? creditMode : !creditMode;
    panel.classList.toggle('is-hidden', !show);
    panel.setAttribute('aria-hidden', show ? 'false' : 'true');
  });
  document.dispatchEvent(new CustomEvent('aiu-dashboard-view-changed', { detail: { creditMode } }));
  refreshCharts();
}

/** @type {ReadonlySet<string>} */
const CHECKOUT_ALLOWED_HOSTS = new Set([
  'payments.pabbly.com',
  'pabbly.com',
  'pabbly.t3planet.de',
  't3planet.shop',
  'www.t3planet.shop',
]);

/** @type {readonly string[]} */
const CHECKOUT_ALLOWED_HOST_SUFFIXES = ['.t3planet.de', '.t3planet.shop', '.t3planet.com', '.pabbly.com'];

/**
 * Mirrors {@see CreditsCheckoutUrlValidator} for client-side checks before opening the modal.
 *
 * @param {string} url
 * @returns {boolean}
 */
function isAllowedCheckoutUrl(url) {
  try {
    const parsed = new URL((url || '').trim());
    if (parsed.protocol !== 'https:') {
      return false;
    }
    const host = parsed.hostname.toLowerCase();
    if (CHECKOUT_ALLOWED_HOSTS.has(host)) {
      return true;
    }
    return CHECKOUT_ALLOWED_HOST_SUFFIXES.some(
      (suffix) => host.endsWith(suffix) && host.length > suffix.length,
    );
  } catch {
    return false;
  }
}

/**
 * Opens T3Planet checkout in a TYPO3 backend modal iframe (direct URL; host allowlist).
 *
 * @param {string} checkoutUrl
 * @param {string} [title]
 */
function openCheckoutModal(checkoutUrl, title = '') {
  const normalized = (checkoutUrl || '').trim();
  if (normalized === '') {
    return;
  }
  if (!isAllowedCheckoutUrl(normalized)) {
    Notification.error(
      ll(LL.brandT3planet, 'T3Planet Credits'),
      ll(LL.checkoutInvalidUrl, 'This checkout link is not allowed.'),
    );
    return;
  }

  Modal.advanced({
    type: Modal.types.iframe,
    size: Modal.sizes.large,
    title: title || ll(LL.brandT3planet, 'T3Planet Credits'),
    content: normalized,
    additionalCssClasses: ['aiu-checkout-modal'],
    staticBackdrop: false,
    buttons: [
      {
        text: ll(LL.checkoutOpenNewTab, 'Open in new tab'),
        btnClass: 'btn-link',
        trigger: () => {
          window.open(normalized, '_blank', 'noopener,noreferrer');
        },
      },
      {
        text: (typeof TYPO3 !== 'undefined' && TYPO3.lang?.['button.close']) || 'Close',
        active: true,
        btnClass: 'btn-default',
        trigger: () => {
          Modal.dismiss();
        },
      },
    ],
    callback: (currentModal) => {
      const body = currentModal?.querySelector?.('.t3js-modal-body');
      const iframe = body?.querySelector?.('iframe');
      if (body) {
        body.classList.add('aiu-checkout-modal__body');
      }
      if (iframe) {
        iframe.classList.add('aiu-checkout-modal__frame');
        iframe.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
      }
    },
  });
}

function bindCheckoutLinks() {
  document.querySelectorAll('[data-aiu-checkout]').forEach((link) => {
    if (!(link instanceof HTMLAnchorElement) || link.dataset.aiuCheckoutBound === '1') {
      return;
    }
    link.dataset.aiuCheckoutBound = '1';
    link.removeAttribute('target');
    link.addEventListener('click', (event) => {
      const checkoutUrl = link.getAttribute('data-aiu-checkout-url') || link.href;
      const title = link.getAttribute('data-aiu-checkout-title') || link.textContent?.trim() || '';
      event.preventDefault();
      openCheckoutModal(checkoutUrl, title);
    });
  });
}

/**
 * Pre-submit credit estimate (token-based; not guaranteed).
 *
 * @param {string} featureKey
 * @param {Record<string, unknown>} metaJson
 * @param {'charge'|'embed'} [endpoint]
 * @returns {Promise<{estimate_label: string, estimated_credits: number, estimated_tokens: number, pricing: object}|null>}
 */
export async function estimateCredits(featureKey, metaJson = {}, endpoint = 'charge') {
  try {
    return await post(routes.estimate, {
      feature_key: featureKey,
      meta_json: metaJson,
      endpoint,
    });
  } catch (err) {
    handleError(err, ll(LL.brandT3planet, 'T3Planet Credits'));
    return null;
  }
}

/**
 * Keeps hero credit progress bars in sync (CSS var + width) after partial DOM refresh.
 *
 * @param {ParentNode} [root]
 */
function initCreditsHeroProgress(root = document) {
  const scope = root instanceof Document ? root : root;
  scope.querySelectorAll('.aiu-credits-hero__progress[data-aiu-credits-progress]').forEach((track) => {
    if (!(track instanceof HTMLElement)) {
      return;
    }
    const raw = track.getAttribute('data-aiu-credits-progress');
    const pct = Number(raw);
    if (!Number.isFinite(pct)) {
      return;
    }
    const clamped = Math.max(0, Math.min(100, pct));
    track.style.setProperty('--aiu-credits-progress', String(clamped));
    const bar = track.querySelector('.progress-bar');
    if (bar instanceof HTMLElement) {
      bar.style.width = `${clamped}%`;
    }
  });
}

function bindScrollHelpers() {
  document.querySelectorAll('[data-aiu-scroll-target]').forEach((button) => {
    if (!(button instanceof HTMLElement) || button.dataset.aiuScrollBound === '1') {
      return;
    }
    button.dataset.aiuScrollBound = '1';
    button.addEventListener('click', () => {
      const targetId = button.getAttribute('data-aiu-scroll-target');
      const target =
        targetId === 'bundles'
          ? document.querySelector('[data-aiu-credits-bundles]')
          : document.getElementById(`aiu-credits-${targetId}`);
      target?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  });
}

function bindCardSelect(card, onSelect) {
  if (!card) {
    return;
  }
  const handler = (event) => {
    if (event.target.closest('[data-credits-activate]')) {
      return;
    }
    event.preventDefault();
    onSelect();
  };
  card.addEventListener('click', handler);
  card.addEventListener('keydown', (event) => {
    if (event.target.closest('[data-credits-activate]')) {
      return;
    }
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      onSelect();
    }
  });
}

/**
 * @param {string} title
 * @param {string} message
 * @param {string} okText
 * @param {() => void} onOk
 */
function confirmSwitch(title, message, okText, onOk) {
  Modal.confirm(title, message, Severity.warning, [
    {
      text: TYPO3?.lang?.['button.cancel'] || 'Cancel',
      active: true,
      btnClass: 'btn-default',
      trigger: () => {
        Modal.dismiss();
      },
    },
    {
      text: okText,
      btnClass: 'btn-warning',
      trigger: () => {
        Modal.dismiss();
        onOk();
      },
    },
  ]);
}

/**
 * @param {HTMLElement} root
 * @param {{ creditMode?: boolean, active?: boolean }} data
 */
function updateTokenDisplay(root, token) {
  const holder = root.querySelector('[data-credits-token-display]');
  const code = root.querySelector('.aiu-mode__token-value');
  if (!holder || !code) {
    return;
  }
  const value = typeof token === 'string' ? token.trim() : '';
  if (value === '') {
    holder.classList.add('is-hidden');
    code.textContent = '';
    return;
  }
  holder.classList.remove('is-hidden');
  code.textContent = value;
}

function applyUiState(root, data) {
  const creditMode = Boolean(data.creditMode);
  const active = Boolean(data.active);

  const creditsCard = qs(root, '[data-aiu-providers-mode="credits"]');
  const ownKeysCard = qs(root, '[data-aiu-providers-mode="ownkeys"]');
  const creditsActiveBadge = qs(root, '[data-credits-active-badge]');
  const activatePill = qs(root, '[data-credits-activate]');
  const ownKeysActiveBadge = qs(root, '[data-ownkeys-active-badge]');

  creditsCard?.classList.toggle('is-active', creditMode);
  creditsCard?.setAttribute('aria-pressed', creditMode ? 'true' : 'false');
  ownKeysCard?.classList.toggle('is-active', !creditMode);
  ownKeysCard?.setAttribute('aria-pressed', creditMode ? 'false' : 'true');

  root.dataset.creditsActive = active ? '1' : '0';
  root.dataset.creditMode = creditMode ? '1' : '0';

  creditsActiveBadge?.classList.toggle('is-hidden', !active);
  activatePill?.classList.toggle('is-hidden', !creditMode || active);
  ownKeysActiveBadge?.classList.toggle('is-hidden', creditMode);

  applyContentPanels(creditMode);
  if (typeof data.creditsBearerToken === 'string') {
    updateTokenDisplay(root, data.creditsBearerToken);
  } else if (!active) {
    updateTokenDisplay(root, '');
  }
}

/**
 * @param {unknown} err
 * @param {string} title
 */
function handleError(err, title) {
  const message = err instanceof Error && err.message ? err.message : String(err);
  if (typeof Notification !== 'undefined' && Notification.error) {
    Notification.error(title, message);
  } else {
    console.error(title, message);
  }
}

function initCreditsMode(root) {
  if (!(root instanceof HTMLElement) || initializedRoots.has(root)) {
    return;
  }
  initializedRoots.add(root);
  observeBrowserAutocomplete(root);

  const creditsFeatureAvailable = isCreditsFeatureAvailable(root);
  const creditsCard = qs(root, '[data-aiu-providers-mode="credits"]');
  const ownKeysCard = qs(root, '[data-aiu-providers-mode="ownkeys"]');
  const activatePill = qs(root, '[data-credits-activate]');

  if (!creditsFeatureAvailable && creditsCard instanceof HTMLElement) {
    creditsCard.classList.add('is-disabled');
    creditsCard.setAttribute('aria-disabled', 'true');
    creditsCard.setAttribute('tabindex', '-1');
  }

  const initialCreditMode = creditsFeatureAvailable && root.dataset.creditMode === '1';
  applyContentPanels(initialCreditMode);
  applyDashboardViews(initialCreditMode);

  const refreshStatus = () =>
    get(routes.status)
      .then((data) => {
        applyUiState(root, data);
        initCreditsHeroProgress(document);
        return data;
      })
      .catch((err) =>
        handleError(err, ll(LL.errorTitleStatus, 'T3Planet Credits status')),
      );

  bindCardSelect(creditsCard, () => {
    if (!creditsFeatureAvailable) {
      return;
    }
    if (root.dataset.creditMode === '1') {
      return;
    }
    confirmSwitch(
      ll(LL.modalSwitchTitle, 'Switch AI Provider Mode?'),
      ll(
        LL.modalToCreditsBody,
        'Switch to T3Planet Credits? All AI requests will be routed through T3Planet Credits. You can switch back anytime.',
      ),
      ll(LL.modalToCreditsOk, 'Switch to T3Planet Credits'),
      () => {
        post(routes.toggle, { enabled: true })
          .then((data) => {
            applyUiState(root, data);
            if (data.creditMode) {
              if (data.active) {
                Notification.success(
                  ll(LL.brandT3planet, 'T3Planet Credits'),
                  ll(LL.notificationCreditsActive, 'Credits mode is active.'),
                );
                window.location.reload();
              } else {
                Notification.info(
                  ll(LL.brandT3planet, 'T3Planet Credits'),
                  ll(
                    LL.notificationCreditsSelected,
                    'Credits mode selected. Click Activate to connect your license.',
                  ),
                );
              }
            }
          })
          .catch((err) =>
            handleError(err, ll(LL.errorTitleEnable, 'Enable T3Planet Credits')),
          );
      },
    );
  });

  bindCardSelect(ownKeysCard, () => {
    if (root.dataset.creditMode !== '1') {
      return;
    }
    confirmSwitch(
      ll(LL.modalSwitchTitle, 'Switch AI Provider Mode?'),
      ll(
        LL.modalToOwnKeysBody,
        'Switch to Your Own API Keys? All AI requests will use your configured providers and API keys.',
      ),
      ll(LL.modalToOwnKeysOk, 'Switch to Your Own API Keys'),
      () => {
        post(routes.toggle, { enabled: false })
          .then((data) => {
            applyUiState(root, data);
            if (!data.creditMode) {
              Notification.info(
                ll(LL.sectionProviders, 'AI providers'),
                ll(LL.notificationOwnKeysAgain, 'Using your own API keys again.'),
              );
            }
          })
          .catch((err) =>
            handleError(err, ll(LL.errorTitleSwitchOwnKeys, 'Switch to own API keys')),
          );
      },
    );
  });

  activatePill?.addEventListener('click', (event) => {
    if (!creditsFeatureAvailable) {
      event.preventDefault();
      event.stopPropagation();
      return;
    }
    event.preventDefault();
    event.stopPropagation();
    post(routes.activate)
      .then((data) => {
        if (data.status === false || data.error || data.error_code) {
          handleError(
            new Error(data.userMessage || data.message || data.error_code || data.error),
            ll(LL.errorTitleActivate, 'Activate T3Planet Credits'),
          );
          return;
        }
        if (data.active) {
          Notification.success(
            ll(LL.brandT3planet, 'T3Planet Credits'),
            ll(
              LL.notificationActivateReload,
              'Credits mode is active. Loading your dashboard…',
            ),
          );
          window.location.reload();
          return;
        }
        Notification.warning(
          ll(LL.brandT3planet, 'T3Planet Credits'),
          ll(
            LL.notificationActivateIncomplete,
            'Mode enabled, but activation did not complete. Check license keys and API connectivity.',
          ),
        );
      })
      .catch((err) =>
        handleError(err, ll(LL.errorTitleActivate, 'Activate T3Planet Credits')),
      );
  });

  activatePill?.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      event.stopPropagation();
      activatePill.click();
    }
  });

  bindCheckoutLinks();
  bindScrollHelpers();
  refreshStatus();
}

/**
 * @param {ParentNode} root
 */
function initDashboardProviderDrawer(root) {
  root.querySelectorAll('[data-aiu-provider-list]').forEach((providerList) => {
    mountProviderList(providerList);
  });
}

/**
 * @param {ParentNode} [scope]
 */
function initDashboardRoots(scope = document) {
  scope.querySelectorAll('[data-aiu-dashboard-root]').forEach((root) => {
    initPeriodDropdownForms(root);
    initDashboardProviderDrawer(root);
    initCreditsHeroProgress(root);
  });
}

/**
 * Re-init after fetch-based partial refresh (dashboard period dropdown).
 *
 * @param {Element} root
 */
function reinitDashboardRoot(root) {
  observeBrowserAutocomplete(root);
  initPeriodDropdownForms(root);
  initDashboardProviderDrawer(root);
  initCreditsHeroProgress(root);
  const creditsRoot = root.querySelector('[data-aiu-providers-credits-root]');
  if (creditsRoot instanceof HTMLElement) {
    initCreditsMode(creditsRoot);
  }
  initCharts(root);
  requestAnimationFrame(() => initCreditsHeroProgress(root));
}

if (typeof window !== 'undefined') {
  window.aiuReinitDashboardRoot = reinitDashboardRoot;
}

function boot() {
  document
    .querySelectorAll('[data-aiu-providers-credits-root]')
    .forEach((root) => initCreditsMode(root));
  initDashboardRoots(document);
  bindCheckoutLinks();
  bindScrollHelpers();
  requestAnimationFrame(() => initCreditsHeroProgress(document));
  document.addEventListener('typo3-module-loaded', () => {
    initDashboardRoots(document);
    requestAnimationFrame(() => initCreditsHeroProgress(document));
  });
  document.addEventListener('aiu-dashboard-view-changed', () => {
    requestAnimationFrame(() => initCreditsHeroProgress(document));
  });
}

boot();
