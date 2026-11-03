import test from 'node:test';
import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {php} from './helpers.mjs';

test('configd retains structured errors from validation actions', () => {
  const out = spawnSync(php, ['plugin/scripts/Xray/xray-api.php', 'validate', 'invalid-ticket'], {encoding:'utf8'});
  assert.equal(out.status, 0, out.stderr);
  assert.deepEqual(JSON.parse(out.stdout), {result:'failed', message:'Invalid request'});
});

import {mkdtempSync, mkdirSync, readFileSync, writeFileSync, symlinkSync, rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join, resolve} from 'node:path';

test('configd reads a private ticket through a system directory alias but rejects a symlink ticket', () => {
  const root = mkdtempSync(join(tmpdir(), 'os-xray-ticket-'));
  try {
    const real = join(root,'real');
    mkdirSync(real);
    mkdirSync(join(real,'xray-api'),{mode:0o700});
    symlinkSync(real,join(root,'alias'));
    const source = readFileSync('plugin/scripts/Xray/xray-api.php','utf8')
      .replace("__DIR__ . '/xray-store.inc.php'", JSON.stringify(resolve('plugin/scripts/Xray/xray-store.inc.php')))
      .replace("'/var/tmp/xray-api'", JSON.stringify(join(root,'alias','xray-api')));
    const runner=join(root,'api.php');
    writeFileSync(runner,source);
    const ticket='a'.repeat(32), entry=join(real,'xray-api',ticket);
    const invoke=()=>JSON.parse(spawnSync(php,[runner,'validate',ticket],{encoding:'utf8'}).stdout);
    writeFileSync(entry,'not JSON',{mode:0o600});
    assert.equal(invoke().message,'Invalid JSON configuration');
    symlinkSync(runner,entry);
    assert.match(invoke().message,/unsafe permissions or ownership/);
  } finally {rmSync(root,{recursive:true,force:true});}
});
