/**
 * Layout Editor — 상점 화면의 상태 미리보기 전환이 상점 주소 설정과 무관하게 표시된다.
 *
 * 상점 주소는 운영자 설정이다. 편집기는 라우트 path 의 `{{...}}` 표현식을 실제 값으로
 * 평가하므로(useEditorRoutes::resolveEditorRouteExpressions) `selectedRoute.path` 의 첫
 * 세그먼트가 사이트마다 다르다 — 기본 `/shop/products`, `route_path` 를 바꾸면
 * `/store/products`, `no_route` 를 켜면 `/products`.
 *
 * editor-spec 의 `states.groups[].scope.match` 가 `/shop/...` 리터럴이던 시절에는 기본값이
 * 아닌 상점에서 상태 그룹이 매칭되지 않아 캔버스 상태 토글(PageStateSwitcher)이 조용히
 * 사라졌다. 예외도 경고도 남지 않아 "이 화면은 원래 상태 전환이 없다" 로 오인된다.
 * 선택 세그먼트 토큰 `/*?`(engine-v1.58.0)로 세 경우를 모두 매칭한다.
 *
 * 단위 테스트(matchStateScope.test.ts)는 매칭 함수만 본다. 이 spec 은 편집기를 실제로
 * 열어 상태 토글이 화면에 나타나는지를 확인한다 — 스펙과 라우트 평가가 실제로 만나는
 * 지점은 브라우저에만 있다.
 *
 * 축 요약(마커 아님 — 평문): route_setting, surface. 요약을 시나리오 축 마커로 적으면 파서가
 * `=` 없는 토큰을 버려 빈 조합 `{}` 이 된다.
 *
 * 효과 요약(마커 아님 — 평문): editor_state_switcher_visible_on_default_shop_route, editor_state_switcher_visible_after_route_path_change.
 *
 * 활성화 절차: PLAYWRIGHT_BASE_URL 과 PlaywrightIssueToken 발급이 가능한 환경에서
 * `test.describe.skip` → `test.describe` 로 바꾼다. 상점 주소를 바꾸는 축은 이커머스
 * 모듈 설정(basic_info.route_path)을 변경해야 하므로, 설정 변경 API 가 열려 있는
 * 검수 환경에서만 두 번째 케이스가 의미를 갖는다.
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

const PRODUCTS_ROUTE = '/shop/products';

test.describe.skip('레이아웃 편집기 — 상점 화면 상태 전환 노출', () => {
  /** @effects editor_state_switcher_visible_on_default_shop_route */
  test('기본 상점 주소에서 상태 전환 토글이 보인다', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);

    await page.goto(
      `/admin/layout-editor/sirsoft-basic?route=${encodeURIComponent(PRODUCTS_ROUTE)}`,
    );
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await page.waitForSelector('[data-testid="g7le-preview-frame"]', { timeout: 30_000 });

    // 상태 그룹이 매칭되어야 토글이 마운트된다. 미매칭이면 이 요소 자체가 없다.
    const switcher = page.locator('[data-testid="g7le-state-switcher"]');
    await expect(switcher).toBeVisible({ timeout: 20_000 });
  });

  /** @effects editor_state_switcher_visible_after_route_path_change */
  test('상점 주소를 바꾼 사이트에서도 상태 전환 토글이 보인다', async ({ page }) => {
    // 이 케이스는 basic_info.route_path 가 기본값이 아닌 환경에서만 의미가 있다.
    // 검수 환경의 실제 설정값을 읽어 라우트를 만든다 — 기본값이면 위 케이스와 같아진다.
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);

    const routePath = process.env.G7_E2E_SHOP_ROUTE_PATH ?? 'store';
    await page.goto(
      `/admin/layout-editor/sirsoft-basic?route=${encodeURIComponent(`/${routePath}/products`)}`,
    );
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await page.waitForSelector('[data-testid="g7le-preview-frame"]', { timeout: 30_000 });

    const switcher = page.locator('[data-testid="g7le-state-switcher"]');
    await expect(switcher).toBeVisible({ timeout: 20_000 });
  });
});
