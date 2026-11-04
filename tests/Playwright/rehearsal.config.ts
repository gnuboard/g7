/**
 * Playwright 리허설 설치본 설정 (코어 E2E 보조).
 *
 * `tests/Playwright/specs/rehearsal/**` 는 개발 사이트가 아니라 **따로 띄운 설치본**을 잰다.
 *   - 설치되지 않은 사본의 웹 설치 마법사(설치 화면은 설치가 끝난 사이트에서 410 이다)
 *   - 하위 폴더(`/g7/`)에 운영 자산 방식으로 설치한 사본
 *
 * 루트 설정(`playwright.config.ts`)의 globalSetup 은 `php artisan playwright:seed-layout` 을
 * `G7_ROOT`(기본: 이 저장소)에서 돌려 개발 사이트에 시드 화면을 깐다. 리허설 spec 은 그 화면을 쓰지
 * 않고, 설치 전 사본에서는 artisan 이 돌지 않으며, 개발 사이트를 건드릴 이유도 없다 — 그래서 이
 * 설정은 루트 설정을 그대로 물려받되 globalSetup/globalTeardown 만 뺀다.
 *
 * 실행 예(PowerShell):
 *   $env:PLAYWRIGHT_BASE_URL='http://127.0.0.1:8731/g7/'
 *   npx playwright test --config=tests/Playwright/rehearsal.config.ts
 *
 * 각 spec 이 받는 환경변수와 건너뛰는 조건은 spec 머리말에 있다. 루트 설정으로 돌려도 이 spec 들은
 * 조건이 맞지 않으면 사유와 함께 건너뛴다.
 */
import { defineConfig } from '@playwright/test';
import base from '../../playwright.config';

export default defineConfig({
  ...base,
  testDir: './specs/rehearsal',
  globalSetup: undefined,
  globalTeardown: undefined,
  workers: 1,
  outputDir: '../../test-results/rehearsal',
  reporter: [
    ['html', { outputFolder: '../../playwright-report/rehearsal', open: 'never' }],
    ['list'],
  ],
  use: {
    ...base.use,
    // 루트 설정과 같은 값 — 한국어 문구 단언이 Accept-Language 에 흔들리지 않게 고정한다.
    locale: 'ko-KR',
  },
});
