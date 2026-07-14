/**
 * In-module navigation via the TYPO3 backend module router (content iframe only).
 * Avoids full /typo3/main reloads when links or filters run inside the module iframe.
 */

const MODULE_ROUTER = 'typo3-backend-module-router';
const IFRAME_MODULE = 'typo3-iframe-module';
const CONTENT_IFRAME = '#typo3-contentIframe, .t3js-scaffold-content-module-iframe';
const PRESERVED_ROUTE_PARAMS = ['token', 'id'];

/**
 * @param {HTMLFormElement} form
 * @returns {URL|null}
 */
function buildActionUrlFromForm(form) {
  const action = form.getAttribute('action') || form.action;
  if (!action) {
    return null;
  }

  const actionUrl = new URL(action, window.location.href);
  const url = new URL(actionUrl.pathname, window.location.href);

  for (const key of PRESERVED_ROUTE_PARAMS) {
    const value = actionUrl.searchParams.get(key);
    if (value !== null && value !== '') {
      url.searchParams.set(key, value);
    }
  }

  new FormData(form).forEach((value, key) => {
    const normalized = value.toString();
    if (normalized === '') {
      url.searchParams.delete(key);
      return;
    }
    url.searchParams.set(key, normalized);
  });

  return url;
}

/**
 * @param {string} url
 * @returns {string}
 */
function normalizeModuleTarget(url) {
  const absolute = new URL(url, window.location.href);
  return `${absolute.pathname}${absolute.search}${absolute.hash}`;
}

/**
 * @param {string} target
 * @returns {boolean}
 */
function setTopModuleEndpoint(target) {
  try {
    const topWindow = window.top;
    if (!topWindow || topWindow === window) {
      return false;
    }

    const doc = topWindow.document;
    const router = doc.querySelector(MODULE_ROUTER);
    if (router instanceof HTMLElement) {
      router.setAttribute('endpoint', target);
      return true;
    }

    const iframeModule = doc.querySelector(IFRAME_MODULE);
    if (iframeModule instanceof HTMLElement) {
      iframeModule.setAttribute('endpoint', target);
      return true;
    }

    const iframe = doc.querySelector(CONTENT_IFRAME);
    if (iframe instanceof HTMLIFrameElement) {
      iframe.src = target;
      return true;
    }
  } catch {
    // Ignore cross-origin access to the top window.
  }

  return false;
}

/**
 * @param {string} url
 * @param {string} selector
 * @param {(root: Element) => void} [onReplace]
 * @returns {Promise<boolean>}
 */
async function replaceContentFromUrl(url, selector, onReplace) {
  try {
    const response = await fetch(url, {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
    if (!response.ok) {
      return false;
    }

    const html = await response.text();
    const doc = new DOMParser().parseFromString(html, 'text/html');
    const fresh = doc.querySelector(selector);
    const current = document.querySelector(selector);
    if (!fresh || !current) {
      return false;
    }

    const imported = document.importNode(fresh, true);
    current.replaceWith(imported);
    if (typeof onReplace === 'function') {
      onReplace(imported);
    }
    return true;
  } catch {
    return false;
  }
}

/**
 * @param {Element|null|undefined} context
 * @returns {{ selector: string|null, onReplace: ((root: Element) => void)|null }}
 */
function resolveReplaceOptions(context) {
  if (!(context instanceof Element)) {
    return { selector: null, onReplace: null };
  }

  const selector = context.getAttribute('data-aiu-nav-replace');
  if (!selector) {
    return { selector: null, onReplace: null };
  }

  const callbackName = context.getAttribute('data-aiu-nav-replace-callback');
  const onReplace = callbackName && typeof window[callbackName] === 'function'
    ? (root) => window[callbackName](root)
    : null;

  return { selector, onReplace };
}

/**
 * @param {string} url
 * @param {Element|null|undefined} [context]
 * @returns {Promise<boolean>}
 */
export async function navigateInModule(url, context) {
  const target = normalizeModuleTarget(url);
  const { selector, onReplace } = resolveReplaceOptions(context);
  const inIframe = window.self !== window.top;

  if (inIframe && selector) {
    const replaced = await replaceContentFromUrl(target, selector, onReplace);
    if (replaced) {
      setTopModuleEndpoint(target);
      return true;
    }
  }

  if (setTopModuleEndpoint(target)) {
    return true;
  }

  if (inIframe) {
    return false;
  }

  window.location.assign(target);
  return true;
}

/**
 * @param {HTMLFormElement} form
 * @param {Element|null|undefined} [context]
 * @returns {Promise<boolean>}
 */
export async function navigateFromForm(form, context) {
  const url = buildActionUrlFromForm(form);
  if (!url) {
    return false;
  }

  return navigateInModule(url.toString(), context ?? form.closest('[data-aiu-nav-replace]'));
}

/**
 * @param {HTMLFormElement} form
 * @returns {string|null}
 */
export function buildUrlFromForm(form) {
  const url = buildActionUrlFromForm(form);
  return url ? url.toString() : null;
}
