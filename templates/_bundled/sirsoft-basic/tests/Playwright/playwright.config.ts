/**
 * sirsoft-basic 템플릿 Playwright E2E 설정.
 *
 * 코어 `playwright.config.ts` 와 동일한 base URL 해석 우선순위를 따른다 — 활성 호스트가
 * 가변(개발자/CI/운영 환경별로 다른 도메인)이므로 하드코딩 회피.
 *
 * Base URL 해석:
 *   1. PLAYWRIGHT_BASE_URL 환경변수 (CI/명시적 오버라이드)
 *   2. .env (코어 루트) 의 APP_URL — 단 localhost 류는 fallback 부적합
 *   3. 그 외 — 명시 에러
 *
 * 뷰포트는 각 spec 이 `page.setViewportSize()` 로 직접 지정한다(모바일 390/320px ↔ 데스크톱
 * 1280px 을 한 파일에서 교차 검증하므로, 프로젝트를 뷰포트별로 나누면 같은 테스트가
 * 중복 실행된다).
 *
 * 실행 예시:
 *   PowerShell — $env:PLAYWRIGHT_BASE_URL='https://example.com'; npx playwright test --config templates/_bundled/sirsoft-basic/tests/Playwright/playwright.config.ts
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
const ARTIFACT_SLUG = 'templates/sirsoft-basic';

/**
 * .env 파일에서 키 하나를 읽습니다.
 *
 * @param filePath .env 파일 절대 경로
 * @param key 읽을 키 이름
 * @return 값 (없으면 null)
 */
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

/**
 * E2E base URL 을 해석합니다.
 *
 * @return base URL
 * @throws Error 활성 호스트를 확정할 수 없을 때
 */
function resolveBaseUrl(): string {
  if (process.env.PLAYWRIGHT_BASE_URL) {
    return process.env.PLAYWRIGHT_BASE_URL;
  }
  const appUrl = readEnvFile(resolve(CORE_ROOT, '.env'), 'APP_URL');
  if (appUrl && !/^https?:\/\/localhost(:\d+)?\/?$/i.test(appUrl)) {
    return appUrl;
  }
  throw new Error(
    'sirsoft-basic 템플릿 E2E base URL 미설정. PLAYWRIGHT_BASE_URL 환경변수를 지정하거나 코어 .env 의 APP_URL 을 활성 호스트로 설정하세요.'
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
