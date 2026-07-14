import Modal from '@typo3/backend/modal.js';
import Severity from '@typo3/backend/severity.js';
import { observeBrowserAutocomplete } from './disable-browser-autocomplete.js';

const root = document.querySelector('[data-aiu-scheduler-root]');

if (root) {
  observeBrowserAutocomplete(root);
  initSchedulerCli(root);
}

function initSchedulerCli(scope) {
  initLibraryFilters(scope);
  initTaskFilters(scope);
  initExtensionCards(scope);
  initCommandPanels(scope);
  initRunButtons(scope);
}

function parseJsonAttribute(scope, key) {
  try {
    const raw = scope.getAttribute(key) || '[]';
    return JSON.parse(raw);
  } catch {
    return [];
  }
}

function esc(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function openFormModal(title, html, submitText, onSubmit) {
  const modal = Modal.advanced({
    title,
    content: '',
    severity: Severity.info,
    staticBackdrop: true,
    size: Modal.sizes.large,
    buttons: [
      {
        text: TYPO3?.lang?.['button.cancel'] || 'Cancel',
        btnClass: 'btn-default',
        trigger: () => Modal.dismiss(),
      },
      {
        text: submitText,
        btnClass: 'btn-primary',
        active: true,
        trigger: () => onSubmit(modal),
      },
    ],
  });

  modal.addEventListener('typo3-modal-shown', () => {
    const body = modal.querySelector('.modal-body');
    if (body) {
      body.innerHTML = html;
      body.classList.add('aiu-scheduler-cli__modal-body');
    }
  });
}

function submitFormFromModal(modal, selector) {
  const form = modal.querySelector(selector);
  if (!(form instanceof HTMLFormElement)) {
    return;
  }
  form.requestSubmit();
}

function buildArgRows(command) {
  const rows = [];
  (command.arguments || []).forEach((arg) => {
    rows.push(`
      <div class="form-group">
        <label class="form-label">${esc(arg.label)}</label>
        <input class="form-control" name="params[${esc(arg.name)}]" ${Number(arg.required) === 1 ? 'required' : ''} placeholder="${esc(arg.placeholder || '')}" />
      </div>
    `);
  });
  (command.options || []).forEach((opt) => {
    if (Number(opt.hasValue) === 1) {
      const defaultValue = opt.default || opt.placeholder || '';
      rows.push(`
        <div class="form-group">
          <label class="form-label">${esc(opt.label)}</label>
          <input class="form-control" name="params[--${esc(opt.name)}]" value="${esc(defaultValue)}" placeholder="${esc(opt.placeholder || defaultValue)}" />
        </div>
      `);
    } else {
      rows.push(`
        <div class="form-group form-check">
          <input class="form-check-input" type="checkbox" id="opt-${esc(opt.name)}" />
          <label class="form-check-label" for="opt-${esc(opt.name)}">${esc(opt.label)}</label>
          <input type="hidden" name="params[--${esc(opt.name)}]" value="" />
        </div>
      `);
    }
  });
  return rows.join('');
}

function initRunButtons(scope) {
  const commands = parseJsonAttribute(scope, 'data-commands-json');
  const runUri = scope.getAttribute('data-run-uri') || '';
  scope.querySelectorAll('[data-scheduler-run]').forEach((button) => {
    button.addEventListener('click', () => {
      const commandName = button.getAttribute('data-command') || '';
      const command = commands.find((item) => item.command === commandName) || { command: commandName, arguments: [], options: [] };
      const html = `
        <form method="post" action="${esc(runUri)}" data-scheduler-run-form>
          <input type="hidden" name="command" value="${esc(command.command)}" />
          <div class="mb-2">${esc(button.getAttribute('data-name') || command.command)}</div>
          ${buildArgRows(command)}
        </form>
      `;
      openFormModal('Run Command', html, 'Run', (modal) => submitFormFromModal(modal, '[data-scheduler-run-form]'));
    });
  });
}

function setFilterChipActive(chips, activeChip) {
  chips.forEach((chip) => {
    chip.classList.remove('is-active', 'active');
  });
  activeChip.classList.add('is-active', 'active');
}

function initLibraryFilters(scope) {
  const chips = Array.from(scope.querySelectorAll('[data-scheduler-chip]'));
  const cards = Array.from(scope.querySelectorAll('[data-scheduler-cli-card]'));
  if (chips.length === 0 && cards.length === 0) {
    return;
  }

  const apply = () => {
    const activeChip = chips.find((chip) => chip.classList.contains('is-active'));
    const ext = activeChip?.getAttribute('data-scheduler-chip') || 'all';

    cards.forEach((card) => {
      const cardExt = card.getAttribute('data-scheduler-extension') || '';
      card.hidden = ext !== 'all' && cardExt !== ext;
    });
  };

  chips.forEach((chip) => {
    chip.addEventListener('click', () => {
      setFilterChipActive(chips, chip);
      apply();
    });
  });

  apply();
}

function initTaskFilters(scope) {
  const chips = Array.from(scope.querySelectorAll('[data-scheduler-status-chip]'));
  const rows = Array.from(scope.querySelectorAll('[data-scheduler-task-row]'));
  const emptyRow = scope.querySelector('[data-scheduler-task-empty]');
  if (chips.length === 0) {
    return;
  }

  const matchesStatus = (row, status) => {
    const disabled = Number(row.getAttribute('data-task-disabled') || '0');
    const failing = Number(row.getAttribute('data-task-failing') || '0');

    switch (status) {
      case 'enabled':
        return disabled === 0;
      case 'disabled':
        return disabled === 1;
      case 'failing':
        return failing === 1;
      default:
        return true;
    }
  };

  const apply = (status) => {
    let visible = 0;
    rows.forEach((row) => {
      const show = matchesStatus(row, status);
      row.hidden = !show;
      if (show) {
        visible += 1;
      }
    });

    if (emptyRow instanceof HTMLElement) {
      emptyRow.hidden = visible > 0;
    }
  };

  chips.forEach((chip) => {
    chip.addEventListener('click', () => {
      const status = chip.getAttribute('data-scheduler-status-chip') || 'all';
      setFilterChipActive(chips, chip);
      apply(status);
    });
  });

  apply('all');
}

function initExtensionCards(scope) {
  scope.querySelectorAll('[data-scheduler-cli-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
      const card = button.closest('[data-scheduler-cli-card]');
      if (!card) {
        return;
      }

      const tools = card.querySelector('.aiu-scheduler-cli__tools');
      if (!tools) {
        return;
      }

      const open = tools.hidden;
      tools.hidden = !open;
      button.setAttribute('aria-expanded', open ? 'true' : 'false');
      button.textContent = open ? 'Collapse' : 'View Commands';

      if (!open) {
        collapseAllCommandPanels(card);
      }
    });
  });

  scope.querySelectorAll('[data-scheduler-cli-command-tag]').forEach((tag) => {
    tag.addEventListener('click', () => {
      openCommandFromTag(tag);
    });
  });
}

