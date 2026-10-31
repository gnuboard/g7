/**
 * 이커머스 관리자 E2E 공용 — 실행 환경변수로 받은 관리자 토큰, 엔진 번들 바꿔 끼우기, 쓰기 가로채기, 첫 수정 가능 항목 조회.
 *
 * 공유 개발 서버에서 도는 spec 이 계정·역할을 새로 만들지 않도록, 관리자 화면 spec 은 아래 환경변수로만 계정을 받는다
 * (basic 템플릿 `templates/_bundled/sirsoft-basic/tests/Playwright/fixtures/env-account.ts` 와 같은 규약 — docs/testing/e2e-testing.md 「실행 환경변수로 계정을 받는 spec」).
 *   - G7_E2E_TOKEN           일회용 관리자 Sanctum 토큰(출력하지 않는다). 없으면 spec 은 `test.skip(!ADMIN_TOKEN, …)` 으로 건너뛴다
 *   - G7_E2E_ENGINE_BUNDLE   주면 이 브라우저만 엔진 번들을 그 파일로 바꿔 끼운다(서버 파일 불변 — 수정 전 번들 대조용)
 *
 * 쓰기 가로채기는 GET·HEAD·OPTIONS 가 아닌 `/api/` 요청을 네트워크 단에서 abort 하고 기록한다(XHR·fetch 모두 — 서버 도달 0).
 */
import { readFileSync } from 'node:fs';
import type { APIRequestContext, Page } from '@playwright/test';

/** 관리자 토큰 — 실행 환경변수로만 받는다(출력하지 않는다) */
export const ADMIN_TOKEN = process.env.G7_E2E_TOKEN ?? '';

/** 토큰이 없을 때 남기는 스킵 사유 */
export const ADMIN_TOKEN_SKIP_REASON = '관리자 토큰 환경변수 없음 — G7_E2E_TOKEN 을 주고 실행한다';

/** 이커머스 관리자 API 접두 */
export const ECOMMERCE_ADMIN_API = '/api/modules/sirsoft-ecommerce/admin';

/** 가로챈 쓰기 요청 */
export interface CapturedWrite {
  method: string;
  path: string;
  body: string;
}

/**
 * 토큰을 심고, 엔진 번들을 바꿔 끼우고(환경변수가 있을 때), 쓰기 요청을 모두 abort 하며 기록한다.
 *
 * @param page Playwright 페이지
 * @returns 쓰기 기록과 바꿔 끼운 번들 응답 수
 */
export async function prepareAdminPage(page: Page): Promise<{ writes: CapturedWrite[]; served: { count: number } }> {
  await page.addInitScript((t) => {
    localStorage.setItem('auth_token', t);
  }, ADMIN_TOKEN);
  const served = { count: 0 };
  const bundle = process.env.G7_E2E_ENGINE_BUNDLE;
  if (bundle) {
    const source = readFileSync(bundle);
    await page.route('**/build/core/template-engine.min.js*', (route) => {
      served.count += 1;

      return route.fulfill({ status: 200, contentType: 'application/javascript', body: source });
    });
  }
  const writes: CapturedWrite[] = [];
  await page.route(
    (url) => url.pathname.startsWith('/api/'),
    (route) => {
      const req = route.request();
      if (req.method() === 'GET' || req.method() === 'HEAD' || req.method() === 'OPTIONS') return route.fallback();
      writes.push({ method: req.method(), path: new URL(req.url()).pathname, body: req.postData() ?? '' });

      return route.abort();
    },
  );
  await page.setViewportSize({ width: 1440, height: 900 });

  return { writes, served };
}

/**
 * 관리자 목록 API 의 첫 수정 가능 항목을 고른다(GET 만).
 *
 * @param request Playwright API 요청 컨텍스트
 * @param path 목록 경로(쿼리 포함)
 * @returns 항목(없으면 null)
 */
export async function firstEditable(request: APIRequestContext, path: string): Promise<Record<string, any> | null> {
  const res = await request.get(path, { headers: { Accept: 'application/json', Authorization: `Bearer ${ADMIN_TOKEN}` } });
  if (!res.ok()) return null;
  const json = await res.json();
  const rows: any[] = json?.data?.data ?? json?.data ?? [];

  return rows.find((r) => r?.id != null && r?.abilities?.can_update !== false) ?? null;
}
