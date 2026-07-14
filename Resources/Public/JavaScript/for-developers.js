/**
 * For Developers static documentation page — copy-to-clipboard for code example.
 */
const root = document.querySelector('[data-aiu-for-developers-root]');

if (root) {
  initForDevelopersPage(root);
}

/**
 * @param {HTMLElement} scope
 */
function initForDevelopersPage(scope) {
  const copyButton = scope.querySelector('[data-aiu-dev-copy]');
  const codeBlock = scope.querySelector('[data-aiu-dev-code-block]');

  if (!(copyButton instanceof HTMLButtonElement) || !(codeBlock instanceof HTMLElement)) {
    return;
  }

  const copyLabel = copyButton.getAttribute('data-copy-label') || 'Copy';
  const copiedLabel = copyButton.getAttribute('data-copied-label') || 'Copied!';

  const showCopiedFeedback = () => {
    const labelSpan = copyButton.querySelector('[data-aiu-dev-copy-label]');

    copyButton.disabled = true;

    if (labelSpan instanceof HTMLElement) {
      labelSpan.textContent = copiedLabel;
    }

    window.setTimeout(() => {
      if (labelSpan instanceof HTMLElement) {
        labelSpan.textContent = copyLabel;
      }
      copyButton.disabled = false;
    }, 2000);
  };

  copyButton.addEventListener('click', async () => {
    const text = codeBlock.textContent?.trim() ?? '';
    if (text === '') {
      return;
    }

    try {
      await navigator.clipboard.writeText(text);
      showCopiedFeedback();
    } catch {
      // Fallback for environments without clipboard API permission.
      const range = document.createRange();
      range.selectNodeContents(codeBlock);
      const selection = window.getSelection();
      selection?.removeAllRanges();
      selection?.addRange(range);
      const copied = document.execCommand('copy');
      selection?.removeAllRanges();

      if (copied) {
        showCopiedFeedback();
      }
    }
  });

  scope.querySelectorAll('a[href^="#"]').forEach((anchor) => {
    anchor.addEventListener('click', (event) => {
      const href = anchor.getAttribute('href');
      if (!href || href === '#') {
        return;
      }
      const target = scope.querySelector(href) ?? document.querySelector(href);
      if (target instanceof HTMLElement) {
        event.preventDefault();
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    });
  });
}
