/**
 * E2E: 끝·연속 슬래시 주소의 정규화 — 서버 301 + 클라이언트 라우터 주소 교체 (배포 번들 + 실서버 기준, 공개#156)
 *
 * 배경: nginx 에서 `/admin/`·`/board//free` 처럼 끝·연속 슬래시가 붙은 주소는 화면은 떴지만 문서 응답이
 * HTTP 404 였고 검색봇은 빈 SPA 셸을 받았다. 서버는 이런 화면 요청을 정규형 주소로 301 하고(`canonical.path`),
 * 라우터는 SPA 안에서 슬래시 주소로 이동해도 주소창을 정규형으로 `replaceState` 한다(engine-v1.84.0).
 *
 * 판정 함수는 RequestPathTest·RedirectToCanonicalPathTest·Router.test.ts 가 고정하고, 이 스펙은 실제 웹서버와
 * 배포 번들에서 관찰되는 결과(응답 Location·최종 주소·히스토리 항목 수)를 잠근다.
 *
 * 시나리오 축·효과는 매니페스트 tests/scenarios/catchall-soft-404.yaml · client-router-routing.yaml 참조.
 * 각 test 의 `// @scenario` 라인 마커가 축 조합을, `// @effects` 가 효과를 커버한다.
 */
import { test, expect } from '../fixtures/auth';

test.describe('끝·연속 슬래시 주소 — 서버 301', () => {
  // @scenario path_shape=trailing_slash, template_type=user
  // @effects trailing_slash_path_redirects_301_to_canonical
  test('끝 슬래시 화면 요청은 정규형 주소로 301 이동하고 쿼리 순서를 지킨다', async ({ request, baseURL }) => {
    const origin = new URL(baseURL!).origin;

    const plain = await request.get('/board/free/', { maxRedirects: 0 });
    expect(plain.status()).toBe(301);
    expect(plain.headers()['location']).toBe(`${origin}/board/free`);

    const query = await request.get('/admin/users/?b=2&a=1', { maxRedirects: 0 });
    expect(query.status()).toBe(301);
    expect(query.headers()['location']).toBe(`${origin}/admin/users?b=2&a=1`);
  });

  // @scenario path_shape=double_slash, template_type=user
  // @effects double_slash_path_redirects_301_collapsed
  test('연속 슬래시 화면 요청은 슬래시를 하나로 줄인 주소로 301 이동한다', async ({ request, baseURL }) => {
    const origin = new URL(baseURL!).origin;
    const response = await request.get('/board//free', { maxRedirects: 0 });

    expect(response.status()).toBe(301);
    expect(response.headers()['location']).toBe(`${origin}/board/free`);
  });

  // @scenario path_shape=plain
  // @effects canonical_path_redirect_skipped_when_not_applicable
  test('정규형 주소·루트·API 는 301 하지 않는다', async ({ request }) => {
    expect((await request.get('/board/free', { maxRedirects: 0 })).status()).toBe(200);
    expect((await request.get('/', { maxRedirects: 0 })).status()).toBe(200);
    expect((await request.get('/api/system/asset-probe/', { maxRedirects: 0 })).status()).not.toBe(301);
  });
});

test.describe('끝·연속 슬래시 주소 — 클라이언트 라우터', () => {
  /**
   * 사용자 화면을 열고 라우터가 준비될 때까지 기다린다.
   *
   * @param page Playwright 페이지
   */
  async function openBoard(page: import('@playwright/test').Page): Promise<void> {
    await page.goto('/board/free');
    await page.waitForFunction(() => Boolean((window as any).__templateApp?.router));
    await page.waitForLoadState('networkidle');
  }

  // @scenario path_shape=trailing_slash, entry=navigate
  // @effects client_router_normalizes_address_with_replace_state
  test('슬래시 주소로 navigate 하면 히스토리는 한 항목만 늘고 주소는 정규형이며 쿼리·해시가 남는다', async ({ page }) => {
    await openBoard(page);
    const before = await page.evaluate(() => history.length);

    await page.evaluate(() => (window as any).__templateApp.router.navigate('/board/test/?page=1#top'));
    await page.waitForFunction(() => location.pathname === '/board/test');

    const state = await page.evaluate(() => ({ url: location.pathname + location.search + location.hash, length: history.length }));
    expect(state.url).toBe('/board/test?page=1#top');
    expect(state.length).toBe(before + 1);
  });

  // @scenario path_shape=double_slash, entry=popstate
  // @effects client_router_normalizes_address_with_replace_state
  test('뒤로/앞으로로 연속 슬래시 항목에 닿아도 주소를 정규형으로 바꾼다', async ({ page }) => {
    await openBoard(page);

    await page.evaluate(() => {
      history.pushState({}, '', '/board//test');
      dispatchEvent(new PopStateEvent('popstate'));
    });
    await page.waitForFunction(() => location.pathname === '/board/test');

    expect(await page.evaluate(() => location.pathname)).toBe('/board/test');
  });
});
