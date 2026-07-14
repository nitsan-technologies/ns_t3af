import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Modal from '@typo3/backend/modal.js';
import Notification from '@typo3/backend/notification.js';
import Severity from '@typo3/backend/severity.js';
import { bindFilterSearchInput, resetFilterSearchInput, observeBrowserAutocomplete } from './disable-browser-autocomplete.js';
import { initPeriodDropdownForms } from './period-filter.js';

/**
 * Re-init after fetch-based partial refresh (period dropdown, presets).
 *
 * @param {Element} root
 */
function reinitMcpToolsRoot(root) {
  initMcpToolsPage(root);
  initPeriodDropdownForms(root);
}

if (typeof window !== 'undefined') {
  window.aiuReinitMcpToolsRoot = reinitMcpToolsRoot;
}

function boot() {
  document.querySelectorAll('[data-mcp-tools]').forEach((root) => {
    observeBrowserAutocomplete(root);
    initMcpToolsPage(root);
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', boot, { once: true });
} else {
  boot();
}

function lang(key, fallback, ...args) {
  let value = typeof TYPO3 !== 'undefined' && TYPO3.lang ? TYPO3.lang[key] : undefined;
  if (value === undefined) {
    value = fallback ?? key;
  }
  return args.reduce((acc, arg) => acc.replace('%s', String(arg)), value);
}

const PROMPT_NAME_PATTERN = /^[a-z][a-z0-9_]*$/;

function resolveErrorMessage(error, fallback) {
  if (error instanceof Error && error.message) {
    return error.message;
  }
  if (typeof error === 'string' && error !== '') {
    return error;
  }
  return fallback;
}

function decodeHtmlEntities(text) {
  const el = document.createElement('textarea');
  el.innerHTML = text;
  return el.value;
}

function parsePromptArgumentsJson(raw) {
  const normalized = decodeHtmlEntities((raw ?? '').trim().replace(/^\uFEFF/, ''));
  if (normalized === '') {
    return [];
  }

  const parsed = JSON.parse(normalized);
  if (!Array.isArray(parsed)) {
    throw new Error('Arguments must be a JSON array');
  }

  parsed.forEach((argument, index) => {
    const argName = String(argument?.name ?? '').trim();
    if (!PROMPT_NAME_PATTERN.test(argName)) {
      throw new Error(
        lang(
          'mcpTools.js.promptArgumentNameInvalid',
          'Argument #%s must have a snake_case name (e.g. page_id).',
          String(index + 1),
        ),
      );
    }
  });

  return parsed;
}

function persistMcpToolsSidebar(section) {
  sessionStorage.setItem('aiuMcpToolsSidebar', section);
}

function persistMcpToolsFlash(message, severity = 'success') {
  sessionStorage.setItem('aiuMcpToolsFlash', JSON.stringify({ message, severity }));
}

function restoreMcpToolsSessionState(root) {
  const storedSidebar = sessionStorage.getItem('aiuMcpToolsSidebar');
  if (storedSidebar) {
    sessionStorage.removeItem('aiuMcpToolsSidebar');
    root.querySelector(`[data-mcp-tools-sidebar="${storedSidebar}"]`)?.click();
  }

  const storedFlash = sessionStorage.getItem('aiuMcpToolsFlash');
  if (!storedFlash) {
    return;
  }

  sessionStorage.removeItem('aiuMcpToolsFlash');
  try {
    const { message, severity } = JSON.parse(storedFlash);
    if (severity === 'error') {
      Notification.error(message);
      return;
    }
    if (severity === 'warning') {
      Notification.warning(message);
      return;
    }
    if (severity === 'info') {
      Notification.info(message);
      return;
    }
    Notification.success(message);
  } catch {
    Notification.success(storedFlash);
  }
}

function parsePlaygroundConfig(root) {
  const raw = root.getAttribute('data-mcp-playground-config') ?? '{}';
  try {
    return JSON.parse(raw);
  } catch {
    return { toolCount: 0, categories: [] };
  }
}

function initMcpToolsPage(root) {
  const playgroundConfig = parsePlaygroundConfig(root);

  initSegmentTabs(root);
  initSidebarNav(root);
  initSearch(root);
  initCategoryFilter(root);
  initExtensionFilter(root);
  initExtensionCards(root);
  initToolPanels(root);
  initToolDrawer(root, playgroundConfig);
  initDiscover(root);
  initCodeConfig(root);
  initTableFilter(root);
  initTableActions(root);
  initCopySnippet(root);
  initPlayground(root, playgroundConfig);
  initPrompts(root);
  initPromptToggles(root);
  initCustomToolModal(root);
  initCustomToolList(root);
  initSkillHub(root);
  restoreMcpToolsSessionState(root);
}

function initSegmentTabs(root) {
  const tabButtons = root.querySelectorAll('[data-mcp-tools-tab]');
  const panels = root.querySelectorAll('[data-mcp-tools-panel]');

  tabButtons.forEach((button) => {
    button.addEventListener('click', () => {
      const target = button.getAttribute('data-mcp-tools-tab');
      if (!target) {
        return;
      }

      tabButtons.forEach((btn) => {
        const isActive = btn === button;
        btn.classList.toggle('active', isActive);
        btn.classList.toggle('is-active', isActive);
        btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
      });

      panels.forEach((panel) => {
        const match = panel.getAttribute('data-mcp-tools-panel') === target;
        panel.classList.toggle('is-active', match);
        panel.hidden = !match;
      });

      resetSearchFilters(root);
    });
  });
}

function initSidebarNav(root) {
  const buttons = root.querySelectorAll('[data-mcp-tools-sidebar]');
  const panels = root.querySelectorAll('[data-mcp-tools-sidebar-panel]');

  buttons.forEach((button) => {
    button.addEventListener('click', () => {
      const target = button.getAttribute('data-mcp-tools-sidebar');
      if (!target) {
        return;
      }

      buttons.forEach((btn) => {
        const isActive = btn === button;
        btn.classList.toggle('active', isActive);
        btn.classList.toggle('is-active', isActive);
        btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
      });

      panels.forEach((panel) => {
        const match = panel.getAttribute('data-mcp-tools-sidebar-panel') === target;
        panel.classList.toggle('is-active', match);
        panel.hidden = !match;
      });

      resetSearchFilters(root);
    });
  });
}

function resetSearchFilters(root) {
  const search = root.querySelector('[data-mcp-tools-search]');
  if (search instanceof HTMLInputElement) {
    resetFilterSearchInput(search);
    filterSearch(root, '');
  }

  resetExtensionFilter(root);

  const tableFilter = root.querySelector('[data-mcp-tools-table-filter]');
  if (tableFilter instanceof HTMLInputElement) {
    resetFilterSearchInput(tableFilter);
    filterTableRows(root, '');
  }
}

function initSearch(root) {
  const search = root.querySelector('[data-mcp-tools-search]');
  if (!(search instanceof HTMLInputElement)) {
    return;
  }

  bindFilterSearchInput(search, (value) => {
    filterSearch(root, value.toLowerCase());
  });
}

function filterSearch(root, query) {
  const activePanel = root.querySelector('[data-mcp-tools-panel].is-active');
  if (!activePanel) {
    return;
  }

  if (activePanel.getAttribute('data-mcp-tools-panel') !== 'tools-resources') {
    return;
  }

  const activeSection = activePanel.querySelector('[data-mcp-tools-sidebar-panel].is-active');
  if (!activeSection) {
    return;
  }

  const sectionKey = activeSection.getAttribute('data-mcp-tools-sidebar-panel');
  if (sectionKey === 'custom' || sectionKey === 'prompts' || sectionKey === 'custom-tools') {
    return;
  }

  if (sectionKey === 'extensions') {
    applyExtensionsPanelFilters(root, query);
    return;
  }

  activeSection.querySelectorAll('[data-mcp-tools-card]').forEach((item) => {
    const haystack = (item.getAttribute('data-search-text') ?? item.textContent ?? '').toLowerCase();
    item.hidden = query !== '' && !haystack.includes(query);
  });
}

function getExtensionsPanel(root) {
  return root.querySelector('[data-mcp-tools-sidebar-panel="extensions"]');
}

function resetExtensionFilter(root) {
  const extensionsPanel = getExtensionsPanel(root);
  if (!(extensionsPanel instanceof HTMLElement)) {
    return;
  }

  extensionsPanel.setAttribute('data-mcp-tools-active-extension', 'all');
  extensionsPanel.querySelectorAll('[data-mcp-tools-extension]').forEach((btn) => {
    const isAll = (btn.getAttribute('data-mcp-tools-extension') ?? 'all') === 'all';
    btn.classList.toggle('active', isAll);
    btn.classList.toggle('is-active', isAll);
  });
  applyExtensionsPanelFilters(root, '');
}

function applyExtensionsPanelFilters(root, query) {
  const extensionsPanel = getExtensionsPanel(root);
  if (!(extensionsPanel instanceof HTMLElement)) {
    return;
  }

  const extensionId = extensionsPanel.getAttribute('data-mcp-tools-active-extension') ?? 'all';

  extensionsPanel.querySelectorAll('[data-mcp-tools-card]').forEach((card) => {
    const cardExtension = card.getAttribute('data-extension-id') ?? '';
    const haystack = (card.getAttribute('data-search-text') ?? card.textContent ?? '').toLowerCase();
    const matchesExtension = extensionId === 'all' || cardExtension === extensionId;
    const matchesSearch = query === '' || haystack.includes(query);
    card.hidden = !(matchesExtension && matchesSearch);
  });
}

function initExtensionFilter(root) {
  const extensionsPanel = getExtensionsPanel(root);
  if (!(extensionsPanel instanceof HTMLElement)) {
    return;
  }

  extensionsPanel.setAttribute('data-mcp-tools-active-extension', 'all');

  extensionsPanel.querySelectorAll('[data-mcp-tools-extension]').forEach((button) => {
    button.addEventListener('click', () => {
      const extensionId = button.getAttribute('data-mcp-tools-extension') ?? 'all';

      extensionsPanel.querySelectorAll('[data-mcp-tools-extension]').forEach((btn) => {
        const isActive = btn === button;
        btn.classList.toggle('active', isActive);
        btn.classList.toggle('is-active', isActive);
      });

      extensionsPanel.setAttribute('data-mcp-tools-active-extension', extensionId);

      const search = root.querySelector('[data-mcp-tools-search]');
      const query = search instanceof HTMLInputElement ? search.value.trim().toLowerCase() : '';
      applyExtensionsPanelFilters(root, query);
    });
  });
}

function initCategoryFilter(root) {
  root.querySelectorAll('[data-mcp-tools-category]').forEach((button) => {
    button.addEventListener('click', () => {
      const category = button.getAttribute('data-mcp-tools-category') ?? 'all';
      const section = button.closest('[data-mcp-tools-sidebar-panel]');
      if (!(section instanceof HTMLElement)) {
        return;
      }

      section.querySelectorAll('[data-mcp-tools-category]').forEach((btn) => {
        const isActive = btn === button;
        btn.classList.toggle('active', isActive);
        btn.classList.toggle('is-active', isActive);
      });

      section.querySelectorAll('[data-mcp-tools-tool-panel]').forEach((panel) => {
        const panelCategory = panel.getAttribute('data-tool-category') ?? '';
        const card = panel.closest('[data-mcp-tools-card]');
        if (!card) {
          return;
        }

        if (category === 'all') {
          panel.hidden = false;
          return;
        }

        panel.hidden = panelCategory !== category;
      });

      section.querySelectorAll('[data-mcp-tools-card]').forEach((card) => {
        if (category === 'all') {
          card.hidden = false;
          return;
        }

        const visibleTools = [...card.querySelectorAll('[data-mcp-tools-tool-panel]')].some(
          (panel) => panel.hidden === false,
        );
        card.hidden = !visibleTools;
      });

      section.querySelectorAll('[data-mcp-tools-tool-tag]').forEach((tag) => {
        if (!(tag instanceof HTMLElement)) {
          return;
        }

        const tagCategory = tag.getAttribute('data-tool-category') ?? '';
        if (category === 'all') {
          tag.hidden = false;
          return;
        }

        tag.hidden = tagCategory !== category;
      });
    });
  });
}

function initTableFilter(root) {
  const filter = root.querySelector('[data-mcp-tools-table-filter]');
  if (!(filter instanceof HTMLInputElement)) {
    return;
  }

  bindFilterSearchInput(filter, (value) => {
    filterTableRows(root, value.toLowerCase());
  });
}

function filterTableRows(root, query) {
  root.querySelectorAll('[data-mcp-tools-row]').forEach((row) => {
    const haystack = (row.getAttribute('data-search-text') ?? row.textContent ?? '').toLowerCase();
    const visible = query === '' || haystack.includes(query);
    row.hidden = !visible;

    const detail = row.nextElementSibling;
    if (detail?.matches('[data-mcp-tools-row-detail]')) {
      if (!visible) {
        detail.hidden = true;
        row.querySelector('[data-mcp-tools-expand-row]')?.setAttribute('aria-expanded', 'false');
        row.querySelector('.aiu-mcp-ext-table__expand')?.classList.remove('is-open');
      } else if (detail.hidden === false) {
        detail.hidden = false;
      }
    }
  });
}

function initExtensionCards(root) {
  root.querySelectorAll('[data-mcp-tools-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
      const card = button.closest('[data-mcp-tools-card]');
      if (!card) {
        return;
      }

      const tools = card.querySelector('.aiu-ext-card__tools');
      if (!tools) {
        return;
      }

      const open = tools.hidden;
      tools.hidden = !open;
      button.setAttribute('aria-expanded', open ? 'true' : 'false');
      button.textContent = open
        ? lang('module.mcpTools.collapse', 'Collapse')
        : lang('module.mcpTools.viewTools', 'View Tools');

      if (!open) {
        collapseAllToolPanels(card);
      }
    });
  });

  root.querySelectorAll('[data-mcp-tools-download-skill]').forEach((button) => {
    button.addEventListener('click', () => {
      downloadSkill(button);
    });
  });

  root.querySelectorAll('[data-mcp-tools-tool-tag]').forEach((tag) => {
    tag.addEventListener('click', () => {
      if (tag.hasAttribute('data-mcp-tools-open-drawer')) {
        openToolDrawerFromTag(root, tag);
        return;
      }
      openToolFromTag(tag);
    });
  });
}

