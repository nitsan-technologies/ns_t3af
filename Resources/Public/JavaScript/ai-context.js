/**
 * AI Context — profile list + slide-in profile drawer.
 */

import AjaxRequest from '@typo3/core/ajax/ajax-request.js';
import Notification from '@typo3/backend/notification.js';

const getAjaxUrl = (routeKey) => {
  const urls = (typeof TYPO3 !== 'undefined' && TYPO3.settings?.ajaxUrls) || {};
  return urls[routeKey] || '';
};

const CONFIDENCE_FIELD_SELECTORS = {
  brandName: '[data-aiu-context-field-brand-name]',
  industry: '[data-aiu-context-field-industry]',
  websiteUrl: '[data-aiu-context-field-website]',
  tagline: '[data-aiu-context-field-tagline]',
  description: '[data-aiu-context-field-description]',
  voiceNotes: '[data-aiu-context-field-voice-notes]',
  languageCode: '[data-aiu-context-field-language]',
};

const MANUAL_FIELD_LABELS = {
  content_rules: 'aiContext.js.manual.contentRules',
  forbidden_words: 'aiContext.js.manual.forbiddenWords',
  sample_content: 'aiContext.js.manual.sampleContent',
  compliance_notes: 'aiContext.js.manual.complianceNotes',
  document_upload: 'aiContext.js.manual.documentUpload',
};

const qs = (root, selector) => root?.querySelector(selector) ?? null;
const qsa = (root, selector) => Array.from(root?.querySelectorAll(selector) ?? []);

const parseJson = (raw, fallback = null) => {
  if (typeof raw !== 'string' || raw.trim() === '') {
    return fallback;
  }
  try {
    return JSON.parse(raw);
  } catch {
    return fallback;
  }
};

const parseProfileJson = (raw) => {
  const parsed = parseJson(raw, null);
  return typeof parsed === 'object' && parsed !== null ? parsed : null;
};

const parseFormConfig = () => {
  const el = document.getElementById('aiu-context-form-config');
  if (!(el instanceof HTMLScriptElement)) {
    return { toneTags: [], personaLevels: [], sections: [] };
  }
  const parsed = parseJson(el.textContent ?? '', {});
  return {
    toneTags: Array.isArray(parsed?.toneTags) ? parsed.toneTags : [],
    personaLevels: Array.isArray(parsed?.personaLevels) ? parsed.personaLevels : [],
    sections: Array.isArray(parsed?.sections) ? parsed.sections : [],
  };
};

const lang = (key, fallback) => (typeof TYPO3 !== 'undefined' && TYPO3.lang?.[key]) || fallback;

