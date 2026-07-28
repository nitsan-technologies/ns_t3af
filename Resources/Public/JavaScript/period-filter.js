/**
 * Shared AI Foundation date-range dropdown (Dashboard, AI Usage, AI Logs, …).
 * Avoid native form submits — they can reload /typo3/main inside the module iframe.
 */

import { navigateFromForm, navigateInModule, preserveRouteParams } from './module-navigation.js';
import { disableBrowserAutocomplete } from './disable-browser-autocomplete.js';

const BOUND_ATTR = 'data-aiu-period-bound';

/**
 * @param {Element} element
 * @param {Element|null} fallbackContext
 * @returns {Element|null}
 */
function resolveNavContext(element, fallbackContext) {
  return element.closest('[data-aiu-nav-replace]') ?? fallbackContext ?? null;
}

/**
 * @param {ParentNode} [scope]
 */
export function initPeriodDropdownForms(scope = document) {
  const fallbackContext = scope instanceof Element
    ? scope
    : scope.querySelector?.('[data-aiu-nav-replace]') ?? null;

  scope.querySelectorAll('.aiu-period__custom-form').forEach((form) => {
    if (!(form instanceof HTMLFormElement)) {
      return;
    }
    if (form.getAttribute(BOUND_ATTR) === '1') {
      return;
    }
    form.setAttribute(BOUND_ATTR, '1');

    form.addEventListener('submit', (event) => {
      event.preventDefault();
      const fromInput = form.querySelector('[name="from"]');
      const toInput = form.querySelector('[name="to"]');
      const from = fromInput instanceof HTMLInputElement ? fromInput.value.trim() : '';
      const to = toInput instanceof HTMLInputElement ? toInput.value.trim() : '';
      if (from === '' || to === '') {
        return;
      }
      navigateFromForm(form, resolveNavContext(form, fallbackContext));
    });
  });

  scope.querySelectorAll('.aiu-period__option[href]').forEach((anchor) => {
    if (!(anchor instanceof HTMLAnchorElement)) {
      return;
    }
    if (anchor.getAttribute(BOUND_ATTR) === '1') {
      return;
    }
    anchor.setAttribute(BOUND_ATTR, '1');

    anchor.addEventListener('click', (event) => {
      event.preventDefault();
      const url = preserveRouteParams(anchor.href);
      navigateInModule(url.toString(), resolveNavContext(anchor, fallbackContext));
    });
  });
}

function boot() {
  disableBrowserAutocomplete(document);
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => initPeriodDropdownForms(), { once: true });
  } else {
    initPeriodDropdownForms();
  }
}

boot();
