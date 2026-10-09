/**
 * AI Agent message body rendering: markdown, tool traces, media previews.
 */

import { lang, escapeHtml, formatWorkDuration } from './format.js';

/**
 * Mirror of AgentLowRiskFieldMatrix for suggestion "Apply safe" UX.
 *
 * @param {string} table
 * @param {string} fieldKey
 * @returns {boolean}
 */
export function isSuggestionFieldSafe(table, fieldKey) {
  const normalizedTable = String(table ?? '').toLowerCase().trim();
  let field = String(fieldKey ?? '').toLowerCase().trim();
  if (field.includes(':')) {
    field = field.slice(field.lastIndexOf(':') + 1);
  }
  const aliases = {
    metatitle: 'seo_title',
    seo_title: 'seo_title',
    metadescription: 'description',
    ogtitle: 'og_title',
    og_title: 'og_title',
    ogdescription: 'og_description',
    og_description: 'og_description',
    alttext: 'alternative',
    alternative: 'alternative',
  };
  field = aliases[field] ?? field;
  const safe = {
    pages: ['seo_title', 'description', 'abstract', 'keywords', 'og_title', 'og_description'],
    sys_file_metadata: ['alternative', 'description', 'title'],
  };
  return (safe[normalizedTable] ?? []).includes(field);
}

/**
 * @param {object} source
 * @returns {string}
 */
/**
 * "targetLanguageId" / "target_language" → "Target language id".
 *
 * @param {string} key
 * @returns {string}
 */
/**
 * Generated image or audio file of a tool result (only same-site paths or http(s) URLs).
 *
 * @param {unknown} details
 * @returns {string}
 */
/**
 * Thumbnails prepared by the server (meta.previews): the image a result or a prepared change is about.
 *
 * @param {Array<{url?: string, href?: string, name?: string, alt?: string}>} previews
 * @returns {string}
 */
export function renderImagePreviews(previews) {
  if (!Array.isArray(previews) || previews.length === 0) {
    return '';
  }
  const safeUrl = (value) => {
    const url = String(value ?? '').trim();
    return url !== '' && /^(https?:\/\/|\/)/i.test(url) && !url.startsWith('//') ? url : '';
  };
  const items = previews.map((preview) => {
    const src = safeUrl(preview?.url);
    if (src === '') {
      return '';
    }
    const href = safeUrl(preview?.href) || src;
    const name = String(preview?.name ?? '');
    const alt = String(preview?.alt ?? '');
    const openLabel = lang('agent.media.open', 'Open image in a new tab');
    return `<figure class="nst3af-agent-gallery__item">
      <a href="${escapeHtml(href)}" target="_blank" rel="noopener" title="${escapeHtml(openLabel)}"><img src="${escapeHtml(src)}" alt="${escapeHtml(alt !== '' ? alt : name)}" loading="lazy"></a>
      <figcaption class="nst3af-agent-gallery__name" title="${escapeHtml(name)}">${escapeHtml(name)}</figcaption>
    </figure>`;
  }).join('');
  if (items === '') {
    return '';
  }
  const single = previews.length === 1 ? ' nst3af-agent-gallery--single' : '';
  return `<div class="nst3af-agent-gallery${single}">${items}</div>`;
}

/**
 * @param {object} details
 * @param {Array<object>} [previews]
 * @returns {string}
 */
