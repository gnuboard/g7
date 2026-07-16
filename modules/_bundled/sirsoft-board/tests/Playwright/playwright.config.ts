/**
 * 게시판 모듈 Playwright E2E 설정.
 *
 * 코어/이커머스 config 와 동일한 base URL 해석 우선순위. 모듈 디렉토리 기준 6단계 상위가 코어 루트.
 *
 * 실행 예시:
 *   PowerShell — $env:PLAYWRIGHT_BASE_URL='https://g7.dev'; npx playwright test --config modules/_bundled/sirsoft-board/tests/Playwright/playwright.config.ts
 *   Bash       — PLAYWRIGHT_BASE_URL=https://g7.dev npx playwright test --config modules/_bundled/sirsoft-board/tests/Playwright/playwright.config.ts
 */
import { defineConfig, devices } from '@playwright/test';
import { readFileSync, existsSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));

/**
 * 코어 루트 (artisan / .env / Playwright 산출물의 기준 경로).
 *
 * 확장 config 는 확장 디렉토리에서 실행되지만, 산출물을 그 안에 쓰면 Windows 에서
 * `{module|template}:update` 의 디렉토리 이동이 열린 핸들에 걸려 실패한다.
 * 산출물은 코어 루트 아래로 모아 update 경로와 분리한다 (.gitignore 가 이미 덮는 위치).
 */
const CORE_ROOT = process.env.G7_ROOT || resolve(__dirname, '../../../../../');

/** 확장별 산출물 격리 — 확장끼리 리포트를 덮어쓰지 않도록 slug 로 네임스페이스. */
const ARTIFACT_SLUG = 'modules/sirsoft-board';

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
  const appUrl = readEnvFile(resolve(CORE_ROOT, '.env'), 'APP_URL');
  if (appUrl && !/^https?:\/\/localhost(:\d+)?\/?$/i.test(appUrl)) {
    return appUrl;
  }
  throw new Error(
    '게시판 모듈 E2E base URL 미설정. PLAYWRIGHT_BASE_URL 환경변수를 지정하거나 코어 .env 의 APP_URL 을 활성 호스트로 설정하세요.'
  );
}

export default defineConfig({
  testDir: './specs',
  outputDir: resolve(CORE_ROOT, 'test-results', ARTIFACT_SLUG),
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: process.env.CI ? 1 : undefined,
  reporter: [
    ['html', { outputFolder: resolve(CORE_ROOT, 'playwright-report', ARTIFACT_SLUG), open: 'never' }],
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