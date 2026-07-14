import Modal from '@typo3/backend/modal.js';
import Severity from '@typo3/backend/severity.js';
import { bindFilterSearchInput, resetFilterSearchInput, observeBrowserAutocomplete } from '@nitsan/nst3af/disable-browser-autocomplete.js';

const root = document.querySelector('[data-aiu-prompts-root]');

if (root) {
  observeBrowserAutocomplete(root);
  initAiPrompts(root);
}

function initAiPrompts(scope) {
  initOverviewFilters(scope);
  initDetailFilters(scope);
  initDrawer(scope);
  initPromptTextModal(scope);
  initDeleteConfirm(scope);
}

function initPromptTextModal(scope) {
  scope.querySelectorAll('[data-aiu-prompt-view-text]').forEach((button) => {
    if (!(button instanceof HTMLButtonElement)) {
      return;
    }
    button.addEventListener('click', () => {
      const promptTitle = button.getAttribute('data-prompt-title') || 'Prompt text';
      const promptText = button.getAttribute('data-prompt-text') || '';
      Modal.advanced({
        title: promptTitle,
        content: promptText,
        severity: Severity.notice,
        buttons: [
          {
            text: TYPO3?.lang?.['button.close'] || 'Close',
            btnClass: 'btn-default',
            active: true,
            trigger: () => Modal.dismiss(),
          },
        ],
      });
    });
  });
}

function initOverviewFilters(scope) {
  if (scope.getAttribute('data-prompts-mode') !== 'overview') {
    return;
  }

  const searchField = scope.querySelector('[data-aiu-prompt-overview-search]');
  const extensionField = scope.querySelector('[data-aiu-prompt-overview-extension]');
  const resetButton = scope.querySelector('[data-aiu-prompt-overview-reset]');
  const emptyState = scope.querySelector('[data-aiu-overview-empty]');
  const cards = scope.querySelectorAll('[data-aiu-overview-category-card]');

  if (!(searchField instanceof HTMLInputElement) || !(extensionField instanceof HTMLSelectElement) || cards.length === 0) {
    return;
  }

  const applyOverviewFilters = () => {
    const search = searchField.value.trim().toLowerCase();
    const extension = extensionField.value;
    let visibleCount = 0;

    cards.forEach((card) => {
      if (!(card instanceof HTMLElement)) {
        return;
      }
      const cardProviderExtension = card.getAttribute('data-category-provider-extension') || '';
      const cardSearch = (card.getAttribute('data-category-search') || '').toLowerCase();
      const extensionMatches = extension === 'all' || extension === '' || cardProviderExtension === extension;
      const searchMatches = search === '' || cardSearch.includes(search);
      const visible = extensionMatches && searchMatches;
      card.hidden = !visible;
      if (visible) {
        visibleCount += 1;
      }
    });

    if (emptyState instanceof HTMLElement) {
      emptyState.hidden = visibleCount > 0;
    }
  };

  const resetOverviewFilters = () => {
    resetFilterSearchInput(searchField);
    extensionField.value = 'all';
    applyOverviewFilters();
  };

  bindFilterSearchInput(searchField, () => applyOverviewFilters());
  extensionField.addEventListener('change', applyOverviewFilters);
  if (resetButton instanceof HTMLButtonElement) {
    resetButton.addEventListener('click', resetOverviewFilters);
  }

  applyOverviewFilters();
}

