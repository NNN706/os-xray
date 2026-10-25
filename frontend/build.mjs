import {build} from 'esbuild';
import {readFile,writeFile} from 'node:fs/promises';
const result=await build({entryPoints:['frontend/editor.js'],bundle:true,format:'iife',globalName:'XrayEditor',minify:true,outfile:'plugin/www/assets/xray/editor.js',legalComments:'eof',metafile:true});
const packages=new Set(Object.keys(result.metafile.inputs).filter(p=>p.startsWith('node_modules/')).map(p=>p.split('/').slice(1,p.split('/')[1].startsWith('@')?3:2).join('/')));
let text='Third-party licenses for the locally bundled JSON editor.\n\n';
for(const name of [...packages].sort()){
 const pkg=JSON.parse(await readFile('node_modules/'+name+'/package.json','utf8'));let license;
 for(const file of ['LICENSE','LICENSE.txt','LICENSE.md','license']){try{license=await readFile('node_modules/'+name+'/'+file,'utf8');break;}catch(_){}}
 if(!license)throw new Error('Missing bundled dependency license: '+name);
 text+='===== '+name+' '+pkg.version+' =====\n'+license+'\n\n';
}
await writeFile('plugin/www/assets/xray/LICENSES.txt',text.trimEnd()+'\n');
