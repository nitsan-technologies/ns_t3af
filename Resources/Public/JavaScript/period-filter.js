/**
 * Shared AI Foundation date-range dropdown (Dashboard, AI Usage, AI Logs, …).
 * Avoid native form submits — they can reload /typo3/main inside the module iframe.
 */

import { navigateInModule } from './module-navigation.js';
import { disableBrowserAutocomplete } from './disable-browser-autocomplete.js';

/**
 * @param {ParentNode} [scope]
 */
export function initPeriodDropdownForms(scope = document) {
  const context = scope instanceof Element
    ? scope
    : scope.querySelector?.('[data-aiu-nav-replace]') ?? null;

  scope.querySelectorAll('.aiu-period__custom-form').forEach((form) => {
    if (!(form instanceof HTMLFormElement)) {
      return;
    }
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      const action = form.getAttribute('action') || form.action;
      if (!action) {
        return;
      }
      const fromInput = form.querySelector('[name="from"]');
      const toInput = form.querySelector('[name="to"]');
      const from = fromInput instanceof HTMLInputElement ? fromInput.value.trim() : '';
      const to = toInput instanceof HTMLInputElement ? toInput.value.trim() : '';
      if (from === '' || to === '') {
        return;
      }
      const url = new URL(action, window.location.href);
      url.searchParams.set('period', 'custom');
      url.searchParams.set('from', from);
      url.searchParams.set('to', to);
      navigateInModule(url.toString(), context ?? form.closest('[data-aiu-nav-replace]'));
    });
  });

  scope.querySelectorAll('.aiu-period__option[href]').forEach((anchor) => {
    if (!(anchor instanceof HTMLAnchorElement)) {
      return;
    }
    anchor.addEventListener('click', (event) => {
      event.preventDefault();
      navigateInModule(anchor.href, context ?? anchor.closest('[data-aiu-nav-replace]'));
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
