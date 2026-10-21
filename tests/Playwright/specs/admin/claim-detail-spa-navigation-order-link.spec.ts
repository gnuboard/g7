/**
 * E2E: 클레임 상세 A → 목록 → 다른 클레임 B 로 SPA 이동하면 「주문 보기」 링크가 B 의 주문번호를 쓰는지 (7.1.1 결함)
 *
 * 배경: 엔진의 정적 경로 영구 캐시(키 = 경로 글자, 30초)가 데이터소스 루트도 담아,
 * 상세 A → 상세 B 로 SPA 이동하면 `href="/admin/ecommerce/orders/{{claim.data.claim.order_number}}"`
 * (admin_ecommerce_claim_detail.g7.html 「주문 보기」)가 A 의 주문번호로 남았다. route·query 갈래는
 * 1차 수정(engine-v1.82.1)이 막았고, 이 spec 은 데이터소스 경로 갈래(2차 수정)를 실제 화면으로 잠근다.
 *
 * **첫 진입만 `page.goto`** — 새로고침은 엔진 캐시를 비워 결함이 사라지므로, 이후 이동은 화면 안
 * 「목록」 버튼 클릭과 라우터 navigate 핸들러(`G7Core.dispatch`)로만 한다.
 *
 * 데이터: 클레임은 주문·결제·클레임 상태 기계를 거쳐야 생겨 시드 비용이 커서, 활성 설치본에 이미 있는
 * 클레임 중 주문번호가 서로 다른 두 건을 읽기만 한다(쓰기 없음). 두 건이 없으면 건너뛴다.
 *
 * @scenario surface=cache
 * @effects route_query_bindings_follow_current_screen
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';
import { request as pwRequest, type Page, type Response } from '@playwright/test';

/** 클레임 한 건의 식별자 */
type ClaimRef = { claimNumber: string; orderNumber: string };

/**
 * 활성 설치본에서 주문번호가 서로 다른 클레임 두 건을 고른다(읽기 전용).
 *
 * @param baseURL 기준 주소
 * @param token 관리자 토큰
 * @returns 두 건, 없으면 null
 */
async function pickTwoClaims(baseURL: string, token: string): Promise<[ClaimRef, ClaimRef] | null> {
  const api = await pwRequest.newContext({ baseURL, ignoreHTTPSErrors: true });
  const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' };
  const found: ClaimRef[] = [];
  try {
    for (const type of ['cancel', 'refund', 'return', 'exchange']) {
      const res = await api.get(`/api/modules/sirsoft-ecommerce/admin/claims?type=${type}&per_page=20`, { headers });
      if (!res.ok()) continue;
      for (const c of (await res.json())?.data?.data ?? []) {
        const orderNumber = c?.order_number ?? c?.order?.order_number;
        if (typeof c?.claim_number === 'string' && typeof orderNumber === 'string'
          && !found.some((f) => f.orderNumber === orderNumber)) {
          found.push({ claimNumber: c.claim_number, orderNumber });
        }
        if (found.length >= 2) return [found[0], found[1]];
      }
    }
  } finally {
    await api.dispose();
  }

  return null;
}

/**
 * 클레임 상세 API 응답 술어.
 *
 * @param claimNumber 클레임 번호
 * @returns 응답 술어
 */
function isClaimShow(claimNumber: string): (r: Response) => boolean {
  return (r) => r.request().method() === 'GET'
    && new URL(r.url()).pathname === `/api/modules/sirsoft-ecommerce/admin/claims/${claimNumber}`;
}

/**
 * 본문의 「원 주문 보기」 링크 — 사이드바 「주문 관리」(`/admin/ecommerce/orders`)는 끝에 `/` 가 없어 빠진다.
 * `data-ref` 는 DOM 속성으로 그려지지 않아 href 접두어로 찾는다.
 *
 * @param page 대상 페이지
 * @returns 로케이터
 */
function orderLink(page: Page) {
  return page.locator('a[href^="/admin/ecommerce/orders/"]').first();
}

/**
 * 「주문 보기」 링크의 주문번호를 읽는다.
 *
 * @param page 대상 페이지
 * @returns href 끝 주문번호(없으면 null)
 */
async function orderLinkNumber(page: Page): Promise<string | null> {
  const href = await orderLink(page).getAttribute('href');

  return href ? href.replace(/^.*\/admin\/ecommerce\/orders\//, '') : null;
}

test.describe('클레임 상세 SPA 이동 뒤 「주문 보기」 링크 (7.1.1)', () => {
  // @scenario surface=cache
  // @effects route_query_bindings_follow_current_screen
  test('상세 A → 목록 → 상세 B 로 가면 주문 보기 href 가 B 의 주문번호다', async ({ page }, testInfo) => {
    const token = issueToken('sirsoft-ecommerce.claims.read');
    const pair = await pickTwoClaims(testInfo.project.use.baseURL as string, token);
    test.skip(pair === null, '주문번호가 서로 다른 클레임 두 건이 활성 설치본에 없다');
    const [a, b] = pair!;
    await authenticatePage(page, token);

    // 첫 진입만 goto — 상세 A
    await Promise.all([
      page.waitForResponse(isClaimShow(a.claimNumber), { timeout: 30_000 }),
      page.goto(`/admin/ecommerce/claims/${a.claimNumber}`),
    ]);
    const link = orderLink(page);
    await expect(link).toBeAttached({ timeout: 20_000 });
    expect(await orderLinkNumber(page)).toBe(a.orderNumber);

    // 화면 안 「목록」 버튼으로 목록 (SPA)
    await page.getByRole('link', { name: /^(목록|List)$/ }).first().click();
    await page.waitForURL(/\/admin\/ecommerce\/claims(\?|$)/, { timeout: 30_000 });

    // 목록 → 상세 B (메뉴·행 클릭과 같은 라우터 navigate)
    const [show] = await Promise.all([
      page.waitForResponse(isClaimShow(b.claimNumber), { timeout: 30_000 }),
      page.evaluate((p) => (window as any).G7Core.dispatch({ handler: 'navigate', params: { path: p } }), `/admin/ecommerce/claims/${b.claimNumber}`),
    ]);
    expect(show.status()).toBe(200);
    expect((await show.json())?.data?.claim?.order_number).toBe(b.orderNumber);
    await expect(orderLink(page)).toBeAttached({ timeout: 20_000 });

    // 수정 전(데이터소스 갈래): A 의 주문번호로 남는다 — 다시 그려질 때까지 기다린다
    await expect.poll(() => orderLinkNumber(page), { timeout: 10_000 }).toBe(b.orderNumber);
  });
});
