import {spawnSync} from 'node:child_process';
const errors=[];
function run(bin,args,label){const r=spawnSync(bin,args,{encoding:'utf8'});if(r.status!==0)errors.push(label+': '+(r.error?.message||r.stderr||r.stdout).trim());return r;}
if(Number(process.versions.node.split('.')[0])<22)errors.push('Node.js 22 or newer is required');
for(const [bin,args] of [['git',['--version']],['python3',['--version']],['openssl',['version']],['curl',['--version']]])run(bin,args,bin+' is required');
run(process.env.PHP_BIN||'php',['-r',`$missing=[];foreach(['json','SimpleXML','dom','filter'] as $e)if(!extension_loaded($e))$missing[]=$e;foreach(['proc_open','shell_exec','exec'] as $f)if(!is_callable($f))$missing[]=$f;if(PHP_VERSION_ID<80300)$missing[]='PHP >= 8.3';if($missing){fwrite(STDERR,implode(', ',$missing));exit(1);}`],'PHP CLI/modules/functions missing');
if(errors.length){console.error('Build prerequisites are missing:\n'+errors.map(x=>' - '+x).join('\n')+'\nSee docs/BUILD.md for Debian runner setup.');process.exit(1);}
console.log('Build prerequisites OK; browser dependencies are checked by the browser launch step.');
