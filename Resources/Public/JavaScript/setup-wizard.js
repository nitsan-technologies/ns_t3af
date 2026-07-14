/**
 * Setup Wizard: gated stepper, mode/provider/API/test, summary, checklist toggle.
 *
 * @see EXT:ns_t3af Partials/Module/SetupWizard.html
 */
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';
import { observeBrowserAutocomplete } from '@nitsan/nst3af/disable-browser-autocomplete.js';

const WIZARD_STEPS = 8;

/**
 * @param {ParentNode} root
 * @param {string} sel
 * @returns {HTMLElement | null}
 */
function qs(root, sel) {
  const el = root.querySelector(sel);
  return el instanceof HTMLElement ? el : null;
}

/**
 * @param {string} id
 * @returns {unknown}
 */
function readJsonScript(id) {
  const el = document.getElementById(id);
  const raw = el?.textContent?.trim();
  if (!raw) {
    return null;
  }
  try {
    return JSON.parse(raw);
  } catch {
    return null;
  }
}

/**
 * TYPO3.lang fallback when inline labels are missing (see locallang_js.xlf).
 *
 * @param {string} key
 * @param {string} fallback
 */
function ll(key, fallback) {
  const v = typeof TYPO3 !== 'undefined' && TYPO3.lang ? TYPO3.lang[key] : undefined;
  return typeof v === 'string' && v !== '' ? v : fallback;
}