function initCommandPanels(scope) {
  scope.querySelectorAll('[data-scheduler-cli-toggle-command]').forEach((button) => {
    button.addEventListener('click', () => {
      toggleCommandPanel(button);
    });
  });
}

function toggleCommandPanel(headerButton, forceOpen = false) {
  const panel = headerButton.closest('[data-scheduler-cli-command-panel]');
  const card = headerButton.closest('[data-scheduler-cli-card]');
  const body = panel?.querySelector('.aiu-scheduler-cli__command-panel-body');
  if (!panel || !body) {
    return;
  }

  const open = forceOpen || body.hidden;
  if (open && card) {
    card.querySelectorAll('[data-scheduler-cli-command-panel]').forEach((otherPanel) => {
      if (otherPanel === panel) {
        return;
      }

      const otherBody = otherPanel.querySelector('.aiu-scheduler-cli__command-panel-body');
      const otherHeader = otherPanel.querySelector('[data-scheduler-cli-toggle-command]');
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

function collapseAllCommandPanels(card) {
  card.querySelectorAll('[data-scheduler-cli-command-panel]').forEach((panel) => {
    const body = panel.querySelector('.aiu-scheduler-cli__command-panel-body');
    const header = panel.querySelector('[data-scheduler-cli-toggle-command]');
    if (body) {
      body.hidden = true;
    }
    header?.setAttribute('aria-expanded', 'false');
    header?.classList.remove('is-open');
  });
}

function openCommandFromTag(tag) {
  const commandName = tag.getAttribute('data-scheduler-cli-command-tag');
  const card = tag.closest('[data-scheduler-cli-card]');
  if (!commandName || !card) {
    return;
  }

  const toggleButton = card.querySelector('[data-scheduler-cli-toggle]');
  const tools = card.querySelector('.aiu-scheduler-cli__tools');
  if (tools?.hidden && toggleButton instanceof HTMLButtonElement) {
    toggleButton.click();
  }

  const panel = card.querySelector(`[data-scheduler-cli-command-panel][data-command-name="${CSS.escape(commandName)}"]`);
  const header = panel?.querySelector('[data-scheduler-cli-toggle-command]');
  if (header instanceof HTMLButtonElement) {
    toggleCommandPanel(header, true);
    panel?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }
}
