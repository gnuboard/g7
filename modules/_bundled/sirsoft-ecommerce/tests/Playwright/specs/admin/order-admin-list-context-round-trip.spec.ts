/**
 * 관리자 주문 목록 컨텍스트 왕복 — 걸어 둔 필터·페이지가 상세를 다녀와도 살아남는지 (공개 이슈 #75).
 *
 * 결함: 주문 목록에서 주문 하나를 열었다가 '목록' 으로 돌아오면 걸어 둔 필터와 페이지가 전부 사라져
 *   1페이지 무필터 상태로 되돌아갔다. 필터를 여러 개 걸어 두고 주문을 하나씩 확인하는 실제 운영 흐름에서
 *   가장 아프게 드러나는 형태다.
 *
 * 표식으로 `page` 와 `per_page` 를 쓴다 — 시드 주문 105건이므로 2페이지가 항상 존재한다.
 *
 * 커버 범위: 이 spec 은 **복귀 leg**(상세 → 목록)를 브라우저 수준에서 실측한다. 진입 leg 는
 *   `resources/js/__tests__/layouts/admin-list-context-round-trip.test.ts` 가 실 JSON 의 액션 params 로
 *   고정하고 audit 룰 layout-list-context-navigate-merge-query 가 재발을 차단한다.
 *
 * @scenario order-admin-list-context-round-trip
 * @axes leg=back
 * @effects list_return_preserves_query
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';

const LIST_URL = '/admin/ecommerce/orders';
const LIST_STATE = 'page=2&per_page=10';

/**
 * 현재 URL 의 쿼리 파라미터를 돌려줍니다.
 *
 * @param url 페이지 URL
 * @returns 쿼리 파라미터
 */
function queryOf(url: string): URLSearchParams {
  return new URL(url).searchParams;
}

test.describe('관리자 주문 목록 컨텍스트 왕복 (#75)', () => {
  // @scenario leg=back
  // @effects list_return_preserves_query
  test('주문 상세에서 목록으로 돌아오면 페이지와 표시 개수가 복원된다', async ({ page, ordersReadToken }) => {
    await authenticatePage(page, ordersReadToken);
    await page.goto(`${LIST_URL}?${LIST_STATE}`);
    // `networkidle` 은 쓰지 않는다 — 관리자 SPA 는 실시간 알림 연결·주기 폴링이 붙어 500ms 무통신
    // 구간이 오지 않을 수 있고, 실제로 주문 상세 진입에서 30초 타임아웃했다. 다음 단계에 필요한
    // 구체 신호만 기다린다(여기서는 API 를 직접 부르므로 문서 로드까지).
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    // 목록에서 상세로 들어간 직후와 동일한 URL 상태 (진입 leg 가 mergeQuery 로 만들어 주는 형태)
    const orderNumber = await page.evaluate(async () => {
      const res = await fetch('/api/modules/sirsoft-ecommerce/admin/orders?per_page=10', {
        headers: {
          Authorization: `Bearer ${localStorage.getItem('auth_token')}`,
          Accept: 'application/json',
        },
      });
      const json = await res.json();
      // 응답 봉투 형태에 의존하지 않도록 order_number 를 가진 첫 객체를 찾는다.
      const stack: unknown[] = [json];
      while (stack.length > 0) {
        const node = stack.pop();
        if (!node || typeof node !== 'object') continue;
        const rec = node as Record<string, unknown>;
        if (typeof rec.order_number === 'string') return rec.order_number;
        stack.push(...Object.values(rec));
      }
      return null;
    });
    expect(orderNumber, '주문 시드가 없어 왕복을 검증할 수 없습니다.').not.toBeNull();

    await page.goto(`${LIST_URL}/${orderNumber}?${LIST_STATE}`);
    // 다음 단계가 클릭하는 '목록' 버튼이 실제로 조작 가능해질 때까지만 기다린다.
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await expect(page.locator('#list_button')).toBeVisible({ timeout: 30_000 });

    await page.locator('#list_button').click();
    await page.waitForURL(/\/admin\/ecommerce\/orders\?/, { timeout: 30_000 });

    const q = queryOf(page.url());
    expect(q.get('page')).toBe('2');
    expect(q.get('per_page')).toBe('10');
  });
});