function initToolPanels(root) {
  root.querySelectorAll('[data-mcp-tools-toggle-tool]').forEach((button) => {
    button.addEventListener('click', () => {
      toggleToolPanel(button);
    });
  });
}

function initToolDrawer(root, playgroundConfig) {
  const drawer = root.querySelector('[data-mcp-tools-drawer]');
  if (!(drawer instanceof HTMLElement)) {
    return;
  }

  drawer.querySelectorAll('[data-mcp-tools-drawer-close]').forEach((button) => {
    button.addEventListener('click', () => closeToolDrawer(drawer));
  });

  drawer.addEventListener('click', (event) => {
    if (event.target === drawer) {
      closeToolDrawer(drawer);
    }
  });

  const playgroundButton = drawer.querySelector('[data-mcp-tools-drawer-playground]');
  playgroundButton?.addEventListener('click', () => {
    const toolName = drawer.querySelector('[data-mcp-tools-drawer-name]')?.textContent?.trim() ?? '';
    if (toolName === '') {
      return;
    }

    closeToolDrawer(drawer);
    switchToPlayground(root, toolName, playgroundConfig);
  });
}

function openToolDrawerFromTag(root, tag) {
  const drawer = root.querySelector('[data-mcp-tools-drawer]');
  if (!(drawer instanceof HTMLElement)) {
    openToolFromTag(tag);
    return;
  }

  const toolName = tag.getAttribute('data-tool-name') ?? tag.getAttribute('data-mcp-tools-tool-tag') ?? '';
  const panel = tag.closest('[data-mcp-tools-card]')?.querySelector(
    `[data-mcp-tools-tool-panel][data-tool-name="${CSS.escape(toolName)}"]`,
  );

  drawer.querySelector('[data-mcp-tools-drawer-name]')?.replaceChildren(document.createTextNode(toolName));
  const subtitle = tag.getAttribute('data-tool-short-title') ?? '';
  drawer.querySelector('[data-mcp-tools-drawer-subtitle]')?.replaceChildren(document.createTextNode(subtitle));

  const body = drawer.querySelector('[data-mcp-tools-drawer-body]');
  if (body instanceof HTMLElement) {
    body.innerHTML = '';
    const sourceBody = panel?.querySelector('.aiu-tool-panel__body');
    if (sourceBody instanceof HTMLElement) {
      body.innerHTML = sourceBody.innerHTML;
    } else {
      const description = tag.getAttribute('data-tool-description') ?? '';
      body.innerHTML = `<p class="text-variant">${escapeHtml(description)}</p>`;
    }
  }

  drawer.classList.remove('is-closing');
  drawer.classList.add('is-open');
  drawer.hidden = false;
  drawer.setAttribute('aria-hidden', 'false');
}

function closeToolDrawer(drawer) {
  if (drawer.classList.contains('is-closing')) {
    return;
  }
  drawer.classList.remove('is-open');
  drawer.classList.add('is-closing');

  const finish = () => {
    if (!drawer.classList.contains('is-closing')) {
      return;
    }
    drawer.classList.remove('is-closing');
    drawer.hidden = true;
    drawer.setAttribute('aria-hidden', 'true');
  };
  const panel = drawer.querySelector('.aiu-drawer__panel');
  if (!(panel instanceof HTMLElement)) {
    finish();
    return;
  }
  let done = false;
  const onEnd = () => {
    if (done) {
      return;
    }
    done = true;
    panel.removeEventListener('animationend', onEnd);
    finish();
  };
  panel.addEventListener('animationend', onEnd);
  window.setTimeout(onEnd, 350);
}

