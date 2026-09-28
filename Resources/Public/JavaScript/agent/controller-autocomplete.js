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
        label.textContent = lang('agent.starters.executable', 'Suggested actions');
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
     * @returns {HTMLButtonElement}
     */
    createStarterButton(tool, locked) {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = `nst3af-agent-starter${locked ? ' nst3af-agent-starter--locked' : ''}`;
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
        const lock = document.createElement('span');
        lock.className = 'nst3af-agent-starter__lock';
        lock.setAttribute('aria-hidden', 'true');
        lock.textContent = '🔒';
        btn.appendChild(lock);
      }

      const dot = document.createElement('span');
      dot.className = `nst3af-agent-sev-dot nst3af-agent-sev-dot--${String(tool.severity ?? 'read')}`;
      dot.setAttribute('aria-hidden', 'true');
      btn.appendChild(dot);

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
     * @param {HTMLButtonElement} btn
     */
    async runStarter(btn) {
      // Context chips carry a request in the editor's language: send it like typed text.
      const prompt = String(btn.dataset.prompt ?? '').trim();
      if (prompt !== '' && btn.dataset.locked !== '1') {
        if (this.input) {
          this.input.value = prompt;
        }
        await this.submitTurn();
        return;
      }
      const tool = btn.dataset.tool ?? '';
      const action = btn.dataset.action ?? '';
      if (tool === '' && action === '') {
        return;
      }
      let toolArguments = {};
      try {
        toolArguments = JSON.parse(btn.dataset.arguments ?? '{}');
      } catch {
        toolArguments = {};
      }
      if (this.input) {
        this.input.value = action !== '' ? `/${action}` : `/${tool} `;
      }
      await this.submitTurn(tool, toolArguments, action);
    },

  handleComposerInput() {
      if (!this.input) {
        return;
      }

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
      url.searchParams.set('pageId', String(resolveBackendContext().pageId));

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
     * @param {{ executable?: Array<object>, locked?: Array<object> }} catalog
     */
    renderToolAutocomplete(catalog) {
      if (!this.autocomplete) {
        return;
      }

      const executable = Array.isArray(catalog.executable) ? catalog.executable : [];
      const locked = Array.isArray(catalog.locked) ? catalog.locked : [];
      const sections = [];

      if (executable.length > 0) {
        sections.push(`<div class="nst3af-agent-autocomplete__heading">${escapeHtml(lang('agent.starters.executable', 'Suggested actions'))}</div>`);
        sections.push(executable.map((tool) => this.renderToolItem(tool, false)).join(''));
      }
      if (locked.length > 0) {
        sections.push(`<div class="nst3af-agent-autocomplete__heading">${escapeHtml(lang('agent.starters.locked', 'Needs another extension'))}</div>`);
        sections.push(locked.map((tool) => this.renderToolItem(tool, true)).join(''));
      }

      this.autocomplete.innerHTML = sections.join('');
      this.autocomplete.hidden = sections.length === 0;
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