function initDetailFilters(scope) {
  if (scope.getAttribute('data-prompts-mode') !== 'detail') {
    return;
  }

  const searchField = scope.querySelector('[data-aiu-prompt-detail-search]');
  const resetButton = scope.querySelector('[data-aiu-prompt-detail-reset]');
  const rows = scope.querySelectorAll('[data-aiu-prompt-detail-row]');
  const emptyFilterRow = scope.querySelector('[data-aiu-prompt-detail-empty-filter]');
  const emptyDataRow = scope.querySelector('[data-aiu-prompt-detail-empty-data]');
  const countEl = scope.querySelector('[data-aiu-prompt-detail-count]');

  if (!(searchField instanceof HTMLInputElement)) {
    return;
  }

  const applyDetailFilters = () => {
    const search = searchField.value.trim().toLowerCase();
    let visibleCount = 0;

    rows.forEach((row) => {
      if (!(row instanceof HTMLElement)) {
        return;
      }
      const rowSearch = (row.getAttribute('data-row-search') || '').toLowerCase();
      const visible = search === '' || rowSearch.includes(search);
      row.hidden = !visible;
      if (visible) {
        visibleCount += 1;
      }
    });

    if (emptyFilterRow instanceof HTMLElement) {
      emptyFilterRow.hidden = visibleCount > 0 || rows.length === 0;
    }
    if (emptyDataRow instanceof HTMLElement) {
      emptyDataRow.hidden = rows.length > 0;
    }
    if (countEl instanceof HTMLElement) {
      countEl.textContent = String(visibleCount);
    }
  };

  const resetDetailFilters = () => {
    resetFilterSearchInput(searchField);
    applyDetailFilters();
  };

  bindFilterSearchInput(searchField, () => applyDetailFilters());
  if (resetButton instanceof HTMLButtonElement) {
    resetButton.addEventListener('click', resetDetailFilters);
  }

  applyDetailFilters();
}

function parseCatalog(scope) {
  const raw = scope.getAttribute('data-prompts-catalog-json') || '{}';
  try {
    return JSON.parse(raw);
  } catch {
    return { available: false, scopes: [], typesByScope: {}, variablesByType: {}, defaultTextByType: {}, byCategory: {} };
  }
}

function resolveScopeForCategory(categoryId) {
  if (typeof categoryId !== 'string' || categoryId === '') {
    return '';
  }
  if (categoryId === 'rte') {
    // RTE category contains multiple group scopes (optimize/adjust/tone/translate).
    // Let caller pick from catalog scopes instead of forcing "rte".
    return '';
  }
  if (categoryId.startsWith('t3aa_')) {
    return categoryId.slice(5);
  }
  return categoryId;
}

function catalogForCategory(fullCatalog, categoryId) {
  if (!fullCatalog || typeof fullCatalog !== 'object') {
    return { available: false, scopes: [], typesByScope: {}, variablesByType: {}, defaultTextByType: {} };
  }
  const slice = fullCatalog.byCategory && categoryId !== '' ? fullCatalog.byCategory[categoryId] : null;
  if (slice) {
    return {
      ...fullCatalog,
      scopes: slice.scopes || [],
      typesByScope: slice.typesByScope || {},
      variablesByType: slice.variablesByType || {},
      defaultTextByType: slice.defaultTextByType || {},
    };
  }
  return fullCatalog;
}

function parseRows(scope) {
  const raw = scope.getAttribute('data-prompts-rows-json') || '[]';
  try {
    const rows = JSON.parse(raw);
    return Array.isArray(rows) ? rows : [];
  } catch {
    return [];
  }
}

function parseValidationMessages(scope) {
  const raw = scope.getAttribute('data-prompts-validation-messages') || '{}';
  try {
    return JSON.parse(raw);
  } catch {
    return {};
  }
}

function parseBrandContext(scope) {
  const raw = scope.getAttribute('data-prompts-brand-context-json') || '{}';
  try {
    const data = JSON.parse(raw);
    return data && typeof data === 'object' ? data : {};
  } catch {
    return {};
  }
}

function textContainsVariable(promptText, variable) {
  return promptText.includes(`[${variable}]`);
}

/**
 * @returns {{ code: string, missing?: string[] } | null}
 */
