#!/usr/bin/env python3
"""Deterministic, allowlisted installation bundle; no checkout caches or local config."""
import gzip, hashlib, io, json, os, re, subprocess, sys, tarfile, tempfile
from pathlib import Path, PurePosixPath
ROOT = Path(__file__).resolve().parent.parent
TOP_FILES = ['VERSION', 'install.sh', 'LICENSE', 'README.md', 'README.ru.md']
TREE_ROOTS = ['plugin', 'docs']
REQUIRED = TOP_FILES + ['plugin/www/assets/xray/editor.js', 'plugin/www/assets/xray/editor.css', 'plugin/www/assets/xray/LICENSES.txt', 'plugin/scripts/Xray/xray-service-control.php', 'plugin/scripts/Xray/xray-heartbeat.php', 'plugin/service/conf/actions.d/actions_xray.conf', 'plugin/mvc/app/controllers/OPNsense/Xray/forms/instance.xml', 'plugin/mvc/app/models/OPNsense/Xray/Instance.xml', 'plugin/etc/rc.syshook.d/start/50-xray']
def sha(data): return hashlib.sha256(data).hexdigest()
def version():
    value = (ROOT/'VERSION').read_text().strip()
    if not re.fullmatch(r'(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-(?:rc|beta|alpha)\.[1-9]\d*)?', value): raise ValueError('Invalid VERSION')
    return value

def pack(out, tag=None):
    v = version()
    if tag and tag != 'v'+v: raise ValueError('Tag must equal v'+v)
    for f in ['package.json', 'package-lock.json']:
        if json.loads((ROOT/f).read_text())['version'] != v: raise ValueError('VERSION differs from '+f)
    dirty = subprocess.run(['git','diff','--quiet','HEAD'],cwd=ROOT).returncode != 0
    if tag and dirty: raise ValueError('Tagged release requires a clean source tree')
    commit = subprocess.check_output(['git','rev-parse','HEAD'],cwd=ROOT,text=True).strip()
    epoch = int(subprocess.check_output(['git','show','-s','--format=%ct','HEAD'],cwd=ROOT,text=True))
    # Only tracked allowlisted files, including regenerated editor assets. Never glob a workspace.
    names = subprocess.check_output(['git','ls-files','-z'],cwd=ROOT).decode().split('\0')
    names = sorted(n for n in names if n and (n in TOP_FILES or any(n.startswith(d+'/') for d in TREE_ROOTS)))
    for required in REQUIRED:
        if required not in names: raise ValueError('Required release file not tracked: '+required)
    contents={}
    for name in names:
        path=ROOT/name
        if path.is_symlink() or not path.is_file(): raise ValueError('Not a regular release file: '+name)
        data=path.read_bytes()
        if not data: raise ValueError('Empty release file: '+name)
        contents[name]=data
    out.mkdir(parents=True,exist_ok=True)
    archive=out/('os-xray-v'+v+'.tar.gz')
    prefix='os-xray-v'+v
    with archive.open('wb') as fd, gzip.GzipFile(filename='',fileobj=fd,mode='wb',mtime=0) as gz, tarfile.open(fileobj=gz,mode='w') as tar:
        for name,data in contents.items():
            item=tarfile.TarInfo(prefix+'/'+name);item.size=len(data);item.mtime=epoch;item.mode=0o755 if name=='install.sh' else 0o644
            item.uid=item.gid=0;item.uname=item.gname='root'
            tar.addfile(item,io.BytesIO(data))
    manifest={'source_dirty':dirty,'version':v,'tag':'v'+v,'commit':commit,'archive':archive.name,'archive_sha256':sha(archive.read_bytes()),'platform_validation':'OPNsense/FreeBSD integration pending','files':{name:sha(data) for name,data in contents.items()}}
    (out/'manifest.json').write_text(json.dumps(manifest,indent=2,sort_keys=True)+'\n')
    (out/'SHA256SUMS').write_text(''.join(sha((out/n).read_bytes())+'  '+n+'\n' for n in [archive.name,'manifest.json']))
    verify(out,tag)
    print('Built '+str(archive))

