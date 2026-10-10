/**
 * E2E: 본인인증 결과 브리지의 모바일 복귀 주소 — 같은 출처 경로만 따른다
 *
 * 팝업이 아닌 흐름(모바일)에서 브리지 화면은 인증 시작 전 sessionStorage 에 넣어 둔 복귀 주소
 * (`g7.identity.redirectStash.return_url`)로 이동하면서 결과(토큰 또는 오류 코드)를 쿼리로 붙인다.
 * 그 저장값이 다른 출처·`//`·역슬래시 주소면 결과가 사이트 밖으로 실려 나간다. 브리지 스크립트는
 * 사이트 주소의 절대 주소를 경로로 바꾸고, 그 밖의 주소는 버리고 사이트 첫 화면으로 이동한다.
 *
 * 기능 테스트(InicisHostTrustTest)는 브리지 HTML 에 판정 함수가 실리는지만 볼 수 있다 — 이 스펙은
 * 실제 브라우저에서 그 스크립트를 실행해 최종 이동 주소를 잰다.
 *
 * 시나리오 축·효과는 매니페스트 tests/scenarios/request-host-absolute-url.yaml 참조.
 */
import { test, expect } from '@playwright/test';

const BRIDGE_PATH = '/plugins/sirsoft-verification_kginicis/plugin/inicis/popup-bridge?identity_error=PROVIDER_ERROR';
const STASH_KEY = 'g7.identity.redirectStash';

/**
 * 같은 탭에 복귀 주소를 저장한 뒤 브리지를 열고, 브리지가 이동시킨 최종 주소를 돌려준다.
 *
 * @param page Playwright 페이지
 * @param returnUrl 저장해 둘 복귀 주소
 * @returns 이동이 끝난 주소
 */
async function landAfterBridge(page: import('@playwright/test').Page, returnUrl: string): Promise<URL> {
  await page.goto('/robots.txt');
  await page.evaluate(([key, value]) => sessionStorage.setItem(key, JSON.stringify({ return_url: value })), [STASH_KEY, returnUrl]);
  await page.goto(BRIDGE_PATH);
  await page.waitForURL((url) => !url.pathname.includes('/popup-bridge'), { timeout: 15_000 });

  return new URL(page.url());
}

test.describe('본인인증 브리지 모바일 복귀 주소', () => {
  // @scenario host_header=canonical, sink=bridge_page
  // @effects bridge_mobile_return_accepts_same_origin_path_only
  test('사이트 안 경로면 그 경로로 돌아가며 결과를 붙인다', async ({ page, baseURL }) => {
    const landed = await landAfterBridge(page, '/mypage/profile?tab=1');

    expect(landed.origin).toBe(new URL(baseURL!).origin);
    expect(landed.pathname + landed.search).toBe('/mypage/profile?tab=1&identity_error=PROVIDER_ERROR');
  });

  // @scenario host_header=canonical, sink=bridge_page
  // @effects bridge_mobile_return_accepts_same_origin_path_only
  test('사이트 주소의 절대 주소는 경로로 바꿔 따른다', async ({ page, baseURL }) => {
    const origin = new URL(baseURL!).origin;
    const landed = await landAfterBridge(page, `${origin}/mypage/orders`);

    expect(landed.origin).toBe(origin);
    expect(landed.pathname + landed.search).toBe('/mypage/orders?identity_error=PROVIDER_ERROR');
  });

  for (const hostile of ['//evil.example/steal', 'https://evil.example/steal', '/\\evil.example/steal', 'javascript:alert(1)']) {
    // @scenario host_header=forged, sink=bridge_page
    // @effects bridge_mobile_return_accepts_same_origin_path_only
    test(`다른 출처·우회 주소(${hostile})는 버리고 사이트 첫 화면으로 간다`, async ({ page, baseURL }) => {
      const landed = await landAfterBridge(page, hostile);

      expect(landed.origin).toBe(new URL(baseURL!).origin);
      expect(landed.pathname + landed.search).toBe('/?identity_error=PROVIDER_ERROR');
    });
  }
});
