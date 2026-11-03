import {readdir,readFile,mkdtemp,writeFile,rm} from 'node:fs/promises';
import {existsSync} from 'node:fs';
import {spawnSync} from 'node:child_process';
import {resolve,join} from 'node:path';
import {tmpdir} from 'node:os';
const php=process.env.PHP_BIN || (existsSync('.tools/php-8.4.13/sapi/cli/php')?resolve('.tools/php-8.4.13/sapi/cli/php'):'php');
async function files(dir){const result=[];for(const ent of await readdir(dir,{withFileTypes:true})){const path=dir+'/'+ent.name;if(ent.isDirectory())result.push(...await files(path));else result.push(path);}return result;}
let failed=false;
function run(binary,args,label){const result=spawnSync(binary,args,{encoding:'utf8'});if(result.status!==0){failed=true;console.error('FAIL '+label+'\n'+result.stdout+'\n'+(result.stderr||result.error));}return result;}
const paths=await files('plugin');
for(const path of (await files('tests')).filter(p=>p.endsWith('.php'))){run(php,['-l',path],path);}
let count=0;for(const path of paths.filter(p=>p.endsWith('.php'))){run(php,['-l',path],path);count++;}console.log('PHP syntax checked: '+count+' files');
run(php,['-r','foreach(array_slice($argv,1) as $p){if(simplexml_load_file($p)===false){exit(1);}}',...paths.filter(p=>p.endsWith('.xml'))],'XML parsing');
run('sh',['-n','install.sh'],'installer shell syntax');run('sh',['-n','plugin/etc/rc.syshook.d/start/50-xray'],'syshook shell syntax');
run(process.execPath,['--check','plugin/www/assets/xray/dialog.js'],'dialog JavaScript syntax');
const dir=await mkdtemp(join(tmpdir(),'os-xray-syntax-'));
try{const src=(await readFile('plugin/mvc/app/views/OPNsense/Xray/partials/scripts.volt','utf8')).replace(/{{[\s\S]*?}}/g,'LOCALIZED').replace(/<\/?script>/g,'');await writeFile(dir+'/script.js',src);run(process.execPath,['--check',dir+'/script.js'],'Volt embedded JavaScript syntax');}finally{await rm(dir,{recursive:true,force:true});}
const unit=run(php,['tests/config.php',...(process.env.XRAY_BIN?[process.env.XRAY_BIN]:[])],'configuration unit tests');if(unit.status===0)console.log(unit.stdout.trim());
const vless=run(php,['tests/vless.php'],'VLESS import tests');if(vless.status===0)console.log(vless.stdout.trim());
const health=run(php,['tests/health.php'],'heartbeat and destination policy tests');if(health.status===0)console.log(health.stdout.trim());
run(process.execPath,['--check','plugin/www/assets/xray/health.js'],'heartbeat UI syntax');
if(failed)process.exitCode=1;else console.log('PHP / shell / XML / JavaScript checks passed');
