import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Modal from '@typo3/backend/modal.js';
import Notification from '@typo3/backend/notification.js';
import Severity from '@typo3/backend/severity.js';
import { initCharts } from './dashboard-charts.js';
import { initPeriodDropdownForms } from './period-filter.js';
import { navigateInModule, preserveRouteParams } from './module-navigation.js';
import { mountProviderList } from './provider-drawer.js';
import { observeBrowserAutocomplete } from './disable-browser-autocomplete.js';

const routes = {
  status: 'nst3af_credits_status',
  toggle: 'nst3af_credits_toggle',
  activate: 'nst3af_credits_activate',
  refreshToken: 'nst3af_credits_refresh_token',
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
  modalRefreshTitle: 'credits.js.modal.refreshTitle',
  modalRefreshBody: 'credits.js.modal.refreshBody',
  modalRefreshOk: 'credits.js.modal.refreshOk',
  errorTitleRefresh: 'credits.js.error.title.refresh',
  notificationTokenRefreshed: 'credits.js.notification.tokenRefreshed',
  contactModalTitle: 'credits.js.contactModal.title',
  contactModalSubtitle: 'credits.js.contactModal.subtitle',
  contactModalName: 'credits.js.contactModal.name',
  contactModalEmail: 'credits.js.contactModal.email',
  contactModalConsent: 'credits.js.contactModal.consent',
  contactModalPrivacy: 'credits.js.contactModal.privacy',
  contactModalTerms: 'credits.js.contactModal.terms',
  contactModalPrivacyUrl: 'credits.js.contactModal.privacyUrl',
  contactModalTermsUrl: 'credits.js.contactModal.termsUrl',
  contactModalContinue: 'credits.js.contactModal.continue',
  contactModalValidation: 'credits.js.contactModal.validation',
  contactModalConsentRequired: 'credits.js.contactModal.consentRequired',
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

/**
 * Plans / top-ups tabs and monthly / yearly billing toggle on the credits dashboard.
 *
 * @param {ParentNode} [scope]
 */
function initCreditsBundleTabs(scope = document) {
  const bindVersion = '3';
  scope.querySelectorAll('[data-aiu-credits-bundles-root]').forEach((root) => {
    if (!(root instanceof HTMLElement) || root.dataset.aiuBundlesTabsBound === bindVersion) {
      return;
    }
    root.dataset.aiuBundlesTabsBound = bindVersion;

    const syncEmptyStates = () => {
      root.querySelectorAll('[data-aiu-plan-period-panel]').forEach((panel) => {
        if (!(panel instanceof HTMLElement)) {
          return;
        }
        const period = panel.getAttribute('data-aiu-plan-period-panel') ?? '';
        const empty = root.querySelector(`[data-aiu-bundles-empty="plan-${period}"]`);
        const hasCards = panel.querySelector('.aiu-credits-product') instanceof HTMLElement;
        if (empty instanceof HTMLElement) {
          empty.hidden = hasCards || panel.hidden;
        }
      });

      const topupPanel = root.querySelector('[data-aiu-bundle-panel="topup"]');
      const topupEmpty = root.querySelector('[data-aiu-bundles-empty="topup"]');
      if (topupPanel instanceof HTMLElement && topupEmpty instanceof HTMLElement) {
        const hasTopups = topupPanel.querySelector('.aiu-credits-product') instanceof HTMLElement;
        topupEmpty.hidden = hasTopups || topupPanel.hidden;
      }
    };

    const setPeriodGroupVisible = (showPeriod) => {
      root.querySelectorAll('[data-aiu-plan-period-group]').forEach((group) => {
        if (!(group instanceof HTMLElement)) {
          return;
        }
        group.hidden = !showPeriod;
        group.classList.toggle('is-hidden', !showPeriod);
        group.style.display = showPeriod ? '' : 'none';
        group.setAttribute('aria-hidden', showPeriod ? 'false' : 'true');
      });
    };

    const activateType = (type) => {
      root.dataset.aiuBundleType = type;
      root.querySelectorAll('[data-aiu-bundle-tab]').forEach((tab) => {
        if (!(tab instanceof HTMLElement)) {
          return;
        }
        const active = tab.getAttribute('data-aiu-bundle-tab') === type;
        tab.classList.toggle('is-active', active);
        tab.classList.toggle('active', active);
        tab.setAttribute('aria-pressed', active ? 'true' : 'false');
      });
      root.querySelectorAll('[data-aiu-bundle-panel]').forEach((panel) => {
        if (!(panel instanceof HTMLElement)) {
          return;
        }
        panel.hidden = panel.getAttribute('data-aiu-bundle-panel') !== type;
      });
      root.querySelectorAll('[data-aiu-bundles-sub]').forEach((node) => {
        if (!(node instanceof HTMLElement)) {
          return;
        }
        node.hidden = node.getAttribute('data-aiu-bundles-sub') !== type;
      });
      setPeriodGroupVisible(type === 'plan');
      syncEmptyStates();
    };

    const activatePeriod = (period) => {
      root.querySelectorAll('[data-aiu-plan-period]').forEach((btn) => {
        if (!(btn instanceof HTMLElement)) {
          return;
        }
        const active = btn.getAttribute('data-aiu-plan-period') === period;
        btn.classList.toggle('is-active', active);
        btn.classList.toggle('active', active);
        btn.setAttribute('aria-pressed', active ? 'true' : 'false');
      });
      root.querySelectorAll('[data-aiu-plan-period-panel]').forEach((panel) => {
        if (!(panel instanceof HTMLElement)) {
          return;
        }
        panel.hidden = panel.getAttribute('data-aiu-plan-period-panel') !== period;
      });
      syncEmptyStates();
    };

    root.addEventListener('click', (event) => {
      const target = event.target;
      if (!(target instanceof Element)) {
        return;
      }
      const typeTab = target.closest('[data-aiu-bundle-tab]');
      if (typeTab instanceof HTMLElement && root.contains(typeTab)) {
        event.preventDefault();
        activateType(typeTab.getAttribute('data-aiu-bundle-tab') ?? 'plan');
        return;
      }
      const periodBtn = target.closest('[data-aiu-plan-period]');
      if (periodBtn instanceof HTMLElement && root.contains(periodBtn)) {
        event.preventDefault();
        activatePeriod(periodBtn.getAttribute('data-aiu-plan-period') ?? 'monthly');
      }
    });

    activateType('plan');
    activatePeriod('monthly');
    syncEmptyStates();
  });
}

function bindCardSelect(card, onSelect) {
  if (!card) {
    return;
  }
  const radio = card.querySelector('[data-aiu-mode-radio]');
  const handler = (event) => {
    if (event.target.closest('[data-credits-activate]') || event.target.closest('[data-credits-refresh-token]')) {
      return;
    }
    if (event.target.closest('[data-aiu-mode-radio]')) {
      return;
    }
    event.preventDefault();
    onSelect();
  };
  card.addEventListener('click', handler);
  card.addEventListener('keydown', (event) => {
    if (event.target.closest('[data-credits-activate]') || event.target.closest('[data-credits-refresh-token]')) {
      return;
    }
    if (event.target.closest('[data-aiu-mode-radio]')) {
      return;
    }
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      onSelect();
    }
  });
  if (radio instanceof HTMLInputElement) {
    radio.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      onSelect();
    });
  }
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
  const creditsRadio = qs(root, '[data-aiu-mode-radio="credits"]');
  const ownKeysRadio = qs(root, '[data-aiu-mode-radio="ownkeys"]');

  creditsCard?.classList.toggle('is-active', creditMode);
  creditsCard?.classList.toggle('active', creditMode);
  creditsCard?.setAttribute('aria-checked', creditMode ? 'true' : 'false');
  creditsCard?.setAttribute('aria-pressed', creditMode ? 'true' : 'false');
  ownKeysCard?.classList.toggle('is-active', !creditMode);
  ownKeysCard?.classList.toggle('active', !creditMode);
  ownKeysCard?.setAttribute('aria-checked', creditMode ? 'false' : 'true');
  ownKeysCard?.setAttribute('aria-pressed', creditMode ? 'false' : 'true');

  if (creditsRadio instanceof HTMLInputElement) {
    creditsRadio.checked = creditMode;
  }
  if (ownKeysRadio instanceof HTMLInputElement) {
    ownKeysRadio.checked = !creditMode;
  }

  root.dataset.creditsActive = active ? '1' : '0';
  root.dataset.creditMode = creditMode ? '1' : '0';
  if (typeof data.needsContact === 'boolean') {
    root.dataset.creditsNeedsContact = data.needsContact ? '1' : '0';
  }

  creditsActiveBadge?.classList.toggle('is-hidden', !active);
  activatePill?.classList.toggle('is-hidden', !creditMode || active);
  activatePill?.toggleAttribute('disabled', !creditMode || active);
  ownKeysActiveBadge?.classList.toggle('is-hidden', creditMode);

  // Keep dashboard/provider Activate CTAs outside this root in sync.
  document.querySelectorAll('[data-credits-activate]').forEach((el) => {
    if (el === activatePill) {
      return;
    }
    el.classList.toggle('is-hidden', !creditMode || active);
    if (el instanceof HTMLButtonElement) {
      el.disabled = !creditMode || active;
    } else {
      el.toggleAttribute('disabled', !creditMode || active);
    }
  });
  document.querySelectorAll('[data-credits-active-badge]').forEach((el) => {
    if (el === creditsActiveBadge) {
      return;
    }
    el.classList.toggle('is-hidden', !active);
  });

  applyContentPanels(creditMode);
  if (typeof data.creditsBearerToken === 'string') {
    updateTokenDisplay(root, data.creditsBearerToken);
  } else if (!active) {
    updateTokenDisplay(root, '');
  }
}

