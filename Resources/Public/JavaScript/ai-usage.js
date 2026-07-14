import Modal from '@typo3/backend/modal.js';
import Severity from '@typo3/backend/severity.js';
import { initPeriodDropdownForms } from './period-filter.js';
import { observeBrowserAutocomplete } from './disable-browser-autocomplete.js';
import { navigateFromForm, navigateInModule } from './module-navigation.js';

/**
 * Re-init after fetch-based partial refresh (period presets, custom range).
 *
 * @param {Element} root
 */
function reinitAiUsageRoot(root) {
  observeBrowserAutocomplete(root);
  initAiUsage(root);
  initPeriodDropdownForms(root);
}

if (typeof window !== 'undefined') {
  window.aiuReinitAiUsageRoot = reinitAiUsageRoot;
}

function initInModuleLinks(root) {
  root.querySelectorAll('a[href]').forEach((anchor) => {
    if (!(anchor instanceof HTMLAnchorElement)) {
      return;
    }
    if (anchor.href.includes('ai_usage.export')) {
      return;
    }
    const isListLink = anchor.href.includes('/module/t3af/dashboard/ai-usage')
      || anchor.href.includes('redirect=t3af_dashboard.ai_usage');
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
  const form = root.querySelector('#aiu-ai-usage-filter');
  if (!(form instanceof HTMLFormElement)) {
    return;
  }
  if (form.dataset.aiuUsageFilterBound === '1') {
    return;
  }
  form.dataset.aiuUsageFilterBound = '1';

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    navigateFromForm(form, root);
  });

  const applyButton = form.querySelector('.aiu-ai-usage__filter-toolbar button[type="button"]');
  if (applyButton instanceof HTMLButtonElement) {
    applyButton.addEventListener('click', () => {
      navigateFromForm(form, root);
    });
  }
}

function buildPerPageUrl(root, max) {
  const refresh = root.querySelector('[data-aiu-usage-refresh]');
  if (!(refresh instanceof HTMLAnchorElement)) {
    return null;
  }
  const url = new URL(refresh.href, window.location.href);
  url.searchParams.set('max', String(max));
  url.searchParams.set('currentPage', '1');
  url.searchParams.set('mode', 'log');
  return url.toString();
}

function initPerPageSelect(root) {
  const select = root.querySelector('[data-aiu-usage-per-page]');
  if (!(select instanceof HTMLSelectElement)) {
    return;
  }
  if (select.dataset.aiuUsagePerPageBound === '1') {
    return;
  }
  select.dataset.aiuUsagePerPageBound = '1';
  select.addEventListener('change', () => {
    const target = buildPerPageUrl(root, select.value);
    if (target) {
      navigateInModule(target, root);
    }
  });
}

function updateDeleteSelectedVisibility(root) {
  const anyChecked = root.querySelectorAll('[data-aiu-usage-select]:checked').length > 0;
  root.querySelectorAll('.aiu-ai-usage__delete-selected').forEach((btn) => {
    btn.classList.toggle('d-none', !anyChecked);
  });
}

function initAiUsage(scope) {
  initFilterForm(scope);
  initInModuleLinks(scope);
  initPerPageSelect(scope);
  initDetailsModal(scope);
  initSelectAll(scope);
  initBulkDeleteConfirm(scope);
  updateDeleteSelectedVisibility(scope);
}

function t(scope, key, fallback) {
  if (!(scope instanceof HTMLElement)) {
    return fallback;
  }
  const value = scope.dataset[key];
  return typeof value === 'string' && value.trim() !== '' ? value : fallback;
}

function decodeHtmlEntities(value) {
  const raw = String(value ?? '');
  if (raw === '') {
    return '';
  }
  const el = document.createElement('textarea');
  el.innerHTML = raw;
  return el.value;
}

/**
 * @param {string} value
 */
function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

/**
 * @param {string} raw
 * @returns {Record<string, unknown>|null}
 */
function parseMetaObject(raw) {
  const decoded = decodeHtmlEntities(raw).trim();
  if (decoded === '') {
    return null;
  }
  try {
    const parsed = JSON.parse(decoded);
    return parsed && typeof parsed === 'object' ? parsed : null;
  } catch (_error) {
    return null;
  }
}

/**
 * @param {string} fqcn
 */
