/**
 * Playwright 코어 E2E 설정 (G7 코어 영역)
 *
 * 위치 규약 (CLAUDE.md 코어/확장 분리 원칙):
 * - 코어 spec : tests/Playwright/specs/
 * - 모듈 spec : modules/_bundled/{id}/tests/Playwright/specs/ (모듈 자체 config)
 * - 플러그인 : plugins/_bundled/{id}/tests/Playwright/specs/ (플러그인 자체 config)
 * - 템플릿   : templates/_bundled/{id}/tests/Playwright/specs/ (템플릿 자체 config)
 *
 * Base URL 해석 우선순위 (하드코딩 회피 — 도메인/디렉토리 변경 무관):
 *   1. PLAYWRIGHT_BASE_URL  환경변수 (CI/명시적 오버라이드)
 *   2. .env 의 APP_URL      (단 'localhost' 류는 fallback 으로 부적합 — Apache vhost 미경유)
 *   3. 그 외 — 명시 에러
 *
 * `.env` 파일을 자체 파싱하지 않고 Node.js 환경변수만 사용한다.
 * PowerShell 호출 예: `$env:PLAYWRIGHT_BASE_URL='https://g7.dev'; npm run test:e2e`
 */
import { defineConfig, devices } from '@playwright/test';
import { readFileSync, existsSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

// ESM 환경(package.json "type": "module")에서는 __dirname 이 정의되지 않으므로
// import.meta.url 로 재구성한다.
const __dirname = dirname(fileURLToPath(import.meta.url));

/**
 * .env 파일에서 단일 키의 값을 추출한다 (간이 파서 — dotenv 의존 회피).
 * 파일 부재 / 키 부재 시 null 반환.
 */
function readEnvFile(filePath: string, key: string): string | null {
  if (!existsSync(filePath)) return null;
  const content = readFileSync(filePath, { encoding: 'utf-8' });
  const pattern = new RegExp(`^${key}=(.*)$`, 'm');
  const match = content.match(pattern);
  if (!match) return null;
  let value = match[1].trim();
  // dotenv 호환 — 양 끝 따옴표 제거
  if ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'"))) {
    value = value.slice(1, -1);
  }
  return value || null;
}

/**
 * E2E base URL 을 결정한다. 우선순위는 모듈 상단 주석 참조.
 */
function resolveBaseUrl(): string {
  if (process.env.PLAYWRIGHT_BASE_URL) {
    return process.env.PLAYWRIGHT_BASE_URL;
  }
  const envPath = resolve(__dirname, '.env');
  const appUrl = readEnvFile(envPath, 'APP_URL');
  if (appUrl && !/^https?:\/\/localhost(:\d+)?\/?$/i.test(appUrl)) {
    return appUrl;
  }
  throw new Error(
    'Playwright base URL 미설정. PLAYWRIGHT_BASE_URL 환경변수를 지정하거나 .env 의 APP_URL 을 활성 호스트로 설정하세요. ' +
      `(.env 의 APP_URL=${appUrl ?? '<없음>'})`
  );
}

export default defineConfig({
  testDir: './tests/Playwright/specs',
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
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
  ],
});