const notificationDedupeMs = 5000;
let lastNotificationKey = '';
let lastNotificationAt = 0;

/**
 * @param {unknown} err
 * @param {string} title
 */
function handleError(err, title) {
  const message = err instanceof Error && err.message ? err.message : String(err);
  const errorCode =
    err && typeof err === 'object' && err.creditsError && typeof err.creditsError.errorCode === 'string'
      ? err.creditsError.errorCode
      : '';
  const dedupeKey = `${title}|${errorCode}|${message}`;
  const now = Date.now();
  if (dedupeKey === lastNotificationKey && now - lastNotificationAt < notificationDedupeMs) {
    return;
  }
  lastNotificationKey = dedupeKey;
  lastNotificationAt = now;

  const notify =
    typeof Notification !== 'undefined' && err && typeof err === 'object' && err.httpStatus === 429 && Notification.warning
      ? Notification.warning.bind(Notification)
      : typeof Notification !== 'undefined' && Notification.error
        ? Notification.error.bind(Notification)
        : null;

  if (notify) {
    notify(title, message);
  } else {
    console.error(title, message);
  }
}

function runCreditsRefresh(root) {
  confirmSwitch(
    ll(LL.modalRefreshTitle, 'Refresh support token?'),
    ll(
      LL.modalRefreshBody,
      'This generates a new token for this server. Anyone with the old token will no longer be able to use T3Planet Credits.',
    ),
    ll(LL.modalRefreshOk, 'Refresh token'),
    () => {
      post(routes.refreshToken)
        .then((data) => {
          if (data.status === false || data.error || data.error_code) {
            handleError(
              new Error(data.userMessage || data.message || data.error_code || data.error),
              ll(LL.errorTitleRefresh, 'Refresh token'),
            );
            return;
          }
          document.querySelectorAll('[data-aiu-providers-credits-root]').forEach((creditsRoot) => {
            if (creditsRoot instanceof HTMLElement) {
              applyUiState(creditsRoot, {
                creditMode: true,
                active: true,
                creditsBearerToken: data.creditsBearerToken,
              });
            }
          });
          Notification.success(
            ll(LL.brandT3planet, 'T3Planet Credits'),
            ll(LL.notificationTokenRefreshed, 'Support token refreshed. Use the new token when contacting support.'),
          );
        })
        .catch((err) => handleError(err, ll(LL.errorTitleRefresh, 'Refresh token')));
    },
  );
}

