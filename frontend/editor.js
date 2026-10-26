import {EditorState, StateField, StateEffect} from '@codemirror/state';
import {EditorView, keymap, lineNumbers, highlightActiveLineGutter, highlightActiveLine, drawSelection, Decoration} from '@codemirror/view';
import {defaultKeymap, history, historyKeymap, indentWithTab} from '@codemirror/commands';
import {json} from '@codemirror/lang-json';
import {syntaxTree, syntaxHighlighting, defaultHighlightStyle, foldGutter, foldKeymap, indentOnInput, bracketMatching} from '@codemirror/language';
import {searchKeymap, highlightSelectionMatches, search} from '@codemirror/search';
import {linter, lintGutter} from '@codemirror/lint';
import {sha256} from '@noble/hashes/sha2.js';
import {bytesToHex, utf8ToBytes} from '@noble/hashes/utils.js';

export function canonical(value) {
  if (Array.isArray(value)) return '[' + value.map(canonical).join(',') + ']';
  if (value && typeof value === 'object') return '{' + Object.keys(value).sort().map(k => JSON.stringify(k) + ':' + canonical(value[k])).join(',') + '}';
  return JSON.stringify(value);
}
export function hash(value) { return bytesToHex(sha256(utf8ToBytes(canonical(value)))); }
export function diagnostics(state) {
  const found = [];
  syntaxTree(state).iterate({enter(node) {
    if (node.type.isError) {
      const from = Math.min(node.from, state.doc.length), line = state.doc.lineAt(from);
      found.push({from, to: Math.min(state.doc.length, Math.max(from + 1, node.to)), severity: 'error', message: `Invalid JSON — line ${line.number}, column ${from - line.from + 1}`});
    }
  }});
  if (!found.length) {
    try { JSON.parse(state.doc.toString()); } catch (_) {
      const from = state.doc.length, line = state.doc.lineAt(from);
      found.push({from, to: from, severity: 'error', message: `Invalid JSON — line ${line.number}, column ${from - line.from + 1}`});
    }
  }
  return found;
}
const dnsEffect = StateEffect.define();
const dnsEnabled = StateField.define({create: () => true, update(value, tr) { for (const effect of tr.effects) if (effect.is(dnsEffect)) value = effect.value; return value; }});
function dnsMarks(state) {
  if (state.field(dnsEnabled)) return Decoration.none;
  const ranges = [], root = syntaxTree(state).topNode.firstChild;
  for (const prop of root?.getChildren('Property') || []) {
    const name = prop.getChild('PropertyName');
    let key; try { key = name ? JSON.parse(state.sliceDoc(name.from, name.to)) : null; } catch (_) { continue; }
    if (key === 'dns') ranges.push(Decoration.mark({class: 'xray-dns-disabled'}).range(prop.from, prop.to));
  }
  return Decoration.set(ranges);
}
const dimDns = EditorView.decorations.compute(['doc', dnsEnabled], dnsMarks);
export function mount(textarea, onChange = () => {}) {
  const host = document.createElement('div'); host.className = 'xray-json-editor';
  textarea.after(host); textarea.style.display = 'none';
  let loading = false;
  const view = new EditorView({parent: host, state: EditorState.create({doc: textarea.value || '{}', extensions: [
    lineNumbers(), highlightActiveLineGutter(), history(), foldGutter(), drawSelection(), indentOnInput(), bracketMatching(), highlightActiveLine(),
    syntaxHighlighting(defaultHighlightStyle), json(), lintGutter(), linter(v => diagnostics(v.state)), search({top: true}), highlightSelectionMatches(),
    keymap.of([...defaultKeymap, ...historyKeymap, ...foldKeymap, ...searchKeymap, indentWithTab]), dnsEnabled, dimDns,
    EditorView.theme({'&': {height: '100%', fontSize: '13px'}, '.cm-scroller': {overflow: 'auto', fontFamily: 'monospace'}, '.cm-content': {minHeight: '100%'}}),
    EditorView.updateListener.of(update => { if (update.docChanged) { textarea.value = update.state.doc.toString(); if (!loading) { textarea.dispatchEvent(new Event('change', {bubbles: true})); onChange(); } } })
  ]})});
  return {
    view,
    getValue: () => view.state.doc.toString(),
    setValue(value) { loading = true; view.dispatch({changes: {from: 0, to: view.state.doc.length, insert: value || '{}'}}); textarea.value = value || '{}'; loading = false; },
    setDns(value) { view.dispatch({effects: dnsEffect.of(value)}); },
    format() { const value = JSON.stringify(JSON.parse(view.state.doc.toString()), null, 2); view.dispatch({changes: {from: 0, to: view.state.doc.length, insert: value}}); },
    diagnostics: () => diagnostics(view.state),
    destroy() { view.destroy(); host.remove(); textarea.style.display = ''; }
  };
}
