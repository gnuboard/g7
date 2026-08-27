/**
 * Playwright 코어 E2E 설정 (G7 코어 영역)
 *
 * 위치 규약 (코어/확장 분리 원칙):
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
  // 레이아웃 편집기 저장(PUT) spec 전용 시드 화면 설치/제거.
  // 저장 spec 이 제품 화면(home/admin_dashboard)을 대상으로 하면 실행마다 편집 결과가
  // 누적돼 개발 사이트가 오염된다 — 상세는 tests/Playwright/fixtures/seed-layout.ts.
  globalSetup: './tests/Playwright/global-setup.ts',
  globalTeardown: './tests/Playwright/global-teardown.ts',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: process.env.CI ? 1 : undefined,
  reporter: [
    ['html', { outputFolder: 'playwright-report', open: 'never' }],
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
    // spec 이 한국어 화면 문구를 단언하므로 로케일을 고정한다.
    // 로케일 우선순위는 localStorage g7_locale → 서버 응답값 → 'ko' 이고, 서버값은 미인증
    // 요청에서 Accept-Language 로 결정된다(SetLocale 미들웨어). Playwright 의 locale 옵션이
    // 그 헤더를 만들므로, 지정하지 않으면 첫 페이지 로드가 en-US 로 나가 화면이 영어로 렌더되고
    // 엔진이 그 값을 localStorage 에 저장해 이후 인증해도 세션 전체가 영어로 고정된다.
    locale: 'ko-KR',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    ignoreHTTPSErrors: true,
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
  ],
});
