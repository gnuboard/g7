/**
 * 비즈뿌리오 메시징 플러그인 Playwright E2E 설정 (#597).
 *
 * 코어 `playwright.config.ts` 와 동일한 base URL 해석 우선순위를 따른다 — 활성 호스트가
 * 환경별로 다르므로 하드코딩을 피한다.
 *
 * Base URL 해석:
 *   1. PLAYWRIGHT_BASE_URL 환경변수 (CI/명시적 오버라이드)
 *   2. .env (코어 루트) 의 APP_URL — 단 localhost 류는 fallback 부적합
 *   3. 그 외 — 명시 에러
 *
 * 실행 예시:
 *   PowerShell — $env:PLAYWRIGHT_BASE_URL='https://g7.dev'; npx playwright test -c plugins/_bundled/sirsoft-message_bizppurio/tests/Playwright/playwright.config.ts
 *   Bash       — PLAYWRIGHT_BASE_URL=https://g7.dev npx playwright test -c plugins/_bundled/sirsoft-message_bizppurio/tests/Playwright/playwright.config.ts
 */
import { defineConfig, devices } from '@playwright/test';
import { readFileSync, existsSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

// ESM 환경(package.json "type": "module")에서는 __dirname 이 정의되지 않으므로
// import.meta.url 로 재구성한다.
const __dirname = dirname(fileURLToPath(import.meta.url));

/**
 * 코어 루트 (artisan / .env / Playwright 산출물의 기준 경로).
 *
 * 산출물을 확장 디렉토리 안에 쓰면 Windows 에서 `plugin:update` 의 디렉토리 이동이
 * 열린 핸들에 걸려 실패하므로, 코어 루트 아래로 모아 update 경로와 분리한다.
 */
const CORE_ROOT = process.env.G7_ROOT || resolve(__dirname, '../../../../../');

/** 확장별 산출물 격리 — 확장끼리 리포트를 덮어쓰지 않도록 slug 로 네임스페이스. */
const ARTIFACT_SLUG = 'plugins/sirsoft-message_bizppurio';

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
    '비즈뿌리오 플러그인 E2E base URL 미설정. PLAYWRIGHT_BASE_URL 환경변수를 지정하거나 코어 .env 의 APP_URL 을 활성 호스트로 설정하세요.'
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
    // 실제 브라우저 UA 를 지정한다.
    //
    // Playwright 기본 UA 에는 `HeadlessChrome` 이 들어 있어 `SeoMiddleware` 의 봇 판정에
    // 걸린다. 그러면 공개 사용자 경로 요청이 SPA 가 아니라 **검색엔진용 정적 HTML** 을
    // 받는다 — `window.G7Core` 도 엔진 스크립트도 없는 화면이다. 그 상태에서도 서버가
    // 심은 글꼴·아이콘은 정상이라 "페이지가 잘 뜬다" 로 보이고, 정작 재려던 SPA 동작
    // (테마 적용·핸들러·확장 번들 로드)은 한 번도 실행되지 않은 채 통과한다.
    //
    // 봇 경로를 의도적으로 재는 spec 은 UA 가 아니라 `?_escaped_fragment_=` 로 유발하므로
    // 여기서 실제 UA 를 고정해도 그 검증은 그대로 동작한다.
    userAgent:
      'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36',
    baseURL: resolveBaseUrl(),
    // spec 이 한국어 화면 문구('비즈뿌리오'·'알림톡' 등)를 단언하므로 로케일을 고정한다.
    locale: 'ko-KR',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    ignoreHTTPSErrors: true,
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
