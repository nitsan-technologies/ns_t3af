import DocumentService from '@typo3/core/document-service.js';

const STORAGE_PREFS_KEY = 'nst3af.agent.prefs';
const HOTKEY_DEFAULT = 'mod+shift+k';
const HOTKEY_OPT_IN_K = 'mod+k';

DocumentService.ready().then(() => {
  const root = document.querySelector('[data-ai-agent-settings]');
  if (root instanceof HTMLElement) {
    initAiAgentSettings(root);
  }
});

/**
 * @param {HTMLElement} scope
 */
function initAiAgentSettings(scope) {
  const tabs = scope.querySelectorAll('[data-ai-agent-tab]');
  const panels = scope.querySelectorAll('[data-ai-agent-panel]');

  tabs.forEach((tab) => {
    tab.addEventListener('click', () => {
      const key = tab.getAttribute('data-ai-agent-tab');
      if (!key) {
        return;
      }

      tabs.forEach((item) => {
        const active = item === tab;
        item.classList.toggle('active', active);
        item.classList.toggle('is-active', active);
        item.setAttribute('aria-selected', active ? 'true' : 'false');
      });

      panels.forEach((panel) => {
        const active = panel.getAttribute('data-ai-agent-panel') === key;
        panel.classList.toggle('is-active', active);
        panel.classList.toggle('is-hidden', !active);
        panel.hidden = !active;
      });
    });
  });

  initHotkeyPrefs(scope);
  initCollapsibleCards(scope);
}

/**
 * Makes module cards collapsible (default expanded). Scoped to AI Agent settings only.
 *
 * @param {HTMLElement} scope
 */
function initCollapsibleCards(scope) {
  scope.querySelectorAll('.card > .card-header').forEach((header) => {
    const card = header.parentElement;
    if (!(card instanceof HTMLElement) || card.closest('[data-ai-agent-settings]') !== scope) {
      return;
    }
    if (header.querySelector('.aiu-agent-card__toggle')) {
      return;
    }

    card.classList.add('aiu-agent-card--collapsible', 'aiu-agent-card--expanded');

    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'aiu-agent-card__toggle';
    toggle.setAttribute('aria-expanded', 'true');

    const title = document.createElement('span');
    title.className = 'aiu-agent-card__title';
    while (header.firstChild) {
      title.appendChild(header.firstChild);
    }

    const chevron = document.createElement('span');
    chevron.className = 'aiu-agent-card__chevron';
    chevron.setAttribute('aria-hidden', 'true');

    header.classList.add('aiu-agent-card__header');
    toggle.append(title, chevron);
    header.appendChild(toggle);

    const body = document.createElement('div');
    body.className = 'aiu-agent-card__body';
    let sibling = header.nextElementSibling;
    while (sibling) {
      const next = sibling.nextElementSibling;
      body.appendChild(sibling);
      sibling = next;
    }
    card.appendChild(body);

    toggle.addEventListener('click', () => {
      const expanded = !card.classList.contains('aiu-agent-card--expanded');
      card.classList.toggle('aiu-agent-card--expanded', expanded);
      card.classList.toggle('aiu-agent-card--collapsed', !expanded);
      toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    });
  });
}

/**
 * @param {HTMLElement} scope
 */
function initHotkeyPrefs(scope) {
  const form = scope.querySelector('[data-ai-agent-hotkey-form]');
  if (!(form instanceof HTMLElement)) {
    return;
  }

  const status = form.querySelector('[data-ai-agent-hotkey-status]');
  const radios = form.querySelectorAll('input[name="nst3af_agent_hotkey"]');
  const current = readHotkey();

  radios.forEach((radio) => {
    if (!(radio instanceof HTMLInputElement)) {
      return;
    }
    radio.checked = radio.value === current;
    radio.addEventListener('change', () => {
      if (!radio.checked) {
        return;
      }
      if (radio.value !== HOTKEY_DEFAULT && radio.value !== HOTKEY_OPT_IN_K) {
        return;
      }
      writeHotkey(radio.value);
      if (status) {
        status.hidden = false;
        status.textContent = status.getAttribute('data-saved-label')
          ?? 'Saved for this browser. Reload other open backend tabs to apply.';
      }
    });
  });
}

/**
 * @returns {string}
 */
function readHotkey() {
  try {
    const prefs = JSON.parse(localStorage.getItem(STORAGE_PREFS_KEY) ?? '{}');
    if (prefs?.hotkey === HOTKEY_OPT_IN_K || prefs?.hotkey === HOTKEY_DEFAULT) {
      return prefs.hotkey;
    }
  } catch {
    // ignore
  }
  return HOTKEY_DEFAULT;
}

/**
 * @param {string} hotkey
 */
function writeHotkey(hotkey) {
  let prefs = {};
  try {
    prefs = JSON.parse(localStorage.getItem(STORAGE_PREFS_KEY) ?? '{}') || {};
  } catch {
    prefs = {};
  }
  prefs.hotkey = hotkey;
  localStorage.setItem(STORAGE_PREFS_KEY, JSON.stringify(prefs));
}

export default {};