function switchToPlayground(root, toolName, playgroundConfig) {
  const tab = root.querySelector('[data-mcp-tools-tab="playground"]');
  tab?.click();

  const select = root.querySelector('[data-mcp-tools-playground-tool]');
  if (select instanceof HTMLSelectElement) {
    select.value = toolName;
    select.dispatchEvent(new Event('change'));
  }

  root.querySelector('[data-mcp-tools-playground]')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function toggleToolPanel(headerButton, forceOpen = false) {
  const panel = headerButton.closest('[data-mcp-tools-tool-panel]');
  const card = headerButton.closest('[data-mcp-tools-card]');
  const body = panel?.querySelector('.aiu-tool-panel__body');
  if (!panel || !body) {
    return;
  }

  const open = forceOpen || body.hidden;
  if (open && card) {
    card.querySelectorAll('[data-mcp-tools-tool-panel]').forEach((otherPanel) => {
      if (otherPanel === panel) {
        return;
      }

      const otherBody = otherPanel.querySelector('.aiu-tool-panel__body');
      const otherHeader = otherPanel.querySelector('[data-mcp-tools-toggle-tool]');
      if (otherBody) {
        otherBody.hidden = true;
      }
      otherHeader?.setAttribute('aria-expanded', 'false');
      otherHeader?.classList.remove('is-open');
    });
  }

  body.hidden = !open;
  headerButton.setAttribute('aria-expanded', open ? 'true' : 'false');
  headerButton.classList.toggle('is-open', open);
}

function collapseAllToolPanels(card) {
  card.querySelectorAll('[data-mcp-tools-tool-panel]').forEach((panel) => {
    const body = panel.querySelector('.aiu-tool-panel__body');
    const header = panel.querySelector('[data-mcp-tools-toggle-tool]');
    if (body) {
      body.hidden = true;
    }
    header?.setAttribute('aria-expanded', 'false');
    header?.classList.remove('is-open');
  });
}

function openToolFromTag(tag) {
  const toolName = tag.getAttribute('data-mcp-tools-tool-tag');
  const card = tag.closest('[data-mcp-tools-card]');
  if (!toolName || !card) {
    return;
  }

  const toolsSection = card.querySelector('.aiu-ext-card__tools');
  const toggleButton = card.querySelector('[data-mcp-tools-toggle]');
  if (toolsSection?.hidden && toggleButton instanceof HTMLButtonElement) {
    toolsSection.hidden = false;
    toggleButton.setAttribute('aria-expanded', 'true');
    toggleButton.textContent = lang('module.mcpTools.collapse', 'Collapse');
  }

  const panel = card.querySelector(`[data-mcp-tools-tool-panel][data-tool-name="${CSS.escape(toolName)}"]`);
  const header = panel?.querySelector('[data-mcp-tools-toggle-tool]');
  if (header instanceof HTMLButtonElement) {
    toggleToolPanel(header, true);
    header.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }
}

function downloadSkill(button) {
  const card = button.closest('[data-mcp-tools-card]');
  const toolNames = card
    ? [...card.querySelectorAll('[data-mcp-tools-tool-tag]')].map((el) => el.textContent?.trim() ?? '').filter(Boolean)
    : [];

  const skillName = button.getAttribute('data-skill-name') ?? 'AI Skill';
  const skillTrigger = button.getAttribute('data-skill-trigger') ?? '/skill';
  const skillFile = button.getAttribute('data-skill-file') ?? 'skill.md';
  const skillDesc = button.getAttribute('data-skill-desc') ?? '';
  const skillLabel = button.getAttribute('data-skill-label') ?? skillName;

  const content = [
    `# ${skillName} — TYPO3 AI Skill`,
    '',
    `Trigger: \`${skillTrigger}\``,
    `Extension: ${skillLabel}`,
    '',
    '## Description',
    skillDesc || `MCP tools for ${skillLabel}.`,
    '',
    '## MCP Tools Included',
    ...toolNames.map((name) => `- \`${name}\``),
    '',
    '## Usage',
    'Install this file into your AI assistant\'s system prompt or project instructions.',
    `Type \`${skillTrigger}\` in your chat to activate this workflow.`,
    '',
  ].join('\n');

  const blob = new Blob([content], { type: 'text/markdown' });
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement('a');
  anchor.href = url;
  anchor.download = skillFile;
  anchor.click();
  URL.revokeObjectURL(url);

  Notification.success(lang('mcpTools.js.downloadingSkill', 'Skill file downloaded'));
}

function initCodeConfig(root) {
  const button = root.querySelector('[data-mcp-tools-code-config]');
  const panel = root.querySelector('[data-mcp-tools-code-panel]');
  if (!(button instanceof HTMLButtonElement) || !(panel instanceof HTMLElement)) {
    return;
  }

  button.addEventListener('click', () => {
    const open = panel.hidden;
    panel.hidden = !open;
    button.setAttribute('aria-expanded', open ? 'true' : 'false');
    button.classList.toggle('is-active', open);
  });
}

function initDiscover(root) {
  const button = root.querySelector('[data-mcp-tools-discover]');
  if (!(button instanceof HTMLButtonElement)) {
    return;
  }

  button.addEventListener('click', () => {
    button.disabled = true;
    new AjaxRequest(TYPO3.settings.ajaxUrls.nst3af_mcp_tools_discover)
      .post({})
      .then(async (response) => {
        const data = await response.resolve();
        if (!data.success) {
          throw new Error(data.message ?? lang('mcpTools.js.discoverFailed', 'Table discovery failed'));
        }

        const count = Number(data.newCount ?? 0);
        if (count > 0) {
          Notification.success(lang('mcpTools.js.discoverSuccess', 'Discovery complete. %s new table(s) added.', count));
          window.location.reload();
        } else {
          Notification.info(lang('mcpTools.js.discoverNone', 'No new extension tables found.'));
        }
      })
      .catch((error) => {
        Notification.error(error.message ?? lang('mcpTools.js.discoverFailed', 'Table discovery failed'));
      })
      .finally(() => {
        button.disabled = false;
      });
  });
}

function initTableActions(root) {
  root.querySelectorAll('[data-mcp-tools-expand-row]').forEach((button) => {
    button.addEventListener('click', () => {
      const row = button.closest('[data-mcp-tools-row]');
      const detail = row?.nextElementSibling;
      if (!row || !detail?.matches('[data-mcp-tools-row-detail]')) {
        return;
      }

      const open = detail.hidden;
      detail.hidden = !open;
      button.setAttribute('aria-expanded', open ? 'true' : 'false');
      button.classList.toggle('is-open', open);
    });
  });

  root.querySelectorAll('[data-mcp-tools-toggle-table]').forEach((button) => {
    button.addEventListener('click', () => {
      if (!(button instanceof HTMLButtonElement)) {
        return;
      }

      const row = button.closest('[data-mcp-tools-row]');
      const uid = row?.getAttribute('data-table-id');
      if (!uid) {
        return;
      }

      const currentlyEnabled = row?.getAttribute('data-table-enabled') === '1';
      const enabled = !currentlyEnabled;

      button.disabled = true;
      new AjaxRequest(TYPO3.settings.ajaxUrls.nst3af_mcp_tools_toggle)
        .post({ uid, enabled })
        .then(async (response) => {
          const data = await response.resolve();
          if (!data.success) {
            throw new Error(data.message ?? lang('mcpTools.js.toggleFailed', 'Could not update table status'));
          }

          window.location.reload();
        })
        .catch((error) => {
          Notification.error(error.message ?? lang('mcpTools.js.toggleFailed', 'Could not update table status'));
        })
        .finally(() => {
          button.disabled = false;
        });
    });
  });

  root.querySelectorAll('[data-mcp-tools-edit-row]').forEach((button) => {
    button.addEventListener('click', () => {
      enterRowEdit(button.closest('[data-mcp-tools-row]'));
    });
  });

  root.querySelectorAll('[data-mcp-tools-cancel-row]').forEach((button) => {
    button.addEventListener('click', () => {
      resetRowEdit(button.closest('[data-mcp-tools-row]'));
    });
  });

  root.querySelectorAll('[data-mcp-tools-save-row]').forEach((button) => {
    button.addEventListener('click', () => {
      const row = button.closest('[data-mcp-tools-row]');
      if (!row) {
        return;
      }

      const uid = row.getAttribute('data-table-id');
      const labelInput = row.querySelector('[data-edit-label]');
      const prefixInput = row.querySelector('[data-edit-prefix]');

      if (!(uid && labelInput instanceof HTMLInputElement && prefixInput instanceof HTMLInputElement)) {
        return;
      }

      new AjaxRequest(TYPO3.settings.ajaxUrls.nst3af_mcp_tools_save)
        .post({ uid, label: labelInput.value.trim(), prefix: prefixInput.value.trim() })
        .then(async (response) => {
          const data = await response.resolve();
          if (!data.success) {
            throw new Error(data.message ?? lang('mcpTools.js.saveFailed', 'Could not save table configuration'));
          }

          const labelEl = row.querySelector('.aiu-table-row-label');
          const prefixEl = row.querySelector('.aiu-table-row-prefix');
          if (labelEl) {
            labelEl.textContent = data.label ?? labelInput.value;
          }
          if (prefixEl) {
            prefixEl.textContent = data.prefix ?? prefixInput.value;
          }

          row.setAttribute(
            'data-search-text',
            `${row.querySelector('.aiu-table__mono')?.textContent ?? ''} ${data.label ?? ''} ${data.prefix ?? ''}`.trim(),
          );

          resetRowEdit(row);
          Notification.success(lang('mcpTools.js.saveSuccess', 'Table configuration saved'));
        })
        .catch((error) => {
          Notification.error(error.message ?? lang('mcpTools.js.saveFailed', 'Could not save table configuration'));
        });
    });
  });
}

function initPlayground(root, playgroundConfig) {
  const select = root.querySelector('[data-mcp-tools-playground-tool]');
  const paramsWrap = root.querySelector('[data-mcp-tools-playground-params]');
  const paramsFields = root.querySelector('[data-mcp-tools-playground-params-fields]');
  const emptyHint = root.querySelector('[data-mcp-tools-playground-empty]');
  const runButton = root.querySelector('[data-mcp-tools-playground-run]');
  const resultWrap = root.querySelector('[data-mcp-tools-playground-result-wrap]');
  const resultPre = root.querySelector('[data-mcp-tools-playground-result]');
  const meta = root.querySelector('[data-mcp-tools-playground-meta]');
  const callLabel = root.querySelector('[data-mcp-tools-playground-call]');
  const infoCard = root.querySelector('[data-mcp-tools-playground-info]');
  const promptsCard = root.querySelector('[data-mcp-tools-playground-prompts]');
  const taglineEl = root.querySelector('[data-mcp-tools-playground-tagline]');
  const notesWrap = root.querySelector('[data-mcp-tools-playground-notes-wrap]');
  const notesEl = root.querySelector('[data-mcp-tools-playground-notes]');
  const callsEl = root.querySelector('[data-mcp-tools-playground-calls]');
  const successEl = root.querySelector('[data-mcp-tools-playground-success]');
  const promptsList = root.querySelector('[data-mcp-tools-playground-prompts-list]');
  const clearButton = root.querySelector('[data-mcp-tools-playground-clear]');

  if (!(select instanceof HTMLSelectElement) || !(runButton instanceof HTMLButtonElement)) {
    return;
  }

  const toolMap = buildPlaygroundToolMap(playgroundConfig);
  const period = root.getAttribute('data-mcp-tools-period') ?? '7d';
  const periodFrom = root.getAttribute('data-mcp-tools-period-from') ?? '';
  const periodTo = root.getAttribute('data-mcp-tools-period-to') ?? '';

  const updatePlaygroundSidebar = (toolName) => {
    const tool = toolMap.get(toolName);
    if (!tool) {
      infoCard?.setAttribute('hidden', 'hidden');
      promptsCard?.setAttribute('hidden', 'hidden');
      runButton.disabled = true;
      callLabel?.setAttribute('hidden', 'hidden');
      return;
    }

    runButton.disabled = false;
    if (callLabel instanceof HTMLElement) {
      callLabel.textContent = `${tool.name}()`;
      callLabel.hidden = false;
    }

    if (infoCard instanceof HTMLElement) {
      infoCard.hidden = false;
    }
    if (taglineEl instanceof HTMLElement) {
      const description = tool.tagline || tool.description || '';
      taglineEl.textContent = description;
    }
    if (notesWrap instanceof HTMLElement && notesEl instanceof HTMLElement) {
      const notes = tool.notes ?? '';
      if (notes !== '') {
        notesEl.textContent = notes;
        notesWrap.hidden = false;
      } else {
        notesWrap.hidden = true;
      }
    }

    if (promptsCard instanceof HTMLElement && promptsList instanceof HTMLElement) {
      const prompts = tool.examplePrompts ?? [];
      promptsList.innerHTML = '';
      if (prompts.length > 0) {
        prompts.forEach((prompt) => {
          const item = document.createElement('button');
          item.type = 'button';
          item.className = 'aiu-mcp-playground-prompt text-start';
          item.textContent = prompt;
          item.addEventListener('click', () => {
            navigator.clipboard.writeText(prompt).then(() => {
              Notification.success(lang('mcpTools.js.copySuccess', 'Snippet copied to clipboard'));
            });
          });
          promptsList.appendChild(item);
        });
        promptsCard.hidden = false;
      } else {
        promptsCard.hidden = true;
      }
    }

    if (callsEl instanceof HTMLElement) {
      callsEl.textContent = '…';
    }
    if (successEl instanceof HTMLElement) {
      successEl.textContent = '…';
    }

    new AjaxRequest(TYPO3.settings.ajaxUrls.nst3af_mcp_tools_playground_tool_stats)
      .post({ toolName, period, from: periodFrom, to: periodTo })
      .then(async (response) => {
        const data = await response.resolve();
        if (!data.success || !data.stats) {
          return;
        }
        if (callsEl instanceof HTMLElement) {
          callsEl.textContent = formatNumber(data.stats.callsWeek ?? 0);
        }
        if (successEl instanceof HTMLElement) {
          const rate = data.stats.successRate ?? 0;
          successEl.textContent = `${Number(rate).toFixed(1)}%`;
        }
      })
      .catch(() => {
        if (callsEl instanceof HTMLElement) {
          callsEl.textContent = '0';
        }
        if (successEl instanceof HTMLElement) {
          successEl.textContent = '—';
        }
      });
  };

  select.addEventListener('change', () => {
    const toolName = select.value.trim();
    renderPlaygroundParams(toolName, toolMap, paramsWrap, paramsFields, emptyHint);
    updatePlaygroundSidebar(toolName);
  });

  clearButton?.addEventListener('click', () => {
    paramsFields?.querySelectorAll('input, select, textarea').forEach((input) => {
      if (input instanceof HTMLInputElement) {
        if (input.type === 'checkbox') {
          input.checked = false;
        } else {
          input.value = '';
        }
      } else if (input instanceof HTMLSelectElement) {
        input.selectedIndex = 0;
      } else if (input instanceof HTMLTextAreaElement) {
        input.value = '';
      }
    });
  });

  runButton.addEventListener('click', () => {
    const toolName = select.value.trim();
    if (toolName === '') {
      Notification.warning(lang('mcpTools.js.playgroundSelectTool', 'Select a tool first'));
      return;
    }

    const argumentsPayload = collectPlaygroundArguments(paramsFields);
    runButton.disabled = true;

    new AjaxRequest(TYPO3.settings.ajaxUrls.nst3af_mcp_tools_playground_invoke)
      .post({ toolName, arguments: argumentsPayload })
      .then(async (response) => {
        const data = await response.resolve();
        if (resultPre instanceof HTMLElement) {
          const code = resultPre.querySelector('code');
          const payload = data.result ?? data.message ?? '';
          const formatted = beautifyJsonDisplay(payload);
          if (code) {
            code.textContent = formatted;
          }
        }
        if (resultWrap instanceof HTMLElement) {
          resultWrap.hidden = false;
        }

        if (meta instanceof HTMLElement) {
          meta.textContent = data.success
            ? lang('mcpTools.js.playgroundSuccess', 'Completed in %s ms', String(data.latencyMs ?? 0))
            : (data.message ?? lang('mcpTools.js.playgroundFailed', 'Tool call failed'));
        }

        if (!data.success) {
          throw new Error(data.message ?? lang('mcpTools.js.playgroundFailed', 'Tool call failed'));
        }

        Notification.success(lang('mcpTools.js.playgroundSuccess', 'Completed in %s ms', String(data.latencyMs ?? 0)));
        updatePlaygroundSidebar(toolName);
      })
      .catch((error) => {
        Notification.error(error.message ?? lang('mcpTools.js.playgroundFailed', 'Tool call failed'));
      })
      .finally(() => {
        runButton.disabled = select.value.trim() === '';
      });
  });

  const defaultTool = String(playgroundConfig.defaultTool ?? 'pages_get').trim();
  if (defaultTool !== '' && toolMap.has(defaultTool)) {
    select.value = defaultTool;
    renderPlaygroundParams(defaultTool, toolMap, paramsWrap, paramsFields, emptyHint);
    updatePlaygroundSidebar(defaultTool);
  }
}

function buildPlaygroundToolMap(config) {
  const map = new Map();
  (config.categories ?? []).forEach((category) => {
    (category.tools ?? []).forEach((tool) => {
      map.set(tool.name, tool);
    });
  });
  return map;
}

function renderPlaygroundParams(toolName, toolMap, paramsWrap, paramsFields, emptyHint) {
  if (!(paramsWrap instanceof HTMLElement) || !(paramsFields instanceof HTMLElement)) {
    return;
  }

  paramsFields.innerHTML = '';
  const tool = toolMap.get(toolName);
  if (!tool) {
    paramsWrap.hidden = true;
    if (emptyHint instanceof HTMLElement) {
      emptyHint.hidden = false;
    }
    return;
  }

  if (emptyHint instanceof HTMLElement) {
    emptyHint.hidden = true;
  }

  const params = tool.params ?? [];
  if (!params.length) {
    paramsWrap.hidden = true;
    return;
  }

  params.forEach((param) => {
    const fieldId = `mcp-playground-${param.name}`;
    const wrapper = document.createElement('div');
    wrapper.className = 'mb-3';
    const typeLabel = param.type ? ` (${param.type}${param.required ? '' : ', Optional'})` : '';
    wrapper.innerHTML = `
      <label class="form-label" for="${escapeHtml(fieldId)}">${escapeHtml(param.name)}${typeLabel}</label>
      <input type="text"
        class="form-control"
        id="${escapeHtml(fieldId)}"
        name="${escapeHtml(fieldId)}"
        data-playground-param="${escapeHtml(param.name)}"
        autocomplete="off"
        autocorrect="off"
        autocapitalize="off"
        spellcheck="false"
        data-1p-ignore="true"
        data-lpignore="true"
        data-form-type="other"
        placeholder="${escapeHtml(String(param.example ?? param.default ?? ''))}" />
      <div class="small text-variant">${escapeHtml(param.description ?? '')}</div>
    `;
    paramsFields.appendChild(wrapper);
  });

  paramsWrap.hidden = false;
}

function collectPlaygroundArguments(paramsFields) {
  const payload = {};
  if (!(paramsFields instanceof HTMLElement)) {
    return payload;
  }

  paramsFields.querySelectorAll('[data-playground-param]').forEach((input) => {
    if (!(input instanceof HTMLInputElement)) {
      return;
    }
    const name = input.getAttribute('data-playground-param');
    if (!name || input.value.trim() === '') {
      return;
    }
    payload[name] = input.value.trim();
  });

  return payload;
}

function initPromptToggles(root) {
  root.querySelectorAll('[data-mcp-tools-prompt-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
      const row = button.closest('[data-mcp-tools-prompt-row]');
      if (!(row instanceof HTMLElement)) {
        return;
      }

      const body = row.querySelector('.aiu-mcp-prompt-row__body');
      const isOpen = button.getAttribute('aria-expanded') === 'true';

      button.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
      row.classList.toggle('is-open', !isOpen);
      if (body instanceof HTMLElement) {
        body.hidden = isOpen;
      }

      const label = button.querySelector('[data-mcp-tools-prompt-toggle-label]');
      if (label instanceof HTMLElement) {
        label.textContent = isOpen
          ? lang('module.mcpTools.prompts.showDetails', 'Show details')
          : lang('module.mcpTools.prompts.hideDetails', 'Hide details');
      }
    });
  });
}