const escapeHtml = (value) => String(value)
  .replace(/&/g, '&amp;')
  .replace(/</g, '&lt;')
  .replace(/>/g, '&gt;')
  .replace(/"/g, '&quot;');

const createBackendIcon = (identifier) => {
  const icon = document.createElement('typo3-backend-icon');
  icon.setAttribute('identifier', identifier);
  icon.setAttribute('size', 'medium');
  return icon;
};

const setFieldValue = (field, value) => {
  if (field instanceof HTMLInputElement || field instanceof HTMLSelectElement || field instanceof HTMLTextAreaElement) {
    field.value = value ?? '';
  }
};

const DEFAULT_PERSONA_LEVEL = 'Intermediate';

const normalizePersonaState = (persona, personaLevels) => {
  const levels = Array.isArray(personaLevels) && personaLevels.length > 0
    ? personaLevels
    : ['Beginner', 'Intermediate', 'Expert'];
  const level = levels.includes(persona?.level) ? persona.level : DEFAULT_PERSONA_LEVEL;

  return {
    name: persona?.name ?? '',
    level,
    role: persona?.role ?? '',
    painPoints: persona?.painPoints ?? '',
    caresAbout: persona?.caresAbout ?? '',
  };
};

const createEmptyPersona = (personaLevels) => normalizePersonaState({}, personaLevels);

const buildPersonaLevelOptions = (selectedLevel, personaLevels) => (
  (Array.isArray(personaLevels) ? personaLevels : ['Beginner', 'Intermediate', 'Expert']).map((level) => {
    const selected = selectedLevel === level ? ' selected' : '';
    return `<option value="${level}"${selected}>${level}</option>`;
  }).join('')
);

const emptyState = () => ({
  uid: 0,
  brandName: '',
  industry: '',
  websiteUrl: '',
  tagline: '',
  description: '',
  toneTags: [],
  voiceNotes: '',
  personas: [],
  contentRules: [],
  forbiddenWords: [],
  keywords: [],
  competitors: [],
  languageCode: '',
  sampleContent: '',
  complianceNotes: '',
  documentExtract: '',
  uploadedDocuments: [],
});

const isIdentityComplete = (state) => {
  if ((state.brandName ?? '').trim() === '') {
    return false;
  }
  return (state.tagline ?? '').trim() !== '' || (state.description ?? '').trim() !== '';
};

const isVoiceComplete = (state) => (state.toneTags?.length ?? 0) > 0 || (state.voiceNotes ?? '').trim() !== '';

const isAudienceComplete = (state) => (state.personas ?? []).some(
  (persona) => (persona.name ?? '').trim() !== '' && (persona.level ?? '').trim() !== '',
);

const isRulesComplete = (state) => (state.contentRules ?? []).some(
  (rule) => (rule.text ?? '').trim() !== '',
);

const calculateCompleteness = (state, sections) => {
  const checks = {
    identity: isIdentityComplete(state),
    voice: isVoiceComplete(state),
    audience: isAudienceComplete(state),
    rules: isRulesComplete(state),
    language: (state.languageCode ?? '').trim() !== '',
    keywords: (state.keywords?.length ?? 0) > 0,
    sample: (state.sampleContent ?? '').trim() !== '',
  };

  const resolvedSections = sections.map((section) => ({
    id: section.id,
    label: section.label ?? section.id,
    complete: Boolean(checks[section.id]),
  }));

  const completed = Object.values(checks).filter(Boolean).length;
  const total = Object.keys(checks).length;
  const percent = total > 0 ? Math.min(100, Math.round((100 * completed) / total)) : 0;

  return { percent, completed, total, sections: resolvedSections, checks };
};

const initAiContext = () => {
  const root = document.querySelector('[data-aiu-context-root]');
  if (!(root instanceof HTMLElement) || root.dataset.aiuContextInit === '1') {
    return;
  }
  root.dataset.aiuContextInit = '1';

  const isReadOnly = root.dataset.contextReadOnly === '1';
  const config = parseFormConfig();
  const drawer = qs(root, '[data-aiu-context-drawer]');
  const form = qs(root, '[data-aiu-context-form]');
  const titleEl = qs(root, '[data-aiu-context-drawer-title]');
  const drawerBody = qs(root, '.aiu-ai-context__drawer-body');
  const createUri = root.dataset.contextCreateUri || '';
  const updateUri = root.dataset.contextUpdateUri || '';
  const pageId = Number(root.dataset.aiuPageId || root.dataset.contextPageId || '0');

  const fieldUid = qs(root, '[data-aiu-context-field-uid]');
  const hiddenToneTags = qs(root, '[data-aiu-context-field-tone-tags]');
  const hiddenPersonas = qs(root, '[data-aiu-context-field-personas]');
  const hiddenContentRules = qs(root, '[data-aiu-context-field-content-rules]');
  const hiddenForbiddenWords = qs(root, '[data-aiu-context-field-forbidden-words]');
  const hiddenKeywords = qs(root, '[data-aiu-context-field-keywords]');
  const hiddenCompetitors = qs(root, '[data-aiu-context-field-competitors]');
  const hiddenDocumentExtract = qs(root, '[data-aiu-context-field-document-extract]');

  const fieldBrandName = qs(root, '[data-aiu-context-field-brand-name]');
  const fieldIndustry = qs(root, '[data-aiu-context-field-industry]');
  const fieldWebsite = qs(root, '[data-aiu-context-field-website]');
  const fieldTagline = qs(root, '[data-aiu-context-field-tagline]');
  const fieldDescription = qs(root, '[data-aiu-context-field-description]');
  const fieldVoiceNotes = qs(root, '[data-aiu-context-field-voice-notes]');
  const fieldLanguage = qs(root, '[data-aiu-context-field-language]');
  const fieldSample = qs(root, '[data-aiu-context-field-sample]');
  const fieldCompliance = qs(root, '[data-aiu-context-field-compliance]');
  const fieldResearchUrl = qs(root, '[data-aiu-context-research-url]');
  const researchBtn = qs(root, '[data-aiu-context-research-btn]');
  const researchNotice = qs(root, '[data-aiu-context-research-notice]');
  const manualCallout = qs(root, '[data-aiu-context-manual-callout]');
  const manualList = qs(root, '[data-aiu-context-manual-list]');
  const documentInput = qs(root, '[data-aiu-context-document-input]');
  const documentList = qs(root, '[data-aiu-context-document-list]');

  let confidenceMap = {};

  const toneTagsContainer = qs(root, '[data-aiu-context-tone-tags]');
  const personasContainer = qs(root, '[data-aiu-context-personas]');
  const rulesContainer = qs(root, '[data-aiu-context-content-rules]');
  const rulesPreview = qs(root, '[data-aiu-context-rules-preview]');
  const personaWarning = qs(root, '[data-aiu-context-persona-warning]');
  const completenessList = qs(root, '[data-aiu-context-completeness-list]');
  const modalRing = qs(root, '[data-aiu-context-modal-ring]');
  const modalRingValue = qs(root, '[data-aiu-context-modal-ring-value]');

  const chipInputs = {
    forbiddenWords: qs(root, '[data-aiu-context-chip-input="forbiddenWords"]'),
    keywords: qs(root, '[data-aiu-context-chip-input="keywords"]'),
    competitors: qs(root, '[data-aiu-context-chip-input="competitors"]'),
  };

  let state = emptyState();
  /** @type {Set<number>} */
  let personaExpanded = new Set();

  const resetPersonaExpanded = () => {
    personaExpanded = new Set();
  };

  const setPersonaCardExpanded = (card, expanded) => {
    if (!(card instanceof HTMLElement)) {
      return;
    }
    const toggle = qs(card, '[data-aiu-context-persona-toggle]');
    const body = qs(card, '[data-aiu-context-persona-body]');
    if (body instanceof HTMLElement) {
      body.classList.toggle('show', expanded);
    }
    if (toggle instanceof HTMLButtonElement) {
      toggle.classList.toggle('collapsed', !expanded);
      toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
      toggle.setAttribute(
        'aria-label',
        expanded
          ? lang('aiContext.js.personaCollapse', 'Collapse persona')
          : lang('aiContext.js.personaExpand', 'Expand persona'),
      );
    }
  };

  const reconcilePersonaExpandedAfterRemove = (removedIndex) => {
    const next = new Set();
    personaExpanded.forEach((index) => {
      if (index < removedIndex) {
        next.add(index);
      } else if (index > removedIndex) {
        next.add(index - 1);
      }
    });
    personaExpanded = next;
  };

  const readScalarFields = () => {
    state.brandName = fieldBrandName instanceof HTMLInputElement ? fieldBrandName.value.trim() : '';
    state.industry = fieldIndustry instanceof HTMLSelectElement ? fieldIndustry.value.trim() : '';
    state.websiteUrl = fieldWebsite instanceof HTMLInputElement ? fieldWebsite.value.trim() : '';
    state.tagline = fieldTagline instanceof HTMLInputElement ? fieldTagline.value.trim() : '';
    state.description = fieldDescription instanceof HTMLTextAreaElement ? fieldDescription.value.trim() : '';
    state.voiceNotes = fieldVoiceNotes instanceof HTMLTextAreaElement ? fieldVoiceNotes.value.trim() : '';
    state.languageCode = fieldLanguage instanceof HTMLSelectElement ? fieldLanguage.value.trim() : '';
    state.sampleContent = fieldSample instanceof HTMLTextAreaElement ? fieldSample.value.trim() : '';
    state.complianceNotes = fieldCompliance instanceof HTMLTextAreaElement ? fieldCompliance.value.trim() : '';
  };

  const syncHiddenFields = () => {
    if (hiddenToneTags instanceof HTMLInputElement) {
      hiddenToneTags.value = JSON.stringify(state.toneTags);
    }
    if (hiddenPersonas instanceof HTMLInputElement) {
      hiddenPersonas.value = JSON.stringify(state.personas);
    }
    if (hiddenContentRules instanceof HTMLInputElement) {
      hiddenContentRules.value = JSON.stringify(state.contentRules);
    }
    if (hiddenForbiddenWords instanceof HTMLInputElement) {
      hiddenForbiddenWords.value = JSON.stringify(state.forbiddenWords);
    }
    if (hiddenKeywords instanceof HTMLInputElement) {
      hiddenKeywords.value = JSON.stringify(state.keywords);
    }
    if (hiddenCompetitors instanceof HTMLInputElement) {
      hiddenCompetitors.value = JSON.stringify(state.competitors);
    }
    if (hiddenDocumentExtract instanceof HTMLInputElement) {
      hiddenDocumentExtract.value = state.documentExtract ?? '';
    }
  };

  const clearConfidenceBadges = () => {
    qsa(root, '[data-aiu-context-confidence]').forEach((badge) => badge.remove());
    confidenceMap = {};
  };

  const renderConfidenceBadges = () => {
    clearConfidenceBadges();
    Object.entries(CONFIDENCE_FIELD_SELECTORS).forEach(([fieldKey, selector]) => {
      const confidenceKey = {
        brandName: 'brand_name',
        industry: 'industry',
        websiteUrl: 'website_url',
        tagline: 'one_line_description',
        description: 'what_brand_sells',
        voiceNotes: 'write_like_this',
        languageCode: 'language',
      }[fieldKey];
      const level = confidenceMap[confidenceKey];
      if (!level) {
        return;
      }
      const field = qs(root, selector);
      const label = field?.closest('.form-group')?.querySelector('.form-label');
      if (!(label instanceof HTMLElement)) {
        return;
      }
      const badge = document.createElement('span');
      badge.className = `aiu-ai-context__confidence aiu-ai-context__confidence--${level.toLowerCase()}`;
      badge.dataset.aiuContextConfidence = confidenceKey;
      badge.textContent = level;
      label.append(document.createTextNode(' '));
      label.append(badge);
    });
  };

  const showResearchNotice = (message, severity = 'notice') => {
    if (!(researchNotice instanceof HTMLElement)) {
      return;
    }
    if (!message) {
      researchNotice.hidden = true;
      researchNotice.textContent = '';
      researchNotice.className = 'callout callout-notice small mb-2';
      return;
    }
    researchNotice.hidden = false;
    researchNotice.textContent = message;
    researchNotice.className = `callout callout-${severity} small mb-2`;
  };

  const showManualCallout = (items) => {
    if (!(manualCallout instanceof HTMLElement) || !(manualList instanceof HTMLElement)) {
      return;
    }
    const list = Array.isArray(items) ? items : [];
    if (list.length === 0) {
      manualCallout.hidden = true;
      manualList.replaceChildren();
      return;
    }
    manualCallout.hidden = false;
    manualList.replaceChildren();
    list.forEach((key) => {
      const li = document.createElement('li');
      li.textContent = lang(MANUAL_FIELD_LABELS[key] ?? key, key);
      manualList.append(li);
    });
  };

  const renderDocumentList = () => {
    if (!(documentList instanceof HTMLElement)) {
      return;
    }
    const docs = state.uploadedDocuments ?? [];
    if (docs.length === 0) {
      documentList.replaceChildren();
      return;
    }
    documentList.innerHTML = docs.map((doc, index) => (
      `<li class="d-flex align-items-center justify-content-between gap-2 mb-1">
        <span>${escapeHtml(doc.name)} <span class="text-variant">(${doc.chars} ${lang('aiContext.js.chars', 'chars')})</span></span>
        <button type="button" class="btn btn-default btn-sm" data-aiu-context-remove-document="${index}" aria-label="${lang('aiContext.js.removeDocument', 'Remove')}">×</button>
      </li>`
    )).join('');

    qsa(documentList, '[data-aiu-context-remove-document]').forEach((button) => {
      button.addEventListener('click', () => {
        const index = Number(button.getAttribute('data-aiu-context-remove-document') ?? '-1');
        if (index < 0) {
          return;
        }
        state.uploadedDocuments = state.uploadedDocuments.filter((_, i) => i !== index);
        state.documentExtract = state.uploadedDocuments.map((doc) => doc.extract).filter(Boolean).join('\n\n');
        renderDocumentList();
        syncHiddenFields();
      });
    });
  };

  const renderCompleteness = () => {
    readScalarFields();
    const result = calculateCompleteness(state, config.sections);

    if (modalRing instanceof HTMLElement) {
      modalRing.style.setProperty('--aiu-context-progress', String(result.percent));
      modalRing.setAttribute('aria-valuenow', String(result.percent));
    }
    if (modalRingValue instanceof HTMLElement) {
      modalRingValue.textContent = `${result.percent}%`;
    }

    if (completenessList instanceof HTMLElement) {
      completenessList.replaceChildren();
      result.sections.forEach((section) => {
        const li = document.createElement('li');
        li.className = `aiu-ai-context__checklist-item ${section.complete ? 'is-complete' : 'is-incomplete'}`;
        const icon = createBackendIcon(section.complete ? 'actions-check' : 'actions-placeholder');
        icon.setAttribute('size', 'small');
        li.append(icon);
        const label = document.createElement('span');
        label.className = 'aiu-ai-context__checklist-label';
        label.textContent = section.label;
        li.append(label);
        completenessList.append(li);
      });
    }

    if (personaWarning instanceof HTMLElement) {
      const personas = state.personas ?? [];
      const hasPartial = personas.some((p) => {
        const hasName = (p.name ?? '').trim() !== '';
        const hasLevel = (p.level ?? '').trim() !== '';
        return hasName !== hasLevel;
      });
      const firstIncomplete = personas.length > 0 && !isAudienceComplete({ personas: [personas[0]] });
      personaWarning.hidden = !(hasPartial || firstIncomplete);
    }

    const previewRules = (state.contentRules ?? []).filter((rule) => (rule.text ?? '').trim() !== '');
    if (rulesPreview instanceof HTMLElement) {
      if (previewRules.length === 0) {
        rulesPreview.innerHTML = `<li class="text-variant">${lang('aiContext.js.rulesPreviewEmpty', 'Add rules to preview them here.')}</li>`;
      } else {
        rulesPreview.innerHTML = previewRules.map((rule) => {
          const prefix = rule.direction === 'never'
            ? lang('aiContext.js.ruleNever', 'Never')
            : lang('aiContext.js.ruleAlways', 'Always');
          return `<li><strong>${prefix}:</strong> ${escapeHtml(rule.text)}</li>`;
        }).join('');
      }
    }
  };

  const renderToneTags = () => {
    if (!(toneTagsContainer instanceof HTMLElement)) {
      return;
    }
    toneTagsContainer.innerHTML = config.toneTags.map((tag) => {
      const active = state.toneTags.includes(tag) ? ' is-active' : '';
      const disabled = !state.toneTags.includes(tag) && state.toneTags.length >= 5 ? ' disabled' : '';
      return `<button type="button" class="aiu-ai-context__tone-pill${active}" data-aiu-context-tone-tag="${tag}"${disabled}>${tag}</button>`;
    }).join('');

    qsa(toneTagsContainer, '[data-aiu-context-tone-tag]').forEach((button) => {
      button.addEventListener('click', () => {
        if (!(button instanceof HTMLButtonElement) || button.disabled) {
          return;
        }
        const tag = button.dataset.aiuContextToneTag ?? '';
        if (state.toneTags.includes(tag)) {
          state.toneTags = state.toneTags.filter((item) => item !== tag);
        } else if (state.toneTags.length < 5) {
          state.toneTags = [...state.toneTags, tag];
        }
        renderToneTags();
        syncHiddenFields();
        renderCompleteness();
      });
    });
  };

  const renderPersonas = () => {
    if (!(personasContainer instanceof HTMLElement)) {
      return;
    }
    const personas = state.personas.length > 0
      ? state.personas.map((persona) => normalizePersonaState(persona, config.personaLevels))
      : [createEmptyPersona(config.personaLevels)];
    state.personas = personas;

    personasContainer.innerHTML = personas.map((persona, index) => {
      const displayName = (persona.name ?? '').trim()
        || lang('aiContext.js.personaUnnamed', 'Unnamed persona');
      const levelOptions = buildPersonaLevelOptions(persona.level, config.personaLevels);
      const removeHidden = personas.length <= 1 ? ' hidden' : '';
      const isExpanded = personaExpanded.has(index);
      return `<div class="panel panel-default aiu-ai-context__persona-card" id="aiu-persona-heading-${index}" data-aiu-context-persona-row="${index}">
        <div class="panel-heading" role="tab">
          <div class="panel-heading-row">
            <button type="button" class="panel-button${isExpanded ? '' : ' collapsed'}" data-aiu-context-persona-toggle
                    aria-expanded="${isExpanded ? 'true' : 'false'}"
                    aria-controls="aiu-persona-body-${index}"
                    aria-label="${isExpanded
    ? lang('aiContext.js.personaCollapse', 'Collapse persona')
    : lang('aiContext.js.personaExpand', 'Expand persona')}">
              <span class="panel-title d-inline-flex align-items-center gap-2">
                <span class="aiu-ai-context__persona-icon" aria-hidden="true">
                  <typo3-backend-icon identifier="actions-user" size="small"></typo3-backend-icon>
                </span>
                <span class="aiu-ai-context__persona-title" data-aiu-context-persona-title>${escapeHtml(displayName)}</span>
                <span class="badge badge-default" data-aiu-context-persona-level-label>${escapeHtml(persona.level)}</span>
              </span>
              <span class="aiu-ai-context__persona-chevron" aria-hidden="true">
                <typo3-backend-icon identifier="actions-chevron-down" size="small"></typo3-backend-icon>
              </span>
            </button>
            <button type="button" class="btn btn-danger btn-sm aiu-ai-context__persona-remove"${removeHidden} data-aiu-context-remove-persona aria-label="${lang('aiContext.js.removePersona', 'Remove persona')}">
              <typo3-backend-icon identifier="actions-delete" size="small"></typo3-backend-icon>
            </button>
          </div>
        </div>
        <div id="aiu-persona-body-${index}" class="panel-collapse collapse${isExpanded ? ' show' : ''}" role="tabpanel" aria-labelledby="aiu-persona-heading-${index}" data-aiu-context-persona-body>
         <div class="panel-body">
          <div class="row g-2 mb-2">
            <div class="col-md-6">
              <label class="form-label">${lang('aiContext.js.personaLabel', 'Persona Label')}</label>
              <input type="text" class="form-control" maxlength="50"
                     placeholder="${lang('aiContext.js.personaLabelPlaceholder', 'e.g. TYPO3 Admin')}"
                     value="${escapeHtml(persona.name ?? '')}" data-aiu-context-persona-name />
            </div>
            <div class="col-md-6">
              <label class="form-label">${lang('aiContext.js.personaRole', 'Role / Title')}</label>
              <input type="text" class="form-control" maxlength="50"
                     placeholder="${lang('aiContext.js.personaRolePlaceholder', 'e.g. CMS Administrator')}"
                     value="${escapeHtml(persona.role ?? '')}" data-aiu-context-persona-role />
            </div>
          </div>
          <div class="form-group mb-2">
            <label class="form-label">${lang('aiContext.js.personaExpertiseLevel', 'Expertise Level')}</label>
            <select class="form-select" data-aiu-context-persona-level>
              ${levelOptions}
            </select>
          </div>
          <div class="form-group mb-2">
            <label class="form-label">${lang('aiContext.js.personaPainPoints', 'Key Pain Points')}</label>
            <textarea class="form-control" rows="2" maxlength="300"
                      placeholder="${lang('aiContext.js.personaPainPointsPlaceholder', 'What challenges does this persona face?')}"
                      data-aiu-context-persona-pain-points>${escapeHtml(persona.painPoints ?? '')}</textarea>
          </div>
          <div class="form-group mb-0">
            <label class="form-label">${lang('aiContext.js.personaCaresAbout', 'What They Care About')}</label>
            <textarea class="form-control" rows="2" maxlength="300"
                      placeholder="${lang('aiContext.js.personaCaresAboutPlaceholder', 'What outcomes matter most to them?')}"
                      data-aiu-context-persona-cares-about>${escapeHtml(persona.caresAbout ?? '')}</textarea>
          </div>
         </div>
        </div>
      </div>`;
    }).join('');

    qsa(personasContainer, '[data-aiu-context-persona-row]').forEach((row) => {
      const index = Number(row.getAttribute('data-aiu-context-persona-row') ?? '0');
      const toggleBtn = qs(row, '[data-aiu-context-persona-toggle]');
      const titleEl = qs(row, '[data-aiu-context-persona-title]');
      const levelLabelEl = qs(row, '[data-aiu-context-persona-level-label]');
      const nameField = qs(row, '[data-aiu-context-persona-name]');
      const roleField = qs(row, '[data-aiu-context-persona-role]');
      const levelField = qs(row, '[data-aiu-context-persona-level]');
      const painPointsField = qs(row, '[data-aiu-context-persona-pain-points]');
      const caresAboutField = qs(row, '[data-aiu-context-persona-cares-about]');
      const removeBtn = qs(row, '[data-aiu-context-remove-persona]');

      toggleBtn?.addEventListener('click', () => {
        const expanded = !personaExpanded.has(index);
        if (expanded) {
          personaExpanded.add(index);
        } else {
          personaExpanded.delete(index);
        }
        setPersonaCardExpanded(row, expanded);
      });

      const updatePersona = () => {
        const level = levelField instanceof HTMLSelectElement
          ? levelField.value.trim()
          : DEFAULT_PERSONA_LEVEL;
        state.personas[index] = {
          name: nameField instanceof HTMLInputElement ? nameField.value.trim() : '',
          level: config.personaLevels.includes(level) ? level : DEFAULT_PERSONA_LEVEL,
          role: roleField instanceof HTMLInputElement ? roleField.value.trim() : '',
          painPoints: painPointsField instanceof HTMLTextAreaElement ? painPointsField.value.trim() : '',
          caresAbout: caresAboutField instanceof HTMLTextAreaElement ? caresAboutField.value.trim() : '',
        };
        if (titleEl instanceof HTMLElement) {
          titleEl.textContent = state.personas[index].name
            || lang('aiContext.js.personaUnnamed', 'Unnamed persona');
        }
        if (levelLabelEl instanceof HTMLElement) {
          levelLabelEl.textContent = state.personas[index].level;
        }
        syncHiddenFields();
        renderCompleteness();
      };

      nameField?.addEventListener('input', updatePersona);
      roleField?.addEventListener('input', updatePersona);
      painPointsField?.addEventListener('input', updatePersona);
      caresAboutField?.addEventListener('input', updatePersona);
      levelField?.addEventListener('change', updatePersona);
      removeBtn?.addEventListener('click', (event) => {
        event.stopPropagation();
        reconcilePersonaExpandedAfterRemove(index);
        state.personas = state.personas.filter((_, i) => i !== index);
        if (state.personas.length === 0) {
          state.personas = [createEmptyPersona(config.personaLevels)];
        }
        renderPersonas();
        syncHiddenFields();
        renderCompleteness();
      });
    });
  };

  const renderRules = () => {
    if (!(rulesContainer instanceof HTMLElement)) {
      return;
    }
    const rules = state.contentRules.length > 0 ? state.contentRules : [{ direction: 'always', text: '' }];
    state.contentRules = rules;

    rulesContainer.innerHTML = rules.map((rule, index) => {
      const alwaysSelected = rule.direction !== 'never' ? ' selected' : '';
      const neverSelected = rule.direction === 'never' ? ' selected' : '';
      const removeHidden = rules.length <= 1 ? ' hidden' : '';
      return `<div class="aiu-ai-context__rule-row d-flex flex-wrap gap-2 align-items-start" data-aiu-context-rule-row="${index}">
        <div style="min-width: 8rem;">
          <select class="form-select" data-aiu-context-rule-direction>
            <option value="always"${alwaysSelected}>${lang('aiContext.js.ruleAlways', 'Always')}</option>
            <option value="never"${neverSelected}>${lang('aiContext.js.ruleNever', 'Never')}</option>
          </select>
        </div>
        <div class="flex-grow-1">
          <input type="text" class="form-control" maxlength="100" placeholder="${lang('aiContext.js.ruleText', 'Rule text')}"
                 value="${rule.text ?? ''}" data-aiu-context-rule-text />
        </div>
        <button type="button" class="btn btn-danger btn-sm"${removeHidden} data-aiu-context-remove-rule aria-label="${lang('aiContext.js.removeRule', 'Remove rule')}"><typo3-backend-icon identifier="actions-delete" size="small"></typo3-backend-icon></button>
      </div>`;
    }).join('');

    qsa(rulesContainer, '[data-aiu-context-rule-row]').forEach((row) => {
      const index = Number(row.getAttribute('data-aiu-context-rule-row') ?? '0');
      const directionField = qs(row, '[data-aiu-context-rule-direction]');
      const textField = qs(row, '[data-aiu-context-rule-text]');
      const removeBtn = qs(row, '[data-aiu-context-remove-rule]');

      const updateRule = () => {
        state.contentRules[index] = {
          direction: directionField instanceof HTMLSelectElement ? directionField.value : 'always',
          text: textField instanceof HTMLInputElement ? textField.value.trim() : '',
        };
        syncHiddenFields();
        renderCompleteness();
      };

      directionField?.addEventListener('change', updateRule);
      textField?.addEventListener('input', updateRule);
      removeBtn?.addEventListener('click', () => {
        state.contentRules = state.contentRules.filter((_, i) => i !== index);
        if (state.contentRules.length === 0) {
          state.contentRules = [{ direction: 'always', text: '' }];
        }
        renderRules();
        syncHiddenFields();
        renderCompleteness();
      });
    });
  };

  const renderChips = (key) => {
    const container = chipInputs[key];
    if (!(container instanceof HTMLElement)) {
      return;
    }
    const chipsEl = qs(container, '[data-aiu-context-chips]');
    const inputEl = qs(container, '[data-aiu-context-chip-field]');
    if (!(chipsEl instanceof HTMLElement) || !(inputEl instanceof HTMLInputElement)) {
      return;
    }

    const limits = { forbiddenWords: 20, keywords: 15, competitors: 5 };
    const maxItems = limits[key] ?? 20;
    const items = state[key] ?? [];

    chipsEl.innerHTML = items.map((item, index) => (
      `<span class="badge badge-default aiu-ai-context__chip">
        ${item}
        <button type="button" class="aiu-ai-context__chip-remove" data-aiu-context-chip-remove="${index}" aria-label="${lang('aiContext.js.removeChip', 'Remove')}">×</button>
      </span>`
    )).join('');

    inputEl.disabled = items.length >= maxItems;
    inputEl.placeholder = inputEl.disabled
      ? lang('aiContext.js.chipLimit', 'Maximum reached')
      : inputEl.dataset.defaultPlaceholder ?? '';

    qsa(chipsEl, '[data-aiu-context-chip-remove]').forEach((button) => {
      button.addEventListener('click', () => {
        const index = Number(button.getAttribute('data-aiu-context-chip-remove') ?? '-1');
        if (index >= 0) {
          state[key] = state[key].filter((_, i) => i !== index);
          renderChips(key);
          syncHiddenFields();
          renderCompleteness();
        }
      });
    });

    if (inputEl.dataset.bound !== '1') {
      inputEl.dataset.bound = '1';
      inputEl.dataset.defaultPlaceholder = inputEl.placeholder;

      const addFromInput = () => {
        const value = inputEl.value.trim();
        if (value === '' || (state[key]?.length ?? 0) >= maxItems) {
          return;
        }
        if (!(state[key] ?? []).includes(value)) {
          state[key] = [...(state[key] ?? []), value];
        }
        inputEl.value = '';
        renderChips(key);
        syncHiddenFields();
        renderCompleteness();
      };

      inputEl.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ',') {
          event.preventDefault();
          addFromInput();
        } else if (event.key === 'Backspace' && inputEl.value === '' && (state[key]?.length ?? 0) > 0) {
          state[key] = state[key].slice(0, -1);
          renderChips(key);
          syncHiddenFields();
          renderCompleteness();
        }
      });
      inputEl.addEventListener('blur', addFromInput);
    }
  };

  const renderAll = () => {
    renderToneTags();
    renderPersonas();
    renderRules();
    renderChips('forbiddenWords');
    renderChips('keywords');
    renderChips('competitors');
    renderDocumentList();
    clearConfidenceBadges();
    syncHiddenFields();
    renderCompleteness();

    const addPersonaBtn = qs(root, '[data-aiu-context-add-persona]');
    if (addPersonaBtn instanceof HTMLButtonElement) {
      addPersonaBtn.disabled = state.personas.length >= 3;
    }
    const addRuleBtn = qs(root, '[data-aiu-context-add-rule]');
    if (addRuleBtn instanceof HTMLButtonElement) {
      addRuleBtn.disabled = state.contentRules.length >= 10;
    }
  };

  const loadState = (profile, options = {}) => {
    state = {
      ...emptyState(),
      ...(profile ?? {}),
      toneTags: Array.isArray(profile?.toneTags) ? [...profile.toneTags] : [],
      personas: Array.isArray(profile?.personas) && profile.personas.length > 0
        ? profile.personas.map((p) => normalizePersonaState(p, config.personaLevels))
        : [],
      contentRules: Array.isArray(profile?.contentRules) && profile.contentRules.length > 0
        ? profile.contentRules.map((r) => ({ direction: r.direction === 'never' ? 'never' : 'always', text: r.text ?? '' }))
        : [],
      forbiddenWords: Array.isArray(profile?.forbiddenWords) ? [...profile.forbiddenWords] : [],
      keywords: Array.isArray(profile?.keywords) ? [...profile.keywords] : [],
      competitors: Array.isArray(profile?.competitors) ? [...profile.competitors] : [],
      documentExtract: profile?.documentExtract ?? '',
      uploadedDocuments: Array.isArray(profile?.uploadedDocuments) ? [...profile.uploadedDocuments] : [],
    };

    if (!options.keepResearchUi) {
      confidenceMap = {};
      showResearchNotice('');
      showManualCallout([]);
      resetPersonaExpanded();
    }

    setFieldValue(fieldUid, String(state.uid ?? 0));
    setFieldValue(fieldBrandName, state.brandName);
    setFieldValue(fieldIndustry, state.industry);
    setFieldValue(fieldWebsite, state.websiteUrl);
    setFieldValue(fieldTagline, state.tagline);
    setFieldValue(fieldDescription, state.description);
    setFieldValue(fieldVoiceNotes, state.voiceNotes);
    setFieldValue(fieldLanguage, state.languageCode);
    setFieldValue(fieldSample, state.sampleContent);
    setFieldValue(fieldCompliance, state.complianceNotes);
    setFieldValue(fieldResearchUrl, state.websiteUrl);

    renderAll();
  };

  const openDrawer = (mode, profile) => {
    if (isReadOnly || !(drawer instanceof HTMLElement) || !(form instanceof HTMLFormElement)) {
      return;
    }

    const isEdit = mode === 'edit' && profile !== null;
    form.action = isEdit ? updateUri : createUri;
    if (titleEl instanceof HTMLElement) {
      titleEl.textContent = isEdit
        ? lang('aiContext.js.modal.editTitle', 'Edit Profile')
        : lang('aiContext.js.modal.addTitle', 'Add Profile');
    }

    loadState(isEdit ? profile : emptyState());

    drawer.classList.remove('is-closing');
    drawer.classList.add('is-open');
    drawer.setAttribute('aria-hidden', 'false');

    if (drawerBody instanceof HTMLElement) {
      drawerBody.scrollTop = 0;
    }
  };

  const closeDrawer = () => {
    if (!(drawer instanceof HTMLElement) || drawer.classList.contains('is-closing')) {
      return;
    }
    drawer.classList.remove('is-open');
    drawer.classList.add('is-closing');

    const panel = drawer.querySelector('.aiu-drawer__panel');
    const finish = () => {
      if (!drawer.classList.contains('is-closing')) {
        return;
      }
      drawer.classList.remove('is-closing');
      drawer.setAttribute('aria-hidden', 'true');
    };
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
  };

  if (!isReadOnly) {
    root.querySelectorAll('[data-aiu-context-add]').forEach((button) => {
      button.addEventListener('click', () => openDrawer('add', null));
    });

    root.querySelectorAll('[data-aiu-context-edit]').forEach((button) => {
      button.addEventListener('click', () => {
        const card = button.closest('[data-aiu-context-card]');
        const profile = parseProfileJson(card instanceof HTMLElement ? card.dataset.profileJson : '');
        openDrawer('edit', profile);
      });
    });
  }

  root.querySelectorAll('[data-aiu-context-drawer-close]').forEach((button) => {
    button.addEventListener('click', closeDrawer);
  });

  qs(root, '[data-aiu-context-add-persona]')?.addEventListener('click', () => {
    if (state.personas.length >= 3) {
      return;
    }
    const newIndex = state.personas.length > 0 ? state.personas.length : 0;
    state.personas = [...state.personas, createEmptyPersona(config.personaLevels)];
    personaExpanded.add(newIndex);
    renderPersonas();
    syncHiddenFields();
    renderCompleteness();
  });

  qs(root, '[data-aiu-context-add-rule]')?.addEventListener('click', () => {
    if (state.contentRules.length >= 10) {
      return;
    }
    state.contentRules = [...state.contentRules, { direction: 'always', text: '' }];
    renderRules();
    syncHiddenFields();
    renderCompleteness();
  });

  qsa(root, '[data-aiu-context-track]').forEach((field) => {
    field.addEventListener('input', renderCompleteness);
    field.addEventListener('change', renderCompleteness);
  });

  if (form instanceof HTMLFormElement) {
    form.addEventListener('submit', () => {
      readScalarFields();
      state.personas = state.personas.filter(
        (p) => (p.name ?? '').trim() !== '' && (p.level ?? '').trim() !== '',
      );
      state.contentRules = state.contentRules.filter(
        (r) => (r.text ?? '').trim() !== '' && ['always', 'never'].includes(r.direction),
      );
      syncHiddenFields();
    });
  }

  if (drawer instanceof HTMLElement) {
    drawer.addEventListener('click', (event) => {
      if (event.target === drawer) {
        closeDrawer();
      }
    });
  }

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && drawer instanceof HTMLElement && drawer.classList.contains('is-open')) {
      closeDrawer();
    }
  });

  const resolveWebsiteUrl = () => {
    const fromResearch = fieldResearchUrl instanceof HTMLInputElement ? fieldResearchUrl.value.trim() : '';
    const fromWebsite = fieldWebsite instanceof HTMLInputElement ? fieldWebsite.value.trim() : '';
    return fromResearch || fromWebsite;
  };

  const setResearchLoading = (loading) => {
    if (!(researchBtn instanceof HTMLButtonElement)) {
      return;
    }
    researchBtn.disabled = loading;
    researchBtn.classList.toggle('is-loading', loading);
    researchBtn.setAttribute('aria-busy', loading ? 'true' : 'false');
  };

  const runResearch = async () => {
    const url = resolveWebsiteUrl();
    const researchEndpoint = getAjaxUrl('nst3af_brand_context_research');

    if (!url) {
      const message = lang('aiContext.js.researchUrlRequired', 'Enter a website URL first.');
      showResearchNotice(message, 'warning');
      Notification.warning(
        lang('aiContext.js.researchFailedTitle', 'Research failed'),
        message,
      );
      return;
    }

    if (!researchEndpoint) {
      const message = lang(
        'aiContext.js.researchRouteMissing',
        'Research endpoint is unavailable. Flush TYPO3 caches and reload the page.',
      );
      showResearchNotice(message, 'warning');
      Notification.error(
        lang('aiContext.js.researchFailedTitle', 'Research failed'),
        message,
      );
      return;
    }

    if (fieldResearchUrl instanceof HTMLInputElement && fieldResearchUrl.value.trim() === '') {
      fieldResearchUrl.value = url;
    }

    setResearchLoading(true);
    showResearchNotice(lang('aiContext.js.researchRunning', 'Analyzing website…'));

    try {
      const payload = { url };
      if (pageId > 0) {
        payload.id = pageId;
      }
      const response = await new AjaxRequest(researchEndpoint).post(payload);
      const data = await response.resolve('json');
      if (!data?.success) {
        throw new Error(data?.message || lang('aiContext.js.researchFailed', 'Research failed.'));
      }

      confidenceMap = data.confidence ?? {};
      loadState({
        ...state,
        ...data.fields,
        toneTags: data.fields?.toneTags ?? state.toneTags,
        personas: data.fields?.personas ?? state.personas,
        keywords: data.fields?.keywords ?? state.keywords,
        competitors: data.fields?.competitors ?? state.competitors,
      }, { keepResearchUi: true });
      renderConfidenceBadges();
      showManualCallout(data.manualRequired ?? []);
      if (data.fetchNotice) {
        showResearchNotice(data.fetchNotice, 'warning');
      } else if (data.contentFetched) {
        showResearchNotice(lang('aiContext.js.researchSuccess', 'Profile fields updated from website analysis.'), 'success');
      } else {
        showResearchNotice(lang('aiContext.js.researchSuccess', 'Profile fields updated from website analysis.'), 'success');
      }
      Notification.success(
        lang('aiContext.js.researchSuccessTitle', 'Research complete'),
        lang('aiContext.js.researchSuccess', 'Profile fields updated from website analysis.'),
      );
    } catch (error) {
      const message = error instanceof Error ? error.message : lang('aiContext.js.researchFailed', 'Research failed.');
      showResearchNotice(message, 'danger');
      Notification.error(
        lang('aiContext.js.researchFailedTitle', 'Research failed'),
        message,
      );
    } finally {
      setResearchLoading(false);
    }
  };

  fieldWebsite?.addEventListener('input', () => {
    if (fieldResearchUrl instanceof HTMLInputElement && fieldResearchUrl.value.trim() === ''
      && fieldWebsite instanceof HTMLInputElement) {
      fieldResearchUrl.value = fieldWebsite.value;
    }
  });

  root.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    if (!target?.closest('[data-aiu-context-research-btn]')) {
      return;
    }
    event.preventDefault();
    runResearch();
  });

  root.addEventListener('keydown', (event) => {
    if (event.key !== 'Enter') {
      return;
    }
    const target = event.target instanceof Element ? event.target : null;
    if (!target?.closest('[data-aiu-context-research-url]')) {
      return;
    }
    event.preventDefault();
    runResearch();
  });

  documentInput?.addEventListener('change', async () => {
    if (!(documentInput instanceof HTMLInputElement) || !documentInput.files?.length) {
      return;
    }

    const extractUrl = getAjaxUrl('nst3af_brand_context_extract_documents');
    if (!extractUrl) {
      Notification.error(
        lang('aiContext.js.documentsFailedTitle', 'Upload failed'),
        lang('aiContext.js.researchRouteMissing', 'Upload endpoint is unavailable. Flush TYPO3 caches and reload the page.'),
      );
      documentInput.value = '';
      return;
    }

    const selected = Array.from(documentInput.files);
    const existingCount = state.uploadedDocuments?.length ?? 0;
    if (existingCount + selected.length > 3) {
      Notification.warning(
        lang('aiContext.js.documentsLimitTitle', 'Too many files'),
        lang('aiContext.js.documentsLimit', 'Maximum 3 documents allowed.'),
      );
      documentInput.value = '';
      return;
    }

    const formData = new FormData();
    formData.append('id', String(pageId));
    selected.forEach((file) => {
      formData.append('documents[]', file, file.name);
    });

    try {
      const response = await new AjaxRequest(extractUrl).post(formData);
      const data = await response.resolve('json');
      if (!data?.success) {
        throw new Error(data?.message || lang('aiContext.js.documentsFailed', 'Document extraction failed.'));
      }

      const chunk = data.extract ?? '';
      (data.files ?? []).forEach((meta) => {
        state.uploadedDocuments = [
          ...(state.uploadedDocuments ?? []),
          {
            name: meta?.name ?? 'document',
            chars: meta?.chars ?? 0,
            extract: meta?.extract ?? '',
          },
        ];
      });

      state.documentExtract = [
        state.documentExtract ?? '',
        chunk,
      ].filter(Boolean).join('\n\n');

      renderDocumentList();
      syncHiddenFields();

      if (Array.isArray(data.warnings) && data.warnings.length > 0) {
        Notification.warning(
          lang('aiContext.js.documentsWarningTitle', 'Document warnings'),
          data.warnings.join(' '),
        );
      } else {
        Notification.success(
          lang('aiContext.js.documentsSuccessTitle', 'Documents processed'),
          lang('aiContext.js.documentsSuccess', 'Document text added to the profile.'),
        );
      }
    } catch (error) {
      const message = error instanceof Error ? error.message : lang('aiContext.js.documentsFailed', 'Document extraction failed.');
      Notification.error(
        lang('aiContext.js.documentsFailedTitle', 'Upload failed'),
        message,
      );
    } finally {
      documentInput.value = '';
    }
  });
};

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initAiContext);
} else {
  initAiContext();
}

export default initAiContext;
