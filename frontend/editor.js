import {EditorState, StateField, StateEffect} from '@codemirror/state';
import {EditorView, keymap, lineNumbers, highlightActiveLineGutter, highlightActiveLine, drawSelection, Decoration} from '@codemirror/view';
import {defaultKeymap, history, historyKeymap, indentWithTab} from '@codemirror/commands';
import {json} from '@codemirror/lang-json';
import {ensureSyntaxTree, syntaxTree, syntaxHighlighting, defaultHighlightStyle, foldGutter, foldKeymap, indentOnInput, bracketMatching} from '@codemirror/language';
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
  const source = state.doc.toString();
  try { JSON.parse(source); return []; } catch (error) {
    // CodeMirror's background parse may be incomplete after a large edit.
    const position = /\bposition (\d+)/i.exec(error.message);
    const location = /\bline (\d+) column (\d+)/i.exec(error.message);
    let offset = position ? Number(position[1]) : null;
    if (offset === null && location && Number(location[1]) <= state.doc.lines) {
      const line = state.doc.line(Number(location[1]));
      offset = Math.min(line.to, line.from + Number(location[2]) - 1);
    }
    if (offset === null) {
      const tree = ensureSyntaxTree(state, state.doc.length, 50);
      tree?.iterate({enter(node) {
        if (node.type.isError && offset === null) offset = node.from;
      }});
    }
    if (offset === null && !/end of (?:JSON )?(?:input|data)/i.test(error.message)) {
      return [{from:0, to:0, severity:'error', message:'Invalid JSON'}];
    }
    const from = Math.max(0, Math.min(offset ?? state.doc.length, state.doc.length));
    const line = state.doc.lineAt(from);
    return [{from, to:Math.min(from + 1, state.doc.length), severity:'error', message:`Invalid JSON — line ${line.number}, column ${from - line.from + 1}`}];
  }
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
