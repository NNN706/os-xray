import importlib.util, json, os, subprocess, tempfile, unittest
from pathlib import Path
from unittest.mock import patch
ROOT=Path(__file__).resolve().parent.parent
spec=importlib.util.spec_from_file_location('bundle',ROOT/'scripts/release.py');bundle=importlib.util.module_from_spec(spec);spec.loader.exec_module(bundle)
class Packaging(unittest.TestCase):
    def setUp(self):self.tmp=tempfile.TemporaryDirectory();self.out=Path(self.tmp.name);bundle.pack(self.out)
    def tearDown(self):self.tmp.cleanup()
    def test_allowlist_and_manifest(self):
        j=json.loads((self.out/'manifest.json').read_text());self.assertEqual(j['version'],(ROOT/'VERSION').read_text().strip());self.assertEqual(len(j['commit']),40)
        self.assertIn('plugin/www/assets/xray/editor.js',j['files'])
        self.assertFalse(any(n.startswith(('tests/','scripts/','.git/','node_modules/','.tools/')) for n in j['files']))
        bundle.verify(self.out)
    def test_reproducible(self):
        before={p.name:p.read_bytes() for p in self.out.iterdir()};bundle.pack(self.out)
        self.assertEqual(before,{p.name:p.read_bytes() for p in self.out.iterdir()})
    def test_corrupt_archive_rejected(self):
        p=next(self.out.glob('*.tar.gz'));p.write_bytes(p.read_bytes()+b'corruption')
        with self.assertRaisesRegex(ValueError,'Checksum mismatch'):bundle.verify(self.out)
    def test_manifest_tampering_rejected(self):
        p=self.out/'manifest.json';p.write_text(p.read_text().replace('OPNsense','other'))
        with self.assertRaisesRegex(ValueError,'Checksum mismatch'):bundle.verify(self.out)
    def test_wrong_tag_rejected(self):
        with self.assertRaisesRegex(ValueError,'Tag must equal'):bundle.pack(self.out,'v999.0.0')
    def test_publication_refuses_asset_overwrite(self):
        manifest=json.loads((self.out/'manifest.json').read_text());manifest['source_dirty']=False
        # Exercise production publisher with an API adapter, not live GitHub writes.
        (self.out/'manifest.json').write_text(json.dumps(manifest))
        env={'GITHUB_REPOSITORY':'NNN706/os-xray','GITHUB_SHA':manifest['commit'],'RELEASE_TAG':manifest['tag'],'GH_TOKEN':'fixture-token'}
        with patch.dict(os.environ,env):
            spec=importlib.util.spec_from_file_location('publisher',ROOT/'scripts/publish-release.py');publisher=importlib.util.module_from_spec(spec);spec.loader.exec_module(publisher)
        release={'id':1,'draft':False,'assets':[{'name':manifest['archive'],'url':'https://api.github.com/fixture-asset'}]}
        def api(url,method='GET',body=None,**kwargs):
            self.assertEqual(method,'GET','existing assets must never be replaced')
            if '/commits/' in url:return json.dumps({'sha':manifest['commit']}).encode()
            if '/releases/tags/' in url:return json.dumps(release).encode()
            return b'different-existing-content'
        with patch.object(publisher.bundle,'verify'),patch.object(publisher,'request',side_effect=api),patch.object(Path,'read_text',side_effect=lambda *args,**kwargs:json.dumps(manifest)),patch.object(Path,'read_bytes',return_value=b'new-content'):
            with self.assertRaisesRegex(RuntimeError,'refusing to overwrite'):publisher.main()
    def test_failed_verification_prevents_any_publication(self):
        env={'GITHUB_REPOSITORY':'NNN706/os-xray','GITHUB_SHA':'0'*40,'RELEASE_TAG':'v4.0.0-rc.1','GH_TOKEN':'fixture-token'}
        with patch.dict(os.environ,env):
            spec=importlib.util.spec_from_file_location('publisher2',ROOT/'scripts/publish-release.py');publisher=importlib.util.module_from_spec(spec);spec.loader.exec_module(publisher)
        with patch.object(publisher.bundle,'verify',side_effect=ValueError('invalid bundle')),patch.object(publisher,'request') as api:
            with self.assertRaises(ValueError):publisher.main()
            api.assert_not_called()
if __name__=='__main__':unittest.main()
