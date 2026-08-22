/**
 * 주문상세/목록 표시 결함 (U19②③) — 무할인 항목 소계 표시 + 취소 주문 적립예정 숨김.
 *
 * @scenario order-detail-display-orphans
 * @effects member_sees_subtotal_on_no_discount_item,
 *          no_regression_on_discount_item_subtotal,
 *          earned_points_hidden_on_cancelled_order,
 *          earned_points_hidden_on_partially_cancelled_order,
 *          earned_points_shown_on_normal_order
 *
 * e2e:allow U19②③ 는 순수 표시(읽기 전용) 정정으로 신규 인터랙션 axis 가 없다.
 *           ② 무할인 항목 소계: OrderOptionResource 가 비교용 숫자 보조 필드
 *           subtotal_discount_amount_value(float) 를 노출하고 _items.json 의 `=== 0`/`> 0`
 *           비교가 이 숫자 필드를 쓰도록 정정(decimal:2 문자열 "0.00" 의 strict 비교 회귀 차단).
 *           ③ 취소 주문 적립예정: 5개 레이아웃(_payment/_list/order_complete + admin
 *           payment_info/datagrid) 의 적립예정 if 가드에 취소/부분취소 제외 조건 결합
 *           (값 보존, 표시만 숨김).
 *
 *           레이아웃 렌더링 테스트가 두 결함을 구조적으로 회귀 차단한다:
 *           - templates/_bundled/sirsoft-basic: order-item-subtotal-display.test.tsx(3),
 *             order-cancelled-earned-points-hidden.test.tsx(9 — 유저상세/목록/주문완료 +
 *             _list.json 가드가 order.order_status 가 아닌 order.status 를 검사하는지 회귀 가드)
 *           - modules/_bundled/sirsoft-ecommerce: adminOrderCancelledEarnedPointsHidden.test.tsx(6 —
 *             관리자 상세/목록 정상/전체취소/부분취소)
 *           백엔드 회귀(OrderOptionResourceSubtotalDiscountValueTest)가 무할인 옵션 →
 *           subtotal_discount_amount_value=0.0(float), 할인 옵션 → 양수 float 노출을 라이브 검증한다.
 *           Developer 가 Chrome MCP 로 유저상세(무할인/할인/혼합 ko·en)·유저목록·관리자상세·
 *           관리자목록 DataGrid 의 정상/전체취소/부분취소를 실측 통과했다(취소 후 컬럼 값 보존 tinker 확인).
 *
 * 본 spec 은 다음 사전 작업 완료 후 활성화한다 (data-testid 보강):
 *   1. _items.json 무할인 소계 Span 에 data-testid="order-item-subtotal-{id}"
 *   2. _payment.json 적립예정 블록에 data-testid="order-payment-earned-points"
 *   3. _partial_order_datagrid.json 적립 컬럼 셀에 data-testid="order-row-earned-{id}"
 *   4. 무할인/할인/혼합 항목 + 정상/전체취소/부분취소 주문을 시드하는 OrderSeeder 보강
 *   5. orders.read 권한 토큰 fixture(ordersReadToken) 추가
 *   6. test.describe.skip → test.describe 변경
 *
 * 매트릭스(시나리오 매니페스트 order-detail-display-orphans.yaml 와 1:1):
 *   ② 무할인 항목      : 항목 소계 Span 노출(decimal 문자열 strict 비교 회귀 차단)
 *   ② 할인 항목        : line-through 정가 + 할인가 노출(회귀 없음)
 *   ③ 정상 주문        : 적립예정 노출
 *   ③ 전체취소 주문    : 적립예정 미노출
 *   ③ 부분취소 주문    : 적립예정 미노출
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';

const ORDER_DETAIL_URL = (orderNumber: string) => `/admin/ecommerce/orders/${orderNumber}`;
const ORDER_LIST_URL = '/admin/ecommerce/orders';

test.describe.skip('주문상세/목록 표시 결함 U19②③ (placeholder — data-testid 보강 후 활성화)', () => {
  test('② 무할인 항목 — 주문상세 항목 소계가 노출된다', async ({ page, ordersReadToken }) => {
    await authenticatePage(page, ordersReadToken);
    await page.goto(ORDER_DETAIL_URL('ORD-NO-DISCOUNT'));

    const row = page.getByTestId(/^order-item-subtotal-/).first();
    await expect(row).toBeVisible();
  });

  test('③ 정상 주문 — 결제정보에 적립예정이 노출된다', async ({ page, ordersReadToken }) => {
    await authenticatePage(page, ordersReadToken);
    await page.goto(ORDER_DETAIL_URL('ORD-NORMAL-EARNED'));

    await expect(page.getByTestId('order-payment-earned-points')).toBeVisible();
  });

  test('③ 전체취소 주문 — 결제정보에 적립예정이 미노출된다', async ({ page, ordersReadToken }) => {
    await authenticatePage(page, ordersReadToken);
    await page.goto(ORDER_DETAIL_URL('ORD-CANCELLED-EARNED'));

    await expect(page.getByTestId('order-payment-earned-points')).toHaveCount(0);
  });

  test('③ 부분취소 주문 — 결제정보에 적립예정이 미노출된다', async ({ page, ordersReadToken }) => {
    await authenticatePage(page, ordersReadToken);
    await page.goto(ORDER_DETAIL_URL('ORD-PARTIAL-CANCELLED-EARNED'));

    await expect(page.getByTestId('order-payment-earned-points')).toHaveCount(0);
  });

  test('③ 주문목록 DataGrid — 취소 주문 행에 적립예정이 미노출된다', async ({ page, ordersReadToken }) => {
    await authenticatePage(page, ordersReadToken);
    await page.goto(ORDER_LIST_URL);

    // 취소 주문 행의 적립 컬럼 셀이 비어 있어야 한다(미노출).
    await expect(page.getByTestId('order-row-earned-cancelled')).toHaveCount(0);
  });
});
