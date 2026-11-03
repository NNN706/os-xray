import {existsSync} from 'node:fs';
import {resolve} from 'node:path';
import {execFileSync} from 'node:child_process';
const candidate=process.env.PHP_BIN || (existsSync('.tools/php-8.4.13/sapi/cli/php')?resolve('.tools/php-8.4.13/sapi/cli/php'):'php');
export const php=execFileSync(candidate,['-r','echo PHP_BINARY;'],{encoding:'utf8'}).trim();
