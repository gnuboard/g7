import {defineConfig} from '@playwright/test';
import {readFileSync} from 'node:fs';
import {resolve, dirname} from 'node:path';
import {fileURLToPath} from 'node:url';

const root=resolve(dirname(fileURLToPath(import.meta.url)), '../../../../..');
const appUrl=readFileSync(resolve(root,'.env'),'utf8').match(/^APP_URL=(.*)$/m)?.[1]?.trim().replace(/^["']|["']$/g,'');
export default defineConfig({
  testDir:'./specs', workers:1, fullyParallel:false, timeout:45000,
  outputDir:resolve(root,'test-results/plugins/sirsoft-koreaexim'),
  reporter:[['list']],
  use:{baseURL:process.env.PLAYWRIGHT_BASE_URL||appUrl,ignoreHTTPSErrors:true,locale:'ko-KR',viewport:{width:1440,height:1000},
    userAgent:'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36',
    screenshot:'only-on-failure',trace:'retain-on-failure'},
});