function validatePromptPayload(catalog, category, scope, promptType, promptText) {
  if (category === 'sidebar' || catalog.available !== true) {
    return null;
  }

  if (scope === '' || promptType === '') {
    return { code: 'missing_scope_type' };
  }

  const typesForScope = (catalog.typesByScope[scope] || []).map((entry) => entry.id);
  const allTypes = Object.values(catalog.typesByScope || {})
    .flat()
    .map((entry) => entry.id);

  if (!allTypes.includes(promptType)) {
    return { code: 'invalid_prompt_type' };
  }

  if (!typesForScope.includes(promptType)) {
    return { code: 'scope_mismatch' };
  }

  const requiredVariables = catalog.variablesByType[promptType] || [];
  const missing = requiredVariables.filter((variable) => !textContainsVariable(promptText, variable));
  if (missing.length > 0) {
    return { code: 'missing_required_variables', missing };
  }

  return null;
}

function formatValidationMessage(messages, result, catalog, promptType) {
  if (!result) {
    return '';
  }

  const template = messages[result.code] || messages.missing_required_variables || 'Validation failed.';
  if (result.code === 'missing_required_variables') {
    const requiredVariables = result.missing || catalog.variablesByType[promptType] || [];
    const placeholders = requiredVariables.map((variable) => `[${variable}]`).join(', ');
    return template.replace('%s', placeholders);
  }

  return template;
}