let refreshDelegationBound = false;

function bindRefreshDelegation() {
  if (refreshDelegationBound) {
    return;
  }
  refreshDelegationBound = true;
  document.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof Element)) {
      return;
    }
    const refreshEl = target.closest('[data-credits-refresh-token]');
    if (!refreshEl) {
      return;
    }
    event.preventDefault();
    event.stopPropagation();
    runCreditsRefresh(null);
  });
}

function runCreditsActivate(root, contact = null) {
  const creditsFeatureAvailable = !root || isCreditsFeatureAvailable(root);
  if (!creditsFeatureAvailable) {
    return;
  }

  const needsContact = root instanceof HTMLElement && root.dataset.creditsNeedsContact === '1';
  if (needsContact && (!contact || !contact.name || !contact.email)) {
    openFreeCreditsContactModal(root);
    return;
  }

  const body = contact && contact.name && contact.email
    ? { name: contact.name, email: contact.email }
    : {};

  post(routes.activate, body)
    .then((data) => {
      if (data.status === false || data.error || data.error_code) {
        handleError(
          new Error(data.userMessage || data.message || data.error_code || data.error),
          ll(LL.errorTitleActivate, 'Activate T3Planet Credits'),
        );
        return;
      }
      if (data.active) {
        try {
          document.querySelectorAll('[data-aiu-providers-credits-root]').forEach((creditsRoot) => {
            if (creditsRoot instanceof HTMLElement) {
              applyUiState(creditsRoot, {
                creditMode: true,
                active: true,
                needsContact: false,
                creditsBearerToken: data.creditsBearerToken,
              });
            }
          });
        } catch (err) {
          console.error('[ns_t3af] Credits activate UI update failed', err);
        }
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
          'Mode enabled, but activation did not complete. Check API connectivity and try Activate again.',
        ),
      );
    })
    .catch((err) =>
      handleError(err, ll(LL.errorTitleActivate, 'Activate T3Planet Credits')),
    );
}

