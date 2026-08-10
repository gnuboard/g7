/**
 * 관리자 페이지 목록 컨텍스트 왕복 — 목록 상태가 상세·폼을 거쳐도 살아남는지 (공개 이슈 #75).
 *
 * 결함: 목록 → 상세 → 목록, 목록 → 등록/수정 폼 → 취소 경로에 `mergeQuery` 가 빠져 있어 표시 개수·
 *   필터 같은 목록 상태가 이동 한 번에 사라졌다. 취소 버튼은 목적지가 삼항 표현식이라 정적 검사에서도
 *   오래 빠져 있던 자리다.
 *
 * 표시 개수(`per_page`)를 표식으로 쓴다 — 시드 건수와 무관하게 항상 URL 에 실리는 목록 상태다.
 *
 * 커버 범위: 이 spec 은 **복귀 leg**(상세 → 목록, 폼 취소 → 목록)를 브라우저 수준에서 실측한다.
 *   진입 leg(목록 행 → 상세)는 행 끝 ⋯ 메뉴가 포털로 렌더되어 로케이터가 불안정하므로,
 *   `resources/js/__tests__/layouts/admin-list-context-round-trip.test.ts` 가 실 JSON 의 액션 params 로
 *   고정하고 audit 룰이 재발을 차단한다. 복귀 leg 는 진입으로 만들어진 URL 상태를 그대로 재현해 검증한다.
 *
 * @scenario page-admin-list-context-round-trip
 * @axes leg=back leg=cancel
 * @effects list_return_preserves_query
 */
import { test, expect, authenticatePage } from '../../fixtures/page-auth';

const LIST_URL = '/admin/pages';
const LIST_STATE = 'per_page=10';

/**
 * 현재 URL 의 쿼리 파라미터를 돌려줍니다.
 *
 * @param url 페이지 URL
 * @returns 쿼리 파라미터
 */
function queryOf(url: string): URLSearchParams {
  return new URL(url).searchParams;
}

/**
 * 목록의 첫 페이지 id 를 조회합니다.
 *
 * 브라우저 컨텍스트에서 조회해 화면과 같은 토큰·권한을 그대로 쓴다.
 *
 * @param page Playwright 페이지
 * @returns 페이지 id
 */
async function firstPageId(page: import('@playwright/test').Page): Promise<number> {
  const id = await page.evaluate(async () => {
    const res = await fetch('/api/modules/sirsoft-page/admin/pages?per_page=1', {
      headers: {
        Authorization: `Bearer ${localStorage.getItem('auth_token')}`,
        Accept: 'application/json',
      },
    });
    const json = await res.json();
    const rows = json?.data?.data ?? json?.data ?? [];
    return Array.isArray(rows) && rows.length > 0 ? rows[0].id : null;
  });
  expect(id, '페이지 시드가 없어 왕복을 검증할 수 없습니다.').not.toBeNull();
  return id as number;
}

test.describe('관리자 페이지 목록 컨텍스트 왕복 (#75)', () => {
  // @scenario leg=back
  // @effects list_return_preserves_query
  test('상세에서 목록으로 돌아오면 목록 상태가 복원된다', async ({ page, pageManageToken }) => {
    await authenticatePage(page, pageManageToken);
    await page.goto(`${LIST_URL}?${LIST_STATE}`);
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    const id = await firstPageId(page);
    // 목록에서 상세로 들어간 직후와 동일한 URL 상태 (진입 leg 가 mergeQuery 로 만들어 주는 형태)
    await page.goto(`${LIST_URL}/${id}?${LIST_STATE}`);
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    await page.locator('#header_back_button').click();
    await page.waitForURL(/\/admin\/pages\?/, { timeout: 30_000 });

    expect(queryOf(page.url()).get('per_page')).toBe('10');
  });

  // @scenario leg=cancel
  // @effects list_return_preserves_query
  test('등록 폼에서 취소하면 목록 상태가 유지된 채 목록으로 돌아온다', async ({ page, pageManageToken }) => {
    await authenticatePage(page, pageManageToken);
    await page.goto(`${LIST_URL}/create?${LIST_STATE}`);
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    await page.locator('#footer_cancel_button').click();
    await page.waitForURL(/\/admin\/pages\?/, { timeout: 30_000 });

    expect(queryOf(page.url()).get('per_page')).toBe('10');
  });

  // @scenario leg=cancel
  // @effects list_return_preserves_query
  test('수정 폼에서 취소하면 그 페이지 상세로 돌아오며 목록 상태가 살아 있다', async ({ page, pageManageToken }) => {
    await authenticatePage(page, pageManageToken);
    await page.goto(`${LIST_URL}?${LIST_STATE}`);
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    const id = await firstPageId(page);
    await page.goto(`${LIST_URL}/${id}/edit?${LIST_STATE}`);
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    await page.locator('#footer_cancel_button').click();
    await page.waitForURL(new RegExp(`/admin/pages/${id}\\?`), { timeout: 30_000 });

    expect(queryOf(page.url()).get('per_page')).toBe('10');
  });
});