function initSetupWizard() {
  const dialog = document.getElementById('aiu-setup-wizard-dialog');
  if (!(dialog instanceof HTMLDialogElement) || typeof dialog.showModal !== 'function') {
    return;
  }

  observeBrowserAutocomplete(dialog);

  const testUrl = TYPO3.settings.ajaxUrls?.nst3af_provider_test;
  const finalizeUrl = TYPO3.settings.ajaxUrls?.nst3af_wizard_finalize;
  const ensureProviderUrl = TYPO3.settings.ajaxUrls?.nst3af_wizard_ensure_provider;
  const progressUrl = TYPO3.settings.ajaxUrls?.nst3af_wizard_progress;
  const extensionsAvailable = dialog.dataset.extensionsAvailable === '1';
  const catalogRaw = readJsonScript('aiu-wizard-json-catalog');
  /** @type {Array<{id: string, title: string, badge: string, models: string, badgeTone: string, adapterType: string, identifier: string, defaultModel: string}>} */
  const catalog = Array.isArray(catalogRaw) ? catalogRaw : [];
  const extensionsCatalogRaw = readJsonScript('aiu-wizard-json-extensions');
  /** @type {{ hasToggles?: boolean, groups?: Array<Record<string, unknown>>, defaults?: Record<string, Record<string, boolean>> }} */
  const extensionsCatalog =
    typeof extensionsCatalogRaw === 'object' && extensionsCatalogRaw !== null
      ? /** @type {{ hasToggles?: boolean, groups?: Array<Record<string, unknown>>, defaults?: Record<string, Record<string, boolean>> }} */ (
          extensionsCatalogRaw
        )
      : { groups: [], defaults: {} };
  const uiRaw = readJsonScript('aiu-wizard-json-ui');
  /** @type {Record<string, string>} */
  const ui = typeof uiRaw === 'object' && uiRaw !== null ? /** @type {Record<string, string>} */ (uiRaw) : {};

  let step = 1;
  let maxReachedStep = 1;

  /** @type {{ mode: 'credits'|'ownkeys', providerCatalogId: string, providerUid: number|null, modelId: string, connectionOk: boolean, extensionToggles: Record<string, Record<string, boolean>>, brandContext: { brandName: string, industry: string, toneTags: string[], voiceDescription: string, skipped: boolean }, mcp: boolean }} */
  const state = {
    mode: 'ownkeys',
    providerCatalogId: catalog[0]?.id ?? 'openai',
    providerUid: null,
    modelId: catalog[0]?.defaultModel ?? 'gpt-4o',
    connectionOk: true,
    extensionToggles: buildExtensionTogglesFromDefaults(extensionsCatalog.defaults ?? {}),
    brandContext: {
      brandName: '',
      industry: '',
      toneTags: [],
      voiceDescription: '',
      skipped: false,
    },
    mcp: false,
  };

  const titleEl = qs(dialog, '[data-aiu-wizard-title]');
  const kickerStepEl = qs(dialog, '[data-aiu-wizard-kicker-step]');
  const panels = [...dialog.querySelectorAll('[data-aiu-wizard-step]')].filter(
    (el) => el instanceof HTMLElement,
  );
  const tabs = [...dialog.querySelectorAll('[data-aiu-wizard-goto]')].filter(
    (el) => el instanceof HTMLElement,
  );

  const providerListMount = dialog.querySelector('#aiu-wizard-provider-list-mount');
  const panelProviderOwn = qs(dialog, '#aiu-wizard-provider-own');
  const panelProviderCredits = qs(dialog, '#aiu-wizard-provider-credits');
  const panelApiOwn = qs(dialog, '#aiu-wizard-api-own');
  const panelApiCredits = qs(dialog, '#aiu-wizard-api-credits');
  const apiKeyInput = dialog.querySelector('#aiu-wizard-api-key');
  const apiLeadEl = qs(dialog, '#aiu-wizard-api-lead');
  const modelPillsMount = qs(dialog, '#aiu-wizard-model-pills');
  const apiKeyWrap = qs(dialog, '#aiu-wizard-api-key-wrap');
  const apiKeyHelpEl = qs(dialog, '#aiu-wizard-api-key-help');
  const apiKeyToggle = qs(dialog, '#aiu-wizard-api-key-toggle');
  const testStatusEl = qs(dialog, '#aiu-wizard-test-status');
  const summaryCardsUl = qs(dialog, '#aiu-wizard-summary-cards');
  const summaryLeadEl = qs(dialog, '#aiu-wizard-summary-lead');
  const mcpInput = dialog.querySelector('#aiu-wizard-mcp');
  const mcpCardEl = qs(dialog, '#aiu-wizard-mcp-card');
  const extCountEl = qs(dialog, '#aiu-wizard-ext-count');
  const extensionsRoot = qs(dialog, '#aiu-wizard-extensions-root');
  const brandNameInput = dialog.querySelector('#aiu-wizard-brand-name');
  const industrySelect = dialog.querySelector('#aiu-wizard-industry');
  const voiceNotesInput = dialog.querySelector('#aiu-wizard-voice-notes');
  const tonePillsMount = qs(dialog, '#aiu-wizard-tone-pills');

  const modeCreditsBtn = dialog.querySelector('[data-aiu-wizard-mode="credits"]');
  const modeOwnBtn = dialog.querySelector('[data-aiu-wizard-mode="ownkeys"]');
  const creditsFeatureAvailable = dialog.dataset.creditsFeatureAvailable !== '0';

  /**
   * @param {number} n
   */
  function panelFor(n) {
    return panels.find((p) => Number.parseInt(p.dataset.aiuWizardStep ?? '', 10) === n);
  }

  function syncModeCards() {
    if (modeCreditsBtn instanceof HTMLButtonElement && modeOwnBtn instanceof HTMLButtonElement) {
      const credits = state.mode === 'credits';
      modeCreditsBtn.classList.toggle('is-active', credits);
      modeCreditsBtn.setAttribute('aria-pressed', credits ? 'true' : 'false');
      modeOwnBtn.classList.toggle('is-active', !credits);
      modeOwnBtn.setAttribute('aria-pressed', credits ? 'false' : 'true');
    }
  }

  function readBrandContextFromDom() {
    if (brandNameInput instanceof HTMLInputElement) {
      state.brandContext.brandName = brandNameInput.value.trim();
    }
    if (industrySelect instanceof HTMLSelectElement) {
      state.brandContext.industry = industrySelect.value.trim();
    }
    if (voiceNotesInput instanceof HTMLTextAreaElement) {
      state.brandContext.voiceDescription = voiceNotesInput.value.trim();
    }
    if (tonePillsMount instanceof HTMLElement) {
      state.brandContext.toneTags = [...tonePillsMount.querySelectorAll('[data-aiu-wizard-tone].is-active')]
        .map((el) => el.getAttribute('data-aiu-wizard-tone') || '')
        .filter((tag) => tag !== '');
    }
  }

  /**
   * @param {boolean} [showNotification]
   */
  function validateBrandContextStep(showNotification = true) {
    readBrandContextFromDom();
    state.brandContext.skipped = false;
    if (state.brandContext.brandName === '') {
      if (showNotification) {
        Notification.warning(
          ui.brandNameRequired || ll('wizard.error.brandNameRequired', 'Enter a brand name to continue.'),
          '',
        );
      }
      return false;
    }
    if (state.brandContext.industry === '') {
      if (showNotification) {
        Notification.warning(
          ui.industryRequired || ll('wizard.error.industryRequired', 'Select an industry to continue.'),
          '',
        );
      }
      return false;
    }
    const toneCount = state.brandContext.toneTags.length;
    if (toneCount < 3 || toneCount > 5) {
      if (showNotification) {
        Notification.warning(
          ui.toneTagsInvalid || ll('wizard.error.toneTagsInvalid', 'Pick 3–5 tone tags.'),
          '',
        );
      }
      return false;
    }
    return true;
  }

  function syncTonePills() {
    if (!(tonePillsMount instanceof HTMLElement)) {
      return;
    }
    tonePillsMount.querySelectorAll('[data-aiu-wizard-tone]').forEach((el) => {
      if (!(el instanceof HTMLButtonElement)) {
        return;
      }
      const tag = el.getAttribute('data-aiu-wizard-tone') || '';
      const active = state.brandContext.toneTags.includes(tag);
      el.classList.toggle('is-active', active);
      el.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
  }

  function resetBrandContextDom() {
    if (brandNameInput instanceof HTMLInputElement) {
      brandNameInput.value = '';
    }
    if (industrySelect instanceof HTMLSelectElement) {
      industrySelect.value = '';
    }
    if (voiceNotesInput instanceof HTMLTextAreaElement) {
      voiceNotesInput.value = '';
    }
    state.brandContext = {
      brandName: '',
      industry: '',
      toneTags: [],
      voiceDescription: '',
      skipped: false,
    };
    syncTonePills();
  }

  function brandContextSummaryDetail() {
    readBrandContextFromDom();
    const parts = [];
    if (state.brandContext.brandName !== '') {
      parts.push(state.brandContext.brandName);
    }
    if (state.brandContext.toneTags.length > 0) {
      parts.push(state.brandContext.toneTags.join(', '));
    }
    if (parts.length === 0 && state.brandContext.industry !== '') {
      parts.push(state.brandContext.industry);
    }
    return parts.length > 0
      ? parts.join(' · ')
      : ui.brandContextConfigured || ll('wizard.summary.brandContextConfigured', 'Basic profile saved');
  }

  function catalogEntry() {
    return catalog.find((entry) => entry.id === state.providerCatalogId) ?? null;
  }

  /**
   * @param {string} value
   */
  function escapeHtml(value) {
    return value
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  /**
   * @param {string} template
   * @param {string} providerTitle
   */
  function formatWizardLeadHtml(template, providerTitle) {
    const name = `<strong>${escapeHtml(providerTitle)}</strong>`;
    return template.replace('%1$s', name).replace('%s', name);
  }

  /**
   * @param {{ keyUrl?: string, keyUrlHref?: string, keyUrlHost?: string }} entry
   */
  function renderApiKeyHelp(entry) {
    if (!(apiKeyHelpEl instanceof HTMLElement)) {
      return;
    }
    apiKeyHelpEl.textContent = '';
    const href = typeof entry.keyUrlHref === 'string' ? entry.keyUrlHref.trim() : '';
    const host = typeof entry.keyUrlHost === 'string' ? entry.keyUrlHost.trim() : '';
    if (href !== '' && host !== '') {
      const prefix = document.createTextNode(
        `${ui.keyUrlPrefix || ll('wizard.step4.keyUrlPrefix', 'Get your key from')} `,
      );
      const link = document.createElement('a');
      link.className = 'aiu-wizard__api-key-help-link';
      link.href = href;
      link.target = '_blank';
      link.rel = 'noopener noreferrer';
      link.textContent = host;
      apiKeyHelpEl.append(prefix, link);
      return;
    }
    apiKeyHelpEl.textContent = entry.keyUrl ?? '';
  }

  function renderModelPills() {
    if (!(modelPillsMount instanceof HTMLElement)) {
      return;
    }
    const entry = catalogEntry();
    modelPillsMount.innerHTML = '';
    if (!entry || !Array.isArray(entry.modelOptions) || entry.modelOptions.length === 0) {
      return;
    }
    if (!entry.modelOptions.includes(state.modelId)) {
      state.modelId = entry.defaultModel;
    }
    entry.modelOptions.forEach((modelId) => {
      const pill = document.createElement('button');
      pill.type = 'button';
      pill.className = `btn btn-default btn-sm${modelId === state.modelId ? ' active is-active' : ''}`;
      pill.textContent = modelId;
      pill.setAttribute('aria-pressed', modelId === state.modelId ? 'true' : 'false');
      pill.addEventListener('click', () => {
        state.modelId = modelId;
        state.connectionOk = false;
        if (testStatusEl) {
          testStatusEl.textContent = '';
          testStatusEl.classList.remove('is-ok', 'text-danger', 'text-success');
        }
        renderModelPills();
        syncApiPanel();
      });
      modelPillsMount.appendChild(pill);
    });
  }

  /**
   * @param {string} title
   * @param {string} badge
   * @param {string} badgeTone
   * @param {string} models
   * @param {boolean} selected
   * @param {() => void} onSelect
   */
  function appendProviderCatalogCard(title, badge, badgeTone, models, selected, onSelect) {
    if (!(providerListMount instanceof HTMLElement)) {
      return;
    }
    const card = document.createElement('button');
    card.type = 'button';
    card.className = `card card-size-small aiu-wizard__catalog-card${selected ? ' is-selected' : ''}`;
    card.setAttribute('aria-pressed', selected ? 'true' : 'false');

    const head = document.createElement('span');
    head.className = 'aiu-wizard__catalog-head';

    const titleEl = document.createElement('span');
    titleEl.className = 'card-title aiu-wizard__catalog-title mb-0';
    titleEl.textContent = title;

    const badgeClassMap = {
      blue: 'badge-default',
      green: 'badge-success',
      purple: 'badge-info',
      orange: 'badge-warning',
    };
    const badgeEl = document.createElement('span');
    badgeEl.className = `badge ${badgeClassMap[badgeTone] || 'badge-default'} flex-shrink-0`;
    badgeEl.textContent = badge;

    head.append(titleEl, badgeEl);

    const modelsEl = document.createElement('span');
    modelsEl.className = 'card-subtitle aiu-wizard__catalog-models';
    modelsEl.textContent = models;

    card.append(head, modelsEl);
    card.addEventListener('click', onSelect);
    providerListMount.appendChild(card);
  }

  function renderProviderCatalog() {
    if (!(providerListMount instanceof HTMLElement)) {
      return;
    }
    providerListMount.innerHTML = '';

    if (catalog.length === 0) {
      const empty = document.createElement('p');
      empty.className = 'aiu-wizard__catalog-empty';
      empty.textContent =
        ui.providerEmpty ||
        ll('wizard.step3.empty', 'No provider catalog entries are configured.');
      providerListMount.appendChild(empty);
      state.providerCatalogId = '';
      state.providerUid = null;
      return;
    }

    if (!catalog.some((entry) => entry.id === state.providerCatalogId)) {
      state.providerCatalogId = catalog[0].id;
      state.modelId = catalog[0].defaultModel;
    }

    catalog.forEach((entry) => {
      const selected = entry.id === state.providerCatalogId;
      appendProviderCatalogCard(
        entry.title,
        entry.badge,
        entry.badgeTone || 'blue',
        entry.models,
        selected,
        () => {
          state.providerCatalogId = entry.id;
          state.modelId = entry.defaultModel;
          state.providerUid = null;
          state.connectionOk = false;
          renderProviderCatalog();
          syncApiPanel();
        },
      );
    });
  }

  async function ensureWizardProvider() {
    if (state.mode !== 'ownkeys') {
      return true;
    }
    const entry = catalogEntry();
    if (entry === null) {
      return false;
    }
    if (entry.adapterAvailable === false) {
      Notification.warning(
        ui.adapterUnavailable ||
          ll(
            'wizard.error.adapterUnavailable',
            'This provider adapter is not installed. Install the matching Symfony AI platform package first.',
          ),
        '',
      );
      return false;
    }
    if (state.providerUid !== null && state.providerUid > 0) {
      return true;
    }
    if (!ensureProviderUrl) {
      return false;
    }
    try {
      const pageId = parseInt(dialog.dataset.aiuPageId || '0', 10);
      /** @type {Record<string, string|number>} */
      const payload = {
        catalogId: entry.id,
        modelId: state.modelId,
      };
      if (Number.isFinite(pageId) && pageId > 0) {
        payload.id = pageId;
      }
      const response = await new AjaxRequest(ensureProviderUrl).post(payload);
      const json = await response.resolve();
      if (json.ok && json.uid) {
        state.providerUid = json.uid;
        return true;
      }
      Notification.warning(json.message ?? ui.adapterUnavailable ?? '', '');
    } catch {
      return false;
    }
    return false;
  }

  function syncProviderStepVisibility() {
    const credits = state.mode === 'credits';
    if (panelProviderOwn) {
      panelProviderOwn.hidden = credits;
    }
    if (panelProviderCredits) {
      panelProviderCredits.hidden = !credits;
    }
    if (!credits) {
      renderProviderCatalog();
    }
  }

  function syncApiPanel() {
    const credits = state.mode === 'credits';
    if (panelApiOwn) {
      panelApiOwn.hidden = credits;
    }
    if (panelApiCredits) {
      panelApiCredits.hidden = !credits;
    }
    const entry = catalogEntry();
    if (apiLeadEl && entry) {
      const leadTemplate = entry.requiresApiKey === false
        ? ui.step4LeadNoKey || ll('wizard.step4.leadNoKey', 'Configure your %1$s connection.')
        : ui.step4Lead || ll('wizard.step4.lead', 'Enter your %1$s API key.');
      apiLeadEl.innerHTML = formatWizardLeadHtml(leadTemplate, entry.title);
    }
    if (apiKeyWrap) {
      apiKeyWrap.hidden = entry?.requiresApiKey === false;
    }
    if (entry) {
      renderApiKeyHelp(entry);
    } else if (apiKeyHelpEl) {
      apiKeyHelpEl.textContent = '';
    }
    renderModelPills();
    if (apiKeyInput instanceof HTMLInputElement) {
      apiKeyInput.disabled = credits || state.providerUid === null;
    }
    const testBtn = qs(dialog, '#aiu-wizard-test-api');
    const keyRequired = entry?.requiresApiKey !== false;
    const hasKey = apiKeyInput instanceof HTMLInputElement && apiKeyInput.value.trim() !== '';
    if (testBtn instanceof HTMLButtonElement) {
      testBtn.disabled =
        credits || state.providerUid === null || !testUrl || (keyRequired && !hasKey);
    }
    if (credits) {
      state.connectionOk = true;
    }
  }

  /**
   * @param {Record<string, Record<string, boolean>>} defaults
   */
  function buildExtensionTogglesFromDefaults(defaults) {
    /** @type {Record<string, Record<string, boolean>>} */
    const toggles = {};
    Object.entries(defaults).forEach(([extKey, fields]) => {
      if (!fields || typeof fields !== 'object') {
        return;
      }
      toggles[extKey] = {};
      Object.entries(fields).forEach(([field, enabled]) => {
        toggles[extKey][field] = Boolean(enabled);
      });
    });
    return toggles;
  }

  /**
   * @param {string} identifier
   */
  function createWizardIcon(identifier) {
    const icon = document.createElement('typo3-backend-icon');
    icon.setAttribute('identifier', identifier);
    icon.setAttribute('size', 'small');
    return icon;
  }

  function renderExtensionsStep() {
    if (!extensionsRoot) {
      return;
    }
    extensionsRoot.replaceChildren();

    const groups = Array.isArray(extensionsCatalog.groups) ? extensionsCatalog.groups : [];
    groups.forEach((group) => {
      const extensionKey = typeof group.extensionKey === 'string' ? group.extensionKey : '';
      if (extensionKey === '') {
        return;
      }

      const section = document.createElement('section');
      section.className = 'aiu-wizard__ext-group';
      section.dataset.aiuWizardExtGroup = extensionKey;

      const header = document.createElement('header');
      header.className = 'aiu-wizard__ext-group-header mb-2';
      const title = document.createElement('h3');
      title.className = 'h5 mb-0';
      title.textContent = typeof group.label === 'string' ? group.label : extensionKey;
      header.appendChild(title);
      if (typeof group.sublabel === 'string' && group.sublabel !== '') {
        const sub = document.createElement('p');
        sub.className = 'text-variant small mb-0';
        sub.textContent = group.sublabel;
        header.appendChild(sub);
      }
      section.appendChild(header);

      const suiteBadges = Array.isArray(group.suiteBadges) ? group.suiteBadges : [];
      if (suiteBadges.length > 0) {
        const badges = document.createElement('div');
        badges.className = 'd-flex flex-wrap gap-1 mb-2';
        suiteBadges.forEach((badge) => {
          if (typeof badge !== 'string' || badge === '') {
            return;
          }
          const span = document.createElement('span');
          span.className = 'badge badge-default';
          span.textContent = badge;
          badges.appendChild(span);
        });
        section.appendChild(badges);
      }

      if (group.informational === true && (!Array.isArray(group.features) || group.features.length === 0)) {
        const note = document.createElement('p');
        note.className = 'aiu-wizard__hint text-muted small mb-0';
        note.textContent =
          ui.step5SuiteNote ||
          ll('wizard.step5.suiteNote', 'Configure detailed settings later under AI Features.');
        section.appendChild(note);
      }

      const features = Array.isArray(group.features) ? group.features : [];
      if (features.length > 0) {
        const featureList = document.createElement('div');
        featureList.className = 'aiu-wizard__ext-list d-flex flex-column gap-2';
        features.forEach((feature) => {
          if (!feature || typeof feature !== 'object') {
            return;
          }
          const toggleField =
            typeof feature.toggleField === 'string' ? feature.toggleField : '';
          if (toggleField === '') {
            return;
          }
          const checked = Boolean(state.extensionToggles[extensionKey]?.[toggleField]);
          featureList.appendChild(
            buildToggleCard({
              extensionKey,
              toggleField,
              title: typeof feature.name === 'string' ? feature.name : toggleField,
              description:
                typeof feature.subtitle === 'string' && feature.subtitle !== ''
                  ? feature.subtitle
                  : typeof feature.description === 'string'
                    ? feature.description
                    : '',
              icon: typeof feature.icon === 'string' ? feature.icon : 'actions-check',
              checked,
            }),
          );
        });
        section.appendChild(featureList);
      }

      extensionsRoot.appendChild(section);
    });

    syncExtensionCards();
    updateExtensionCount();
  }

  /**
   * @param {{ extensionKey: string, toggleField: string, title: string, description: string, icon: string, checked: boolean }} config
   */
  function buildToggleCard(config) {
    const label = document.createElement('label');
    label.className = 'card card-size-small aiu-wizard__ext-card mb-0';
    if (config.checked) {
      label.classList.add('is-active');
    }

    const input = document.createElement('input');
    input.type = 'checkbox';
    input.className = 'aiu-wizard__ext-input';
    input.checked = config.checked;
    input.dataset.aiuWizardToggleExt = config.extensionKey;
    input.dataset.aiuWizardToggleField = config.toggleField;

    const body = document.createElement('span');
    body.className = 'card-body d-flex align-items-center gap-3 py-3';

    const iconWrap = document.createElement('span');
    iconWrap.className = 'aiu-wizard__ext-icon flex-shrink-0';
    iconWrap.setAttribute('aria-hidden', 'true');
    iconWrap.appendChild(createWizardIcon(config.icon));

    const copy = document.createElement('span');
    copy.className = 'aiu-wizard__ext-copy flex-grow-1 min-w-0';
    const titleEl = document.createElement('span');
    titleEl.className = 'fw-semibold d-block';
    titleEl.textContent = config.title;
    copy.appendChild(titleEl);
    if (config.description !== '') {
      const desc = document.createElement('span');
      desc.className = 'text-variant small d-block';
      desc.textContent = config.description;
      copy.appendChild(desc);
    }

    const toggle = document.createElement('span');
    toggle.className = 'aiu-wizard__ext-toggle flex-shrink-0';
    toggle.setAttribute('aria-hidden', 'true');
    const track = document.createElement('span');
    track.className = 'aiu-wizard__ext-toggle-track';
    toggle.appendChild(track);

    body.append(iconWrap, copy, toggle);
    label.append(input, body);
    return label;
  }

  function syncExtensionTogglesToDom() {
    dialog.querySelectorAll('[data-aiu-wizard-toggle-ext][data-aiu-wizard-toggle-field]').forEach((el) => {
      if (!(el instanceof HTMLInputElement) || el.type !== 'checkbox') {
        return;
      }
      const extKey = el.dataset.aiuWizardToggleExt ?? '';
      const field = el.dataset.aiuWizardToggleField ?? '';
      if (extKey === '' || field === '') {
        return;
      }
      el.checked = Boolean(state.extensionToggles[extKey]?.[field]);
    });
    syncExtensionCards();
    updateExtensionCount();
  }

  function readExtensionsFromDom() {
    dialog.querySelectorAll('[data-aiu-wizard-toggle-ext][data-aiu-wizard-toggle-field]').forEach((el) => {
      if (!(el instanceof HTMLInputElement) || el.type !== 'checkbox') {
        return;
      }
      const extKey = el.dataset.aiuWizardToggleExt ?? '';
      const field = el.dataset.aiuWizardToggleField ?? '';
      if (extKey === '' || field === '') {
        return;
      }
      if (!state.extensionToggles[extKey]) {
        state.extensionToggles[extKey] = {};
      }
      state.extensionToggles[extKey][field] = el.checked;
    });
    syncExtensionCards();
    updateExtensionCount();
  }

  function syncExtensionCards() {
    dialog.querySelectorAll('.aiu-wizard__ext-card').forEach((card) => {
      if (!(card instanceof HTMLLabelElement)) {
        return;
      }
      const input = card.querySelector('[data-aiu-wizard-toggle-field]');
      if (input instanceof HTMLInputElement) {
        card.classList.toggle('is-active', input.checked);
      }
    });
  }

  function updateExtensionCount() {
    if (!extCountEl) {
      return;
    }
    const inputs = [...dialog.querySelectorAll('[data-aiu-wizard-toggle-field]')].filter(
      (el) => el instanceof HTMLInputElement,
    );
    const total = inputs.length;
    const selected = inputs.filter((el) => el.checked).length;
    const template =
      ui.extSelectedCount || ll('wizard.step5.selectedCount', '%1$s of %2$s extensions selected');
    extCountEl.textContent = template
      .replace('%1$s', String(selected))
      .replace('%2$s', String(total))
      .replace('%s', String(total));
  }

  function syncMcpCard() {
    if (mcpCardEl) {
      mcpCardEl.classList.toggle('is-active', state.mcp);
    }
  }

  function extensionSummaryDetail() {
    const groups = Array.isArray(extensionsCatalog.groups) ? extensionsCatalog.groups : [];
    const parts = [];
    groups.forEach((group) => {
      const extensionKey = typeof group.extensionKey === 'string' ? group.extensionKey : '';
      const label = typeof group.label === 'string' ? group.label : extensionKey;
      if (extensionKey === '' || label === '') {
        return;
      }
      const toggles = state.extensionToggles[extensionKey] ?? {};
      const enabled = Object.values(toggles).filter(Boolean).length;
      if (enabled > 0) {
        parts.push(`${label} (${enabled})`);
      }
    });
    if (parts.length === 0) {
      return ui.extensionsNone || ll('wizard.summary.extensionsNone', 'None selected');
    }
    const template =
      ui.extensionsActivated || ll('wizard.summary.extensionsActivated', '%1$s activated (%2$s)');
    return template.replace('%1$s', String(parts.length)).replace('%2$s', parts.join(', '));
  }

  /**
   * @param {string} identifier
   */
  function createSummaryIcon(identifier) {
    const icon = document.createElement('typo3-backend-icon');
    icon.setAttribute('identifier', identifier);
    icon.setAttribute('size', 'small');
    return icon;
  }

  /**
   * @param {string} label
   * @param {string} detail
   * @param {string} tone
   * @param {string} iconId
   */
  function appendSummaryCard(label, detail, tone, iconId) {
    if (!summaryCardsUl) {
      return;
    }
    const li = document.createElement('li');
    li.className = 'list-group-item aiu-wizard__summary-card';
    const ic = document.createElement('span');
    ic.className = 'aiu-wizard__summary-ico flex-shrink-0';
    ic.setAttribute('aria-hidden', 'true');
    ic.appendChild(createSummaryIcon(iconId));
    const tx = document.createElement('div');
    tx.className = 'aiu-wizard__summary-text flex-grow-1 min-w-0';
    const labelEl = document.createElement('div');
    labelEl.className = 'text-variant small text-uppercase fw-bold mb-0';
    labelEl.textContent = label;
    const detailEl = document.createElement('div');
    detailEl.className = 'fw-semibold small mb-0 mt-1';
    detailEl.textContent = detail;
    tx.append(labelEl, detailEl);
    const ok = document.createElement('span');
    ok.className = 'aiu-wizard__summary-ok';
    ok.setAttribute('aria-hidden', 'true');
    ok.appendChild(createSummaryIcon('actions-check'));
    li.append(ic, tx, ok);
    summaryCardsUl.appendChild(li);
  }

  function draftApiKey() {
    return apiKeyInput instanceof HTMLInputElement ? apiKeyInput.value.trim() : '';
  }

  function apiKeyGateOk() {
    return state.connectionOk;
  }

  function renderSummary() {
    if (!summaryCardsUl || !summaryLeadEl) {
      return;
    }
    summaryCardsUl.innerHTML = '';
    summaryLeadEl.textContent =
      ui.summaryLeadIntro ||
      ll('wizard.step8.summaryLeadIntro', 'Your configuration has been saved. Here’s a summary:');

    const entry = catalogEntry();

    const modeLabel = ui.summaryMode || ll('wizard.summary.labelMode', 'AI PROVIDER MODE');
    const modeDetail =
      state.mode === 'credits'
        ? ui.modeCredits || ll('wizard.summary.modeCredits', 'T3Planet Credits')
        : `${ui.modeOwn || ll('wizard.summary.modeOwn', 'Own API Keys')}${
            entry ? ` — ${entry.title} · ${state.modelId || entry.defaultModel}` : ''
          }`;

    let apiDetail = '';
    if (state.mode === 'credits') {
      apiDetail =
        ui.apiManagedCredits || ll('wizard.summary.apiManagedCredits', 'Managed by T3Planet');
    } else if (state.connectionOk) {
      apiDetail = ui.apiVerified || ll('wizard.summary.apiVerified', 'Configured & verified');
    } else {
      apiDetail =
        ui.apiPendingTest ||
        ll('wizard.summary.apiPendingTest', 'Test pending — run Test connection');
    }

    appendSummaryCard(
      modeLabel,
      modeDetail,
      'cpu',
      'actions-cpu',
    );
    appendSummaryCard(
      ui.summaryApi || ll('wizard.summary.labelApi', 'API KEY'),
      apiDetail,
      'key',
      'actions-key',
    );
    appendSummaryCard(
      ui.summaryExtensions || ll('wizard.summary.labelExtensions', 'EXTENSIONS'),
      extensionSummaryDetail(),
      'bolt',
      'actions-bolt',
    );
    appendSummaryCard(
      ui.summaryBrandContext || ll('wizard.summary.labelBrandContext', 'BRAND CONTEXT'),
      brandContextSummaryDetail(),
      'identity',
      'actions-user',
    );
    appendSummaryCard(
      ui.summaryMcp || ll('wizard.summary.labelMcp', 'MCP SERVER'),
      state.mcp
        ? ui.mcpOn || ll('wizard.summary.mcpOn', 'Enabled · File System & DB connectors ready')
        : ui.mcpOff || ll('wizard.summary.mcpOff', 'Disabled'),
      'mcp',
      'actions-server',
    );
  }

  /**
   * @param {number} n
   * @param {boolean} persist
   */
  function setStep(n, persist = true) {
    step = Math.min(WIZARD_STEPS, Math.max(1, Math.floor(n)));
    panels.forEach((p) => {
      const num = Number.parseInt(p.dataset.aiuWizardStep ?? '', 10);
      const active = num === step;
      p.classList.toggle('is-active', active);
      if (active) {
        p.removeAttribute('hidden');
      } else {
        p.setAttribute('hidden', '');
      }
      p.setAttribute('aria-hidden', active ? 'false' : 'true');
    });
    tabs.forEach((t) => {
      const num = Number.parseInt(t.dataset.aiuWizardGoto ?? '', 10);
      const sel = num === step;
      t.classList.toggle('is-active', sel);
      t.classList.toggle('active', sel);
      t.setAttribute('aria-selected', sel ? 'true' : 'false');
      t.tabIndex = sel ? 0 : -1;
      const complete = num < step;
      t.classList.toggle('is-complete', complete);
      const locked = num > maxReachedStep;
      t.classList.toggle('is-locked', locked);
      if (t instanceof HTMLButtonElement) {
        t.disabled = locked;
      }
    });
    const panel = panelFor(step);
    const heading = panel?.dataset.wizardHeading ?? '';
    if (titleEl) {
      titleEl.textContent = heading;
    }
    if (kickerStepEl) {
      kickerStepEl.textContent = String(step);
    }

    if (step === 3) {
      syncProviderStepVisibility();
    }
    if (step === 4) {
      syncApiPanel();
    }
    if (step === 5) {
      renderExtensionsStep();
      readExtensionsFromDom();
    }
    if (step === 6) {
      syncTonePills();
    }
    if (step === 7) {
      syncMcpCard();
    }
    if (step === 8) {
      readExtensionsFromDom();
      readBrandContextFromDom();
      renderSummary();
    }

    if (persist && dialog.open) {
      persistProgress();
    }
  }

  function persistProgress() {
    if (!progressUrl) {
      return;
    }
    new AjaxRequest(progressUrl)
      .post({ lastStep: step, maxStep: maxReachedStep })
      .catch(() => {
        // Non-fatal: remind-later still works client-side until next visit.
      });
  }

  function skipCurrentStep() {
    if (step >= WIZARD_STEPS) {
      return;
    }
    const next = adjustStepForFlow(step + 1, 1);
    maxReachedStep = Math.max(maxReachedStep, next);
    setStep(next);
  }

  /**
   * @param {number} n
   * @param {number} direction 1 = forward, -1 = back
   */
  function adjustStepForFlow(n, direction) {
    let target = n;
    if (!extensionsAvailable && target === 5) {
      target = direction > 0 ? 6 : 4;
    }
    return target;
  }

  /**
   * @param {number} fromStep
   */
  function canLeaveStep(fromStep) {
    if (fromStep === 3 && state.mode === 'ownkeys' && state.providerCatalogId === '') {
      Notification.warning(
        ui.testMissingProvider ||
          ll('wizard.error.selectProvider', 'Select a provider to continue.'),
        '',
      );
      return false;
    }
    if (fromStep === 4 && state.mode === 'ownkeys' && !apiKeyGateOk()) {
      Notification.warning(
        ui.needApiTest || ll('wizard.error.needApiTest', 'Run Test connection before continuing.'),
        '',
      );
      return false;
    }
    if (fromStep === 6) {
      return validateBrandContextStep(true);
    }
    return true;
  }

  function wireModeCards() {
    modeCreditsBtn?.addEventListener('click', () => {
      if (!creditsFeatureAvailable) {
        return;
      }
      state.mode = 'credits';
      state.providerUid = null;
      state.connectionOk = true;
      syncModeCards();
      syncProviderStepVisibility();
      syncApiPanel();
    });
    modeOwnBtn?.addEventListener('click', () => {
      state.mode = 'ownkeys';
      state.connectionOk = false;
      state.providerUid = null;
      if (!catalog.some((entry) => entry.id === state.providerCatalogId)) {
        state.providerCatalogId = catalog[0]?.id ?? 'openai';
      }
      state.modelId = catalogEntry()?.defaultModel ?? state.modelId;
      syncModeCards();
      syncProviderStepVisibility();
      syncApiPanel();
    });
  }

  async function runApiTest() {
    if (!testUrl || !(apiKeyInput instanceof HTMLInputElement)) {
      return;
    }
    const uid = state.providerUid;
    if (uid === null || uid <= 0) {
      Notification.warning(
        ui.testMissingUid || ll('wizard.error.testMissingUid', 'Choose a provider before testing.'),
        '',
      );
      return;
    }
    const btn = qs(dialog, '#aiu-wizard-test-api');
    if (btn instanceof HTMLButtonElement) {
      btn.disabled = true;
    }
    const payload = /** @type {Record<string, string|number>} */ ({ uid });
    const key = apiKeyInput.value.trim();
    if (key !== '') {
      payload.apiKey = key;
    }
    try {
      const response = await new AjaxRequest(testUrl).post(payload);
      const json = await response.resolve();
      if (json.ok) {
        state.connectionOk = true;
        Notification.success(ui.notifyOk || ll('wizard.notify.testOk', 'Connection OK'), json.message ?? '');
        if (testStatusEl) {
          testStatusEl.textContent =
            ui.step4TestOkInline || ll('wizard.step4.testOkInline', 'Connection successful');
          testStatusEl.classList.remove('text-danger', 'text-success', 'text-muted');
          testStatusEl.classList.add('is-ok');
        }
      } else {
        state.connectionOk = false;
        Notification.error(
          ui.notifyFail || ll('wizard.notify.testFail', 'Connection failed'),
          json.message ?? '',
        );
        if (testStatusEl) {
          testStatusEl.textContent = json.message ?? '';
          testStatusEl.classList.remove('is-ok', 'text-success');
          testStatusEl.classList.add('text-danger');
        }
      }
    } catch (err) {
      state.connectionOk = false;
      Notification.error(ui.notifyFail || ll('wizard.notify.testFail', 'Connection failed'), String(err));
      if (testStatusEl) {
        testStatusEl.textContent = '';
        testStatusEl.classList.remove('is-ok', 'text-success');
      }
    } finally {
      syncApiPanel();
    }
  }

  function resetWizardState() {
    step = 1;
    maxReachedStep = 1;
    state.mode = 'ownkeys';
    state.providerCatalogId = catalog[0]?.id ?? 'openai';
    state.providerUid = null;
    state.modelId = catalog[0]?.defaultModel ?? 'gpt-4o';
    state.connectionOk = true;
    state.extensionToggles = buildExtensionTogglesFromDefaults(extensionsCatalog.defaults ?? {});
    state.mcp = false;
    resetBrandContextDom();
    if (apiKeyInput instanceof HTMLInputElement) {
      apiKeyInput.value = '';
    }
    if (mcpInput instanceof HTMLInputElement) {
      mcpInput.checked = false;
    }
    renderExtensionsStep();
    syncExtensionTogglesToDom();
    syncModeCards();
    syncProviderStepVisibility();
    syncApiPanel();
    syncMcpCard();
  }

  /**
   * @param {{ resumeStep?: number, maxReachedStep?: number }} [options]
   */
  function openWizard(options = {}) {
    const resumeStep = Math.min(
      WIZARD_STEPS,
      Math.max(1, Number.isFinite(options.resumeStep) ? options.resumeStep : 1),
    );
    const resumeMax = Math.min(
      WIZARD_STEPS,
      Math.max(
        resumeStep,
        Number.isFinite(options.maxReachedStep) ? options.maxReachedStep : resumeStep,
      ),
    );

    resetWizardState();
    maxReachedStep = resumeMax;
    renderProviderCatalog();
    syncApiPanel();
    setStep(resumeStep, false);
    dialog.showModal();
    window.requestAnimationFrame(() => {
      panelFor(step)?.querySelector('[data-aiu-wizard-next]')?.focus();
    });
  }

  function closeWizard() {
    if (dialog.open) {
      persistProgress();
      dialog.close();
    }
  }

  document.querySelectorAll('[data-aiu-wizard-open]').forEach((btn) => {
    btn.addEventListener('click', (evt) => {
      evt.preventDefault();
      openWizard();
    });
  });

  dialog.addEventListener('click', (evt) => {
    if (evt.target === dialog) {
      closeWizard();
    }
  });

  dialog.addEventListener('cancel', (evt) => {
    evt.preventDefault();
    closeWizard();
  });

  dialog.querySelectorAll('[data-aiu-wizard-close]').forEach((el) => {
    el.addEventListener('click', () => closeWizard());
  });

  dialog.querySelectorAll('[data-aiu-wizard-skip]').forEach((el) => {
    el.addEventListener('click', () => skipCurrentStep());
  });

  dialog.querySelectorAll('[data-aiu-wizard-prev]').forEach((el) => {
    el.addEventListener('click', () => {
      setStep(adjustStepForFlow(step - 1, -1));
    });
  });

  dialog.querySelectorAll('[data-aiu-wizard-next]').forEach((el) => {
    el.addEventListener('click', async () => {
      if (!canLeaveStep(step)) {
        return;
      }
      if (step === 3 && state.mode === 'ownkeys') {
        const btn = el instanceof HTMLButtonElement ? el : null;
        if (btn) {
          btn.disabled = true;
        }
        const ensured = await ensureWizardProvider();
        if (btn) {
          btn.disabled = false;
        }
        if (!ensured) {
          return;
        }
        if (apiKeyInput instanceof HTMLInputElement) {
          apiKeyInput.value = '';
        }
        state.connectionOk = false;
        if (testStatusEl) {
          testStatusEl.textContent = '';
          testStatusEl.classList.remove('is-ok', 'text-danger', 'text-success');
        }
        syncApiPanel();
      }
      const next = adjustStepForFlow(step + 1, 1);
      maxReachedStep = Math.max(maxReachedStep, next);
      setStep(next);
    });
  });

  tabs.forEach((tab) => {
    tab.addEventListener('click', () => {
      const target = Number.parseInt(tab.dataset.aiuWizardGoto ?? '', 10);
      if (!Number.isFinite(target)) {
        return;
      }
      if (target > maxReachedStep) {
        return;
      }
      setStep(target);
    });
  });

  qs(dialog, '#aiu-wizard-test-api')?.addEventListener('click', () => {
    runApiTest();
  });

  if (apiKeyInput instanceof HTMLInputElement) {
    apiKeyInput.addEventListener('input', () => {
      state.connectionOk = false;
      if (testStatusEl) {
        testStatusEl.textContent = '';
        testStatusEl.classList.remove('is-ok', 'text-danger', 'text-success');
      }
      syncApiPanel();
    });
  }

  if (apiKeyToggle instanceof HTMLButtonElement && apiKeyInput instanceof HTMLInputElement) {
    apiKeyToggle.addEventListener('click', () => {
      const show = apiKeyInput.type === 'password';
      apiKeyInput.type = show ? 'text' : 'password';
      apiKeyToggle.setAttribute('aria-pressed', show ? 'true' : 'false');
    });
  }

  /**
   * @param {HTMLButtonElement | null} btn
   */
  async function finishWizard(btn) {
    const href = dialog.dataset.dashboardUri;
    if (!finalizeUrl) {
      closeWizard();
      if (href) {
        top.window.location.href = href;
      }
      return;
    }

    /** @type {Record<string, unknown>} */
    const payload = {
      mode: state.mode,
      providerUid: state.providerUid,
      providerCatalog: state.providerCatalogId,
      mcp: state.mcp,
    };
    readBrandContextFromDom();
    if (!validateBrandContextStep(true)) {
      return;
    }
    payload.brandContext = { ...state.brandContext };
    const pageId = parseInt(dialog.dataset.aiuPageId || '0', 10);
    if (Number.isFinite(pageId) && pageId > 0) {
      payload.id = pageId;
    }
    if (state.mode === 'ownkeys') {
      payload.modelId = state.modelId;
      const key = draftApiKey();
      if (key !== '') {
        payload.apiKey = key;
      }
    }
    if (extensionsAvailable) {
      readExtensionsFromDom();
      payload.extensionToggles = { ...state.extensionToggles };
    }

    if (btn instanceof HTMLButtonElement) {
      btn.disabled = true;
    }
    try {
      const response = await new AjaxRequest(finalizeUrl).post(payload);
      const json = await response.resolve();
      if (json.ok) {
        Notification.success(ui.finalizeOk || ll('wizard.notify.finalizeOk', 'Setup saved'), '');
        closeWizard();
        if (href) {
          window.location.href = href;
        }
        return;
      }
      Notification.error(
        ui.finalizeFail || ll('wizard.notify.finalizeFail', 'Could not save setup'),
        json.message ?? '',
      );
    } catch (err) {
      Notification.error(
        ui.finalizeFail || ll('wizard.notify.finalizeFail', 'Could not save setup'),
        String(err),
      );
    } finally {
      if (btn instanceof HTMLButtonElement) {
        btn.disabled = false;
      }
    }
  }

  dialog.querySelectorAll('[data-aiu-wizard-goto-dashboard]').forEach((el) => {
    el.addEventListener('click', (evt) => {
      finishWizard(evt.currentTarget instanceof HTMLButtonElement ? evt.currentTarget : null);
    });
  });

  if (mcpInput instanceof HTMLInputElement) {
    mcpInput.addEventListener('change', () => {
      state.mcp = mcpInput.checked;
      syncMcpCard();
    });
  }

  tonePillsMount?.querySelectorAll('[data-aiu-wizard-tone]').forEach((el) => {
    if (!(el instanceof HTMLButtonElement)) {
      return;
    }
    el.addEventListener('click', () => {
      const tag = el.getAttribute('data-aiu-wizard-tone') || '';
      if (tag === '') {
        return;
      }
      const idx = state.brandContext.toneTags.indexOf(tag);
      if (idx >= 0) {
        state.brandContext.toneTags.splice(idx, 1);
      } else if (state.brandContext.toneTags.length < 5) {
        state.brandContext.toneTags.push(tag);
      }
      syncTonePills();
    });
  });

  if (extensionsRoot) {
    extensionsRoot.addEventListener('change', (evt) => {
      const target = evt.target;
      if (
        target instanceof HTMLInputElement &&
        target.type === 'checkbox' &&
        target.dataset.aiuWizardToggleField
      ) {
        readExtensionsFromDom();
      }
    });
  }

  wireModeCards();
  syncModeCards();

  if (dialog.dataset.wizardAutoOpen === '1' && dialog.dataset.aiuWizardAutoOpened !== '1') {
    dialog.dataset.aiuWizardAutoOpened = '1';
    openWizard({
      resumeStep: parseInt(dialog.dataset.wizardResumeStep || '1', 10),
      maxReachedStep: parseInt(dialog.dataset.wizardMaxStep || '1', 10),
    });
  }
}

function initModuleAutocomplete() {
  const shell = document.querySelector('.aiu-module-shell');
  if (shell instanceof HTMLElement) {
    observeBrowserAutocomplete(shell);
  }
}

function init() {
  initModuleAutocomplete();
  initSetupWizard();
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init, { once: true });
} else {
  init();
}