export function renderMediaPreview(details, previews = []) {
  const gallery = renderImagePreviews(previews);
  if (gallery !== '') {
    return gallery;
  }
  if (!details || typeof details !== 'object') {
    return '';
  }
  let url = String(details.previewImageUrl ?? details.publicUrl ?? '').trim();
  if (/^fileadmin\//i.test(url)) {
    url = `/${url}`;
  }
  if (url === '' || !/^(https?:\/\/|\/)/i.test(url) || url.startsWith('//')) {
    return '';
  }
  const name = String(details.fileName ?? '');
  if (/\.(mp3|wav|ogg|m4a)(\?|$)/i.test(url)) {
    return `<div class="nst3af-agent-media"><audio controls preload="none" src="${escapeHtml(url)}"></audio><span class="nst3af-agent-media__name">${escapeHtml(name)}</span></div>`;
  }
  if (details.previewImageUrl || /\.(png|jpe?g|gif|webp|svg)(\?|$)/i.test(url)) {
    return `<div class="nst3af-agent-media"><a href="${escapeHtml(url)}" target="_blank" rel="noopener"><img src="${escapeHtml(url)}" alt="${escapeHtml(name)}" loading="lazy"></a><span class="nst3af-agent-media__name">${escapeHtml(name)}</span></div>`;
  }
  return '';
}

export function resolveToolDisplayLabel(source) {
  const editorLabel = String(source?.editorLabel ?? source?.toolCallLabel ?? source?.label ?? '').trim();
  if (editorLabel !== '') {
    return editorLabel;
  }

  return String(source?.tool ?? source?.name ?? '').trim();
}

/**
 * @param {Array<object>} trace
 * @returns {string}
 */
export function renderToolTrace(trace) {
  if (!Array.isArray(trace) || trace.length === 0) {
    return '';
  }

  const steps = trace.map((entry, index) => {
    const stepLabel = escapeHtml(String(entry.step ?? `step-${index + 1}`));
    const status = escapeHtml(String(entry.status ?? ''));
    const latency = entry.latencyMs != null && Number.isFinite(Number(entry.latencyMs))
      ? `${Number(entry.latencyMs)} ms`
      : '';
    let requestJson = '';
    let responseJson = '';
    try {
      requestJson = JSON.stringify(entry.request ?? null, null, 2);
    } catch {
      requestJson = String(entry.request ?? '');
    }
    try {
      responseJson = JSON.stringify(entry.response ?? null, null, 2);
    } catch {
      responseJson = String(entry.response ?? '');
    }

    return `<details class="nst3af-agent-trace__step">
      <summary>
        <span class="nst3af-agent-trace__step-label">${stepLabel}</span>
        <span class="nst3af-agent-trace__status">${status}</span>
        ${latency ? `<span class="nst3af-agent-trace__latency">${escapeHtml(latency)}</span>` : ''}
      </summary>
      <div class="nst3af-agent-trace__blocks">
        <details class="nst3af-agent-trace__block">
          <summary>${escapeHtml(lang('agent.trace.request', 'Request'))}</summary>
          <pre><code>${escapeHtml(requestJson)}</code></pre>
        </details>
        <details class="nst3af-agent-trace__block">
          <summary>${escapeHtml(lang('agent.trace.response', 'Response'))}</summary>
          <pre><code>${escapeHtml(responseJson)}</code></pre>
        </details>
      </div>
    </details>`;
  }).join('');

  return `<details class="nst3af-agent-trace">
    <summary>${escapeHtml(lang('agent.trace.title', 'Tool trace'))}</summary>
    <div class="nst3af-agent-trace__steps">${steps}</div>
  </details>`;
}

/**
 * Compact Cursor-style work trace (Working… / Worked for 12s).
 *
 * @param {{
 *   working?: boolean,
 *   durationMs?: number,
 *   toolName?: string,
 *   summary?: string,
 *   args?: Array<{key?: string, value?: string}>,
 *   resultContent?: string,
 *   facts?: Array<{label?: string, value?: string}>,
 *   open?: boolean,
 * }} config
 * @returns {string}
 */