function shortClassName(fqcn) {
  const value = String(fqcn ?? '').trim();
  if (value === '') {
    return '';
  }
  const parts = value.split('\\');
  return parts[parts.length - 1] || value;
}

/**
 * @param {string} code
 */
function isMeaningfulErrorCode(code) {
  const value = String(code ?? '').trim();
  return value !== '' && value !== '0';
}

/**
 * @param {HTMLElement} scope
 * @param {string} key
 * @param {string} fallback
 */
function labelText(scope, key, fallback) {
  return decodeHtmlEntities(t(scope, key, fallback));
}

/**
 * @param {HTMLElement} scope
 */
function detailLabels(scope) {
  return {
    sectionRequest: labelText(scope, 'l10nDetailSectionRequest', 'Request'),
    sectionMetrics: labelText(scope, 'l10nDetailSectionMetrics', 'Usage & cost'),
    sectionError: labelText(scope, 'l10nDetailSectionError', 'Error'),
    sectionMeta: labelText(scope, 'l10nDetailSectionMeta', 'Metadata'),
    time: labelText(scope, 'l10nDetailTime', 'Time'),
    provider: labelText(scope, 'l10nDetailProvider', 'Provider'),
    module: labelText(scope, 'l10nDetailModule', 'Module'),
    scope: labelText(scope, 'l10nDetailScope', 'Scope'),
    model: labelText(scope, 'l10nDetailModel', 'Model'),
    type: labelText(scope, 'l10nDetailType', 'Type'),
    status: labelText(scope, 'l10nDetailStatus', 'Status'),
    tokens: labelText(scope, 'l10nDetailTokens', 'Tokens'),
    promptTokens: labelText(scope, 'l10nDetailPromptTokens', 'Prompt tokens'),
    completionTokens: labelText(scope, 'l10nDetailCompletionTokens', 'Completion tokens'),
    latency: labelText(scope, 'l10nDetailLatency', 'Latency'),
    credits: labelText(scope, 'l10nDetailCredits', 'Credits'),
    cost: labelText(scope, 'l10nDetailCost', 'Cost'),
    errorMessage: labelText(scope, 'l10nDetailErrorMessage', 'Message'),
    errorCode: labelText(scope, 'l10nDetailErrorCode', 'Error code'),
    errorClass: labelText(scope, 'l10nDetailErrorClass', 'Exception'),
    noErrorMessage: labelText(scope, 'l10nDetailNoErrorMessage', 'No detailed error message was recorded for this entry.'),
    empty: labelText(scope, 'l10nDetailEmpty', '—'),
  };
}

/**
 * @param {string} label
 * @param {string} value
 * @param {{ mono?: boolean, empty?: string }} [options]
 */
function buildDetailRow(label, value, options = {}) {
  const { mono = false, empty = '—' } = options;
  const display = String(value ?? '').trim() || empty;
  const valueClass = mono
    ? 'aiu-ai-usage-detail__value aiu-table__mono'
    : 'aiu-ai-usage-detail__value';

  return '<tr>'
    + `<th scope="row" class="aiu-ai-usage-detail__label">${escapeHtml(label)}</th>`
    + `<td class="${valueClass}">${escapeHtml(display)}</td>`
    + '</tr>';
}

/**
 * @param {string} label
 * @param {string} valueHtml
 */
function buildDetailRowHtml(label, valueHtml) {
  return '<tr>'
    + `<th scope="row" class="aiu-ai-usage-detail__label">${escapeHtml(label)}</th>`
    + `<td class="aiu-ai-usage-detail__value">${valueHtml}</td>`
    + '</tr>';
}

/**
 * @param {string} title
 */
function buildSectionDividerRow(title) {
  return '<tr class="aiu-ai-usage-detail__divider">'
    + `<th colspan="2" scope="colgroup">${escapeHtml(title)}</th>`
    + '</tr>';
}

/**
 * @param {string} html
 */
function buildCalloutRow(html) {
  return '<tr class="aiu-ai-usage-detail__callout-row">'
    + `<td colspan="2">${html}</td>`
    + '</tr>';
}

/**
 * @param {string} rowsHtml
 */