/**
 * Collect name/email when no AI Universe license contact is available.
 * Native <dialog> (same pattern as #aiu-setup-wizard-dialog) — not TYPO3 Lit Modal.
 *
 * @param {HTMLElement|null} root
 */
/** @type {HTMLElement|null} */
let creditsContactActivateRoot = null;
let creditsContactDialogBound = false;

/**
 * @param {HTMLDialogElement} dialog
 */
function closeCreditsContactDialog(dialog) {
  if (dialog.open) {
    dialog.close();
  }
  creditsContactActivateRoot = null;
}

/**
 * @param {HTMLDialogElement} dialog
 * @returns {boolean}
 */
function submitCreditsContactForm(dialog) {
  const form = dialog.querySelector('[data-credits-contact-form]');
  const nameInput = dialog.querySelector('#aiu-credits-contact-name');
  const emailInput = dialog.querySelector('#aiu-credits-contact-email');
  const consentInput = dialog.querySelector('#aiu-credits-contact-consent');
  const errorEl = dialog.querySelector('[data-credits-contact-error]');

  if (!(form instanceof HTMLFormElement)
    || !(nameInput instanceof HTMLInputElement)
    || !(emailInput instanceof HTMLInputElement)) {
    Notification.error(
      ll(LL.errorTitleActivate, 'Activate T3Planet Credits'),
      'Credits activation form is not ready. Reload the module and try Activate again.',
    );
    return false;
  }

  const name = nameInput.value.trim();
  const email = emailInput.value.trim();
  const consented = consentInput instanceof HTMLInputElement && consentInput.checked;
  const emailOk = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);

  if (errorEl instanceof HTMLElement) {
    errorEl.classList.add('is-hidden');
    errorEl.textContent = '';
  }

  if (!name || !emailOk) {
    if (errorEl instanceof HTMLElement) {
      errorEl.textContent = ll(
        LL.contactModalValidation,
        'Please enter your name and a valid email address.',
      );
      errorEl.classList.remove('is-hidden');
    }
    nameInput.focus();
    return false;
  }
  if (!consented) {
    if (errorEl instanceof HTMLElement) {
      errorEl.textContent = ll(
        LL.contactModalConsentRequired,
        'Please agree to the Privacy Policy and Terms & Conditions to continue.',
      );
      errorEl.classList.remove('is-hidden');
    }
    consentInput instanceof HTMLInputElement && consentInput.focus();
    return false;
  }

  const root = creditsContactActivateRoot;
  closeCreditsContactDialog(dialog);
  runCreditsActivate(root, { name, email });
  return true;
}