export function renderWorkTraceHtml(config) {
  const working = config.working === true;
  const durationMs = Math.max(0, Number(config.durationMs ?? 0));
  const open = config.open ?? working;
  const toolName = String(config.toolName ?? '').trim();
  const summary = String(config.summary ?? '').trim();

  const label = working
    ? lang('agent.work.working', 'Working…')
    : lang('agent.work.workedFor', 'Worked for %1$s').replace('%1$s', formatWorkDuration(durationMs));

  const args = Array.isArray(config.args) ? config.args : [];
  const argRows = args.map((entry) => {
    const key = escapeHtml(String(entry.key ?? ''));
    const value = escapeHtml(String(entry.value ?? ''));
    if (key === '') {
      return '';
    }

    return `<div class="nst3af-agent-work-trace__arg"><dt>${key}</dt><dd>${value}</dd></div>`;
  }).join('');

  const argsSection = argRows !== ''
    ? `<div class="nst3af-agent-work-trace__section"><div class="nst3af-agent-work-trace__section-label">${escapeHtml(lang('agent.draft.toolArguments', 'Parameters'))}</div>${argRows}</div>`
    : '';

  const stepLine = toolName !== ''
    ? `<div class="nst3af-agent-work-trace__step">${working
      ? escapeHtml(lang('agent.draft.runningTool', 'Running %1$s…').replace('%1$s', toolName))
      : `<strong>${escapeHtml(toolName)}</strong>`}</div>`
    : '';

  const summaryLine = summary !== ''
    ? `<div class="nst3af-agent-work-trace__detail">${escapeHtml(summary)}</div>`
    : '';

  const facts = Array.isArray(config.facts) ? config.facts : [];
  const factsRows = facts.map((fact) => (
    `<div class="nst3af-agent-work-trace__arg"><dt>${escapeHtml(String(fact.label ?? ''))}</dt><dd>${escapeHtml(String(fact.value ?? ''))}</dd></div>`
  )).join('');
  const factsSection = factsRows !== ''
    ? `<div class="nst3af-agent-work-trace__section">${factsRows}</div>`
    : '';

  const resultContent = String(config.resultContent ?? '').trim();
  const resultSection = resultContent !== ''
    ? `<div class="nst3af-agent-work-trace__result">${renderMessageBody(resultContent)}</div>`
    : '';

  const spinner = working
    ? '<span class="nst3af-agent-work-trace__spinner" aria-hidden="true"></span>'
    : '';

  const bodyHtml = `${stepLine}${summaryLine}${argsSection}${resultSection}${factsSection}`;

  if (bodyHtml.trim() === '') {
    return `<div class="nst3af-agent-msg nst3af-agent-msg--assistant nst3af-agent-msg--work-trace">
      <div class="nst3af-agent-work-trace nst3af-agent-work-trace--compact"${working ? ' data-nst3af-work-trace-active="1"' : ''}>
        ${spinner}
        <span class="nst3af-agent-work-trace__label" data-nst3af-work-trace-timer>${escapeHtml(label)}</span>
      </div>
    </div>`;
  }

  return `<div class="nst3af-agent-msg nst3af-agent-msg--assistant nst3af-agent-msg--work-trace">
    <details class="nst3af-agent-work-trace"${open ? ' open' : ''}${working ? ' data-nst3af-work-trace-active="1"' : ''}>
      <summary class="nst3af-agent-work-trace__summary">
        ${spinner}
        <span class="nst3af-agent-work-trace__label" data-nst3af-work-trace-timer>${escapeHtml(label)}</span>
      </summary>
      <div class="nst3af-agent-work-trace__body">
        ${stepLine}
        ${summaryLine}
        ${argsSection}
        ${resultSection}
        ${factsSection}
      </div>
    </details>
  </div>`;
}

/**
 * Work-trace fields are live UI only — not useful when reopening the modal.
 *
 * @param {object} meta
 * @returns {object}
 */
export function stripEphemeralWorkTraceMeta(meta) {
  if (!meta || typeof meta !== 'object') {
    return {};
  }

  const cleaned = { ...meta };
  delete cleaned.fromDraftApply;
  delete cleaned.workDurationMs;
  delete cleaned.workSummary;
  delete cleaned.workArguments;

  return cleaned;
}

/**
 * @param {string} line
 * @returns {string}
 */
export function renderPlainInline(line) {
  return escapeHtml(line).replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
}

/**
 * @param {string} text
 * @returns {string}
 */