function buildDetailTable(rowsHtml) {
  if (!rowsHtml.trim()) {
    return '';
  }

  return `<table class="table table-sm mb-0 aiu-ai-usage-detail__table">`
    + '<colgroup>'
    + '<col class="aiu-ai-usage-detail__col-label">'
    + '<col class="aiu-ai-usage-detail__col-value">'
    + '</colgroup>'
    + `<tbody>${rowsHtml}</tbody>`
    + '</table>';
}

/**
 * @param {HTMLElement} scope
 * @param {HTMLElement} button
 */
function buildUsageDetailContent(scope, button) {
  const labels = detailLabels(scope);
  const empty = labels.empty;
  const meta = parseMetaObject(button.getAttribute('data-meta') || '') ?? {};
  const status = button.getAttribute('data-status') || '';
  const statusBadge = status
    ? `<span class="badge ${status === 'success' ? 'badge-success' : 'badge-danger'}">${escapeHtml(status)}</span>`
    : empty;
  const latency = button.getAttribute('data-latency') || '';
  const latencyDisplay = latency !== '' ? `${latency} ms` : '';
  const cost = button.getAttribute('data-cost') || '';
  const costDisplay = cost !== '' ? `$${cost}` : '';
  const errorCode = (button.getAttribute('data-error-code') || '').trim();
  const errorClass = (button.getAttribute('data-error-class') || '').trim();
  const errorMessage = typeof meta.message === 'string' ? meta.message.trim() : '';
  const isFailed = status === 'failed' || errorClass !== '' || errorMessage !== '';

  const requestRows = [
    buildDetailRow(labels.time, button.getAttribute('data-time') || '', { empty }),
    buildDetailRow(labels.provider, button.getAttribute('data-provider') || '', { mono: true, empty }),
    buildDetailRow(labels.module, button.getAttribute('data-module') || '', { mono: true, empty }),
    buildDetailRow(labels.scope, button.getAttribute('data-scope') || '', { mono: true, empty }),
    buildDetailRow(labels.model, button.getAttribute('data-model') || '', { mono: true, empty }),
    buildDetailRow(labels.type, button.getAttribute('data-type') || '', { empty }),
    buildDetailRowHtml(labels.status, statusBadge),
  ].join('');

  const metricsRows = [
    buildDetailRow(labels.tokens, button.getAttribute('data-tokens') || '', { empty }),
    buildDetailRow(labels.promptTokens, button.getAttribute('data-prompt-tokens') || '', { empty }),
    buildDetailRow(labels.completionTokens, button.getAttribute('data-completion-tokens') || '', { empty }),
    buildDetailRow(labels.latency, latencyDisplay, { empty }),
    buildDetailRow(labels.credits, button.getAttribute('data-credits') || '', { empty }),
    buildDetailRow(labels.cost, costDisplay, { empty }),
  ].join('');

  let errorRows = '';
  if (isFailed) {
    const errorDetailRows = [];
    if (errorClass !== '') {
      errorDetailRows.push(buildDetailRow(labels.errorClass, shortClassName(errorClass), { mono: true, empty }));
      if (errorClass !== shortClassName(errorClass)) {
        errorDetailRows.push(buildDetailRow('FQCN', errorClass, { mono: true, empty }));
      }
    }
    if (isMeaningfulErrorCode(errorCode)) {
      errorDetailRows.push(buildDetailRow(labels.errorCode, errorCode, { mono: true, empty }));
    }

    const callout = errorMessage !== ''
      ? `<div class="aiu-ai-usage-detail__callout callout callout-danger mb-0">`
        + `<div class="callout-title">${escapeHtml(labels.errorMessage)}</div>`
        + `<div class="callout-body">${escapeHtml(errorMessage)}</div>`
        + '</div>'
      : `<div class="aiu-ai-usage-detail__callout callout callout-warning mb-0">`
        + `<div class="callout-body">${escapeHtml(labels.noErrorMessage)}</div>`
        + '</div>';

    errorRows = buildSectionDividerRow(labels.sectionError)
      + buildCalloutRow(callout)
      + errorDetailRows.join('');
  }

  const usedMetaKeys = new Set(['message']);
  let metaRows = '';
  const metaEntries = Object.entries(meta).filter(([key, value]) => {
    if (usedMetaKeys.has(key)) {
      return false;
    }
    if (value === null || value === undefined || value === '') {
      return false;
    }
    return true;
  });
  if (metaEntries.length > 0) {
    metaRows = buildSectionDividerRow(labels.sectionMeta)
      + metaEntries.map(([key, value]) => {
        const display = typeof value === 'object'
          ? JSON.stringify(value)
          : String(value);
        return buildDetailRow(key, display, { mono: true });
      }).join('');
  }

  const tableRows = buildSectionDividerRow(labels.sectionRequest)
    + requestRows
    + buildSectionDividerRow(labels.sectionMetrics)
    + metricsRows
    + errorRows
    + metaRows;

  return `<div class="aiu-ai-usage-detail">${buildDetailTable(tableRows)}</div>`;
}