function openCustomToolOverlay(overlay, toolData = null) {
  if (!(overlay instanceof HTMLElement)) {
    return;
  }

  resetCustomToolForm(overlay);

  if (toolData) {
    prefillCustomToolForm(overlay, toolData);
    setCustomToolOverlayMode(overlay, 'edit');
  } else {
    setCustomToolOverlayMode(overlay, 'create');
  }

  overlay.hidden = false;
  overlay.setAttribute('aria-hidden', 'false');
  const labelInput = overlay.querySelector('[data-mcp-tools-custom-tool-field="label"]');
  if (labelInput instanceof HTMLInputElement) {
    labelInput.focus();
  }
}

function setCustomToolOverlayMode(overlay, mode) {
  const form = overlay.querySelector('[data-mcp-tools-custom-tool-form]');
  if (form instanceof HTMLElement) {
    if (mode === 'edit') {
      form.dataset.editUid = form.dataset.editUid ?? '';
    } else {
      delete form.dataset.editUid;
    }
  }

  const title = overlay.querySelector('[data-mcp-tools-custom-tool-title]');
  if (title instanceof HTMLElement) {
    const text = mode === 'edit' ? title.dataset.titleEdit : title.dataset.titleCreate;
    if (text) {
      title.textContent = text;
    }
  }

  const submitLabel = overlay.querySelector('[data-mcp-tools-custom-tool-submit-label]');
  if (submitLabel instanceof HTMLElement) {
    const text = mode === 'edit' ? submitLabel.dataset.labelEdit : submitLabel.dataset.labelCreate;
    if (text) {
      submitLabel.textContent = text;
    }
  }
}

