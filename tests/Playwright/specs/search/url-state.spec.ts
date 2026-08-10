/**
 * E2E: 통합검색 화면 상태의 주소 승계 (#519)
 *
 * 탭·게시판필터·정렬·페이지는 전역 상태가 아니라 URL 쿼리가 SSoT 여야 한다.
 * 전역 상태에만 두면 화면은 정상으로 보이고 콘솔 에러도 없지만, 주소가 바뀌지 않아
 *
 *   - 새로고침하면 검색어만 남고 나머지가 초기값으로 돌아가고,
 *   - 뒤로가기가 직전에 보던 탭·페이지로 되돌아가지 못하며,
 *   - 보고 있던 결과를 주소로 공유할 수 없다.
 *
 * 셋 다 브라우저의 주소·히스토리를 실제로 움직여야만 드러나므로 단위 테스트로는
 * 잡히지 않는다. 레이아웃 계약 단위 검사는
 * `templates/_bundled/sirsoft-basic/__tests__/layouts/search-url-state-contract.test.ts` 가 맡는다.
 *
 * @scenario case=search_state_round_trips_through_url
 *
 * @effects search_state_persists_in_url,
 *          search_state_survives_reload,
 *          search_state_restores_on_back,
 *          search_new_keyword_resets_state
 */
import type { Page } from '@playwright/test';
import { test, expect } from '../../fixtures/auth';

const KEYWORD = '테스트';
const SEARCH_PATH = '/search?q=' + encodeURIComponent(KEYWORD);

/**
 * 화면 진입 공통 대기.
 *
 * `networkidle` 은 폴링이 있는 화면에서 idle 이 되지 않으므로 쓰지 않는다.
 *
 * @param page 대상 페이지
 * @param path 이동할 경로
 * @returns void
 */
async function gotoAndSettle(page: Page, path: string): Promise<void> {
  await page.goto(path);
  await page.waitForLoadState('domcontentloaded');
  await acceptCookieConsent(page);
  await page.waitForTimeout(1200);
}

/**
 * 쿠키 동의 배너를 처리한다.
 *
 * 동의 전에는 사전 차단이 데이터 요청을 막아 검색 결과가 그려지지 않는다.
 * 배너가 없는 사이트에서는 아무 일도 하지 않는다.
 *
 * @param page 대상 페이지
 * @returns void
 */
async function acceptCookieConsent(page: Page): Promise<void> {
  const accept = page.getByRole('button', { name: /모두 동의|Accept all/i }).first();

  if (await accept.isVisible({ timeout: 3_000 }).catch(() => false)) {
    await accept.click();
    await page.waitForTimeout(500);
  }
}

/**
 * 게시글 탭 버튼을 반환합니다.
 *
 * @param page 대상 페이지
 * @returns 게시글 탭 로케이터
 */
function postsTab(page: Page) {
  return page.getByRole('button', { name: /게시글|Posts/i }).first();
}

/**
 * 현재 주소의 쿼리 파라미터를 읽습니다.
 *
 * @param page 대상 페이지
 * @returns 쿼리 파라미터 객체
 */
async function queryParams(page: Page): Promise<Record<string, string>> {
  const url = new URL(page.url());

  return Object.fromEntries(url.searchParams.entries());
}