function initDrawer(scope) {
  const drawer = scope.querySelector('[data-aiu-prompt-drawer]');
  const overlay = scope.querySelector('[data-aiu-prompt-drawer-overlay]');
  const form = scope.querySelector('[data-aiu-prompt-form]');
  if (!(drawer instanceof HTMLElement) || !(overlay instanceof HTMLElement) || !(form instanceof HTMLFormElement)) {
    return;
  }

  const catalog = parseCatalog(scope);
  const validationMessages = parseValidationMessages(scope);
  const brandContextByCategory = parseBrandContext(scope);
  const brandContextHintTemplate = scope.getAttribute('data-prompts-brand-context-hint')
    || 'Profile used for %s — brand details are applied automatically at runtime.';
  const promptRows = parseRows(scope);
  const promptRowsByUid = new Map(
    promptRows.map((row) => [String(row.uid ?? ''), row]),
  );
  const isOverviewMode = scope.getAttribute('data-prompts-mode') === 'overview';
  const pageCategory = scope.getAttribute('data-prompts-category') || 'seo';
  const catalogFields = drawer.querySelector('[data-aiu-prompt-catalog-fields]');
  const hasCatalogFields = catalogFields instanceof HTMLElement;

  const titleEl = drawer.querySelector('[data-aiu-prompt-drawer-title]');
  const uidField = form.querySelector('[data-aiu-prompt-field-uid]');
  const categoryField = form.querySelector('[data-aiu-prompt-field-category]');
  const titleField = form.querySelector('[data-aiu-prompt-field-title]');
  const scopeField = form.querySelector('[data-aiu-prompt-field-scope]');
  const typeField = form.querySelector('[data-aiu-prompt-field-type]');
  const textField = form.querySelector('[data-aiu-prompt-field-text]');
  const typeHintsWrap = drawer.querySelector('[data-aiu-prompt-type-hints-wrap]');
  const requiredVarsSection = drawer.querySelector('[data-aiu-prompt-required-vars-section]');
  const requiredVarsEl = drawer.querySelector('[data-aiu-prompt-required-vars]');
  const defaultInfoPanel = drawer.querySelector('[data-aiu-prompt-default-info]');
  const defaultTextEl = drawer.querySelector('[data-aiu-prompt-default-text]');
  const contextInfoPanel = drawer.querySelector('[data-aiu-prompt-context-info]');
  const contextHintEl = drawer.querySelector('[data-aiu-prompt-context-hint]');
  const contextProfileEl = drawer.querySelector('[data-aiu-prompt-context-profile]');
  const createUri = scope.getAttribute('data-prompts-create-uri') || '';
  const updateUri = scope.getAttribute('data-prompts-update-uri') || '';
  const validationErrorEl = form.querySelector('[data-aiu-prompt-validation-error]');

  const clearValidationError = () => {
    if (!(validationErrorEl instanceof HTMLElement)) {
      return;
    }
    validationErrorEl.hidden = true;
    validationErrorEl.textContent = '';
  };

  const showValidationError = (message) => {
    if (!(validationErrorEl instanceof HTMLElement) || message === '') {
      return;
    }
    validationErrorEl.textContent = message;
    validationErrorEl.hidden = false;
    validationErrorEl.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  };

  const getSelectedCategory = () => {
    if (categoryField instanceof HTMLSelectElement || categoryField instanceof HTMLInputElement) {
      return categoryField.value.trim();
    }

    return pageCategory;
  };

  const getActiveCategory = () => {
    const selected = getSelectedCategory();

    return selected !== '' ? selected : pageCategory;
  };

  const isCreateMode = () => uidField instanceof HTMLInputElement && uidField.value === '0';

  const syncDefaultInfoPanel = (promptType) => {
    const activeCatalog = catalogForCategory(catalog, getActiveCategory());
    const defaultText = (activeCatalog.defaultTextByType && activeCatalog.defaultTextByType[promptType]) || '';
    const showPanel = isCreateMode() && defaultText.trim() !== '';

    if (defaultInfoPanel instanceof HTMLElement) {
      defaultInfoPanel.hidden = !showPanel;
    }
    if (defaultTextEl instanceof HTMLElement) {
      defaultTextEl.textContent = showPanel ? defaultText : '';
    }
    if (textField instanceof HTMLTextAreaElement) {
      textField.placeholder = '';
    }
  };

  const syncBrandContextInfo = () => {
    const categoryId = getSelectedCategory();
    if (isOverviewMode && categoryId === '') {
      if (contextInfoPanel instanceof HTMLElement) {
        contextInfoPanel.hidden = true;
      }
      if (contextHintEl instanceof HTMLElement) {
        contextHintEl.textContent = '';
      }
      if (contextProfileEl instanceof HTMLElement) {
        contextProfileEl.textContent = '';
      }
      return;
    }

    const promptScope = scopeField instanceof HTMLSelectElement ? scopeField.value.trim() : '';
    const activeCategory = getActiveCategory();
    const contextKey = promptScope !== '' ? promptScope : activeCategory;
    if (contextKey === '') {
      if (contextInfoPanel instanceof HTMLElement) {
        contextInfoPanel.hidden = true;
      }
      if (contextHintEl instanceof HTMLElement) {
        contextHintEl.textContent = '';
      }
      if (contextProfileEl instanceof HTMLElement) {
        contextProfileEl.textContent = '';
      }
      return;
    }

    const entry = brandContextByCategory[contextKey];
    const showPanel = entry?.available === true;

    if (contextInfoPanel instanceof HTMLElement) {
      contextInfoPanel.hidden = !showPanel;
    }
    if (!showPanel) {
      if (contextHintEl instanceof HTMLElement) {
        contextHintEl.textContent = '';
      }
      if (contextProfileEl instanceof HTMLElement) {
        contextProfileEl.textContent = '';
      }
      return;
    }

    if (contextProfileEl instanceof HTMLElement) {
      contextProfileEl.textContent = entry.profileName || '';
    }
    if (contextHintEl instanceof HTMLElement) {
      contextHintEl.textContent = brandContextHintTemplate.replace('%s', entry.featureLabel || contextKey);
    }
  };

  const collectFormPayload = () => {
    const category = getActiveCategory();
    const scope = scopeField instanceof HTMLSelectElement ? scopeField.value.trim() : '';
    const promptType = typeField instanceof HTMLSelectElement ? typeField.value.trim() : '';
    const promptText = textField instanceof HTMLTextAreaElement ? textField.value : '';

    return { category, scope, promptType, promptText };
  };

  const setCatalogFieldsVisibility = (visible) => {
    if (!hasCatalogFields) {
      return;
    }
    catalogFields.hidden = !visible;
    if (scopeField instanceof HTMLSelectElement) {
      scopeField.required = visible;
      if (!visible) {
        scopeField.value = '';
      }
    }
    if (typeField instanceof HTMLSelectElement) {
      typeField.required = visible;
      if (!visible) {
        typeField.value = '';
      }
    }
    if (!visible) {
      updateTypeHints('');
    }
  };

  const syncCatalogForCategory = (categoryId) => {
    const showCatalog = catalog.available === true && categoryId !== '' && categoryId !== 'sidebar';
    setCatalogFieldsVisibility(showCatalog);
    if (showCatalog) {
      populateScopeOptions(categoryId);
      populateTypeOptions(categoryId);
      updateTypeHints('');
    }
    syncBrandContextInfo();
  };

  const populateScopeOptions = (categoryId = '', selectedScope = '') => {
    if (!(scopeField instanceof HTMLSelectElement)) {
      return;
    }
    const activeCategory = categoryId || getActiveCategory();
    const activeCatalog = catalogForCategory(catalog, activeCategory);
    const scopeToSelect = selectedScope || resolveScopeForCategory(activeCategory);
    const placeholder = scopeField.querySelector('option[value=""]');
    scopeField.innerHTML = '';
    if (placeholder instanceof HTMLOptionElement) {
      scopeField.appendChild(placeholder);
    } else {
      const empty = document.createElement('option');
      empty.value = '';
      empty.textContent = 'Select scope…';
      scopeField.appendChild(empty);
    }
    (activeCatalog.scopes || []).forEach((entry) => {
      const option = document.createElement('option');
      option.value = entry.id;
      option.textContent = entry.label || entry.id;
      if (entry.id === scopeToSelect) {
        option.selected = true;
      }
      scopeField.appendChild(option);
    });
    if (scopeToSelect !== '' && scopeField.value !== scopeToSelect) {
      scopeField.value = scopeToSelect;
    }
    if (scopeField.value === '' && scopeField.options.length > 1) {
      scopeField.selectedIndex = 1;
    }
  };

  const populateTypeOptions = (categoryId = '', scopeId = '', selectedType = '') => {
    if (!(typeField instanceof HTMLSelectElement)) {
      return;
    }
    const activeCategory = categoryId || getActiveCategory();
    const activeCatalog = catalogForCategory(catalog, activeCategory);
    const fallbackScope = scopeField instanceof HTMLSelectElement
      ? scopeField.value
      : resolveScopeForCategory(activeCategory);
    const scope = scopeId || fallbackScope;
    const placeholder = typeField.querySelector('option[value=""]');
    typeField.innerHTML = '';
    if (placeholder instanceof HTMLOptionElement) {
      typeField.appendChild(placeholder);
    } else {
      const empty = document.createElement('option');
      empty.value = '';
      empty.textContent = 'Select prompt type…';
      typeField.appendChild(empty);
    }
    let types = (activeCatalog.typesByScope && activeCatalog.typesByScope[scope]) || [];
    if (types.length === 0) {
      const firstScope = (activeCatalog.scopes || [])[0]?.id || '';
      if (firstScope !== '') {
        types = (activeCatalog.typesByScope && activeCatalog.typesByScope[firstScope]) || [];
      }
    }
    types.forEach((entry) => {
      const option = document.createElement('option');
      option.value = entry.id;
      option.textContent = entry.label || entry.id;
      if (entry.id === selectedType) {
        option.selected = true;
      }
      typeField.appendChild(option);
    });
  };

  const updateTypeHints = (promptType) => {
    const activeCatalog = catalogForCategory(catalog, getActiveCategory());
    const variables = (activeCatalog.variablesByType && activeCatalog.variablesByType[promptType]) || [];
    const hasVariables = variables.length > 0;

    syncDefaultInfoPanel(promptType);

    if (!(typeHintsWrap instanceof HTMLElement)) {
      return;
    }

    if (!promptType || !hasVariables) {
      typeHintsWrap.hidden = true;
      return;
    }

    typeHintsWrap.hidden = false;

    if (requiredVarsSection instanceof HTMLElement && requiredVarsEl instanceof HTMLElement) {
      requiredVarsEl.innerHTML = '';
      requiredVarsSection.hidden = false;
      variables.forEach((variable) => {
        const chip = document.createElement('span');
        chip.textContent = `[${variable}]`;
        requiredVarsEl.appendChild(chip);
      });
    }
  };

  if (hasCatalogFields && catalog.available === true) {
    populateScopeOptions();
    if (scopeField instanceof HTMLSelectElement) {
      scopeField.addEventListener('change', () => {
        populateTypeOptions(getActiveCategory(), scopeField.value);
        updateTypeHints('');
        syncBrandContextInfo();
      });
    }
    if (typeField instanceof HTMLSelectElement) {
      typeField.addEventListener('change', () => {
        updateTypeHints(typeField.value);
      });
    }
  }

  if (isOverviewMode && categoryField instanceof HTMLSelectElement) {
    categoryField.addEventListener('change', () => {
      syncCatalogForCategory(categoryField.value);
    });
  }

  if (hasCatalogFields && !isOverviewMode && pageCategory !== 'sidebar') {
    setCatalogFieldsVisibility(true);
  }

  if (hasCatalogFields && isOverviewMode) {
    setCatalogFieldsVisibility(false);
  }

  const openDrawer = () => {
    drawer.classList.remove('is-closing');
    overlay.classList.remove('is-closing');
    drawer.hidden = false;
    overlay.hidden = false;
  };
  const closeDrawer = () => {
    if (drawer.classList.contains('is-closing')) {
      return;
    }
    drawer.classList.add('is-closing');
    overlay.classList.add('is-closing');

    const finish = () => {
      if (!drawer.classList.contains('is-closing')) {
        return;
      }
      drawer.classList.remove('is-closing');
      overlay.classList.remove('is-closing');
      drawer.hidden = true;
      overlay.hidden = true;
      clearValidationError();
    };
    let done = false;
    const onEnd = () => {
      if (done) {
        return;
      }
      done = true;
      drawer.removeEventListener('animationend', onEnd);
      finish();
    };
    drawer.addEventListener('animationend', onEnd);
    window.setTimeout(onEnd, 350);
  };

  const setModeCreate = () => {
    clearValidationError();
    form.action = createUri;
    if (uidField instanceof HTMLInputElement) {
      uidField.value = '0';
    }
    if (titleField instanceof HTMLInputElement) {
      titleField.value = '';
    }
    if (textField instanceof HTMLTextAreaElement) {
      textField.value = '';
      textField.placeholder = '';
    }
    if (defaultInfoPanel instanceof HTMLElement) {
      defaultInfoPanel.hidden = true;
    }
    if (defaultTextEl instanceof HTMLElement) {
      defaultTextEl.textContent = '';
    }
    if (isOverviewMode) {
      if (categoryField instanceof HTMLSelectElement) {
        categoryField.value = '';
      }
      syncCatalogForCategory('');
    } else if (hasCatalogFields && pageCategory !== 'sidebar' && catalog.available === true) {
      populateScopeOptions(pageCategory, resolveScopeForCategory(pageCategory));
      populateTypeOptions(pageCategory, resolveScopeForCategory(pageCategory));
      updateTypeHints('');
    } else {
      if (scopeField instanceof HTMLSelectElement) {
        scopeField.value = '';
      }
      if (typeField instanceof HTMLSelectElement) {
        typeField.value = '';
      }
    }
    syncBrandContextInfo();
    if (titleEl instanceof HTMLElement) {
      titleEl.textContent = 'New Prompt';
    }
  };

  const setModeEdit = (button) => {
    clearValidationError();
    form.action = updateUri;
    const uid = button.getAttribute('data-prompt-uid') || '0';
    const row = promptRowsByUid.get(uid) || null;
    const rowScope = row ? String(row.scope ?? '') : (button.getAttribute('data-prompt-scope') || '');
    const rowType = row ? String(row.promptType ?? '') : (button.getAttribute('data-prompt-type') || '');
    const rowTitle = row ? String(row.promptTitle ?? '') : (button.getAttribute('data-prompt-title') || '');
    const rowText = row ? String(row.promptText ?? '') : (button.getAttribute('data-prompt-text') || '');

    if (uidField instanceof HTMLInputElement) {
      uidField.value = uid;
    }
    if (titleField instanceof HTMLInputElement) {
      titleField.value = rowTitle;
    }
    if (textField instanceof HTMLTextAreaElement) {
      textField.value = rowText;
      textField.placeholder = '';
    }
    if (defaultInfoPanel instanceof HTMLElement) {
      defaultInfoPanel.hidden = true;
    }
    if (defaultTextEl instanceof HTMLElement) {
      defaultTextEl.textContent = '';
    }
    if (hasCatalogFields && getActiveCategory() !== 'sidebar' && catalog.available === true) {
      populateScopeOptions(getActiveCategory(), rowScope);
      populateTypeOptions(getActiveCategory(), rowScope, rowType);
      updateTypeHints(rowType);
      setCatalogFieldsVisibility(true);
    } else if (scopeField instanceof HTMLSelectElement) {
      scopeField.value = rowScope;
    } else if (typeField instanceof HTMLSelectElement) {
      typeField.value = rowType;
    }
    syncBrandContextInfo();
    if (titleEl instanceof HTMLElement) {
      titleEl.textContent = 'Edit Prompt';
    }
  };

  scope.querySelectorAll('[data-aiu-prompt-create]').forEach((button) => {
    button.addEventListener('click', () => {
      setModeCreate();
      openDrawer();
    });
  });

  scope.querySelectorAll('[data-aiu-prompt-edit]').forEach((button) => {
    button.addEventListener('click', () => {
      setModeEdit(button);
      openDrawer();
    });
  });

  scope.querySelectorAll('[data-aiu-prompt-close]').forEach((button) => {
    button.addEventListener('click', closeDrawer);
  });
  overlay.addEventListener('click', closeDrawer);

  [scopeField, typeField, textField, titleField, categoryField].forEach((field) => {
    if (field instanceof HTMLElement) {
      field.addEventListener('input', clearValidationError);
      field.addEventListener('change', clearValidationError);
    }
  });

  form.addEventListener('submit', (event) => {
    const payload = collectFormPayload();
    const activeCatalog = catalogForCategory(catalog, payload.category);
    const validationResult = validatePromptPayload(
      activeCatalog,
      payload.category,
      payload.scope,
      payload.promptType,
      payload.promptText,
    );

    if (validationResult !== null) {
      event.preventDefault();
      showValidationError(formatValidationMessage(validationMessages, validationResult, activeCatalog, payload.promptType));
      openDrawer();
    }
  });

  const serverValidationError = scope.getAttribute('data-prompts-validation-error') || '';
  if (serverValidationError !== '') {
    const serverPromptType = scope.getAttribute('data-prompts-validation-prompt-type') || '';
    const serverCategory = scope.getAttribute('data-prompts-category') || pageCategory;
    const serverCatalog = catalogForCategory(catalog, serverCategory);
    const serverResult = { code: serverValidationError };
    if (serverValidationError === 'missing_required_variables' && serverPromptType !== '') {
      serverResult.missing = serverCatalog.variablesByType[serverPromptType] || [];
    }
    showValidationError(formatValidationMessage(
      validationMessages,
      serverResult,
      serverCatalog,
      serverPromptType,
    ));
    openDrawer();
  }
}

function initDeleteConfirm(scope) {
  scope.querySelectorAll('[data-aiu-prompt-delete]').forEach((button) => {
    button.addEventListener('click', () => {
      const form = button.closest('form');
      if (!(form instanceof HTMLFormElement)) {
        return;
      }
      Modal.confirm('Delete prompt', 'Do you want to delete this prompt?', Severity.error, [
        {
          text: TYPO3?.lang?.['button.cancel'] || 'Cancel',
          btnClass: 'btn-default',
          active: true,
          trigger: () => Modal.dismiss(),
        },
        {
          text: 'Delete',
          btnClass: 'btn-danger',
          trigger: () => {
            Modal.dismiss();
            form.submit();
          },
        },
      ]);
    });
  });
}