function prefillCustomToolForm(overlay, toolData) {
  const form = overlay.querySelector('[data-mcp-tools-custom-tool-form]');
  if (!(form instanceof HTMLElement)) {
    return;
  }

  form.dataset.editUid = String(toolData.uid ?? '');

  const labelInput = form.querySelector('[data-mcp-tools-custom-tool-field="label"]');
  if (labelInput instanceof HTMLInputElement) {
    labelInput.value = toolData.label ?? '';
  }

  const descriptionInput = form.querySelector('[data-mcp-tools-custom-tool-field="description"]');
  if (descriptionInput instanceof HTMLTextAreaElement) {
    descriptionInput.value = toolData.description ?? '';
  }

  const handlerType = ['php', 'rest', 'webhook'].includes(toolData.handlerType) ? toolData.handlerType : 'php';
  form.querySelectorAll('[data-mcp-tools-custom-tool-handler]').forEach((button) => {
    const isActive = button.getAttribute('data-mcp-tools-custom-tool-handler') === handlerType;
    button.classList.toggle('active', isActive);
    button.classList.toggle('is-active', isActive);
  });
  form.querySelectorAll('[data-mcp-tools-custom-tool-handler-panel]').forEach((panel) => {
    if (panel instanceof HTMLElement) {
      panel.hidden = panel.getAttribute('data-mcp-tools-custom-tool-handler-panel') !== handlerType;
    }
  });

  const handlerFieldKey = handlerType === 'rest'
    ? 'handlerValueRest'
    : (handlerType === 'webhook' ? 'handlerValueWebhook' : 'handlerValue');
  const handlerInput = form.querySelector(`[data-mcp-tools-custom-tool-field="${handlerFieldKey}"]`);
  if (handlerInput instanceof HTMLInputElement) {
    handlerInput.value = toolData.handlerValue ?? '';
  }

  prefillCustomToolParams(form, Array.isArray(toolData.parameters) ? toolData.parameters : []);
}

function prefillCustomToolParams(form, parameters) {
  const paramsBody = form.querySelector('[data-mcp-tools-custom-tool-params]');
  if (!(paramsBody instanceof HTMLElement) || parameters.length === 0) {
    return;
  }

  const templateRow = paramsBody.querySelector('[data-mcp-tools-custom-tool-param-row]');
  if (!(templateRow instanceof HTMLTableRowElement)) {
    return;
  }

  paramsBody.querySelectorAll('[data-mcp-tools-custom-tool-param-row]').forEach((row) => row.remove());

  parameters.forEach((param) => {
    const row = templateRow.cloneNode(true);
    if (!(row instanceof HTMLTableRowElement)) {
      return;
    }

    const nameInput = row.querySelector('[data-param-name]');
    if (nameInput instanceof HTMLInputElement) {
      nameInput.value = param.name ?? '';
    }
    const typeInput = row.querySelector('[data-param-type]');
    if (typeInput instanceof HTMLSelectElement) {
      typeInput.value = ['string', 'integer', 'number', 'boolean', 'array', 'object'].includes(param.type)
        ? param.type
        : 'string';
    }
    const requiredInput = row.querySelector('[data-param-required]');
    if (requiredInput instanceof HTMLInputElement) {
      requiredInput.checked = param.required === true;
    }
    const descriptionField = row.querySelector('[data-param-description]');
    if (descriptionField instanceof HTMLInputElement) {
      descriptionField.value = param.description ?? '';
    }

    paramsBody.appendChild(row);
    bindCustomToolParamRow(row);
  });

  updateCustomToolParamRemoveButtons(paramsBody);
}

function closeCustomToolOverlay(overlay) {
  if (!(overlay instanceof HTMLElement)) {
    return;
  }

  overlay.hidden = true;
  overlay.setAttribute('aria-hidden', 'true');
}

function resetCustomToolForm(overlay) {
  const form = overlay.querySelector('[data-mcp-tools-custom-tool-form]');
  if (!(form instanceof HTMLElement)) {
    return;
  }

  form.querySelectorAll('input[type="text"], input[type="url"], textarea').forEach((input) => {
    if (input instanceof HTMLInputElement || input instanceof HTMLTextAreaElement) {
      input.value = '';
    }
  });

  form.querySelectorAll('input[type="checkbox"]').forEach((input) => {
    if (input instanceof HTMLInputElement) {
      input.checked = false;
    }
  });

  form.querySelectorAll('[data-mcp-tools-custom-tool-handler]').forEach((button, index) => {
    const isActive = index === 0;
    button.classList.toggle('active', isActive);
    button.classList.toggle('is-active', isActive);
  });

  form.querySelectorAll('[data-mcp-tools-custom-tool-handler-panel]').forEach((panel) => {
    if (!(panel instanceof HTMLElement)) {
      return;
    }
    panel.hidden = panel.getAttribute('data-mcp-tools-custom-tool-handler-panel') !== 'php';
  });

  const paramsBody = form.querySelector('[data-mcp-tools-custom-tool-params]');
  if (!(paramsBody instanceof HTMLElement)) {
    return;
  }

  const rows = paramsBody.querySelectorAll('[data-mcp-tools-custom-tool-param-row]');
  rows.forEach((row, index) => {
    if (index === 0) {
      row.querySelectorAll('input').forEach((input) => {
        if (input instanceof HTMLInputElement) {
          input.value = input.type === 'checkbox' ? '' : '';
          input.checked = false;
        }
      });
      row.querySelectorAll('select').forEach((select) => {
        if (select instanceof HTMLSelectElement) {
          select.selectedIndex = 0;
        }
      });
      return;
    }
    row.remove();
  });
  updateCustomToolParamRemoveButtons(paramsBody);
}

function initCustomToolModal(root) {
  const overlay = root.querySelector('[data-mcp-tools-custom-tool-overlay]');
  if (!(overlay instanceof HTMLElement)) {
    return;
  }

  if (overlay.parentElement !== document.body) {
    document.body.appendChild(overlay);
  }

  const formScope = overlay.querySelector('.aiu-mcp-custom-tool-overlay__body') ?? overlay;
  bindCustomToolModalForm(formScope);

  root.querySelectorAll('[data-mcp-tools-custom-tool-open]').forEach((button) => {
    button.addEventListener('click', () => openCustomToolOverlay(overlay));
  });

  overlay.querySelectorAll('[data-mcp-tools-custom-tool-close]').forEach((button) => {
    button.addEventListener('click', () => closeCustomToolOverlay(overlay));
  });

  overlay.querySelector('[data-mcp-tools-custom-tool-submit]')?.addEventListener('click', () => {
    submitCustomToolFromOverlay(overlay);
  });

  overlay.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      closeCustomToolOverlay(overlay);
    }
  });
}

