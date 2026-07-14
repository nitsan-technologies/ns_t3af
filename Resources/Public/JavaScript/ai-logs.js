/**
 * AI Logs tab — bulk delete, in-module navigation, and filter helpers.
 */

import { navigateFromForm, navigateInModule } from './module-navigation.js';
import { initPeriodDropdownForms } from './period-filter.js';
import { observeBrowserAutocomplete } from './disable-browser-autocomplete.js';

/**
 * Re-init after fetch-based partial refresh.
 *
 * @param {Element} root
 */
function reinitAiLogsRoot(root) {
  initAiLogs(root);
  initPeriodDropdownForms(root);
}

if (typeof window !== 'undefined') {
  window.aiuReinitAiLogsRoot = reinitAiLogsRoot;
}

function initInModuleLinks(root) {
  root.querySelectorAll('a[href]').forEach((anchor) => {
    if (!(anchor instanceof HTMLAnchorElement)) {
      return;
    }
    if (anchor.href.includes('ai_logs.export')) {
      return;
    }
    const isListLink = anchor.href.includes('/module/t3af/dashboard/ai-logs')
      || anchor.href.includes('redirect=t3af_dashboard.ai_logs');
    if (!isListLink) {
      return;
    }
    anchor.addEventListener('click', (event) => {
      event.preventDefault();
      navigateInModule(anchor.href, root);
    });
  });
}

function initFilterForm(root) {
  const form = root.querySelector('#aiu-ai-logs-filter');
  if (!(form instanceof HTMLFormElement)) {
    return;
  }

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    navigateFromForm(form, root);
  });

  const applyButton = form.querySelector('.aiu-ai-logs__filter-actions button[type="button"]');
  if (applyButton instanceof HTMLButtonElement) {
    applyButton.addEventListener('click', () => {
      navigateFromForm(form, root);
    });
  }
}

function updateDeleteSelectedVisibility(root) {
  const anyChecked = root.querySelectorAll('.aiu-ai-logs__checkbox:checked').length > 0;
  root.querySelectorAll('.aiu-ai-logs__delete-selected').forEach((btn) => {
    btn.classList.toggle('d-none', !anyChecked);
  });
}

function buildPerPageUrl(root, max) {
  const refresh = root.querySelector('[data-aiu-logs-refresh]');
  if (!(refresh instanceof HTMLAnchorElement)) {
    return null;
  }
  const url = new URL(refresh.href, window.location.href);
  url.searchParams.set('max', String(max));
  url.searchParams.set('currentPage', '1');
  return url.toString();
}

function initPerPageSelect(root) {
  const select = root.querySelector('[data-aiu-logs-per-page]');
  if (!(select instanceof HTMLSelectElement)) {
    return;
  }
  if (select.dataset.aiuLogsPerPageBound === '1') {
    return;
  }
  select.dataset.aiuLogsPerPageBound = '1';
  select.addEventListener('change', () => {
    const target = buildPerPageUrl(root, select.value);
    if (target) {
      navigateInModule(target, root);
    }
  });
}

function initAiLogs(root) {
  initFilterForm(root);
  initInModuleLinks(root);
  initPerPageSelect(root);

  const selectAll = root.querySelector('.aiu-ai-logs__select-all');
  const checkboxes = root.querySelectorAll('.aiu-ai-logs__checkbox');

  if (selectAll && checkboxes.length) {
    selectAll.addEventListener('change', () => {
      checkboxes.forEach((checkbox) => {
        checkbox.checked = selectAll.checked;
      });
      updateDeleteSelectedVisibility(root);
    });
  }

  checkboxes.forEach((checkbox) => {
    checkbox.addEventListener('change', () => updateDeleteSelectedVisibility(root));
  });

  updateDeleteSelectedVisibility(root);

  root.querySelectorAll('.aiu-ai-logs__delete-one').forEach((button) => {
    button.addEventListener('click', () => {
      const form = document.createElement('form');
      form.method = 'post';
      form.action = button.getAttribute('data-uri') || '';
      [
        'level',
        'search',
        'logchannel',
        'extension',
        'max',
        'currentpage',
        'period',
        'from',
        'to',
      ].forEach((name) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        const fieldName = name === 'currentpage'
          ? 'currentPage'
          : name === 'logchannel'
            ? 'logChannel'
            : name;
        input.name = fieldName;
        input.value = button.getAttribute(`data-${name}`) || '';
        form.appendChild(input);
      });
      const uidInput = document.createElement('input');
      uidInput.type = 'hidden';
      uidInput.name = 'uids[]';
      uidInput.value = button.getAttribute('data-uid') || '';
      form.appendChild(uidInput);
      document.body.appendChild(form);
      form.submit();
    });
  });
}

function boot() {
  document.querySelectorAll('[data-aiu-logs-root]').forEach((root) => {
    observeBrowserAutocomplete(root);
    initAiLogs(root);
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', boot, { once: true });
} else {
  boot();
}

export { initAiLogs, reinitAiLogsRoot };
