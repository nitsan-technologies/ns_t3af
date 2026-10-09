/**
 * AI Agent text/label formatting helpers (translation, errors, escaping, durations).
 */

/**
 * @param {object} meta
 * @returns {boolean}
 */
export function hasTurnGuardWarning(meta) {
  const warning = meta?.turnGuardWarning;
  return warning != null && warning !== '' && String(warning) !== 'null';
}

/**
 * @param {string} key
 * @param {string} fallback
 * @param {Array<string|number>} [replacements]
 * @returns {string}
 */
export function lang(key, fallback, replacements = []) {
  let value = typeof TYPO3 !== 'undefined' && TYPO3.lang ? TYPO3.lang[key] : undefined;
  value = value ?? fallback;
  if (typeof value !== 'string') {
    value = String(value ?? fallback ?? '');
  }
  replacements.forEach((replacement, index) => {
    value = value.replace(`%${index + 1}$s`, String(replacement));
  });
  return value;
}

/**
 * @param {unknown} error
 * @returns {string}
 */
export function errorMessage(error) {
  if (error instanceof Error) {
    return error.message;
  }
  if (typeof error === 'string') {
    return error;
  }
  if (error && typeof error === 'object' && 'message' in error) {
    return String(error.message);
  }
  return lang('agent.error.generic', 'Something went wrong.');
}

/**
 * Like errorMessage(), but also reads the JSON message of a failed TYPO3 AjaxRequest
 * (it rejects with the response, not with an Error).
 *
 * @param {unknown} error
 * @returns {Promise<string>}
 */
export async function errorText(error) {
  return (await errorDetails(error)).text;
}

/**
 * errorText() plus whether the server said another try cannot help (retryable: false).
 * The response body can only be read once, so callers needing both use this.
 *
 * @param {unknown} error
 * @returns {Promise<{text: string, retryable: boolean}>}
 */
export async function errorDetails(error) {
  if (error && typeof error === 'object' && typeof error.resolve === 'function') {
    try {
      const payload = await error.resolve();
      if (payload && typeof payload === 'object' && typeof payload.message === 'string' && payload.message.trim() !== '') {
        return { text: payload.message, retryable: payload.retryable !== false };
      }
    } catch {
      // Not JSON: fall back to the generic text.
    }
  }
  return { text: errorMessage(error), retryable: true };
}

/**
 * @param {unknown} value
 * @param {string} fallback
 * @returns {string}
 */
export function messageContent(value, fallback = '') {
  if (typeof value === 'string') {
    return value;
  }
  if (value === null || value === undefined) {
    return fallback;
  }
  if (typeof value === 'number' || typeof value === 'boolean') {
    return String(value);
  }
  return fallback;
}

export function humanizeKey(key) {
  const words = String(key).replace(/([a-z0-9])([A-Z])/g, '$1 $2').replace(/[_-]+/g, ' ').trim().toLowerCase();
  return words === '' ? '' : words.charAt(0).toUpperCase() + words.slice(1);
}

/**
 * @param {string} text
 * @returns {string}
 */
export function escapeHtml(text) {
  return text
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

/**
 * @param {number} ms
 * @returns {string}
 */
export function formatWorkDuration(ms) {
  const totalSeconds = Math.max(1, Math.round(ms / 1000));
  if (totalSeconds < 60) {
    return `${totalSeconds}s`;
  }

  const minutes = Math.floor(totalSeconds / 60);
  const seconds = totalSeconds % 60;

  return seconds > 0 ? `${minutes}m ${seconds}s` : `${minutes}m`;
}
