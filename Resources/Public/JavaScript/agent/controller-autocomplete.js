/**
 * AgentController mixin: composer autocomplete, starters, and file attach.
 */

import { lang, errorText, escapeHtml } from './format.js';
import { ajaxUrl, resolveBackendContext } from './context.js';
import { resolveToolDisplayLabel } from './render-helpers.js';
import AjaxRequest from '@typo3/core/ajax/ajax-request.js';

export const autocompleteMethods = {
  toggleAttachMenu() {
      if (!(this.attachMenu instanceof HTMLElement)) {
        return;
      }
      const open = this.attachMenu.hidden;
      this.attachMenu.hidden = !open;
      const toggle = this.root.querySelector('[data-nst3af-agent-attach-toggle]');
      if (toggle instanceof HTMLElement) {
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      }
    },

  hideAttachMenu() {
      if (!(this.attachMenu instanceof HTMLElement)) {
        return;
      }
      this.attachMenu.hidden = true;
      const toggle = this.root.querySelector('[data-nst3af-agent-attach-toggle]');
      if (toggle instanceof HTMLElement) {
        toggle.setAttribute('aria-expanded', 'false');
      }
    },

  openFilePicker() {
      this.hideAttachMenu();
      if (this.fileInput instanceof HTMLInputElement) {
        this.fileInput.value = '';
        this.fileInput.click();
      }
    },

  openMcpToolsPicker() {
      this.hideAttachMenu();
      if (!(this.input instanceof HTMLTextAreaElement)) {
        return;
      }
      const value = this.input.value;
      if (!value.startsWith('/')) {
        this.input.value = '/' + (value.trim() === '' ? '' : value);
      }
      this.input.focus();
      const caret = this.input.value.length;
      this.input.setSelectionRange(caret, caret);
      this.autocompleteMode = 'tools';
      void this.loadAutocomplete('tools', '');
    },

  /**
     * @param {File[]} files
     */
    async uploadFiles(files) {
      if (!this.input || this.isRunning || files.length === 0) {
        return;
      }

      const url = ajaxUrl('nst3af_agent_upload');
      if (url === '') {
        return;
      }

      this.isRunning = true;
      this.showProgress(true);
      this.hideAttachMenu();

      const tokens = [];
      try {
        for (const file of files) {
          const formData = new FormData();
          formData.append('file', file);
          formData.append('directoryPath', '/user_upload/');

          const response = await fetch(url, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
          });
          const payload = await response.json();
          if (!payload?.ok) {
            throw new Error(payload?.message ?? lang('agent.upload.failed', 'Upload failed.'));
          }
          if (payload.attachment) {
            tokens.push(String(payload.attachment));
          }
        }

        if (tokens.length > 0) {
          const prefix = this.input.value.trim() === '' ? '' : `${this.input.value.trim()} `;
          this.input.value = `${prefix}${tokens.join(' ')} `;
          this.input.focus();
        }
      } catch (error) {
        const text = await errorText(error);
        this.messages.push({ role: 'assistant', content: text, meta: { type: 'error' } });
        this.renderStream();
      } finally {
        this.isRunning = false;
        this.showProgress(false);
        if (this.fileInput instanceof HTMLInputElement) {
          this.fileInput.value = '';
        }
        this.renderStream();
      }
    },

  /**
     * @param {{ executable?: Array<object>, locked?: Array<object> }} starters
     */
    renderStarters(starters) {
      if (!this.stream || this.messages.length === 0) {
        return;
      }

      const existing = this.stream.querySelector('[data-nst3af-agent-starters]');
      existing?.remove();
      this.stream.querySelector('[data-nst3af-agent-greeting]')?.remove();

      const executable = Array.isArray(starters.executable) ? starters.executable : [];
      const locked = Array.isArray(starters.locked) ? starters.locked : [];
      if (executable.length === 0 && locked.length === 0) {
        return;
      }

      const wrap = document.createElement('div');
      wrap.dataset.nst3afAgentStarters = '1';
      wrap.className = 'nst3af-agent-starters-wrap';

      const container = document.createElement('div');
      container.className = 'nst3af-agent-starters';

      if (executable.length > 0) {
        const label = document.createElement('div');
        label.className = 'nst3af-agent-starter-group-label';
        label.textContent = lang('agent.starters.executable', 'MCP Tools');
        container.appendChild(label);
        executable.forEach((tool) => container.appendChild(this.createStarterButton(tool, false)));
      }

      if (locked.length > 0) {
        const label = document.createElement('div');
        label.className = 'nst3af-agent-starter-group-label';
        label.textContent = lang('agent.starters.locked', 'Needs another extension');
        container.appendChild(label);
        locked.forEach((tool) => container.appendChild(this.createStarterButton(tool, true)));
      }

      wrap.appendChild(container);
      this.stream.appendChild(wrap);
      this.stream.scrollTop = this.stream.scrollHeight;
    },

  /**
     * @param {object} tool
     * @param {boolean} locked
     * @param {{variant?: 'chip'|'card'}} [options]
     * @returns {HTMLButtonElement}
     */
    createStarterButton(tool, locked, options = {}) {
      const variant = options.variant === 'card' ? 'card' : 'chip';
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = `nst3af-agent-starter nst3af-agent-starter--${variant}${locked ? ' nst3af-agent-starter--locked' : ''}`;
      btn.dataset.nst3afAgentStarter = '1';
      btn.dataset.tool = String(tool.name ?? '');
      btn.dataset.action = String(tool.action ?? '');
      btn.dataset.label = String(tool.label ?? tool.name ?? '');
      if (typeof tool.prompt === 'string' && tool.prompt.trim() !== '') {
        btn.dataset.prompt = tool.prompt;
      }
      if (tool.arguments && typeof tool.arguments === 'object') {
        btn.dataset.arguments = JSON.stringify(tool.arguments);
      }
      btn.dataset.locked = locked ? '1' : '0';
      btn.setAttribute('aria-label', this.buildToolAriaLabel(tool, locked));
      if (locked) {
        btn.disabled = true;
      }

      if (locked) {
        const lock = document.createElement('span');
        lock.className = 'nst3af-agent-starter__lock';
        lock.setAttribute('aria-hidden', 'true');
        lock.innerHTML = '<typo3-backend-icon identifier="actions-lock" size="small"></typo3-backend-icon>';
        btn.appendChild(lock);
      }

      if (variant === 'card') {
        const icon = document.createElement('span');
        icon.className = 'nst3af-agent-starter__icon';
        icon.setAttribute('aria-hidden', 'true');
        icon.innerHTML = `<typo3-backend-icon identifier="${escapeHtml(this.starterIconIdentifier(tool))}" size="small"></typo3-backend-icon>`;
        btn.appendChild(icon);
      } else {
        const dot = document.createElement('span');
        dot.className = `nst3af-agent-sev-dot nst3af-agent-sev-dot--${String(tool.severity ?? 'read')}`;
        dot.setAttribute('aria-hidden', 'true');
        btn.appendChild(dot);
      }

      const label = document.createElement('span');
      label.className = 'nst3af-agent-starter__label';
      label.textContent = String(tool.label ?? tool.name ?? '');
      btn.appendChild(label);

      const severity = document.createElement('span');
      severity.className = 'visually-hidden';
      severity.textContent = this.severityLabel(tool.severity);
      btn.appendChild(severity);

      if (locked && tool.ownerLabel) {
        const owner = document.createElement('span');
        owner.className = 'nst3af-agent-starter__owner';
        owner.textContent = String(tool.ownerLabel);
        btn.appendChild(owner);
      }

      return btn;
    },

  /**
     * Icon API id for an empty-state starter card.
     * @param {object} tool
     * @returns {string}
     */
    starterIconIdentifier(tool) {
      const byStarter = {
        seo: 'actions-search',
        translatePage: 'actions-localize',
        recordTranslate: 'actions-localize',
        newsTranslate: 'actions-localize',
        addContent: 'actions-plus',
        createSubpage: 'actions-page-new',
        accessibility: 'actions-eye',
        summarizePage: 'actions-document-info',
        fileAltText: 'actions-file',
        missingAltText: 'actions-file',
        generateImage: 'actions-image',
        redirectsToPage: 'actions-link',
        createRedirect: 'actions-link',
        failedTasks: 'actions-clock',
        workspaceChanges: 'apps-toolbar-menu-workspace',
        pageTree: 'apps-pagetree-page-default',
        capabilities: 'actions-info-circle',
        recordImprove: 'actions-document-edit',
      };
      const starterId = String(tool.starterId ?? '');
      if (starterId !== '' && byStarter[starterId]) {
        return byStarter[starterId];
      }
      return 'actions-arrow-right';
    },

  /**
     * Fill the composer from a starter; the editor sends explicitly.
     * @param {HTMLButtonElement} btn
     */
    async runStarter(btn) {
      if (btn.dataset.locked === '1' || btn.disabled) {
        return;
      }
      if (!(this.input instanceof HTMLTextAreaElement)) {
        return;
      }

      const prompt = String(btn.dataset.prompt ?? '').trim();
      if (prompt !== '') {
        this.input.value = prompt;
      } else {
        const tool = btn.dataset.tool ?? '';
        const action = btn.dataset.action ?? '';
        if (tool === '' && action === '') {
          return;
        }
        this.input.value = action !== '' ? `/${action}` : `/${tool} `;
      }
      this.resizeComposerInput();
      this.input.focus();
    },

  handleComposerInput() {
      if (!this.input) {
        return;
      }

      this.resizeComposerInput();

      const value = this.input.value;
      const slash = value.match(/\/(\S*)$/);
      const at = value.match(/@(\S*)$/);

      if (slash) {
        this.autocompleteMode = 'tools';
        this.loadAutocomplete('tools', slash[1] ?? '');
        return;
      }

      if (at) {
        this.autocompleteMode = 'records';
        this.loadAutocomplete('records', at[1] ?? '');
        return;
      }

      this.hideAutocomplete();
    },

  /**
     * Grow the composer textarea with content up to ~10 lines, then scroll.
     */
    resizeComposerInput() {
      if (!(this.input instanceof HTMLTextAreaElement)) {
        return;
      }
      const maxPx = 220;
      this.input.style.height = 'auto';
      const scroll = this.input.scrollHeight;
      this.input.style.height = `${Math.min(scroll, maxPx)}px`;
      this.input.style.overflowY = scroll > maxPx ? 'auto' : 'hidden';
    },

  /**
     * @param {'tools'|'records'} mode
     * @param {string} query
     */
    async loadAutocomplete(mode, query) {
      if (!this.autocomplete) {
        return;
      }

      const route = mode === 'tools' ? 'nst3af_agent_tools' : 'nst3af_agent_records';
      const base = ajaxUrl(route);
      if (base === '') {
        return;
      }

      const url = new URL(base, window.location.href);
      url.searchParams.set('q', query);
      const ctx = resolveBackendContext();
      url.searchParams.set('pageId', String(ctx.pageId));
      if (mode === 'tools' && ctx.module) {
        url.searchParams.set('module', String(ctx.module));
      }

      try {
        const payload = await new AjaxRequest(url.toString()).get().then((r) => r.resolve());
        if (!payload?.ok) {
          this.hideAutocomplete();
          return;
        }

        if (mode === 'tools') {
          this.renderToolAutocomplete(payload.tools ?? {});
        } else {
          this.renderRecordAutocomplete(payload.records ?? []);
        }
      } catch {
        this.hideAutocomplete();
      }
    },

  /**
     * @param {{
     *   executable?: Array<object>,
     *   locked?: Array<object>,
     *   module?: { executable?: Array<object>, locked?: Array<object> },
     *   rest?: { executable?: Array<object>, locked?: Array<object> },
     * }} catalog
     */
    renderToolAutocomplete(catalog) {
      if (!this.autocomplete) {
        return;
      }

      const moduleExec = Array.isArray(catalog.module?.executable) ? catalog.module.executable : [];
      const moduleLocked = Array.isArray(catalog.module?.locked) ? catalog.module.locked : [];
      const restExec = Array.isArray(catalog.rest?.executable)
        ? catalog.rest.executable
        : (Array.isArray(catalog.executable) ? catalog.executable : []);
      const restLocked = Array.isArray(catalog.rest?.locked)
        ? catalog.rest.locked
        : (Array.isArray(catalog.locked) ? catalog.locked : []);
      const sections = [];

      const pushGroup = (headingKey, fallback, executable, locked) => {
        if (executable.length === 0 && locked.length === 0) {
          return;
        }
        sections.push(`<div class="nst3af-agent-autocomplete__heading">${escapeHtml(lang(headingKey, fallback))}</div>`);
        sections.push(executable.map((tool) => this.renderToolItem(tool, false)).join(''));
        sections.push(locked.map((tool) => this.renderToolItem(tool, true)).join(''));
      };

      if (moduleExec.length > 0 || moduleLocked.length > 0) {
        pushGroup('agent.tools.module', 'On this module', moduleExec, moduleLocked);
        pushGroup('agent.tools.rest', 'All tools', restExec, restLocked);
      } else {
        pushGroup('agent.starters.executable', 'MCP Tools', restExec, restLocked);
      }

      this.autocomplete.innerHTML = sections.join('');
      this.autocomplete.hidden = sections.length === 0;
      if (!this.autocomplete.hidden) {
        this.positionAutocomplete();
      }
    },

  /**
     * @param {object} tool
     * @param {boolean} locked
     */
    renderToolItem(tool, locked) {
      const severityText = this.severityLabel(tool.severity);
      const displayLabel = resolveToolDisplayLabel(tool);
      const aria = this.buildToolAriaLabel(tool, locked);
      return `<button type="button" class="nst3af-agent-autocomplete__item${locked ? ' nst3af-agent-autocomplete__item--locked' : ''}" data-nst3af-agent-ac-item="1" data-locked="${locked ? '1' : '0'}" data-insert="/${escapeHtml(String(tool.name ?? ''))} " aria-label="${escapeHtml(aria)}" role="option"><span class="nst3af-agent-sev-dot nst3af-agent-sev-dot--${escapeHtml(String(tool.severity ?? 'read'))}" aria-hidden="true"></span><span><span class="nst3af-agent-autocomplete__item-title">${escapeHtml(displayLabel)}</span><span class="nst3af-agent-autocomplete__item-desc">${escapeHtml(String(tool.description ?? ''))} · ${escapeHtml(String(tool.ownerLabel ?? ''))} · ${escapeHtml(severityText)}</span></span></button>`;
    },

  /**
     * @param {Array<object>} records
     */
    renderRecordAutocomplete(records) {
      if (!this.autocomplete) {
        return;
      }

      if (!Array.isArray(records) || records.length === 0) {
        this.hideAutocomplete();
        return;
      }

      const heading = `<div class="nst3af-agent-autocomplete__heading">${escapeHtml(lang('agent.context.record', 'Record'))}</div>`;
      const items = records.map((record) => (
        `<button type="button" class="nst3af-agent-autocomplete__item" data-nst3af-agent-ac-item="1" data-insert="@${escapeHtml(String(record.table ?? ''))}:${Number(record.uid ?? 0)} " data-record-table="${escapeHtml(String(record.table ?? ''))}" data-record-uid="${Number(record.uid ?? 0)}"><span><span class="nst3af-agent-autocomplete__item-title">${escapeHtml(String(record.label ?? ''))}</span><span class="nst3af-agent-autocomplete__item-desc">${escapeHtml(String(record.table ?? ''))}:${Number(record.uid ?? 0)}</span></span></button>`
      )).join('');

      this.autocomplete.innerHTML = heading + items;
      this.autocomplete.hidden = false;
      this.positionAutocomplete();
    },

  /**
     * Pin the list above the composer with fixed coords so panel overflow:hidden cannot clip it.
     */
    positionAutocomplete() {
      if (!(this.autocomplete instanceof HTMLElement) || this.autocomplete.hidden) {
        return;
      }
      const anchor = this.composer instanceof HTMLElement ? this.composer : this.input;
      if (!(anchor instanceof HTMLElement)) {
        return;
      }
      const rect = anchor.getBoundingClientRect();
      const gutter = 12;
      Object.assign(this.autocomplete.style, {
        position: 'fixed',
        left: `${Math.max(8, rect.left + gutter)}px`,
        width: `${Math.max(160, rect.width - gutter * 2)}px`,
        right: 'auto',
        bottom: `${Math.max(8, window.innerHeight - rect.top + 6)}px`,
        top: 'auto',
        zIndex: '1200',
      });
    },

  /**
     * @param {HTMLButtonElement} item
     */
    pickAutocomplete(item) {
      if (!this.input) {
        return;
      }

      const locked = item.dataset.locked === '1';
      const insert = item.dataset.insert ?? '';
      if (insert === '') {
        return;
      }

      const value = this.input.value;
      const mode = this.autocompleteMode;
      const pattern = mode === 'records' ? /@(\S*)$/ : /\/(\S*)$/;
      this.input.value = value.replace(pattern, insert);

      if (item.dataset.recordTable && item.dataset.recordUid) {
        this.context = {
          ...this.context,
          record: {
            table: item.dataset.recordTable,
            uid: Number.parseInt(item.dataset.recordUid, 10),
          },
        };
        this.renderContext();
      }

      this.hideAutocomplete();

      if (locked && mode === 'tools') {
        const toolName = insert.replace(/^\//, '').trim();
        this.submitTurn(toolName);
        return;
      }

      this.input.focus();
    },

  hideAutocomplete() {
      if (!this.autocomplete) {
        return;
      }
      this.autocomplete.hidden = true;
      this.autocomplete.innerHTML = '';
      this.autocompleteMode = null;
      this.autocompleteIndex = -1;
      if (this.autocomplete instanceof HTMLElement) {
        this.autocomplete.style.position = '';
        this.autocomplete.style.left = '';
        this.autocomplete.style.width = '';
        this.autocomplete.style.right = '';
        this.autocomplete.style.bottom = '';
        this.autocomplete.style.top = '';
        this.autocomplete.style.zIndex = '';
      }
    },

  /**
     * @param {string|undefined} severity
     * @returns {string}
     */
    severityLabel(severity) {
      const key = `agent.severity.${String(severity ?? 'read')}`;
      const fallbacks = {
        read: 'Read-only',
        write: 'Write',
        destructive: 'Destructive',
        unclassified: 'Unclassified',
      };
      return lang(key, fallbacks[String(severity ?? 'read')] ?? fallbacks.read);
    },

  /**
     * @param {object} tool
     * @param {boolean} locked
     * @returns {string}
     */
    buildToolAriaLabel(tool, locked) {
      const parts = [
        resolveToolDisplayLabel(tool),
        this.severityLabel(tool.severity),
      ];
      if (locked) {
        parts.push(lang('agent.starters.locked', 'Needs another extension'));
        if (tool.ownerLabel) {
          parts.push(String(tool.ownerLabel));
        }
      }
      return parts.join(', ');
    },

  /**
     * @param {KeyboardEvent} event
     */
    handleAutocompleteKeydown(event) {
      if (!this.autocomplete) {
        return;
      }

      const items = [...this.autocomplete.querySelectorAll('[data-nst3af-agent-ac-item]')];
      if (items.length === 0) {
        return;
      }

      if (event.key === 'ArrowDown') {
        event.preventDefault();
        this.autocompleteIndex = Math.min(this.autocompleteIndex + 1, items.length - 1);
        this.highlightAutocompleteItem(items);
        return;
      }

      if (event.key === 'ArrowUp') {
        event.preventDefault();
        this.autocompleteIndex = Math.max(this.autocompleteIndex - 1, 0);
        this.highlightAutocompleteItem(items);
        return;
      }

      if (event.key === 'Enter' && this.autocompleteIndex >= 0) {
        event.preventDefault();
        const item = items[this.autocompleteIndex];
        if (item instanceof HTMLButtonElement) {
          this.pickAutocomplete(item);
        }
      }
    },

  /**
     * @param {HTMLButtonElement[]} items
     */
    highlightAutocompleteItem(items) {
      items.forEach((item, index) => {
        const active = index === this.autocompleteIndex;
        item.classList.toggle('is-active', active);
        item.setAttribute('aria-selected', active ? 'true' : 'false');
        if (active) {
          item.scrollIntoView({ block: 'nearest' });
        }
      });
    },
};
