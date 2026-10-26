#!/usr/bin/env python3
"""Publish checked assets without replacing an existing asset, including on reruns."""
import importlib.util, json, os, sys, urllib.request, urllib.error, urllib.parse
from pathlib import Path
spec=importlib.util.spec_from_file_location('bundle',Path(__file__).with_name('release.py'));bundle=importlib.util.module_from_spec(spec);spec.loader.exec_module(bundle)
repo=os.environ['GITHUB_REPOSITORY'];tag=os.environ['RELEASE_TAG'];commit=os.environ['GITHUB_SHA'];token=os.environ['GH_TOKEN']
class SafeRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,req,fp,code,msg,hdrs,newurl):
        if urllib.parse.urlparse(newurl).scheme != 'https':raise RuntimeError('Refusing insecure redirect')
        redirected=super().redirect_request(req,fp,code,msg,hdrs,newurl)
        if urllib.parse.urlparse(req.full_url).netloc != urllib.parse.urlparse(newurl).netloc:
            redirected.remove_header('Authorization')
        return redirected
opener=urllib.request.build_opener(SafeRedirect())
base='https://api.github.com/repos/'+repo
headers={'Authorization':'Bearer '+token,'Accept':'application/vnd.github+json','User-Agent':'os-xray-release'}
def request(url,method='GET',body=None,content_type='application/json',missing=False,accept='application/vnd.github+json'):
    data=(json.dumps(body).encode() if isinstance(body,dict) else body)
    req=urllib.request.Request(url,data=data,method=method,headers=headers|{'Accept':accept}|({'Content-Type':content_type} if data is not None else {}))
    try:
        with opener.open(req,timeout=60) as res:return res.read()
    except urllib.error.HTTPError as error:
        if missing and error.code==404:return None
        raise RuntimeError('GitHub API '+method+' returned HTTP '+str(error.code)) from None

def main():
    out=Path('dist');bundle.verify(out,tag)
    manifest=json.loads((out/'manifest.json').read_text())
    if manifest.get('source_dirty',True):raise RuntimeError('Refusing release from a dirty source tree')
    if manifest['commit']!=commit:raise RuntimeError('Artifact was not built from this workflow revision')
    resolved=json.loads(request(base+'/commits/'+urllib.parse.quote(tag,safe='')))
    if resolved['sha']!=commit:raise RuntimeError('Release tag moved after build')
    raw=request(base+'/releases/tags/'+urllib.parse.quote(tag,safe=''),missing=True)
    names=[manifest['archive'],'SHA256SUMS','manifest.json']
    if raw:
        release=json.loads(raw)
        existing={a['name']:a for a in release['assets']}
        if set(existing)-set(names):raise RuntimeError('Existing release contains unexpected assets; review manually')
        for name,asset in existing.items():
            # Draft assets need authenticated API access; redirect handler strips cross-host credentials.
            data=request(asset['url'],accept='application/octet-stream')
            if data!=(out/name).read_bytes():raise RuntimeError('Existing asset differs; refusing to overwrite '+name)
        if not release['draft']:
            if set(existing)!=set(names):raise RuntimeError('Published release is incomplete; refusing to mutate it')
            print('Existing release assets match exactly; no changes');return
    else:
        notes=(Path('docs')/'RELEASE_NOTES.md').read_text().replace('{{TAG}}',tag)
        release=json.loads(request(base+'/releases','POST',{'tag_name':tag,'target_commitish':commit,'name':'os-xray '+tag,'body':notes,'draft':True,'prerelease':'-' in tag,'make_latest':'false'}))
        existing={}
    for name in names:
        if name not in existing:
            request(release['upload_url'].split('{')[0]+'?name='+urllib.parse.quote(name,safe=''),'POST',(out/name).read_bytes(),'application/octet-stream')
    # Only expose the release after all three assets were uploaded successfully.
    request(base+'/releases/'+str(release['id']),'PATCH',{'draft':False,'prerelease':'-' in tag,'make_latest':'false'})
    print('Published '+repo+' '+tag)

if __name__=='__main__':
    try:main()
    except Exception as error:print('ERROR: '+str(error),file=sys.stderr);sys.exit(1)