export function renderPlainProse(text) {
  const lines = text.split('\n');
  const out = [];
  let inList = false;

  for (const line of lines) {
    const trimmed = line.trim();
    if (/^---+$/.test(trimmed)) {
      if (inList) {
        out.push('</ul>');
        inList = false;
      }
      out.push('<hr>');
      continue;
    }

    const bullet = line.match(/^- (.+)$/);
    if (bullet) {
      if (!inList) {
        out.push('<ul>');
        inList = true;
      }
      out.push(`<li>${renderPlainInline(bullet[1])}</li>`);
      continue;
    }

    if (inList) {
      out.push('</ul>');
      inList = false;
    }

    if (line === '') {
      out.push('<br>');
      continue;
    }

    out.push(`${renderPlainInline(line)}<br>`);
  }

  if (inList) {
    out.push('</ul>');
  }

  return out.join('');
}

/**
 * @param {string} content
 * @returns {Array<{type: 'prose' | 'code', text: string}>}
 */
export function splitMessageSegments(content) {
  /** @type {Array<{type: 'prose' | 'code', text: string}>} */
  const segments = [];
  const re = /```(\w*)\n([\s\S]*?)```/g;
  let lastIndex = 0;
  let match = re.exec(content);

  while (match !== null) {
    if (match.index > lastIndex) {
      segments.push({ type: 'prose', text: content.slice(lastIndex, match.index) });
    }
    segments.push({ type: 'code', text: match[2] ?? '' });
    lastIndex = match.index + match[0].length;
    match = re.exec(content);
  }

  if (lastIndex < content.length) {
    segments.push({ type: 'prose', text: content.slice(lastIndex) });
  }

  if (segments.length === 0) {
    segments.push({ type: 'prose', text: content });
  }

  return segments;
}

/**
 * @param {string} content
 * @param {(text: string) => string} renderProse
 * @returns {string}
 */
export function renderSegmentedMessageBody(content, renderProse) {
  return splitMessageSegments(content).map((segment) => {
    if (segment.type === 'code') {
      return `<pre><code>${escapeHtml(segment.text.trim())}</code></pre>`;
    }
    return renderProse(segment.text);
  }).join('');
}

/**
 * @param {string} content
 * @returns {string}
 */
export function renderPlainMessageBody(content) {
  return renderSegmentedMessageBody(content, renderPlainProse);
}

/** @type {(content: string) => string} */
export let renderMessageBodyImpl = renderPlainMessageBody;

/** @type {Promise<void> | null} */
export let messageRendererReady = null;

/**
 * Prefer core marked + DOMPurify (TYPO3 >=13.4.5, all v14). Falls back to plain rendering if unavailable.
 * @returns {Promise<void>}
 */
export function ensureMessageRenderer() {
  if (messageRendererReady === null) {
    messageRendererReady = (async () => {
      try {
        const [{ marked }, dompurifyModule] = await Promise.all([
          import('marked'),
          import('dompurify'),
        ]);
        const DOMPurify = dompurifyModule.default ?? dompurifyModule;
        marked.setOptions({ gfm: true, breaks: false });
        const sanitizeConfig = {
          ALLOWED_TAGS: ['blockquote', 'br', 'code', 'em', 'hr', 'kbd', 'li', 'ol', 'p', 'pre', 'strong', 'ul'],
          ALLOWED_ATTR: [],
        };

        renderMessageBodyImpl = (content) => renderSegmentedMessageBody(content, (text) => {
          const trimmed = text.trim();
          if (trimmed === '') {
            return '';
          }
          const parsed = marked.parse(text, { async: false });
          return DOMPurify.sanitize(parsed, sanitizeConfig);
        });
      } catch {
        // ponytail: TYPO3 <13.4.5 has no core marked/dompurify import map — plain subset above
      }
    })();
  }

  return messageRendererReady;
}

/**
 * @param {string} content
 * @returns {string}
 */
export function renderMessageBody(content) {
  return renderMessageBodyImpl(content);
}
