/**
 * AI Agent open-hotkey chord: label, stored preference, and event matching.
 */

export const STORAGE_PREFS_KEY = 'nst3af.agent.prefs';
export const STORAGE_OPEN_KEY = 'nst3af.agent.open';

/** Safe default — leaves Live Search on Ctrl/Cmd+K (CTO / Sanjay). */
export const HOTKEY_DEFAULT = 'mod+shift+k';

/** Opt-in: takes Live Search chord. */
export const HOTKEY_OPT_IN_K = 'mod+k';

/**
 * @param {string} chord
 * @returns {string}
 */
export function hotkeyLabel(chord) {
  return chord === HOTKEY_OPT_IN_K ? 'Ctrl/Cmd+K' : 'Ctrl/Cmd+Shift+K';
}

/**
 * @returns {string}
 */
export function readHotkeyPref() {
  try {
    const prefs = JSON.parse(localStorage.getItem(STORAGE_PREFS_KEY) ?? '{}');
    if (prefs?.hotkey === HOTKEY_OPT_IN_K || prefs?.hotkey === HOTKEY_DEFAULT) {
      return prefs.hotkey;
    }
  } catch {
    // ignore malformed prefs
  }
  return HOTKEY_DEFAULT;
}

/**
 * @param {KeyboardEvent} event
 * @param {string} chord
 * @returns {boolean}
 */
export function matchesAgentHotkey(event, chord) {
  if (!(event.metaKey || event.ctrlKey) || event.altKey) {
    return false;
  }
  if (event.key.toLowerCase() !== 'k') {
    return false;
  }
  if (chord === HOTKEY_OPT_IN_K) {
    return !event.shiftKey;
  }
  return event.shiftKey;
}
