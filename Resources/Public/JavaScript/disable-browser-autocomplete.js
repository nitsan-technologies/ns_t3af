/**
 * Disable browser/password-manager autofill on form fields within a scope.
 */

const FILTER_SEARCH_SELECTOR = [
  'input[type="search"]',
  'input[data-aiu-provider-search]',
  'input[data-aiu-features-search]',
  'input[data-aiu-prompt-overview-search]',
  'input[data-mcp-tools-search]',
  'input[data-mcp-tools-table-filter]',
  'input[data-group-search]',
  '#aiu-ai-logs-search',
  '#aiu-ai-usage-search',
].join(', ');

/**
 * @param {Element} element
 */
function isFilterSearchInput(element) {
  return element instanceof HTMLInputElement && element.matches(FILTER_SEARCH_SELECTOR);
}

/**
 * Prevent password managers / browsers from prefilling list filter search boxes.
 *
 * @param {HTMLInputElement} input
 */
export function hardenFilterSearchInput(input) {
  if (!(input instanceof HTMLInputElement)) {
    return;
  }

  input.setAttribute('autocomplete', 'off');
  input.setAttribute('autocorrect', 'off');
  input.setAttribute('autocapitalize', 'off');
  input.setAttribute('spellcheck', 'false');
  input.setAttribute('data-1p-ignore', 'true');
  input.setAttribute('data-lpignore', 'true');
  input.setAttribute('data-form-type', 'other');
  input.setAttribute('data-aiu-filter-search', '1');

  if (input.dataset.aiuSearchInitial === undefined) {
    const explicitInitial = input.getAttribute('data-aiu-filter-initial');
    if (explicitInitial !== null) {
      input.dataset.aiuSearchInitial = explicitInitial;
      if (input.value !== explicitInitial) {
        input.value = explicitInitial;
      }
    } else {
      // List filters are always empty server-side; any value here is browser autofill.
      input.dataset.aiuSearchInitial = '';
      if (input.value !== '') {
        input.value = '';
      }
    }
  }

  if (input.dataset.aiuSearchHardened === '1') {
    return;
  }

  input.dataset.aiuSearchHardened = '1';
  input.setAttribute('readonly', 'readonly');

  // Focus alone only makes the field editable; it must NOT count as user
  // engagement, otherwise a password-manager autofill triggered by focus is
  // treated as a real search. Genuine engagement requires an actual keystroke.
  const liftReadonly = () => {
    input.removeAttribute('readonly');
  };

  const engage = () => {
    input.dataset.aiuSearchEngaged = '1';
    input.removeAttribute('readonly');
  };

  input.addEventListener('focus', liftReadonly, { once: true });
  input.addEventListener('keydown', engage, { once: true });
}

/**
 * Bind a filter search field and ignore browser autofill until the editor interacts.
 *
 * @param {HTMLInputElement} input
 * @param {(value: string) => void} callback
 * @returns {() => void} invoke current filter state
 */
export function bindFilterSearchInput(input, callback) {
  if (!(input instanceof HTMLInputElement)) {
    return () => {};
  }

  hardenFilterSearchInput(input);

  const invoke = () => {
    callback(input.value.trim());
  };

  input.addEventListener('input', () => {
    if (input.dataset.aiuSearchEngaged !== '1') {
      const initial = input.dataset.aiuSearchInitial ?? '';
      if (input.value.trim() !== '' && input.value !== initial) {
        input.value = initial;
      }
      callback(initial.trim());
      return;
    }
    invoke();
  });

  return invoke;
}

/**
 * Clear a hardened filter search field after an explicit reset action.
 *
 * @param {HTMLInputElement} input
 */
export function resetFilterSearchInput(input) {
  if (!(input instanceof HTMLInputElement)) {
    return;
  }

  input.value = '';
  input.dataset.aiuSearchInitial = '';
  input.dataset.aiuSearchEngaged = '1';
  input.removeAttribute('readonly');
}

/**
 * Clear values injected after load by password managers (before user focus).
 *
 * @param {ParentNode} scope
 */
export function sweepAutofilledSearchInputs(scope) {
  if (!(scope instanceof Element || scope instanceof Document)) {
    return;
  }

  scope.querySelectorAll('[data-aiu-filter-search]').forEach((node) => {
    if (!(node instanceof HTMLInputElement)) {
      return;
    }
    if (node.dataset.aiuSearchEngaged === '1') {
      return;
    }

    const initial = node.dataset.aiuSearchInitial ?? '';
    if (node.value !== initial) {
      node.value = initial;
      node.dispatchEvent(new Event('input', { bubbles: true }));
    }
  });
}

/**
 * @param {ParentNode} scope
 */
export function disableBrowserAutocomplete(scope = document) {
  if (!(scope instanceof Element || scope instanceof Document)) {
    return;
  }

  scope.querySelectorAll('form').forEach((form) => {
    form.setAttribute('autocomplete', 'off');
  });

  scope.querySelectorAll('input, select, textarea').forEach((element) => {
    if (!(element instanceof HTMLInputElement || element instanceof HTMLSelectElement || element instanceof HTMLTextAreaElement)) {
      return;
    }

    if (element instanceof HTMLInputElement && element.type === 'hidden') {
      return;
    }

    if (isFilterSearchInput(element)) {
      hardenFilterSearchInput(element);
      return;
    }

    element.setAttribute('autocomplete', 'off');
    element.setAttribute('autocorrect', 'off');
    element.setAttribute('autocapitalize', 'off');
    element.setAttribute('spellcheck', 'false');
    element.setAttribute('data-1p-ignore', 'true');
    element.setAttribute('data-lpignore', 'true');
    element.setAttribute('data-form-type', 'other');
  });
}

/**
 * Apply immediately and on dynamically inserted fields.
 *
 * @param {Element} scope
 */
export function observeBrowserAutocomplete(scope) {
  if (!(scope instanceof Element || scope instanceof Document)) {
    return;
  }

  disableBrowserAutocomplete(scope);

  const observeTarget = scope instanceof Document ? scope.documentElement : scope;
  if (!(observeTarget instanceof Element)) {
    return;
  }

  const observer = new MutationObserver((mutations) => {
    mutations.forEach((mutation) => {
      mutation.addedNodes.forEach((node) => {
        if (node instanceof Element) {
          disableBrowserAutocomplete(node);
        }
      });
    });
  });

  observer.observe(observeTarget, { childList: true, subtree: true });

  const runSweep = () => sweepAutofilledSearchInputs(scope);
  requestAnimationFrame(runSweep);
  window.setTimeout(runSweep, 150);
  window.setTimeout(runSweep, 500);
}
