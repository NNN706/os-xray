import test from 'node:test';
import assert from 'node:assert/strict';
import {chromium} from 'playwright';
import {createServer} from 'node:http';
import {readFile} from 'node:fs/promises';
const fixture=JSON.parse(await readFile('tests/full-config.json','utf8'));
const fields=['config_json','use_config_dns','socks_inbound','subscription_url','subscription_hash','subscription_baseline','subscription_metadata','subscription_fetched','subscription_remarks','subscription_hwid','auto_update','update_mode','update_hours','name'];
const id='11111111-1111-4111-8111-111111111111';
test('Full configuration dialog, unsaved validation and subscription choices',async t=>{
 let lastValidation=null, preparedProfile=null, validationReply={result:"ok",message:"Effective configuration is valid"};
 const server=createServer(async(req,res)=>{
  if(req.url==='/') {res.setHeader('Content-Type','text/html; charset=utf-8');res.end(`<meta charset="utf-8"><style>table{width:100%;table-layout:fixed}#DialogInstance{display:block;width:100%}</style><link rel="stylesheet" href="/editor.css"><div id="DialogInstance" class="in"><div class="modal-body"><table>${fields.map(k=>`<tr><td>${k}</td><td>${k==='config_json'||k==='subscription_metadata'?`<textarea id="instance.${k}"></textarea>`:`<input id="instance.${k}" type="${['auto_update','use_config_dns'].includes(k)?'checkbox':'text'}">`}</td></tr>`).join('')}</table></div></div><script src="/jquery.js"></script><script src="/editor.js"></script><script>const originalMount=XrayEditor.mount;XrayEditor={...XrayEditor,mount:(...args)=>window.dialogEditor=originalMount(...args)};window.setInterval=fn=>{window.pollSubscription=fn;};</script><script src="/dialog.js"></script>`);return;}
  if(req.url==='/api/xray/service/validate'){let body='';for await(const chunk of req)body+=chunk;lastValidation=JSON.parse(body);res.setHeader('Content-Type','application/json');res.end(JSON.stringify(validationReply));return;}
  if(req.url==='/api/xray/instance/importSubscription'){
   const parts=[]; for await(const chunk of req)parts.push(chunk);
   const body=JSON.parse(Buffer.concat(parts));
   res.setHeader('Content-Type','application/json');
   res.end(JSON.stringify(body.hwid==='fixture-device' && body.url==='https://example.test/subscription' ? {result:'saved',added:13,total:13} : {result:'failed'}));return;
  }
  if(req.url==='/api/xray/import/subscription'){res.setHeader('Content-Type','application/json');res.end(JSON.stringify({result:'ok',configurations:[{...fixture,remarks:'First'},{...fixture,remarks:'Second'}],metadata:{interval_hours:2,ping_type:'proxy-head',check_url:'https://example.test'},fetched_at:100}));return;}
  if(req.url==='/api/xray/instance/prepared/'+id){res.setHeader('Content-Type','application/json');res.end(JSON.stringify({result:'ok',profile:preparedProfile}));return;}
  if(req.url==='/api/xray/instance/getItem/'+id){res.setHeader('Content-Type','application/json');res.end(JSON.stringify({instance:{config_json:JSON.stringify(fixture),subscription_hash:'',subscription_metadata:'{}',subscription_fetched:'0'}}));return;}
  try{const path=req.url==='/jquery.js'?'node_modules/jquery/dist/jquery.js':'plugin/www/assets/xray'+req.url;res.setHeader('Content-Type',req.url.endsWith('.css')?'text/css':'text/javascript');res.end(await readFile(path));}catch(_){res.writeHead(404).end();}
 });await new Promise(r=>server.listen(0,'127.0.0.1',r));
 const browser=await chromium.launch({headless:true}),page=await browser.newPage({viewport:{width:1400,height:1000}}),errors=[];page.on('pageerror',e=>errors.push(e.message));
 try{
  await page.goto('http://127.0.0.1:'+server.address().port);
  await page.evaluate(value=>{document.getElementById('instance.config_json').value=JSON.stringify(value);document.getElementById('instance.use_config_dns').checked=true;$('#DialogInstance').trigger('show.bs.modal').trigger('shown.bs.modal');},fixture);
  await t.test('Validate sends current unsaved JSON and DNS switch',async()=>{
   await page.evaluate(()=>{const value=JSON.parse(dialogEditor.getValue());value.remarks='Unsaved';dialogEditor.setValue(JSON.stringify(value));document.getElementById('instance.use_config_dns').checked=false;$('#instance\\.use_config_dns').trigger('change');});
   await page.locator('#xray-validate').click();await page.waitForFunction(()=>document.getElementById('xray-validation-result').textContent==='Effective configuration is valid');
   assert.equal(JSON.parse(lastValidation.config_json).remarks,'Unsaved');assert.equal(lastValidation.use_config_dns,false);assert.ok(await page.locator('.xray-dns-disabled').count());
  });
  await t.test('validation colors persist until an edit and failures are red',async()=>{
   const button=page.locator('#xray-validate');
   assert.equal(await button.getAttribute('data-validation'),'success');
   await page.evaluate(()=>$('#instance\\.use_config_dns').trigger('change'));
   assert.equal(await button.getAttribute('data-validation'),'idle');
   validationReply={result:'failed',message:'Xray rejected the configuration'};
   await button.click();
   await page.waitForFunction(()=>document.getElementById('xray-validate').dataset.validation==='error');
   assert.match(await page.locator('#xray-validation-result').textContent(),/Xray rejected/);
   await page.evaluate(()=>{dialogEditor.setValue('{"outbounds":[{"protocol":"freedom"}]}');$('#instance\\.use_config_dns').trigger('change');});
   assert.equal(await button.getAttribute('data-validation'),'idle');
   assert.equal(await page.locator('#xray-validation-result').textContent(),'');
   validationReply={result:'ok',message:'Effective configuration is valid'};
  });
  await t.test('reopening a profile loads saved JSON, not stale unsaved editor text',async()=>{
   await page.evaluate(value=>{
    $('#DialogInstance').trigger('show.bs.modal');
    window.XrayDialogLoad({frm_DialogInstance:{instance:{config_json:JSON.stringify(value)}}});
    $('#DialogInstance').trigger('shown.bs.modal');
   },fixture);
   assert.deepEqual(await page.evaluate(()=>JSON.parse(dialogEditor.getValue())),fixture);
   assert.equal(await page.locator('[id="instance.subscription_url"]').isVisible(),false);
  });
  await t.test('without a subscription URL, update settings are locked and explain why', async()=>{
   await page.evaluate(()=>{$('#instance\\.auto_update').prop('checked',true).trigger('change');});
   const toggle=page.locator('[id="instance.auto_update"]');
   assert.equal(await toggle.isDisabled(),true);
   assert.equal(await toggle.isChecked(),false);
   assert.match(await page.locator('#xray-update-reason').textContent(),/^No subscription URL/);
  });
  await t.test('batch import sends device ID and reports the number of saved hosts', async()=>{
   await page.locator('#xray-import').click();
   await page.locator('#xray-import-input').fill('https://example.test/subscription');
   await page.locator('#xray-import-hwid').fill('fixture-device');
   await page.locator('#xray-import-all').click();
   await page.waitForFunction(()=>document.querySelector('#xray-import-status').textContent.startsWith('Added 13 of 13'));
   await page.locator('#xray-import-hwid').fill('');
   await page.locator('#xray-import').click();
  });
  await t.test('multiple URL profiles require an explicit choice by remarks',async()=>{
   const before = await page.evaluate(()=>dialogEditor.getValue());
   await page.locator('#xray-import').click();
   assert.equal(await page.locator('#xray-import-panel').evaluate(panel=>panel.previousElementSibling.id),'xray-editor-tools');
   await page.locator('#xray-import-input').fill('https://example.test/subscription');await page.locator('#xray-import-read').click();await page.waitForFunction(()=>document.querySelectorAll('#xray-import-choice option').length===3);
   assert.equal(await page.evaluate(()=>dialogEditor.getValue()),before);await page.locator('#xray-import-choice').selectOption('1');assert.equal(await page.evaluate(()=>JSON.parse(dialogEditor.getValue()).remarks),'Second');assert.equal(await page.locator('[id="instance.name"]').inputValue(),'Second');
   assert.equal(await page.locator('[id="instance.subscription_url"]').inputValue(),'https://example.test/subscription');
  });
  await t.test('editor accepts keyboard edits without reentrant updates',async()=>{await page.locator('.cm-content').click();await page.keyboard.press('ControlOrMeta+End');await page.keyboard.type(' ');assert.deepEqual(errors,[]);});
  await t.test('manual warning uses semantic changes; formatting alone is ignored',async()=>{
   await page.locator('[id="instance.auto_update"]').check();await page.evaluate(()=>$('#instance\\.auto_update').trigger('change'));
   assert.equal(await page.locator('#xray-manual-warning').isVisible(),false);
   await page.locator('#xray-format').click();assert.equal(await page.locator('#xray-manual-warning').isVisible(),false);
   await page.evaluate(()=>{const c=JSON.parse(dialogEditor.getValue());c.policy.levels['0'].handshake=8;dialogEditor.setValue(JSON.stringify(c));$('#instance\\.auto_update').trigger('change');});
   assert.equal(await page.locator('#xray-manual-warning').isVisible(),true);assert.match(await page.locator('#xray-manual-warning').textContent(),/изменения будут сброшены/);
   await page.locator('[id="instance.auto_update"]').uncheck();assert.equal(await page.locator('#xray-manual-warning').isVisible(),false);
  });
  await t.test('numeric spelling and PHP/JavaScript float formatting do not trigger warning',async()=>{
   await page.evaluate(()=>{const c=JSON.parse(dialogEditor.getValue());c.policy.levels['0'].handshake=0.000001;dialogEditor.setValue(JSON.stringify(c));document.getElementById('instance.subscription_baseline').value=JSON.stringify(c).replace('0.000001','1.0e-6');document.getElementById('instance.auto_update').checked=true;$('#instance\\.auto_update').trigger('change');});
   assert.equal(await page.locator('#xray-manual-warning').isVisible(),false);
  });
  await t.test('interval controls show 1–24 hours and missing-header reason',async()=>{
   await page.locator('[id="instance.auto_update"]').check();await page.locator('[id="instance.update_mode"]').selectOption('manual');assert.equal(await page.locator('[id="instance.update_hours"] option').count(),24);
   await page.evaluate(()=>{document.getElementById('instance.subscription_metadata').value='{}';});await page.locator('[id="instance.update_mode"]').selectOption('subscription');assert.match(await page.locator('#xray-update-reason').textContent(),/scheduled updates are disabled/);
  });
  await t.test('scheduled preview replaces manual edits and preserves local controls',async()=>{
   await page.evaluate(async uuid=>{await $.getJSON('/api/xray/instance/getItem/'+uuid);},id);
   const next={...fixture,remarks:'Scheduled'};
   preparedProfile={config_json:JSON.stringify(next),name:'Scheduled',subscription_fetched:'200',subscription_metadata:'{"interval_hours":1}',subscription_baseline:JSON.stringify(next),subscription_hash:await page.evaluate(value=>XrayEditor.hash(value),next)};
   await page.evaluate(()=>{dialogEditor.setValue('{"remarks":"Manual","outbounds":[{"protocol":"freedom"}]}');document.getElementById('instance.use_config_dns').checked=false;$('#instance\\.use_config_dns').trigger('change');});
   await page.locator('[id="instance.update_mode"]').selectOption('manual');await page.locator('[id="instance.update_hours"]').selectOption('3');
   await page.evaluate(()=>window.pollSubscription());await page.waitForFunction(()=>JSON.parse(dialogEditor.getValue()).remarks==='Scheduled');
   assert.equal(await page.locator('[id="instance.use_config_dns"]').isChecked(),false);assert.equal(await page.locator('[id="instance.update_hours"]').inputValue(),'3');assert.equal(await page.locator('#xray-manual-warning').isVisible(),false);
  });
  assert.deepEqual(errors,[]);
 }finally{await browser.close();await new Promise(r=>server.close(r));}
});
