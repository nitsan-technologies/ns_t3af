/**
 * AgentController mixin: submitting a turn and consuming the SSE response stream.
 */

import { lang, hasTurnGuardWarning, errorText } from './format.js';
import { ajaxUrl, resolveBackendContext } from './context.js';

export const turnMethods = {
  /**
     * Turns the readable tool name the editor picked from the "/" menu back into the real
     * "/tool_name" command. If the editor changed the text, it is sent as typed.
     *
     * @param {string} text
     * @returns {string}
     */
    expandSlashLabel(text) {
      const mentions = this.recordMentions ?? [];
      this.recordMentions = [];
      let expanded = text;
      const used = [];
      // Longest names first, so "@QA Mounted Child" is not cut short by "@QA Mounted".
      for (const mention of [...mentions].sort((a, b) => b.label.length - a.label.length)) {
        if (expanded.includes(mention.label)) {
          used.push({ mention, at: expanded.lastIndexOf(mention.label) });
          expanded = expanded.split(mention.label).join(mention.token);
        }
      }
      // The record context follows what is really in the message: a picked name the editor
      // deleted or retyped no longer points at that record, and with several picks the last
      // one mentioned is the context.
      if (mentions.length > 0 && mentions.some((m) => m.record)) {
        const withRecord = used.filter((u) => u.mention.record).sort((a, b) => a.at - b.at);
        const context = { ...(this.context ?? {}) };
        if (withRecord.length === 0) {
          delete context.record;
        } else {
          context.record = withRecord[withRecord.length - 1].mention.record;
        }
        this.context = context;
        this.renderContext?.();
      }

      const picked = this.slashLabel;
      this.slashLabel = null;
      if (!picked || picked.label === '' || !expanded.startsWith(picked.label)) {
        return expanded;
      }
      const rest = expanded.slice(picked.label.length).trim();
      return rest === '' ? picked.command : `${picked.command} ${rest}`;
    },

  /**
     * @param {string} [explicitTool]
     * @param {object} [toolArguments]
     * @param {string} [starterAction]
     * @param {{continuation?: {outcome: string, label: string, result: string}}} [options]
     */
    async submitTurn(explicitTool = '', toolArguments = {}, starterAction = '', options = {}) {
      if (!this.input || this.isRunning) {
        return;
      }
      if (this.credits?.empty === true) {
        this.announce(lang('agent.credits.empty', 'Your T3Planet credits are used up.'));
        return;
      }

      const continuation = options.continuation ?? null;
      const typed = this.input.value.trim();
      const message = continuation !== null ? 'continue' : this.expandSlashLabel(typed);
      if (message === '') {
        return;
      }

      this.turnAbort?.abort();
      this.turnAbort = new AbortController();
      const signal = this.turnAbort.signal;

      this.isRunning = true;
      if (continuation === null) {
        this.input.value = '';
        this.resizeComposerInput?.();
      }
      this.hideAutocomplete();

      const backendContext = resolveBackendContext();
      const body = {
        message,
        tool: starterAction !== '' ? '' : explicitTool,
        action: starterAction,
        arguments: toolArguments,
        context: {
          ...backendContext,
          ...this.context,
        },
        sessionUuid: this.freshSession ? '' : (this.session?.uuid ?? ''),
        fresh: this.freshSession,
        provider: this.session?.provider || this.selectedProvider || 'default',
      };
      if (continuation !== null) {
        body.continuation = continuation;
      }

      const preferStream = explicitTool === '' && starterAction === '';

      const userMessage = continuation !== null
        ? { role: 'user', content: message, meta: { hidden: true, type: 'continuation' } }
        : { role: 'user', content: typed !== '' ? typed : message, meta: {} };
      try {
        this.messages.push(userMessage);
        this.renderStream();
        this.showProgress(true, '', true);
        let payload = null;
        let streamed = false;
        if (preferStream) {
          const streamResult = await this.submitTurnStreaming(body, signal);
          if (streamResult !== null) {
            payload = streamResult.payload;
            streamed = true;
          }
        }
        if (payload === null) {
          if (signal.aborted) {
            throw new DOMException('Aborted', 'AbortError');
          }
          const url = ajaxUrl('nst3af_agent_turn');
          const response = await fetch(url, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              Accept: 'application/json',
            },
            body: JSON.stringify(body),
            credentials: 'same-origin',
            signal,
          });
          payload = await response.json();
        }
        if (!payload?.ok) {
          throw new Error(payload?.message ?? lang('agent.error.turnFailed', 'Turn failed'));
        }

        if (!streamed) {
          const replies = Array.isArray(payload.messages) ? payload.messages : [];
          replies.forEach((reply) => {
            const meta = reply.meta ?? {};
            if (hasTurnGuardWarning(meta) && reply.content) {
              reply.content = String(meta.turnGuardWarning) + '\n\n' + String(reply.content);
            }
            this.messages.push(reply);
          });
        }
        this.context = payload.context ?? this.context;
        if (payload.userMessage && typeof payload.userMessage === 'object') {
          // The server's copy carries where it was written (and the continuation text). The bubble
          // keeps what the editor typed ("@QA Mounted"), not the expanded "@pages:224" token.
          const shown = userMessage.content;
          Object.assign(userMessage, payload.userMessage);
          if (continuation === null && typeof shown === 'string' && shown !== '') {
            userMessage.content = shown;
          }
        }
        if (payload.credits !== undefined) {
          this.credits = payload.credits;
          this.renderCredits();
        }
        if (payload.session) {
          this.session = payload.session;
          this.freshSession = false;
          this.rememberLastSession();
          this.ensureSessionInList(payload.session);
        }
        this.applySessionTitle(payload.sessionTitle);
        this.refreshSessionsRail();
        this.contextNotice = '';
        this.renderHeaderControls();

        this.renderContext();
        this.renderStream();
        if (payload.starters) {
          this.starters = payload.starters;
        }
        if (payload.greeting) {
          this.greeting = payload.greeting;
        }
      } catch (error) {
        if (error?.name === 'AbortError' || signal.aborted) {
          for (let i = this.messages.length - 1; i >= 0; i--) {
            const row = this.messages[i];
            if (row?.meta?.streaming === true) {
              row.meta = { ...(row.meta ?? {}), streaming: false, stopped: true };
              break;
            }
          }
          this.messages.push({
            role: 'assistant',
            content: lang('agent.turn.stopped', 'Stopped.'),
            meta: { type: 'info' },
          });
          this.renderStream();
        } else {
          const text = await errorText(error);
          this.messages.push({ role: 'assistant', content: text, meta: { type: 'error' } });
          this.renderStream();
        }
      } finally {
        this.turnAbort = null;
        this.isRunning = false;
        this.showProgress(false);
        this.renderStream();
      }
    },

  stopTurn() {
      if (!this.isRunning) {
        return;
      }
      this.turnAbort?.abort();
    },

  /**
     * @param {object} body
     * @param {AbortSignal} [signal]
     * @returns {Promise<{payload: object}|null>}
     */
    async submitTurnStreaming(body, signal) {
      const streamUrl = ajaxUrl('nst3af_agent_turn_stream');
      if (streamUrl === '') {
        return null;
      }

      let response;
      try {
        response = await fetch(streamUrl, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            Accept: 'text/event-stream',
          },
          body: JSON.stringify(body),
          credentials: 'same-origin',
          signal,
        });
      } catch (error) {
        if (error?.name === 'AbortError' || signal?.aborted) {
          throw error;
        }
        return null;
      }

      if (!response.ok || !response.body) {
        return null;
      }

      const contentType = String(response.headers.get('content-type') ?? '');
      if (!contentType.includes('text/event-stream')) {
        return null;
      }

      const reader = response.body.getReader();
      if (signal instanceof AbortSignal) {
        const cancelReader = () => {
          void reader.cancel().catch(() => {});
        };
        if (signal.aborted) {
          cancelReader();
          throw new DOMException('Aborted', 'AbortError');
        }
        signal.addEventListener('abort', cancelReader, { once: true });
      }
      const decoder = new TextDecoder();
      let buffer = '';
      let donePayload = null;
      let streamingMessage = null;
      let revealState = null;
      let streamAssistantCount = 0;

      /**
       * The server sends the finished answer in one piece. Show it growing over about a second
       * so the editor sees the answer arriving instead of a sudden block of text.
       * Returns null when the text is shown at once (short text or reduced motion).
       */
      const revealInto = (message, full) => {
        const reduceMotion = typeof window.matchMedia === 'function'
          && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reduceMotion || full.length < 80) {
          message.content = full;
          return null;
        }
        const state = { full, shown: 0, final: null };
        const step = Math.max(4, Math.ceil(full.length / 25));
        message.content = '';
        const timer = window.setInterval(() => {
          let next = Math.min(state.full.length, state.shown + step);
          const last = state.full.charCodeAt(next - 1);
          if (next < state.full.length && last >= 0xD800 && last <= 0xDBFF) {
            next += 1;
          }
          state.shown = next;
          message.content = state.full.slice(0, next);
          if (next >= state.full.length) {
            window.clearInterval(timer);
            if (state.final !== null) {
              message.content = state.final.content ?? state.full;
              message.meta = { ...state.final.meta, streaming: false };
            }
          }
          this.renderStream();
        }, 40);
        return state;
      };

      const pushAssistantReply = (reply) => {
        const meta = reply.meta ?? {};
        if (hasTurnGuardWarning(meta) && reply.content) {
          reply.content = String(meta.turnGuardWarning) + '\n\n' + String(reply.content);
        }
        this.messages.push(reply);
      };

      const flushEvent = (eventName, dataText) => {
        if (dataText === '') {
          return;
        }
        let data;
        try {
          data = JSON.parse(dataText);
        } catch {
          return;
        }

        if (eventName === 'progress') {
          const status = String(data.status ?? '');
          if (status === 'tool' && (data.label || data.tool)) {
            // A real tool name is the most useful thing we can show: stop rotating filler words for it.
            this.stopThinkingRotation();
            const label = lang('agent.live.runningTool', 'Running %1$s…').replace('%1$s', String(data.label || data.tool));
            this.updateProgressLabel(label);
          } else {
            // No specific tool yet (the model is still deciding, or the toolbox is looking one up):
            // cycle a few short phrases instead of freezing on one word for a long "Thinking…" stretch.
            this.startThinkingRotation();
          }
          return;
        }

        if (eventName === 'session') {
          if (data.session && typeof data.session === 'object' && data.session.uuid) {
            this.session = data.session;
            this.freshSession = false;
            this.rememberLastSession();
            this.ensureSessionInList(data.session);
          }
          return;
        }

        if (eventName === 'plan') {
          this.livePlan = Array.isArray(data.steps) ? data.steps : [];
          this.renderPlan();
          return;
        }

        if (eventName === 'delta' && data.content) {
          const text = String(data.content);
          if (streamingMessage === null) {
            streamingMessage = { role: 'assistant', content: '', meta: { type: 'nl_reply', streaming: true } };
            this.messages.push(streamingMessage);
            revealState = revealInto(streamingMessage, text);
          } else if (revealState !== null) {
            revealState.full += text;
          } else {
            streamingMessage.content += text;
          }
          this.renderStream();
          return;
        }

        if (eventName === 'message' && data.message) {
          const reply = data.message;
          streamAssistantCount += 1;
          const meta = reply.meta ?? {};
          if (hasTurnGuardWarning(meta) && reply.content) {
            reply.content = String(meta.turnGuardWarning) + '\n\n' + String(reply.content);
          }
          if (streamingMessage !== null && reply.meta?.type === 'nl_reply') {
            if (revealState !== null && revealState.shown < revealState.full.length) {
              // Still growing: the final text and meta are applied when the reveal ends.
              revealState.final = { content: reply.content, meta: reply.meta };
            } else {
              streamingMessage.content = reply.content ?? streamingMessage.content;
              streamingMessage.meta = { ...reply.meta, streaming: false };
            }
            revealState = null;
            streamingMessage = null;
          } else {
            pushAssistantReply(reply);
          }
          this.renderStream();
          return;
        }

        if (eventName === 'done') {
          donePayload = data;
          // Fast-path turns (read prefetch, attachments) may only ship replies on done.
          if (streamAssistantCount === 0 && Array.isArray(data.messages)) {
            data.messages.forEach((reply) => pushAssistantReply(reply));
            streamAssistantCount += data.messages.length;
            this.renderStream();
          }
        }

        if (eventName === 'error') {
          throw new Error(data.message ?? lang('agent.error.streamFailed', 'Stream failed'));
        }
      };

      while (true) {
        const { value, done } = await reader.read();
        if (done) {
          break;
        }
        buffer += decoder.decode(value, { stream: true });
        const chunks = buffer.split('\n\n');
        buffer = chunks.pop() ?? '';
        for (const chunk of chunks) {
          const lines = chunk.split('\n');
          let eventName = 'message';
          const dataLines = [];
          for (const line of lines) {
            if (line.startsWith('event:')) {
              eventName = line.slice(6).trim();
            } else if (line.startsWith('data:')) {
              dataLines.push(line.slice(5).trim());
            }
          }
          flushEvent(eventName, dataLines.join('\n'));
        }
      }

      if (streamingMessage !== null) {
        streamingMessage.meta = { ...(streamingMessage.meta ?? {}), streaming: false };
      }

      return donePayload !== null ? { payload: donePayload } : null;
    },
};
