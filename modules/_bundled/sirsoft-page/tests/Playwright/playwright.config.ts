/**
 * 페이지 모듈 Playwright E2E 설정.
 *
 * 코어 `playwright.config.ts` 및 이커머스 모듈 설정과 동일한 base URL 해석 우선순위를 따른다 —
 * 모듈도 활성 호스트가 가변(개발자/CI/PO 환경별로 다른 도메인)이므로 하드코딩 회피.
 *
 * Base URL 해석:
 *   1. PLAYWRIGHT_BASE_URL 환경변수 (CI/명시적 오버라이드)
 *   2. .env (코어 루트) 의 APP_URL — 단 localhost 류는 fallback 부적합
 *   3. 그 외 — 명시 에러
 *
 * 실행 예시:
 *   PowerShell — $env:PLAYWRIGHT_BASE_URL='https://g7.dev'; npm run test:e2e
 *   Bash       — PLAYWRIGHT_BASE_URL=https://g7.dev npm run test:e2e
 *
 * 모듈은 코어 fixture 의 `issueToken` / `authenticatePage` 헬퍼를 재사용 — 권한 식별자는
 * `sirsoft-page.*` 등 임의 string. 시드는 모듈 자체 artisan 커맨드(playwright:seed-page) 호출.
 */
import { defineConfig, devices } from '@playwright/test';
import { readFileSync, existsSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

// ESM 환경(package.json "type": "module")에서는 __dirname 이 정의되지 않으므로
// import.meta.url 로 재구성한다.
const __dirname = dirname(fileURLToPath(import.meta.url));

function readEnvFile(filePath: string, key: string): string | null {
  if (!existsSync(filePath)) return null;
  const content = readFileSync(filePath, { encoding: 'utf-8' });
  const pattern = new RegExp(`^${key}=(.*)$`, 'm');
  const match = content.match(pattern);
  if (!match) return null;
  let value = match[1].trim();
  if ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'"))) {
    value = value.slice(1, -1);
  }
  return value || null;
}

function resolveBaseUrl(): string {
  if (process.env.PLAYWRIGHT_BASE_URL) {
    return process.env.PLAYWRIGHT_BASE_URL;
  }
  // 모듈 디렉토리 기준 5단계 상위 = 코어 루트 (.env 위치)
  // (modules/_bundled/sirsoft-page/tests/Playwright → 코어 루트)
  const coreRoot = process.env.G7_ROOT || resolve(__dirname, '../../../../../');
  const appUrl = readEnvFile(resolve(coreRoot, '.env'), 'APP_URL');
  if (appUrl && !/^https?:\/\/localhost(:\d+)?\/?$/i.test(appUrl)) {
    return appUrl;
  }
  throw new Error(
    '페이지 모듈 E2E base URL 미설정. PLAYWRIGHT_BASE_URL 환경변수를 지정하거나 코어 .env 의 APP_URL 을 활성 호스트로 설정하세요.'
  );
}

export default defineConfig({
  testDir: './specs',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: process.env.CI ? 1 : undefined,
  reporter: [
    ['html', { outputFolder: 'playwright-report', open: 'never' }],
    ['list'],
  ],
  use: {
    baseURL: resolveBaseUrl(),
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    ignoreHTTPSErrors: true,
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
