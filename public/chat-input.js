/* Shared multiline composer. No API calls: Enter edits; only explicit send submits. */
const ChatInput = (() => {
  const MAX_HEIGHT = 168;

  function resize(box) {
    if (!box) return;
    box.style.height = 'auto';
    box.style.height = Math.min(box.scrollHeight, MAX_HEIGHT) + 'px';
  }

  function insertLineBreak(box) {
    if (!box || box.disabled || box.readOnly) return false;
    const start = box.selectionStart ?? box.value.length;
    const end = box.selectionEnd ?? start;
    const length = box.value.length - (end - start) + 1;
    if (box.maxLength >= 0 && length > box.maxLength) return false;
    box.focus();
    box.setRangeText('\n', start, end, 'end');
    box.dispatchEvent(new Event('input', {bubbles: true}));
    return true;
  }

  function bind(box, {send, canSend = () => true, onInput = () => {}, lineBreakButton = null} = {}) {
    if (!box) return;
    let composing = false;
    box.setAttribute('enterkeyhint', 'enter');
    box.addEventListener('compositionstart', () => { composing = true; });
    box.addEventListener('compositionend', () => { composing = false; });
    box.addEventListener('input', () => { resize(box); onInput(box.value); });
    box.addEventListener('keydown', event => {
      if (event.key !== 'Enter' || event.isComposing || composing || event.keyCode === 229) return;
      // Plain Enter / Shift+Enter keep the textarea's native newline behaviour.
      if (!(event.ctrlKey || event.metaKey) || event.shiftKey || event.altKey) return;
      event.preventDefault();
      if (event.repeat || box.readOnly || box.disabled || !box.value.trim() || !canSend()) return;
      if (typeof send === 'function') send();
    });
    if (lineBreakButton) {
      // Keep the caret (and mobile keyboard) when the explicit newline button is tapped.
      lineBreakButton.addEventListener('pointerdown', event => { event.preventDefault(); });
      lineBreakButton.addEventListener('click', () => { insertLineBreak(box); });
    }
    resize(box);
  }

  return Object.freeze({bind, resize, insertLineBreak});
})();