def verify(out,tag=None):
    manifest=json.loads((out/'manifest.json').read_text())
    v=manifest['version']
    if not re.fullmatch(r'\d+\.\d+\.\d+(?:-(?:rc|beta|alpha)\.[1-9]\d*)?',v): raise ValueError('Invalid manifest version')
    archive='os-xray-v'+v+'.tar.gz'
    if manifest['archive']!=archive or manifest['tag']!='v'+v or (tag and tag!='v'+v): raise ValueError('Manifest/tag mismatch')
    if not re.fullmatch('[a-f0-9]{40}',manifest['commit']):raise ValueError('Invalid source commit')
    sums={}
    for line in (out/'SHA256SUMS').read_text().splitlines():
        m=re.fullmatch(r'([a-f0-9]{64})  ([A-Za-z0-9_.-]+)',line)
        if not m or m[2] in sums:raise ValueError('Invalid checksum list')
        sums[m[2]]=m[1]
    if set(sums)!={archive,'manifest.json'}:raise ValueError('Unexpected checksum entries')
    for name,digest in sums.items():
        if sha((out/name).read_bytes())!=digest:raise ValueError('Checksum mismatch: '+name)
    if manifest['archive_sha256']!=sums[archive]:raise ValueError('Archive digest mismatch')
    found={};prefix='os-xray-v'+v+'/'
    # Validate every member before extracting anything; never run the installer on a build host.
    with tarfile.open(out/archive,'r:gz') as tar:
        for item in tar.getmembers():
            if not item.isfile() or not item.name.startswith(prefix):raise ValueError('Unsafe archive member')
            name=item.name[len(prefix):];path=PurePosixPath(name)
            if path.is_absolute() or '..' in path.parts or name in found or not (name in TOP_FILES or any(name.startswith(d+'/') for d in TREE_ROOTS)):raise ValueError('Unexpected archive path: '+name)
            if item.mode not in [0o644,0o755] or item.size>16*1024*1024:raise ValueError('Invalid member mode/size')
            found[name]=tar.extractfile(item).read()
    if any(n not in found or not found[n] for n in REQUIRED):raise ValueError('Incomplete installation bundle')
    if {n:sha(data) for n,data in found.items()}!=manifest['files']:raise ValueError('File manifest mismatch')
    if found['VERSION'].decode().strip()!=v:raise ValueError('Bundled VERSION mismatch')
    with tempfile.TemporaryDirectory(prefix='os-xray-release-check-') as directory:
        for name,data in found.items():
            path=Path(directory)/name;path.parent.mkdir(parents=True,exist_ok=True);path.write_bytes(data)
        subprocess.run(['sh','-n',str(Path(directory)/'install.sh')],check=True)
        for directory_name in ['scripts/Xray','service/conf/actions.d','mvc/app/models/OPNsense/Xray','mvc/app/controllers/OPNsense/Xray','mvc/app/views/OPNsense/Xray','etc/inc/plugins.inc.d','etc/rc.syshook.d/start','etc/newsyslog.conf.d','www/assets/xray']:
            if not (Path(directory)/'plugin'/directory_name).is_dir():raise ValueError('Installer tree missing: '+directory_name)
    print('Verified archive, manifest, checksums and clean extraction')

if __name__=='__main__':
    import argparse
    parser=argparse.ArgumentParser();parser.add_argument('action',choices=['pack','verify']);parser.add_argument('--out',type=Path,default=ROOT/'dist');parser.add_argument('--tag');args=parser.parse_args()
    try:(pack if args.action=='pack' else verify)(args.out,args.tag)
    except Exception as error:print('ERROR: '+str(error),file=sys.stderr);sys.exit(1)