/**
 * @param {HTMLDialogElement} dialog
 */
function bindCreditsContactDialog(dialog) {
  if (creditsContactDialogBound) {
    return;
  }
  creditsContactDialogBound = true;
  observeBrowserAutocomplete(dialog);

  dialog.addEventListener('click', (evt) => {
    if (evt.target === dialog) {
      closeCreditsContactDialog(dialog);
    }
  });
  dialog.addEventListener('cancel', (evt) => {
    evt.preventDefault();
    closeCreditsContactDialog(dialog);
  });
  dialog.querySelectorAll('[data-credits-contact-close]').forEach((el) => {
    el.addEventListener('click', () => closeCreditsContactDialog(dialog));
  });

  const form = dialog.querySelector('[data-credits-contact-form]');
  if (form instanceof HTMLFormElement) {
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      event.stopPropagation();
      submitCreditsContactForm(dialog);
    });
  }
}

function openFreeCreditsContactModal(root) {
  const dialog = document.getElementById('aiu-credits-contact-dialog');
  if (!(dialog instanceof HTMLDialogElement) || typeof dialog.showModal !== 'function') {
    Notification.error(
      ll(LL.errorTitleActivate, 'Activate T3Planet Credits'),
      'Credits activation form is not ready. Reload the module and try Activate again.',
    );
    return;
  }

  bindCreditsContactDialog(dialog);
  creditsContactActivateRoot = root instanceof HTMLElement ? root : null;

  const form = dialog.querySelector('[data-credits-contact-form]');
  if (form instanceof HTMLFormElement) {
    form.reset();
  }
  const errorEl = dialog.querySelector('[data-credits-contact-error]');
  if (errorEl instanceof HTMLElement) {
    errorEl.classList.add('is-hidden');
    errorEl.textContent = '';
  }

  dialog.showModal();
  window.requestAnimationFrame(() => {
    const nameInput = dialog.querySelector('#aiu-credits-contact-name');
    if (nameInput instanceof HTMLInputElement) {
      nameInput.focus();
    }
  });
}

let activateDelegationBound = false;

function bindActivateDelegation() {
  if (activateDelegationBound) {
    return;
  }
  activateDelegationBound = true;
  document.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof Element)) {
      return;
    }
    const activateEl = target.closest('[data-credits-activate]');
    if (!activateEl) {
      return;
    }
    event.preventDefault();
    event.stopPropagation();
    const root =
      activateEl.closest('[data-aiu-providers-credits-root]') ||
      document.querySelector('[data-aiu-providers-credits-root]');
    runCreditsActivate(root instanceof HTMLElement ? root : null);
  });
  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Enter' && event.key !== ' ') {
      return;
    }
    const target = event.target;
    if (!(target instanceof Element)) {
      return;
    }
    const activateEl = target.closest('[data-credits-activate]');
    if (!activateEl || activateEl !== target) {
      return;
    }
    event.preventDefault();
    event.stopPropagation();
    activateEl.click();
  });
}

