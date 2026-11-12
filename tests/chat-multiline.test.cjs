/* No network, credentials, database writes or AI calls. */
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.resolve(__dirname, '..');
const context = {Event: class Event {constructor(type, options) {this.type = type;Object.assign(this, options);}}};
vm.createContext(context);
vm.runInContext(fs.readFileSync(path.join(root, 'public/chat-input.js'), 'utf8') + '\nglobalThis.helper = ChatInput;', context);
const helper = context.helper;
function element(value = '') {
  const listeners = {};
  return {value, maxLength: 1500, readOnly: false, disabled: false, selectionStart: value.length, selectionEnd: value.length,
    scrollHeight: 220, style: {}, attributes: {}, focused: false,
    addEventListener(t, f) {(listeners[t] ||= []).push(f);},
    dispatchEvent(e) {(listeners[e.type] || []).forEach(f => f(e));},
    setAttribute(k, v) {this.attributes[k] = v;},
    focus() {this.focused = true;},
    setRangeText(s, a, b) {this.value = this.value.slice(0, a) + s + this.value.slice(b);this.selectionStart = this.selectionEnd = a + s.length;}
  };
}
function fixture() {
  const box = element('Dòng đầu'), button = element();
  let sends = 0, draft = box.value, allowed = true;
  helper.bind(box, {send: () => sends++, canSend: () => allowed, onInput: value => draft = value, lineBreakButton: button});
  return {box, button, get sends() {return sends;}, get draft() {return draft;}, disallow() {allowed = false;}};
}
function key(box, extra = {}) {
  const event = {type: 'keydown', key: 'Enter', prevented: false, preventDefault() {this.prevented = true;}, ...extra};
  box.dispatchEvent(event);return event;
}
test('Plain Enter remains a newline and does not submit', () => {const f = fixture();assert.equal(key(f.box).prevented, false);assert.equal(f.sends, 0);});
test('Shift+Enter remains a newline', () => {const f = fixture();assert.equal(key(f.box, {shiftKey: true}).prevented, false);assert.equal(f.sends, 0);});
test('Ctrl+Enter explicitly sends once', () => {const f = fixture();assert.equal(key(f.box, {ctrlKey: true}).prevented, true);assert.equal(f.sends, 1);});
test('Cmd+Enter explicitly sends once', () => {const f = fixture();key(f.box, {metaKey: true});assert.equal(f.sends, 1);});
test('IME composition does not accidentally send Vietnamese text', () => {const f = fixture();key(f.box, {ctrlKey: true, isComposing: true});key(f.box, {ctrlKey: true, keyCode: 229});f.box.dispatchEvent({type: 'compositionstart'});key(f.box, {ctrlKey: true});assert.equal(f.sends, 0);f.box.dispatchEvent({type: 'compositionend'});key(f.box, {ctrlKey: true});assert.equal(f.sends, 1);});
test('Holding shortcut does not repeatedly send', () => {const f = fixture();key(f.box, {ctrlKey: true, repeat: true});assert.equal(f.sends, 0);});
test('Busy/unready composers do not send', () => {const f = fixture();f.disallow();key(f.box, {ctrlKey: true});assert.equal(f.sends, 0);});
test('Blank content does not send', () => {const f = fixture();f.box.value = '\n \n';key(f.box, {ctrlKey: true});assert.equal(f.sends, 0);});
test('Read-only composer rejects shortcut and newline insertion', () => {const f = fixture();f.box.readOnly = true;key(f.box, {ctrlKey: true});assert.equal(helper.insertLineBreak(f.box), false);assert.equal(f.sends, 0);});
test('Newline button inserts at caret and saves multiline draft', () => {const f = fixture();f.box.value = 'ĐầuCuối';f.box.selectionStart = f.box.selectionEnd = 3;f.button.dispatchEvent({type: 'click'});assert.equal(f.box.value, 'Đầu\nCuối');assert.equal(f.draft, 'Đầu\nCuối');assert.equal(f.box.selectionStart, 4);assert.equal(f.sends, 0);});
test('Blank stanza separator and paste remain intact', () => {const f = fixture();const poem = 'Dòng một\nDòng hai\n\nKhổ tiếp theo';f.box.value = poem;f.box.dispatchEvent({type: 'input'});assert.equal(f.draft, poem);assert.equal(JSON.parse(JSON.stringify({text: f.draft})).text, poem);assert.equal(f.sends, 0);});
test('Newline insertion respects maxLength and selection replacement', () => {const box = element('abc');box.maxLength = 3;assert.equal(helper.insertLineBreak(box), false);box.selectionStart = 1;box.selectionEnd = 2;assert.equal(helper.insertLineBreak(box), true);assert.equal(box.value, 'a\nc');});
test('Textarea grows with cap and advertises Enter keyboard', () => {const f = fixture();assert.equal(f.box.style.height, '168px');assert.equal(f.box.attributes.enterkeyhint, 'enter');});
test('Formatter retains paragraph breaks and safely escapes HTML', () => {
  const html = fs.readFileSync(path.join(root, 'public/index.html'), 'utf8');
  const source = html.slice(html.indexOf('const esc ='), html.indexOf('const nick ='));
  const formatted = vm.runInNewContext(source + '\nfmt("Dòng một\\r\\n\\r\\n**Dòng hai**\\n<script>bad</script>")');
  assert.equal(formatted, 'Dòng một<br><br><b>Dòng hai</b><br>&lt;script&gt;bad&lt;/script&gt;');
});
test('Both app composers use the helper instead of Enter-to-send', () => {
  const html = fs.readFileSync(path.join(root, 'public/index.html'), 'utf8');
  const companion = fs.readFileSync(path.join(root, 'public/conversation.js'), 'utf8');
  assert.match(html, /ChatInput\.bind\(box/);assert.match(companion, /ChatInput\.bind\(box/);
  assert.ok(html.indexOf('/chat-input.js?v=') < html.indexOf('/conversation.js?v='));
  assert.doesNotMatch(html, /e\.key==="Enter"&&!e\.shiftKey/);
  assert.doesNotMatch(companion, /e\.key==='Enter'&&!e\.shiftKey/);
});
