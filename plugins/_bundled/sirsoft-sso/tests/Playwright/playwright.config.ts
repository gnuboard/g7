import {defineConfig} from '@playwright/test';
import {readFileSync} from 'node:fs';
import {resolve, dirname} from 'node:path';
import {fileURLToPath} from 'node:url';

// 모의 IdP 를 켠 채 실서버 한 대·DB 하나를 쓰는 종단 시험이다 — 정책·IdP 설정을 바꾸는 스펙이 있어 직렬(워커 1)로만 돈다.
// SAML 스펙은 콜백 주소에 밑줄 없는 호스트가 필요하다(onelogin 의 주소 검사) — 그런 사이트에서 PLAYWRIGHT_BASE_URL 로 돌린다.
const root=resolve(dirname(fileURLToPath(import.meta.url)), '../../../../..');
const appUrl=readFileSync(resolve(root,'.env'),'utf8').match(/^APP_URL=(.*)$/m)?.[1]?.trim().replace(/^["']|["']$/g,'');
export default defineConfig({
  testDir:'./specs', workers:1, fullyParallel:false, timeout:90000,
  outputDir:resolve(root,'test-results/plugins/sirsoft-sso'),
  reporter:[['list']],
  use:{baseURL:process.env.PLAYWRIGHT_BASE_URL||appUrl,ignoreHTTPSErrors:true,locale:'ko-KR',viewport:{width:1440,height:1000},
    userAgent:'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36',
    screenshot:'only-on-failure',trace:'retain-on-failure'},
});
