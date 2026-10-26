import test from 'node:test';
import assert from 'node:assert/strict';
import {chromium} from 'playwright';
import {createServer} from 'node:http';
import {readFile} from 'node:fs/promises';
import {resolve} from 'node:path';
const root = resolve('.');
const fixture = await readFile('tests/full-config.json','utf8');
test('CodeMirror full JSON editor in Chromium', async t => {
  const server = createServer(async (req,res) => {
    if (req.url === '/') { res.setHeader('Content-Type','text/html'); res.end('<link rel="stylesheet" href="/editor.css"><textarea id="config"></textarea><script src="/editor.js"></script><script>window.editor=XrayEditor.mount(document.getElementById("config"));</script>'); return; }
    try { res.setHeader('Content-Type',req.url.endsWith('.js')?'text/javascript':'text/css'); res.end(await readFile(root+'/plugin/www/assets/xray'+req.url)); } catch (_) { res.writeHead(404).end(); }
  });
  await new Promise(r => server.listen(0,'127.0.0.1',r));
  const browser = await chromium.launch({headless:true});
  const page = await browser.newPage({viewport:{width:1200,height:800}});
  const errors=[];page.on('pageerror',e=>errors.push(e.message));
  try {
    await page.goto('http://127.0.0.1:'+server.address().port);
    await page.evaluate(value=>editor.setValue(value),fixture);
    await t.test('local editor, line numbers, folding and initial height', async()=> {
      assert.equal(await page.locator('.xray-json-editor').evaluate(e=>e.offsetHeight),420);
      assert.ok(await page.locator('.cm-lineNumbers .cm-gutterElement').count()>5);
      assert.ok(await page.locator('.cm-foldGutter .cm-gutterElement').count()>1);
      assert.equal(await page.evaluate(()=>editor.diagnostics().length),0);
    });
    await t.test('DNS Off dims source without deleting it', async()=> {
      await page.evaluate(()=>editor.setDns(false));
      assert.ok(await page.locator('.xray-dns-disabled').count()>0);
      assert.equal(await page.evaluate(()=>JSON.parse(editor.getValue()).dns.servers[0]),'1.1.1.1');
      await page.evaluate(()=>editor.setDns(true));
      assert.equal(await page.locator('.xray-dns-disabled').count(),0);
    });
    await t.test('DNS Off remains editable with incomplete JSON',async()=>{await page.evaluate(()=>{editor.setDns(false);editor.setValue('{"dn');});await page.waitForTimeout(100);await page.evaluate(value=>{editor.setValue(value);editor.setDns(true);},fixture);});
    await t.test('formatting retains semantic hash and array order',async()=> {
      const result=await page.evaluate(()=>{const before=XrayEditor.hash(JSON.parse(editor.getValue()));editor.format();return [before,XrayEditor.hash(JSON.parse(editor.getValue())),JSON.parse(editor.getValue()).routing.balancers[0].strategy.settings.expected,document.getElementById('config').value===editor.getValue()];});
      assert.equal(result[0],result[1]);assert.equal(result[2],2);assert.equal(result[3],true);
    });
    await t.test('syntax error has line and column',async()=> {
      await page.evaluate(()=>editor.setValue('{\n "outbounds": [\n}'));
      await page.waitForTimeout(200);
      const issues=await page.evaluate(()=>editor.diagnostics());
      assert.ok(issues.length);assert.match(issues[0].message,/line \d+, column \d+/);
    });
    await page.evaluate(value=>editor.setValue(value),fixture);
    await t.test('search and folding work',async()=> {
      await page.locator('.cm-content').click();await page.keyboard.press('ControlOrMeta+f');
      assert.ok(await page.locator('.cm-search').count());
      await page.keyboard.press('Escape');
      const before=await page.locator('.cm-line').count();
      await page.locator('.cm-foldGutter .cm-gutterElement').filter({hasText:'⌄'}).first().click();
      assert.ok(await page.locator('.cm-line').count()<before);
    });
    await t.test('height can be changed with resize handle',async()=> {
      const box=await page.locator('.xray-json-editor').boundingBox();
      await page.mouse.move(box.x+box.width-3,box.y+box.height-3);await page.mouse.down();await page.mouse.move(box.x+box.width-3,box.y+box.height+100,{steps:10});await page.mouse.up();
      assert.ok(await page.locator('.xray-json-editor').evaluate(e=>e.offsetHeight)>420);
    });
    assert.deepEqual(errors,[]);
  } finally { await browser.close(); await new Promise(r=>server.close(r)); }
});