function bindCustomToolModalForm(scope) {
  scope.querySelectorAll('[data-mcp-tools-custom-tool-handler]').forEach((button) => {
    button.addEventListener('click', () => {
      const handler = button.getAttribute('data-mcp-tools-custom-tool-handler') ?? 'php';

      scope.querySelectorAll('[data-mcp-tools-custom-tool-handler]').forEach((btn) => {
        const isActive = btn === button;
        btn.classList.toggle('active', isActive);
        btn.classList.toggle('is-active', isActive);
      });

      scope.querySelectorAll('[data-mcp-tools-custom-tool-handler-panel]').forEach((panel) => {
        if (!(panel instanceof HTMLElement)) {
          return;
        }
        panel.hidden = panel.getAttribute('data-mcp-tools-custom-tool-handler-panel') !== handler;
      });
    });
  });

  const paramsBody = scope.querySelector('[data-mcp-tools-custom-tool-params]');
  const addParamButton = scope.querySelector('[data-mcp-tools-custom-tool-add-param]');

  addParamButton?.addEventListener('click', () => {
    const firstRow = paramsBody?.querySelector('[data-mcp-tools-custom-tool-param-row]');
    if (!(firstRow instanceof HTMLTableRowElement) || !(paramsBody instanceof HTMLElement)) {
      return;
    }

    const clone = firstRow.cloneNode(true);
    if (!(clone instanceof HTMLTableRowElement)) {
      return;
    }

    clone.querySelectorAll('input').forEach((input) => {
      if (input instanceof HTMLInputElement) {
        input.value = input.type === 'checkbox' ? '' : '';
        input.checked = false;
      }
    });
    clone.querySelectorAll('select').forEach((select) => {
      if (select instanceof HTMLSelectElement) {
        select.selectedIndex = 0;
      }
    });

    const removeButton = clone.querySelector('[data-mcp-tools-custom-tool-remove-param]');
    if (removeButton instanceof HTMLButtonElement) {
      removeButton.hidden = false;
    }

    paramsBody.appendChild(clone);
    bindCustomToolParamRow(clone);
    updateCustomToolParamRemoveButtons(paramsBody);
  });

  paramsBody?.querySelectorAll('[data-mcp-tools-custom-tool-param-row]').forEach((row) => {
    bindCustomToolParamRow(row);
  });
  updateCustomToolParamRemoveButtons(paramsBody);
}

function bindCustomToolParamRow(row) {
  row.querySelector('[data-mcp-tools-custom-tool-remove-param]')?.addEventListener('click', () => {
    const paramsBody = row.closest('[data-mcp-tools-custom-tool-params]');
    row.remove();
    updateCustomToolParamRemoveButtons(paramsBody);
  });
}

function updateCustomToolParamRemoveButtons(paramsBody) {
  if (!(paramsBody instanceof HTMLElement)) {
    return;
  }

  const rows = paramsBody.querySelectorAll('[data-mcp-tools-custom-tool-param-row]');
  rows.forEach((row, index) => {
    const removeButton = row.querySelector('[data-mcp-tools-custom-tool-remove-param]');
    if (removeButton instanceof HTMLButtonElement) {
      removeButton.hidden = rows.length <= 1;
    }
  });
}

function submitCustomToolFromOverlay(overlay) {
  const form = overlay.querySelector('[data-mcp-tools-custom-tool-form]');
  if (!(form instanceof HTMLElement)) {
    return;
  }

  const labelInput = form.querySelector('[data-mcp-tools-custom-tool-field="label"]');
  const descriptionInput = form.querySelector('[data-mcp-tools-custom-tool-field="description"]');

  const label = labelInput instanceof HTMLInputElement ? labelInput.value.trim() : '';
  const description = descriptionInput instanceof HTMLTextAreaElement ? descriptionInput.value.trim() : '';

  const activeHandlerButton = form.querySelector('[data-mcp-tools-custom-tool-handler].active, [data-mcp-tools-custom-tool-handler].is-active');
  const handlerType = activeHandlerButton?.getAttribute('data-mcp-tools-custom-tool-handler') ?? 'php';

  let handlerValue = '';
  if (handlerType === 'rest') {
    const input = form.querySelector('[data-mcp-tools-custom-tool-field="handlerValueRest"]');
    handlerValue = input instanceof HTMLInputElement ? input.value.trim() : '';
  } else if (handlerType === 'webhook') {
    const input = form.querySelector('[data-mcp-tools-custom-tool-field="handlerValueWebhook"]');
    handlerValue = input instanceof HTMLInputElement ? input.value.trim() : '';
  } else {
    const input = form.querySelector('[data-mcp-tools-custom-tool-field="handlerValue"]');
    handlerValue = input instanceof HTMLInputElement ? input.value.trim() : '';
  }

  if (label === '' || handlerValue === '') {
    Notification.warning(lang('mcpTools.js.customToolValidation', 'Label and handler configuration are required'));
    return;
  }

  const parameters = [];
  form.querySelectorAll('[data-mcp-tools-custom-tool-param-row]').forEach((row) => {
    const nameInput = row.querySelector('[data-param-name]');
    const name = nameInput instanceof HTMLInputElement ? nameInput.value.trim() : '';
    if (name === '') {
      return;
    }

    const typeInput = row.querySelector('[data-param-type]');
    const requiredInput = row.querySelector('[data-param-required]');
    const descriptionField = row.querySelector('[data-param-description]');

    parameters.push({
      name,
      type: typeInput instanceof HTMLSelectElement ? typeInput.value : 'string',
      required: requiredInput instanceof HTMLInputElement ? requiredInput.checked : false,
      description: descriptionField instanceof HTMLInputElement ? descriptionField.value.trim() : '',
    });
  });

  const editUid = form.dataset.editUid ? parseInt(form.dataset.editUid, 10) : 0;
  const isEdit = Number.isInteger(editUid) && editUid > 0;

  const payload = { label, description, handlerType, handlerValue, parameters };
  if (isEdit) {
    payload.uid = editUid;
  }

  const endpoint = isEdit
    ? TYPO3.settings.ajaxUrls.nst3af_mcp_tools_custom_update
    : TYPO3.settings.ajaxUrls.nst3af_mcp_tools_custom_create;
  const failureMessage = isEdit
    ? lang('mcpTools.js.customToolUpdateFailed', 'Could not update custom tool')
    : lang('mcpTools.js.customToolCreateFailed', 'Could not create custom tool');
  const successMessage = isEdit
    ? lang('mcpTools.js.customToolUpdateSuccess', 'Custom tool updated')
    : lang('mcpTools.js.customToolCreateSuccess', 'Custom tool created');

  new AjaxRequest(endpoint)
    .post(payload)
    .then(async (response) => {
      const data = await response.resolve();
      if (!data.success) {
        throw new Error(data.message ?? failureMessage);
      }

      closeCustomToolOverlay(overlay);
      persistMcpToolsFlash(successMessage);
      persistMcpToolsSidebar('custom-tools');
      window.location.reload();
    })
    .catch((error) => Notification.error(error.message));
}

function initCustomToolList(root) {
  const overlay = document.querySelector('[data-mcp-tools-custom-tool-overlay]')
    ?? root.querySelector('[data-mcp-tools-custom-tool-overlay]');

  root.querySelectorAll('[data-mcp-tools-custom-tool-edit]').forEach((button) => {
    button.addEventListener('click', () => {
      if (!(overlay instanceof HTMLElement)) {
        return;
      }

      let parameters = [];
      try {
        const raw = button.getAttribute('data-custom-tool-parameters');
        const parsed = raw ? JSON.parse(raw) : [];
        if (Array.isArray(parsed)) {
          parameters = parsed;
        }
      } catch (error) {
        parameters = [];
      }

      openCustomToolOverlay(overlay, {
        uid: button.getAttribute('data-custom-tool-uid') ?? '',
        label: button.getAttribute('data-custom-tool-label') ?? '',
        description: button.getAttribute('data-custom-tool-description') ?? '',
        handlerType: button.getAttribute('data-custom-tool-handler-type') ?? 'php',
        handlerValue: button.getAttribute('data-custom-tool-handler-value') ?? '',
        parameters,
      });
    });
  });

  root.querySelectorAll('[data-mcp-tools-custom-tool-delete]').forEach((button) => {
    button.addEventListener('click', () => {
      const uid = button.getAttribute('data-custom-tool-uid');
      if (!uid) {
        return;
      }

      Modal.confirm(
        lang('mcpTools.js.customToolDeleteConfirmTitle', 'Delete custom tool'),
        lang('mcpTools.js.customToolDeleteConfirmBody', 'Do you want to delete this custom tool?'),
        Severity.error,
        [
          {
            text: TYPO3?.lang?.['button.cancel'] || lang('mcpTools.js.cancel', 'Cancel'),
            btnClass: 'btn-default',
            active: true,
            trigger: () => Modal.dismiss(),
          },
          {
            text: lang('mcpTools.js.customToolDeleteButton', 'Delete'),
            btnClass: 'btn-danger',
            trigger: () => {
              Modal.dismiss();
              deleteCustomTool(uid);
            },
          },
        ],
      );
    });
  });
}

function deleteCustomTool(uid) {
  new AjaxRequest(TYPO3.settings.ajaxUrls.nst3af_mcp_tools_custom_delete)
    .post({ uid })
    .then(async (response) => {
      const data = await response.resolve();
      if (!data.success) {
        throw new Error(data.message ?? lang('mcpTools.js.customToolDeleteFailed', 'Could not delete custom tool'));
      }
      persistMcpToolsFlash(lang('mcpTools.js.customToolDeleteSuccess', 'Custom tool deleted'));
      persistMcpToolsSidebar('custom-tools');
      window.location.reload();
    })
    .catch((error) => Notification.error(error.message));
}

