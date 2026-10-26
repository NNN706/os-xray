import test from 'node:test';
import assert from 'node:assert/strict';
import {chromium} from 'playwright';
import {readFile} from 'node:fs/promises';
test('Heartbeat UI shows bypass, recovery, stale state and timeout honestly',async()=>{
 const browser=await chromium.launch({headless:true});
 try {
  const page=await browser.newPage();
  await page.setContent('<div id="xray-health-panel"><div id="xray-health-summary"></div><div id="xray-health-components"></div><div id="xray-health-last"></div><pre id="xray-health-history"></pre></div>');
  await page.addScriptTag({content:await readFile('node_modules/jquery/dist/jquery.js','utf8')});
  await page.evaluate(()=>{window.healthResponse={result:'ok',enabled:true,interval:30,stale:false,state:{healthy:false,route:'bypass',successes:0,checked_at:100,components:{xray:true,tun2socks:true,tun:true},probe:{message:'Timeout (2000 ms)'},history:[{at:100,ok:false,http_code:0,latency_ms:null,timed_out:true}]}};$.getJSON=()=>$.Deferred().resolve(window.healthResponse).promise();window.setInterval=fn=>window.healthRefresh=fn;});
  await page.addScriptTag({content:await readFile('plugin/www/assets/xray/health.js','utf8')});
  await page.waitForFunction(()=>document.querySelector('#xray-health-summary').textContent.includes('WAN bypass'));
  assert.match(await page.locator('#xray-health-summary').innerText(),/Check failed.*WAN bypass.*30 s/);
  assert.match(await page.locator('#xray-health-history').innerText(),/timeout \(2000 ms\)/);
  assert.doesNotMatch(await page.locator('#xray-health-history').innerText(),/\| 0 ms/);
  await page.evaluate(()=>{Object.assign(window.healthResponse.state,{healthy:true,route:'proxy',successes:3,probe:{message:'OK (204)'}});window.healthRefresh();});
  assert.match(await page.locator('#xray-health-summary').innerText(),/Healthy.*Proxy via TUN/);
  await page.evaluate(()=>{window.healthResponse.stale=true;window.healthRefresh();});
  assert.match(await page.locator('#xray-health-summary').innerText(),/Stale heartbeat/);
  await page.evaluate(()=>{window.healthResponse.enabled=false;window.healthRefresh();});
  assert.equal(await page.locator('#xray-health-summary').innerText(),'Heartbeat Off');
 }finally{await browser.close();}
});
