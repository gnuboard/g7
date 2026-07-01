/**
 * 게시판 환경설정 "본인인증 정책" 탭 — 출처 필터 정리 (이슈 #413 item 11-1).
 *
 * 결함: 이 탭은 게시판 정책 전용(데이터소스가 source_identifier=sirsoft-board 고정)인데
 * 출처(source_type) 필터 드롭다운이 남아 있어, "코어"/"플러그인" 등을 골라도 항상 빈 결과만
 * 나오는 모순이 있었다. 전 출처 정책 관리는 코어 환경설정(/admin/settings?tab=identity)이 담당.
 *
 * 수정: 게시판 탭에서 작동하지 않는 출처 필터 Select 와 항상 비어있는 purpose 배지 카드를 제거하고,
 * scope/search 필터만 남겼다.
 *
 * 단위(Vitest identityPolicySourceIdentifier) 는 레이아웃 JSON 구조(필터 제거, 데이터소스 param)를
 * 검증하므로, 브라우저 렌더 수준(출처 Select 부재, scope/search 동작)은 이 spec 이 담당.
 *
 * @scenario board-identity-policy-source-filter
 * @axes element=source_type_filter element=scope_filter element=search_filter element=purpose_badge_card
 * @effects source_type_filter_removed_from_dom,
 *          scope_filter_remains_functional,
 *          search_filter_remains_functional,
 *          empty_purpose_badge_card_removed,
 *          policy_list_renders_board_module_policies
 *
 * 활성화 절차: PlaywrightIssueToken 발급이 가능한 환경에서 test.describe.skip → test.describe.
 */
import { test, expect, authenticatePage } from '../../fixtures/board-auth';

const IDV_TAB_URL = '/admin/boards/settings?tab=identity_policies';

test.describe.skip('게시판 환경설정 본인인증 정책 — 출처 필터 정리 (#413-11-1)', () => {
  // @scenario element=source_type_filter
  // @effects source_type_filter_removed_from_dom
  test('출처(source_type) 필터 Select 가 화면에 없다', async ({ page, settingsToken }) => {
    await authenticatePage(page, settingsToken);
    await page.goto(IDV_TAB_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    // 제거된 출처 필터 Select 는 DOM 에 존재하지 않아야 함
    await expect(page.locator('[name="filter.source_type"]')).toHaveCount(0, {
      timeout: 5_000,
    });
  });

  // @scenario element=scope_filter, element=search_filter
  // @effects scope_filter_remains_functional, search_filter_remains_functional
  test('scope 필터와 search 입력은 그대로 유지된다', async ({ page, settingsToken }) => {
    await authenticatePage(page, settingsToken);
    await page.goto(IDV_TAB_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    await expect(page.locator('[name="filter.scope"]')).toHaveCount(1, { timeout: 5_000 });
    await expect(page.locator('[name="filter.search"]')).toHaveCount(1, { timeout: 5_000 });
  });

  // @scenario element=purpose_badge_card
  // @effects empty_purpose_badge_card_removed
  test('항상 비어있던 purpose 배지 카드가 제거되었다', async ({ page, settingsToken }) => {
    await authenticatePage(page, settingsToken);
    await page.goto(IDV_TAB_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    await expect(page.locator('#identity_purposes_card')).toHaveCount(0, { timeout: 5_000 });
  });

  // @scenario element=scope_filter
  // @effects policy_list_renders_board_module_policies
  test('정책 목록이 게시판 모듈 정책(출처=모듈)으로 렌더된다', async ({ page, settingsToken }) => {
    await authenticatePage(page, settingsToken);
    await page.goto(IDV_TAB_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    // sirsoft-board.* 정책 키가 목록에 노출되어야 함 (게시판 전용 뷰)
    await expect(
      page.getByText('sirsoft-board.post.user_create', { exact: false }).first(),
    ).toBeVisible({ timeout: 10_000 });
  });
});
