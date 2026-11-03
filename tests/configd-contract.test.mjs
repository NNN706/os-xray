import test from 'node:test';
import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {php} from './helpers.mjs';

test('configd retains structured errors from validation actions', () => {
  const out = spawnSync(php, ['plugin/scripts/Xray/xray-api.php', 'validate', 'invalid-ticket'], {encoding:'utf8'});
  assert.equal(out.status, 0, out.stderr);
  assert.deepEqual(JSON.parse(out.stdout), {result:'failed', message:'Invalid request'});
});