function initCreditsMode(root) {
  if (!(root instanceof HTMLElement) || initializedRoots.has(root)) {
    return;
  }
  initializedRoots.add(root);
  observeBrowserAutocomplete(root);
  bindActivateDelegation();
  bindRefreshDelegation();

  const creditsFeatureAvailable = isCreditsFeatureAvailable(root);
  const creditsCard = qs(root, '[data-aiu-providers-mode="credits"]');
  const ownKeysCard = qs(root, '[data-aiu-providers-mode="ownkeys"]');

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
                    'Credits mode selected. Click Activate to start free credits.',
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

/**
 * Re-init Providers module after fetch-based partial refresh (entry-type filter / usage paging).
 *
 * @param {Element} root
 */
function reinitProvidersRoot(root) {
  observeBrowserAutocomplete(root);
  initPeriodDropdownForms(root);
  initCreditsUsagePaginationLinks(root);
  initCreditsBundleTabs(root);
  mountProviderList(root);
  const creditsRoot = root.querySelector('[data-aiu-providers-credits-root]');
  if (creditsRoot instanceof HTMLElement) {
    initCreditsMode(creditsRoot);
  }
  requestAnimationFrame(() => initCreditsHeroProgress(root));
}

if (typeof window !== 'undefined') {
  window.aiuReinitDashboardRoot = reinitDashboardRoot;
  window.aiuReinitProvidersRoot = reinitProvidersRoot;
}

/**
 * Recent AI Usage pagination — in-module navigation (entry-type uses `.aiu-period__option`
 * via period-filter.js, same as the date-range dropdown).
 *
 * @param {ParentNode} [scope]
 */
function initCreditsUsagePaginationLinks(scope = document) {
  const boundAttr = 'data-aiu-credits-usage-nav-bound';
  const fallbackContext = scope instanceof Element
    ? scope.closest('[data-aiu-nav-replace]') ?? scope
    : scope.querySelector?.('[data-aiu-nav-replace]') ?? null;

  scope.querySelectorAll('.aiu-credits-usage__nav-link[href]').forEach((anchor) => {
    if (!(anchor instanceof HTMLAnchorElement) || anchor.getAttribute(boundAttr) === '1') {
      return;
    }
    anchor.setAttribute(boundAttr, '1');
    anchor.addEventListener('click', (event) => {
      event.preventDefault();
      const url = preserveRouteParams(anchor.href);
      const context = anchor.closest('[data-aiu-nav-replace]') ?? fallbackContext;
      navigateInModule(url.toString(), context);
    });
  });
}

/**
 * @param {ParentNode} [scope]
 */
function initProvidersRoots(scope = document) {
  scope.querySelectorAll('[data-aiu-providers-root]').forEach((root) => {
    initPeriodDropdownForms(root);
    initCreditsUsagePaginationLinks(root);
  });
}

function boot() {
  document
    .querySelectorAll('[data-aiu-providers-credits-root]')
    .forEach((root) => initCreditsMode(root));
  initDashboardRoots(document);
  initProvidersRoots(document);
  bindCheckoutLinks();
  bindScrollHelpers();
  initCreditsBundleTabs(document);
  requestAnimationFrame(() => initCreditsHeroProgress(document));
  document.addEventListener('typo3-module-loaded', () => {
    initDashboardRoots(document);
    initProvidersRoots(document);
    initCreditsBundleTabs(document);
    requestAnimationFrame(() => initCreditsHeroProgress(document));
  });
  document.addEventListener('aiu-dashboard-view-changed', () => {
    requestAnimationFrame(() => initCreditsHeroProgress(document));
  });
}

boot();
