/**
 * 회원 상세 「쇼핑 데이터」 탭의 목록 링크 (#524) — 눌러서 실제로 가는지.
 *
 * 왜 E2E 여야 하는가: 이 링크들은 원래 `href="{{식}}"` 으로 적혀 있었고 **전부 죽어 있었다**.
 * 렌더 시 그 prop 값이 `undefined` 가 되어 DOM 에 `href` 속성 자체가 실리지 않았는데,
 * 레이아웃 JSON 을 읽는 단위 테스트는 «식이 있다» 만 보고 green 이었다(2026-09-15 브라우저
 * 실측에서야 드러났다). 「눌렀을 때 주소가 바뀌는가」는 실브라우저로만 잴 수 있다.
 *
 * 이 spec 은 **코어 spec 디렉터리**에 둔다. `playwright.config.ts` 의 `testDir` 이
 * `./tests/Playwright/specs` 라 `modules/_bundled/**​/tests/Playwright/specs` 는 러너가 잡지
 * 못한다 — 확장에 두면 실행되지 않는 가드가 된다.
 *
 * 스크랩 탭(board)·알림함 탭(core)도 같은 모양을 쓰므로 이 spec 이 그 부류의 대표 가드다.
 *
 * @scenario surface=member_detail_tab, actor=admin
 * @effects member_detail_tab_links_navigate_in_browser
 */
import {
  test,
  expect,
  authenticatePage,
} from '../../../../modules/_bundled/sirsoft-ecommerce/tests/Playwright/fixtures/member-data-seed';
import { issueToken } from '../../fixtures/auth';

/** 탭 링크와 그 링크가 가야 할 목록 주소. */
const LINKS: ReadonlyArray<readonly [string, string]> = [
  ['user-wishlists-link', '/admin/ecommerce/wishlists'],
  ['user-carts-link', '/admin/ecommerce/carts'],
  ['user-addresses-link', '/admin/ecommerce/user-addresses'],
  ['user-inquiries-link', '/admin/ecommerce/inquiries'],
];

test.describe('회원 상세 쇼핑 데이터 탭 링크 (#524)', () => {
  // @scenario surface=member_detail_tab, actor=admin
  // @effects member_detail_tab_links_navigate_in_browser
  test('목록 링크 4개가 회원 uuid 를 싣고 이동한다', async ({ page, memberDataSeed }) => {
    const token = issueToken('core.users.read');
    const uuid = memberDataSeed.member_uuid;

    await authenticatePage(page, token);

    for (const [testId, path] of LINKS) {
      await page.goto(`/admin/users/${uuid}`);

      // 탭 버튼을 눌러야 본문이 붙는다 — 탭은 `inject_props`, 본문은 `append_child` 로 갈라져 있다.
      await page.locator('button.tab-btn-base', { hasText: '쇼핑 데이터' }).first().click();

      const link = page.locator(`[data-testid="${testId}"]`);
      await expect(link).toBeVisible({ timeout: 15_000 });
      await link.click();

      // 「눌렀는데 아무 일도 없다」 를 잡는 자리 — 주소가 실제로 바뀌어야 한다.
      await expect(page).toHaveURL(new RegExp(`${path}\\?.*user_uuid=${uuid}`), {
        timeout: 15_000,
      });
    }
  });
});