/**
 * @param {HTMLElement} scope
 * @param {HTMLElement} button
 */
function openUsageDetailModal(scope, button) {
  const html = buildUsageDetailContent(scope, button);
  const modal = Modal.advanced({
    title: t(scope, 'l10nDetailsTitle', 'Request Details'),
    content: '',
    severity: Severity.info,
    size: Modal.sizes.medium,
    additionalCssClasses: ['aiu-ai-usage-detail-modal'],
    staticBackdrop: true,
    buttons: [
      {
        text: TYPO3?.lang?.['button.close'] || 'Close',
        btnClass: 'btn-default',
        trigger: () => Modal.dismiss(),
      },
    ],
  });

  modal.addEventListener('typo3-modal-shown', () => {
    const body = modal.querySelector('.modal-body');
    if (body) {
      body.innerHTML = html;
    }
  }, { once: true });
}

function initDetailsModal(scope) {
  if (!(scope instanceof HTMLElement)) {
    return;
  }
  if (scope.dataset.aiuUsageDetailBound === '1') {
    return;
  }
  scope.dataset.aiuUsageDetailBound = '1';

  scope.addEventListener('click', (event) => {
    const target = event.target;
    if (!(target instanceof Element)) {
      return;
    }
    const button = target.closest('[data-aiu-usage-open-detail]');
    if (!(button instanceof HTMLElement)) {
      return;
    }
    openUsageDetailModal(scope, button);
  });
}

function initSelectAll(scope) {
  const all = scope.querySelector('[data-aiu-usage-select-all]');
  if (!(all instanceof HTMLInputElement)) {
    return;
  }
  const checks = Array.from(scope.querySelectorAll('[data-aiu-usage-select]')).filter(
    (el) => el instanceof HTMLInputElement
  );

  all.addEventListener('change', () => {
    checks.forEach((check) => {
      check.checked = all.checked;
    });
    updateDeleteSelectedVisibility(scope);
  });

  checks.forEach((checkbox) => {
    checkbox.addEventListener('change', () => updateDeleteSelectedVisibility(scope));
  });
}

function initBulkDeleteConfirm(scope) {
  const form = scope.querySelector('[data-aiu-usage-bulk-form]');
  if (!(form instanceof HTMLFormElement)) {
    return;
  }

  form.addEventListener('submit', (event) => {
    const selected = Array.from(scope.querySelectorAll('[data-aiu-usage-select]')).filter(
      (el) => el instanceof HTMLInputElement && el.checked
    );

    if (selected.length === 0) {
      event.preventDefault();
      window.alert(t(scope, 'l10nAlertSelectAtLeastOne', 'Select at least one entry to delete.'));
      return;
    }

    event.preventDefault();
    const messageTemplate = t(scope, 'l10nBulkDeleteMessage', 'Do you want to soft-delete %1$s selected log entries?');
    Modal.confirm(
      t(scope, 'l10nBulkDeleteTitle', 'Delete selected entries'),
      messageTemplate.replace('%1$s', String(selected.length)),
      Severity.warning,
      [
        {
          text: TYPO3?.lang?.['button.cancel'] || 'Cancel',
          btnClass: 'btn-default',
          active: true,
          trigger: () => Modal.dismiss(),
        },
        {
          text: t(scope, 'l10nBulkDeleteConfirm', 'Delete selected'),
          btnClass: 'btn-danger',
          trigger: () => {
            Modal.dismiss();
            form.submit();
          },
        },
      ]
    );
  });
}

function boot() {
  document.querySelectorAll('[data-aiu-usage-root]').forEach((root) => {
    observeBrowserAutocomplete(root);
    initAiUsage(root);
    initPeriodDropdownForms(root);
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', boot, { once: true });
} else {
  boot();
}

export { initAiUsage, reinitAiUsageRoot };