function initPrompts(root) {
  if (root.dataset.mcpPromptsInit === '1') {
    return;
  }
  root.dataset.mcpPromptsInit = '1';

  const form = root.querySelector('[data-mcp-tools-prompt-form]');
  const createButton = root.querySelector('[data-mcp-tools-prompt-create]');
  const saveButton = root.querySelector('[data-mcp-tools-prompt-save]');
  const cancelButton = root.querySelector('[data-mcp-tools-prompt-cancel]');

  createButton?.addEventListener('click', () => {
    showPromptForm(form, { arguments: '[]' });
    form?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  });
  cancelButton?.addEventListener('click', () => hidePromptForm(form));

  root.querySelectorAll('[data-mcp-tools-prompt-edit]').forEach((button) => {
    button.addEventListener('click', () => {
      const row = button.closest('[data-mcp-tools-prompt-row]');
      const body = row?.querySelector('[data-mcp-prompt-store-body]');
      const args = row?.querySelector('[data-mcp-prompt-store-args]');
      showPromptForm(form, {
        uid: button.getAttribute('data-prompt-uid') ?? '0',
        name: button.getAttribute('data-prompt-name') ?? '',
        description: button.getAttribute('data-prompt-description') ?? '',
        body: body instanceof HTMLTextAreaElement ? body.value : '',
        arguments: args instanceof HTMLTextAreaElement ? args.value : '[]',
      });
      form?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    });
  });

  root.querySelectorAll('[data-mcp-tools-prompt-delete]').forEach((button) => {
    button.addEventListener('click', () => {
      const uid = button.getAttribute('data-prompt-uid');
      if (!uid) {
        return;
      }
      const promptName = button.getAttribute('data-prompt-name') ?? '';
      const message = lang(
        'mcpTools.js.promptDeleteConfirm',
        'Delete prompt template "%s"?',
        promptName,
      );
      Modal.confirm(
        lang('mcpTools.js.promptDeleteTitle', 'Delete prompt template'),
        message,
        Severity.error,
        [
          {
            text: TYPO3?.lang?.['button.cancel'] || 'Cancel',
            btnClass: 'btn-default',
            active: true,
            trigger: () => Modal.dismiss(),
          },
          {
            text: lang('mcpTools.js.promptDeleteAction', 'Delete'),
            btnClass: 'btn-danger',
            trigger: () => {
              Modal.dismiss();
              new AjaxRequest(TYPO3.settings.ajaxUrls.nst3af_mcp_tools_prompt_delete)
                .post({ uid })
                .then(async (response) => {
                  const data = await response.resolve();
                  if (!data.success) {
                    throw new Error(data.message ?? lang('mcpTools.js.promptDeleteFailed', 'Could not delete prompt'));
                  }
                  persistMcpToolsFlash(lang('mcpTools.js.promptDeleteSuccess', 'Prompt template deleted'));
                  persistMcpToolsSidebar('prompts');
                  window.location.reload();
                })
                .catch((error) => Notification.error(
                  resolveErrorMessage(error, lang('mcpTools.js.promptDeleteFailed', 'Could not delete prompt')),
                ));
            },
          },
        ],
      );
    });
  });

  saveButton?.addEventListener('click', () => {
    if (!(form instanceof HTMLElement) || saveButton instanceof HTMLButtonElement && saveButton.disabled) {
      return;
    }

    const uid = form.querySelector('[data-mcp-tools-prompt-form-uid]')?.value ?? '0';
    const name = form.querySelector('[data-mcp-tools-prompt-form-name]')?.value?.trim() ?? '';
    const argumentsRaw = form.querySelector('[data-mcp-tools-prompt-form-arguments]')?.value ?? '[]';

    if (!PROMPT_NAME_PATTERN.test(name)) {
      Notification.error(lang('mcpTools.js.promptNameInvalid', 'Name must be snake_case (lowercase, digits, underscores).'));
      return;
    }

    let argumentsPayload = [];
    try {
      argumentsPayload = parsePromptArgumentsJson(argumentsRaw);
    } catch (error) {
      Notification.error(
        resolveErrorMessage(error, lang('mcpTools.js.promptArgumentsInvalid', 'Arguments must be valid JSON array.')),
      );
      return;
    }

    const payload = {
      uid,
      name,
      description: form.querySelector('[data-mcp-tools-prompt-form-description]')?.value?.trim() ?? '',
      templateBody: form.querySelector('[data-mcp-tools-prompt-form-body]')?.value?.trim() ?? '',
      arguments: argumentsPayload,
    };

    if (payload.templateBody === '') {
      Notification.error(lang('mcpTools.js.promptBodyRequired', 'Template body is required.'));
      return;
    }

    const route = Number(uid) > 0
      ? TYPO3.settings.ajaxUrls.nst3af_mcp_tools_prompt_update
      : TYPO3.settings.ajaxUrls.nst3af_mcp_tools_prompt_create;

    if (saveButton instanceof HTMLButtonElement) {
      saveButton.disabled = true;
    }

    new AjaxRequest(route)
      .post(payload)
      .then(async (response) => {
        const data = await response.resolve();
        if (!data.success) {
          throw new Error(data.message ?? lang('mcpTools.js.promptSaveFailed', 'Could not save prompt'));
        }

        const isEdit = Number(uid) > 0;
        persistMcpToolsFlash(
          isEdit
            ? lang('mcpTools.js.promptUpdateSuccess', 'Prompt template updated')
            : lang('mcpTools.js.promptCreateSuccess', 'Prompt template created'),
        );
        persistMcpToolsSidebar('prompts');
        window.location.reload();
      })
      .catch((error) => Notification.error(
        resolveErrorMessage(error, lang('mcpTools.js.promptSaveFailed', 'Could not save prompt')),
      ))
      .finally(() => {
        if (saveButton instanceof HTMLButtonElement) {
          saveButton.disabled = false;
        }
      });
  });
}

function showPromptForm(form, values) {
  if (!(form instanceof HTMLElement)) {
    return;
  }

  const isEdit = Number(values.uid ?? '0') > 0;
  form.hidden = false;
  const setValue = (selector, value) => {
    const el = form.querySelector(selector);
    if (el instanceof HTMLInputElement || el instanceof HTMLTextAreaElement) {
      el.value = value ?? '';
    }
  };

  setValue('[data-mcp-tools-prompt-form-uid]', String(values.uid ?? '0'));
  setValue('[data-mcp-tools-prompt-form-name]', values.name);
  setValue('[data-mcp-tools-prompt-form-description]', values.description);
  setValue('[data-mcp-tools-prompt-form-body]', values.body);
  setValue('[data-mcp-tools-prompt-form-arguments]', values.arguments ?? '[]');

  const nameInput = form.querySelector('[data-mcp-tools-prompt-form-name]');
  if (nameInput instanceof HTMLInputElement) {
    nameInput.readOnly = isEdit;
  }

  const title = form.querySelector('[data-mcp-tools-prompt-form-title]');
  if (title instanceof HTMLElement) {
    title.textContent = isEdit
      ? lang('mcpTools.js.promptFormEdit', 'Edit prompt template')
      : lang('mcpTools.js.promptFormCreate', 'Add prompt template');
  }
}

function hidePromptForm(form) {
  if (!(form instanceof HTMLElement)) {
    return;
  }

  const resetValue = (selector, value) => {
    const el = form.querySelector(selector);
    if (el instanceof HTMLInputElement || el instanceof HTMLTextAreaElement) {
      el.value = value ?? '';
    }
  };

  resetValue('[data-mcp-tools-prompt-form-uid]', '0');
  resetValue('[data-mcp-tools-prompt-form-name]', '');
  resetValue('[data-mcp-tools-prompt-form-description]', '');
  resetValue('[data-mcp-tools-prompt-form-body]', '');
  resetValue('[data-mcp-tools-prompt-form-arguments]', '[]');

  const nameInput = form.querySelector('[data-mcp-tools-prompt-form-name]');
  if (nameInput instanceof HTMLInputElement) {
    nameInput.readOnly = false;
  }

  form.hidden = true;
}

function formatNumber(value) {
  return new Intl.NumberFormat().format(Number(value) || 0);
}

/**
 * Pretty-print JSON for playground output.
 * Parses JSON strings (including nested MCP contents[].text payloads).
 */
function beautifyJsonDisplay(value) {
  const normalized = normalizeJsonForDisplay(value);
  if (normalized === null || normalized === undefined || normalized === '') {
    return '';
  }
  if (typeof normalized === 'string') {
    return normalized;
  }
  try {
    return JSON.stringify(normalized, null, 2);
  } catch {
    return String(normalized);
  }
}

function normalizeJsonForDisplay(value) {
  if (value === null || value === undefined) {
    return value;
  }

  if (typeof value === 'string') {
    const trimmed = value.trim();
    if (
      (trimmed.startsWith('{') && trimmed.endsWith('}'))
      || (trimmed.startsWith('[') && trimmed.endsWith(']'))
    ) {
      try {
        return normalizeJsonForDisplay(JSON.parse(trimmed));
      } catch {
        return value;
      }
    }
    return value;
  }

  if (Array.isArray(value)) {
    return value.map((item) => normalizeJsonForDisplay(item));
  }

  if (typeof value === 'object') {
    const normalized = {};
    Object.entries(value).forEach(([key, entryValue]) => {
      if (key === 'contents' && Array.isArray(entryValue)) {
        normalized.contents = entryValue.map((item) => {
          if (item && typeof item === 'object' && item.type === 'text' && typeof item.text === 'string') {
            return { ...item, text: normalizeJsonForDisplay(item.text) };
          }
          return normalizeJsonForDisplay(item);
        });
        return;
      }
      normalized[key] = normalizeJsonForDisplay(entryValue);
    });
    return normalized;
  }

  return value;
}

