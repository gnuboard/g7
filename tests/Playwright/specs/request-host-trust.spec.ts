/**
 * E2E: 요청 Host 비신뢰 — 사이트 주소로만 이동·새 창 열기 (배포 번들 + 실서버 기준)
 *
 * 배경: 화면 이동·결제 결과 이동·새 창 열기가 요청의 Host 헤더나 외부 주소를 그대로 따르면,
 * 사이트 주소의 하위 도메인·다른 포트로 들어온 요청이 이동 목적지를 정하거나 열린 외부 창이
 * 원래 화면(opener)을 조작할 수 있다. 서버는 사이트 주소가 아닌 Host 의 화면 요청을 사이트 주소로
 * 301 하고 결제 결과 이동을 사이트 주소로 만들며, 엔진의 「새 창 열기」 동작은 외부 주소를 https
 * 일 때만 opener 없이 연다.
 *
 * 단위·기능 테스트(CanonicalHostRedirectTest·각 PG HostTrustTest·ActionDispatcher.hostTrust)가 판정
 * 함수를 고정하고, 이 스펙은 실제 웹서버·배포 번들에서 관찰되는 결과(응답 Location·열린 창)를 잠근다.
 * 위조 Host 는 사이트 호스트에 다른 포트를 붙여 보낸다 — 같은 웹서버가 받으면서 앱에는 사이트
 * 주소가 아닌 Host 로 보이는 변종이다(하위 도메인은 웹서버 설정에 따라 도달하지 않을 수 있다).
 *
 * 시나리오 축·효과는 매니페스트 tests/scenarios/request-host-absolute-url.yaml 참조.
 * 각 test 의 `// @scenario` 라인 마커가 축 조합을, `// @effects` 가 효과를 커버한다.
 */
import { test, expect } from '../fixtures/auth';

const EXTERNAL_ORIGIN = 'https://e2e-external.invalid';

/**
 * 사이트 주소와 같은 호스트에 다른 포트를 붙인 위조 Host 값을 만든다.
 *
 * @param baseURL 설정의 사이트 주소
 * @returns `호스트:포트` 형식의 Host 헤더 값
 */
function forgedPortHost(baseURL: string): string {
  const url = new URL(baseURL);
  return `${url.hostname}:${url.port === '6666' ? '6667' : '6666'}`;
}

test.describe('요청 Host 비신뢰 — 사이트 주소로만 이동', () => {
  // @scenario host_header=forged, sink=canonical_redirect
  // @effects foreign_host_page_request_redirects_to_site
  test('사이트 주소가 아닌 Host 의 화면 요청은 같은 경로의 사이트 주소로 301 이동한다', async ({ request, baseURL }) => {
    const origin = new URL(baseURL!).origin;
    const response = await request.get('/shop/products?x=1', {
      headers: { host: forgedPortHost(baseURL!) },
      maxRedirects: 0,
    });

    expect(response.status()).toBe(301);
    expect(response.headers()['location']).toBe(`${origin}/shop/products?x=1`);
  });

  // @scenario host_header=canonical, sink=canonical_redirect
  // @effects canonical_host_passes_without_loop
  test('사이트 주소 Host 의 화면 요청은 이동 없이 응답한다', async ({ request }) => {
    const response = await request.get('/shop/products', { maxRedirects: 0 });

    expect(response.status()).toBe(200);
  });

  // @scenario host_header=forged, sink=canonical_redirect
  // @effects canonical_redirect_skipped_when_not_applicable
  test('결제 실패 복귀는 위조 Host 로 와도 301 이 아니라 사이트 주소의 결제 화면으로 이동한다', async ({ request, baseURL }) => {
    const origin = new URL(baseURL!).origin;
    const response = await request.get('/plugins/sirsoft-tosspayments/payment/fail?code=E2E704&orderId=E2E704-NONE', {
      headers: { host: forgedPortHost(baseURL!) },
      maxRedirects: 0,
    });

    // 토스페이먼츠가 비활성인 설치에서는 경로가 없다 — 이동 주소를 잴 대상이 없으므로 건너뛴다.
    test.skip(response.status() === 404, '토스페이먼츠 플러그인이 비활성인 설치');
    expect(response.status()).toBe(302);
    expect(response.headers()['location']).toBe(`${origin}/shop/checkout?error=E2E704&orderId=E2E704-NONE`);
  });
});

test.describe('엔진 「새 창 열기」 — 외부 창은 https 일 때만 opener 없이', () => {
  test.beforeEach(async ({ page, context }) => {
    await context.route(`${EXTERNAL_ORIGIN}/**`, (route) =>
      route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><title>external</title><p>external</p>' }),
    );
    await page.goto('/');
    await page.waitForFunction(() => typeof (window as any).G7Core?.dispatch === 'function', null, { timeout: 30_000 });
  });

  // @scenario host_header=forged, sink=front_open_window
  // @effects external_window_has_no_opener_and_https_only
  test('외부 https 주소는 새 창으로 열리고 opener 가 끊긴다', async ({ page }) => {
    const popupPromise = page.waitForEvent('popup');
    await page.evaluate((url) => (window as any).G7Core.dispatch({ handler: 'openWindow', target: url }), `${EXTERNAL_ORIGIN}/landing`);
    const popup = await popupPromise;
    // 빈 창을 먼저 열어 opener 를 끊은 뒤 주소를 넣는다 — 첫 문서는 about:blank 다.
    await popup.waitForURL(`${EXTERNAL_ORIGIN}/landing`);

    expect(popup.url()).toBe(`${EXTERNAL_ORIGIN}/landing`);
    expect(await popup.evaluate(() => window.opener === null)).toBe(true);
  });

  // @scenario host_header=forged, sink=front_open_window
  // @effects external_window_has_no_opener_and_https_only
  test('https 가 아닌 외부 주소·javascript:·// 주소는 창을 열지 않는다', async ({ page }) => {
    let popups = 0;
    page.on('popup', () => { popups += 1; });

    for (const target of ['http://e2e-external.invalid/landing', 'javascript:alert(1)', '//e2e-external.invalid/x']) {
      await page.evaluate((url) => (window as any).G7Core.dispatch({ handler: 'openWindow', target: url }), target);
    }
    await page.waitForTimeout(1500);

    expect(popups).toBe(0);
  });

  // @scenario host_header=canonical, sink=front_open_window
  // @effects same_origin_window_unchanged
  test('사이트 안 경로는 종전처럼 새 창으로 열린다', async ({ page, baseURL }) => {
    const origin = new URL(baseURL!).origin;
    const popupPromise = page.waitForEvent('popup');
    await page.evaluate(() => (window as any).G7Core.dispatch({ handler: 'openWindow', target: '/shop/products' }));
    const popup = await popupPromise;
    await popup.waitForLoadState('domcontentloaded');

    expect(popup.url()).toBe(`${origin}/shop/products`);
  });
});
