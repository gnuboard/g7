/**
 * sirsoft-basic 템플릿 E2E — 실행 환경변수로 받은 계정의 토큰과 쓰기 가로채기.
 *
 * 공유 개발 서버에서 도는 spec 이 계정·역할을 새로 만들지 않도록, 로그인이 필요한 spec 은 아래 환경변수로만 계정을 받는다.
 *   - G7_E2E_TOKEN                 이미 받은 Sanctum 토큰(가장 먼저 쓴다)
 *   - G7_E2E_ADMIN_EMAIL           관리자 계정 이메일 — 사용자 화면(마이페이지)에도 그대로 로그인된다
 *   - G7_E2E_ADMIN_PASSWORD_FILE   그 계정의 비밀번호를 담은 파일 경로(비밀번호를 명령줄·로그에 남기지 않는다)
 * 어느 것도 없으면 `envAccountToken` 은 null 을 돌려주고, spec 은 `test.skip(!hasEnvAccount(), ACCOUNT_SKIP_REASON)` 으로
 * 사유를 남기고 건너뛴다(조건부 스킵 — 계정이 주어지면 그대로 돈다). 토큰·비밀번호는 출력하지 않는다.
 *
 * 쓰기 가로채기(`guardWrites`)는 GET·HEAD 가 아닌 `/api/` 요청을 서버에 보내지 않고 가짜 200 으로 답한다 — 네트워크 단에서
 * 가로채므로 XHR·fetch 를 함께 막는다(browser-verification.md `mem:in-page-write-interception-must-cover-xhr-and-fetch`).
 * 화면별 모의 응답은 이 함수를 먼저 건 뒤에 등록한다 — Playwright 는 나중에 건 route 를 먼저 탄다.
 */
import { readFileSync } from 'node:fs';
import type { APIRequestContext, Page } from '@playwright/test';

/** 계정이 없을 때 남기는 스킵 사유 — 필요한 환경변수 이름만 적는다. */
export const ACCOUNT_SKIP_REASON =
  '로그인 계정 환경변수 없음 — G7_E2E_TOKEN 또는 G7_E2E_ADMIN_EMAIL + G7_E2E_ADMIN_PASSWORD_FILE 을 주고 실행한다';

/**
 * 실행 환경변수로 계정이 주어졌는가.
 *
 * @returns 토큰 또는 관리자 이메일·비밀번호 파일 짝이 있으면 true
 */
export function hasEnvAccount(): boolean {
  if (process.env.G7_E2E_TOKEN) return true;

  return !!(process.env.G7_E2E_ADMIN_EMAIL && process.env.G7_E2E_ADMIN_PASSWORD_FILE);
}

/**
 * 환경변수로 받은 계정의 토큰을 얻는다.
 *
 * @param request Playwright API 요청 컨텍스트(baseURL 사용)
 * @returns Sanctum 토큰 문자열(계정 환경변수가 없으면 null)
 * @throws Error 로그인이 200 이 아닐 때(상태 코드만 적는다)
 */
export async function envAccountToken(request: APIRequestContext): Promise<string | null> {
  if (process.env.G7_E2E_TOKEN) return process.env.G7_E2E_TOKEN;

  const email = process.env.G7_E2E_ADMIN_EMAIL;
  const passwordFile = process.env.G7_E2E_ADMIN_PASSWORD_FILE;
  if (!email || !passwordFile) return null;

  const password = readFileSync(passwordFile, 'utf-8').trim();
  const res = await request.post('/api/auth/admin/login', {
    data: { email, password },
    headers: { Accept: 'application/json' },
  });
  if (res.status() !== 200) {
    throw new Error(`환경변수 계정 로그인 실패: ${res.status()}`);
  }
  const body = await res.json();

  return body.data.token as string;
}

/**
 * 브라우저 세션에 토큰을 심는다(`page.goto` 전에 부른다 — 모든 이동마다 다시 심긴다).
 *
 * @param page Playwright 페이지
 * @param token Sanctum 토큰
 */
export async function authenticateWith(page: Page, token: string): Promise<void> {
  await page.addInitScript((t) => {
    localStorage.setItem('auth_token', t);
  }, token);
}

/**
 * GET·HEAD 가 아닌 `/api/` 요청을 서버에 보내지 않고 가짜 200 으로 답한다.
 *
 * @param page Playwright 페이지
 * @returns 가로챈 요청 기록(`METHOD 경로`)
 */
export async function guardWrites(page: Page): Promise<string[]> {
  const blocked: string[] = [];
  // 수정 전 대조: G7_E2E_ENGINE_BUNDLE 에 다른 엔진 번들 파일 경로를 주면 이 브라우저에서만 그 번들로 바꿔 끼운다
  // (예: `git show HEAD:public/build/core/template-engine.min.js` 로 뽑은 수정 전 번들 — 서버 파일은 그대로).
  const bundle = process.env.G7_E2E_ENGINE_BUNDLE;
  if (bundle) {
    const source = readFileSync(bundle);
    await page.route('**/build/core/template-engine.min.js*', (route) =>
      route.fulfill({ status: 200, contentType: 'application/javascript', body: source }),
    );
  }
  await page.route(
    (url) => url.pathname.startsWith('/api/'),
    (route) => {
      const req = route.request();
      if (req.method() === 'GET' || req.method() === 'HEAD') return route.fallback();
      blocked.push(`${req.method()} ${new URL(req.url()).pathname}`);

      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: true, message: 'e2e-intercepted', data: {} }),
      });
    },
  );

  return blocked;
}

/**
 * 쿠키 동의 배너가 떠 있으면 닫는다(배너가 하단 단추·링크를 가린다).
 *
 * @param page Playwright 페이지
 */
export async function dismissCookieBanner(page: Page): Promise<void> {
  const accept = page.getByRole('button', { name: /모두 동의|모두 허용|Accept all/ }).first();
  try {
    await accept.waitFor({ state: 'visible', timeout: 3000 });
  } catch {
    return;
  }
  await accept.click();
}

/**
 * 404 오류 화면이 떠 있는지 — 오류 레이아웃의 큰 「404」 표시로 판정한다(로케일 무관, claim-apply-order-missing 과 같은 기준).
 *
 * @param page Playwright 페이지
 * @returns 404 표시 locator
 */
export function errorPage404(page: Page) {
  return page.locator('div.text-8xl', { hasText: '404' });
}

/**
 * 엔진 부팅(SPA)을 기다린다 — 봇 화면이 아니라 SPA 를 재는지 함께 확인한다.
 *
 * @param page Playwright 페이지
 */
export async function waitForSpa(page: Page): Promise<void> {
  await page.waitForFunction(() => typeof (window as any).G7Core?.dispatch === 'function', null, { timeout: 30_000 });
}

/**
 * 엔진의 navigate 액션으로 SPA 이동한다(머리글·마이페이지 메뉴 클릭과 같은 경로).
 *
 * @param page Playwright 페이지
 * @param path 이동할 경로
 */
export async function spaNavigate(page: Page, path: string): Promise<void> {
  await page.evaluate((p) => {
    void (window as any).G7Core.dispatch({ handler: 'navigate', params: { path: p } });
  }, path);
}