function escapeHtml(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function initSkillHub(root) {
  const urlInput = root.querySelector('[data-mcp-tools-skill-url]');
  const fileInput = root.querySelector('[data-mcp-tools-skill-file]');
  const dropzone = root.querySelector('[data-mcp-tools-skill-dropzone]');
  const fileNameEl = root.querySelector('[data-mcp-tools-skill-filename]');
  const installFileButton = root.querySelector('[data-mcp-tools-skill-import-file]');
  let selectedFileContent = '';

  root.querySelector('[data-mcp-tools-skill-import-url]')?.addEventListener('click', () => {
    const url = urlInput instanceof HTMLInputElement ? urlInput.value.trim() : '';
    if (url === '') {
      Notification.warning(lang('mcpTools.js.skillUrlRequired', 'Paste a skill URL first'));
      return;
    }

    new AjaxRequest(TYPO3.settings.ajaxUrls.nst3af_mcp_tools_skill_import)
      .post({ source: 'url', url })
      .then(async (response) => {
        const data = await response.resolve();
        if (!data.success) {
          throw new Error(data.message ?? lang('mcpTools.js.skillImportFailed', 'Skill import failed'));
        }
        Notification.success(lang('mcpTools.js.skillImportOk', 'Skill imported'));
        window.location.reload();
      })
      .catch((error) => Notification.error(error.message));
  });

  const openFilePicker = () => {
    if (fileInput instanceof HTMLInputElement) {
      fileInput.click();
    }
  };

  dropzone?.addEventListener('click', openFilePicker);
  dropzone?.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      openFilePicker();
    }
  });

  dropzone?.addEventListener('dragover', (event) => {
    event.preventDefault();
    dropzone.classList.add('is-dragover');
  });

  dropzone?.addEventListener('dragleave', () => {
    dropzone.classList.remove('is-dragover');
  });

  dropzone?.addEventListener('drop', (event) => {
    event.preventDefault();
    dropzone.classList.remove('is-dragover');
    const file = event.dataTransfer?.files?.[0];
    if (file) {
      handleSkillFile(file);
    }
  });

  fileInput?.addEventListener('change', () => {
    if (!(fileInput instanceof HTMLInputElement) || !fileInput.files?.length) {
      return;
    }
    handleSkillFile(fileInput.files[0]);
  });

  function handleSkillFile(file) {
    if (!file.name.toLowerCase().endsWith('.md')) {
      Notification.warning(lang('mcpTools.js.skillMdOnly', 'Only .md skill files are supported'));
      return;
    }

    const reader = new FileReader();
    reader.onload = () => {
      selectedFileContent = typeof reader.result === 'string' ? reader.result : '';
      if (fileNameEl instanceof HTMLElement) {
        fileNameEl.textContent = file.name;
        fileNameEl.hidden = false;
      }
      if (installFileButton instanceof HTMLButtonElement) {
        installFileButton.disabled = selectedFileContent.trim() === '';
      }
      dropzone?.classList.add('has-file');
    };
    reader.readAsText(file);
  }

  installFileButton?.addEventListener('click', () => {
    if (selectedFileContent.trim() === '') {
      Notification.warning(lang('mcpTools.js.skillChooseFile', 'Choose a skill file first'));
      return;
    }

    const fileName = fileInput instanceof HTMLInputElement && fileInput.files?.[0]
      ? fileInput.files[0].name
      : '';

    installFileButton.disabled = true;
    new AjaxRequest(TYPO3.settings.ajaxUrls.nst3af_mcp_tools_skill_import)
      .post({ source: 'file', body: selectedFileContent, fileName })
      .then(async (response) => {
        const data = await response.resolve();
        if (!data.success) {
          throw new Error(data.message ?? lang('mcpTools.js.skillImportFailed', 'Skill import failed'));
        }
        Notification.success(lang('mcpTools.js.skillImportOk', 'Skill imported'));
        window.location.reload();
      })
      .catch((error) => {
        Notification.error(error.message);
        installFileButton.disabled = false;
      });
  });

  root.querySelectorAll('[data-mcp-tools-skill-install]').forEach((button) => {
    button.addEventListener('click', () => {
      new AjaxRequest(TYPO3.settings.ajaxUrls.nst3af_mcp_tools_skill_import)
        .post({
          source: 'manual',
          name: button.getAttribute('data-skill-name') ?? '',
          triggerKeyword: button.getAttribute('data-skill-trigger') ?? '',
          body: button.getAttribute('data-skill-body') ?? '',
        })
        .then(async (response) => {
          const data = await response.resolve();
          if (!data.success) {
            throw new Error(data.message ?? lang('mcpTools.js.skillImportFailed', 'Skill import failed'));
          }
          window.location.reload();
        })
        .catch((error) => Notification.error(error.message));
    });
  });

  root.querySelectorAll('[data-mcp-tools-skill-remove]').forEach((button) => {
    button.addEventListener('click', () => {
      const uid = button.getAttribute('data-skill-uid');
      if (!uid) {
        return;
      }

      new AjaxRequest(TYPO3.settings.ajaxUrls.nst3af_mcp_tools_skill_remove)
        .post({ uid })
        .then(async (response) => {
          const data = await response.resolve();
          if (!data.success) {
            throw new Error(data.message ?? lang('mcpTools.js.skillRemoveFailed', 'Could not remove skill'));
          }
          window.location.reload();
        })
        .catch((error) => Notification.error(error.message));
    });
  });
}

function enterRowEdit(row) {
  if (!row) {
    return;
  }

  const labelEl = row.querySelector('.aiu-table-row-label');
  const prefixEl = row.querySelector('.aiu-table-row-prefix');
  const labelInput = row.querySelector('[data-edit-label]');
  const prefixInput = row.querySelector('[data-edit-prefix]');

  if (labelInput instanceof HTMLInputElement && labelEl) {
    labelInput.value = labelEl.textContent?.trim() ?? '';
  }
  if (prefixInput instanceof HTMLInputElement && prefixEl) {
    prefixInput.value = prefixEl.textContent?.trim() ?? '';
  }

  row.classList.add('is-editing');
  row.querySelectorAll('.aiu-table-row-label, .aiu-table-row-prefix').forEach((el) => {
    el.hidden = true;
  });
  row.querySelectorAll('.aiu-table-row-edit').forEach((el) => {
    el.hidden = false;
  });

  const saveButton = row.querySelector('[data-mcp-tools-save-row]');
  const cancelButton = row.querySelector('[data-mcp-tools-cancel-row]');
  if (saveButton instanceof HTMLButtonElement) {
    saveButton.disabled = false;
  }
  if (cancelButton instanceof HTMLButtonElement) {
    cancelButton.disabled = false;
  }
}

function resetRowEdit(row) {
  if (!row) {
    return;
  }

  const labelEl = row.querySelector('.aiu-table-row-label');
  const prefixEl = row.querySelector('.aiu-table-row-prefix');
  const labelInput = row.querySelector('[data-edit-label]');
  const prefixInput = row.querySelector('[data-edit-prefix]');

  if (labelInput instanceof HTMLInputElement && labelEl) {
    labelInput.value = labelEl.textContent?.trim() ?? '';
  }
  if (prefixInput instanceof HTMLInputElement && prefixEl) {
    prefixInput.value = prefixEl.textContent?.trim() ?? '';
  }

  row.classList.remove('is-editing');
  row.querySelectorAll('.aiu-table-row-label, .aiu-table-row-prefix').forEach((el) => {
    el.hidden = false;
  });
  row.querySelectorAll('.aiu-table-row-edit').forEach((el) => {
    el.hidden = true;
  });

  const saveButton = row.querySelector('[data-mcp-tools-save-row]');
  const cancelButton = row.querySelector('[data-mcp-tools-cancel-row]');
  if (saveButton instanceof HTMLButtonElement) {
    saveButton.disabled = true;
  }
  if (cancelButton instanceof HTMLButtonElement) {
    cancelButton.disabled = true;
  }
}

function initCopySnippet(root) {
  root.querySelectorAll('[data-mcp-tools-copy-snippet]').forEach((button) => {
    button.addEventListener('click', () => {
      const copyText = button.getAttribute('data-copy-text');
      const snippet = button.closest('.aiu-mcp-code')?.querySelector('pre, code');
      const text = copyText ?? snippet?.textContent ?? '';
      if (!text) {
        return;
      }

      navigator.clipboard.writeText(text).then(() => {
        Notification.success(lang('mcpTools.js.copySuccess', 'Snippet copied to clipboard'));
      });
    });
  });

  const legacySnippet = root.querySelector('[data-mcp-tools-snippet]');
  const legacyButton = root.querySelector('[data-mcp-tools-copy-legacy]');
  if (legacyButton instanceof HTMLButtonElement && legacySnippet instanceof HTMLElement) {
    legacyButton.addEventListener('click', () => {
      const text = legacySnippet.textContent ?? '';
      if (!text) {
        return;
      }

      navigator.clipboard.writeText(text).then(() => {
        Notification.success(lang('mcpTools.js.copySuccess', 'Snippet copied to clipboard'));
      });
    });
  }
}