test.describe('통합검색 화면 상태의 주소 승계', () => {
  // @scenario case=search_state_persists_in_url
  // @effects search_state_persists_in_url
  test('탭을 바꾸면 주소에 남고 검색어는 유지된다', async ({ page }) => {
    await gotoAndSettle(page, SEARCH_PATH);

    const tab = postsTab(page);

    // 탭이 없는 사이트(검색 결과 0건 등)에서는 이 축을 잴 수 없다.
    if (!(await tab.isVisible({ timeout: 5_000 }).catch(() => false))) {
      test.skip(true, '검색 탭이 화면에 없어 상태 승계를 측정할 수 없다');
    }

    await tab.click();
    await page.waitForTimeout(800);

    const params = await queryParams(page);

    expect(params.type, '탭 전환이 주소에 반영되지 않았다').toBe('posts');
    expect(params.q, '탭을 바꿨더니 검색어가 주소에서 사라졌다').toBe(KEYWORD);
    // 결과 집합이 달라지므로 이전 페이지·커서는 따라오면 안 된다.
    expect(params.page, '탭 전환인데 이전 페이지 번호가 남았다').toBeUndefined();
    expect(params.cursor, '탭 전환인데 이전 커서가 남았다').toBeUndefined();
  });

  // @scenario case=search_state_survives_reload
  // @effects search_state_survives_reload
  test('새로고침해도 보고 있던 탭이 유지된다', async ({ page }) => {
    await gotoAndSettle(page, SEARCH_PATH + '&type=posts');

    const before = await queryParams(page);
    expect(before.type, '사전 조건: 주소에 탭이 실려 있어야 한다').toBe('posts');

    await page.reload();
    await page.waitForLoadState('domcontentloaded');
    await page.waitForTimeout(1200);

    const after = await queryParams(page);

    expect(after.type, '새로고침 후 탭이 초기값으로 돌아갔다').toBe('posts');
    expect(after.q, '새로고침 후 검색어가 사라졌다').toBe(KEYWORD);

    // 주소만 남고 화면이 따라오지 않으면 의미가 없다 — 요청도 그 탭으로 나가야 한다.
    const request = await page
      .waitForRequest((r) => r.url().includes('/api/search?') && r.url().includes('type=posts'), {
        timeout: 15_000,
      })
      .catch(() => null);

    expect(request, '새로고침 후 검색 요청이 주소의 탭을 반영하지 않았다').not.toBeNull();
  });

  // @scenario case=search_state_restores_on_back
  // @effects search_state_restores_on_back
  test('뒤로가기가 직전에 보던 탭으로 되돌아간다', async ({ page }) => {
    await gotoAndSettle(page, SEARCH_PATH);

    const tab = postsTab(page);

    if (!(await tab.isVisible({ timeout: 5_000 }).catch(() => false))) {
      test.skip(true, '검색 탭이 화면에 없어 히스토리 왕복을 측정할 수 없다');
    }

    await tab.click();
    await page.waitForTimeout(800);
    expect((await queryParams(page)).type, '사전 조건: 탭 전환이 주소에 반영돼야 한다').toBe('posts');

    await page.goBack();
    await page.waitForTimeout(800);

    const params = await queryParams(page);

    // 탭 전환이 history 항목을 만들지 않으면 뒤로가기가 검색 화면 자체를 떠난다.
    expect(params.q, '뒤로가기가 검색 화면을 벗어났다').toBe(KEYWORD);
    expect(params.type, '뒤로가기 후에도 바뀐 탭이 그대로다').toBeUndefined();
  });

  // @scenario case=search_new_keyword_resets_state
  // @effects search_new_keyword_resets_state
  test('검색어를 새로 실행하면 이전 조건이 따라오지 않는다', async ({ page }) => {
    await gotoAndSettle(page, SEARCH_PATH + '&type=posts&sort=latest&page=2');

    const input = page.locator('input[name="q"]').first();

    if (!(await input.isVisible({ timeout: 5_000 }).catch(() => false))) {
      test.skip(true, '검색 입력란이 화면에 없어 재검색을 측정할 수 없다');
    }

    await input.fill('다른검색어');
    await input.press('Enter');
    await page.waitForTimeout(1200);

    const params = await queryParams(page);

    expect(params.q, '새 검색어가 주소에 반영되지 않았다').toBe('다른검색어');
    // 의도적 리셋이다 — 결과 집합 자체가 달라지므로 이전 조건을 물려받지 않는다.
    expect(params.type, '새 검색인데 이전 탭이 따라왔다').toBeUndefined();
    expect(params.sort, '새 검색인데 이전 정렬이 따라왔다').toBeUndefined();
    expect(params.page, '새 검색인데 이전 페이지가 따라왔다').toBeUndefined();
  });
});
