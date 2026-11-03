import test from 'node:test';
import {execFileSync} from 'node:child_process';
test('Release packaging, corruption checks and no-overwrite publication contract',()=>{
 execFileSync('python3',['tests/release_test.py'],{encoding:'utf8',stdio:'pipe'});
});
