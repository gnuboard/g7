/**
 * 관리자 주문상세 — 재고 적용 여부 / 실제 적립 여부 배지 (skeleton, placeholder).
 *
 * @scenario admin-order-detail-stock-earn-badges
 * @effects admin_sees_stock_deducted_badge_on_deducted_option,
 *          admin_sees_not_deducted_badge_on_undeducted_option,
 *          admin_sees_earned_badge_when_points_earned,
 *          admin_sees_pending_badge_when_points_not_earned
 *
 * e2e:allow 주문상품 테이블 '구매수량' 컬럼에 재고 적용 여부 배지, '적립예정' 컬럼에 실제
 *           적립 여부 배지를 추가하는 읽기 전용 표시 변경(인터랙션 없음)으로 신규 axis 부재 —
 *           본 placeholder spec(test.describe.skip)이 data-testid 보강 후 활성화될 때 함께 검증된다.
 *           레이아웃 렌더링 테스트(orderDetailLayouts.test.tsx 83건 green)가 구매수량 컬럼의
 *           is_stock_deducted 분기 배지 / 적립예정 컬럼의 (subtotal_earned_points_amount>0 &&
 *           is_points_earned) 분기 배지 / Badge color prop(green·gray) 사용을 구조적으로 회귀 차단한다.
 *           백엔드 회귀(OrderResourceFieldsTest)가 is_stock_deducted boolean 노출,
 *           is_points_earned 가 PURCHASE_EARN 발행 시 true / 미발행·사용거래(ORDER_USE) 시 false
 *           (withExists 집계 경로)임을 라이브 검증한다.
 *
 * 본 spec 은 다음 사전 작업 완료 후 활성화한다 (data-testid 보강):
 *   1. _partial_order_info.json 주문상품 DataGrid 행에 data-testid="order-option-row-{id}"
 *   2. 구매수량 컬럼 재고 배지에 data-testid="order-option-stock-badge"
 *   3. 적립예정 컬럼 적립 배지에 data-testid="order-option-earn-badge"
 *   4. 재고 차감/미차감, 적립 발생/미발생 4축을 시드하는 OrderSeeder 보강
 *   5. orders.read 권한 토큰 fixture(ordersReadToken) 추가
 *   6. test.describe.skip → test.describe 변경
 *
 * 매트릭스(시나리오 매니페스트 admin-order-detail-stock-earn-badges.yaml 와 1:1):
 *   - 재고 차감 완료 옵션  : 구매수량 아래 "재고 차감"(green) 배지
 *   - 재고 미차감 옵션      : 구매수량 아래 "재고 미차감"(gray) 배지
 *   - 구매 적립 발행 옵션    : 적립예정 아래 "적립완료"(green) 배지
 *   - 적립 미발행(예정) 옵션 : 적립예정 아래 "적립예정"(gray) 배지
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';

// 시드된 주문번호 — 활성화 시 OrderSeeder 가 보장하는 4축 주문으로 교체
const ORDER_DETAIL_URL = (orderNumber: string) =>
  `/admin/ecommerce/orders/${orderNumber}`;

test.describe.skip('관리자 주문상세 — 재고/적립 배지 (placeholder — data-testid 보강 후 활성화)', () => {
  test('재고 차감 완료 옵션 — 구매수량 아래 "재고 차감" 배지가 노출된다', async ({
    page,
    ordersReadToken,
  }) => {
    await authenticatePage(page, ordersReadToken);
    await page.goto(ORDER_DETAIL_URL('ORD-STOCK-DEDUCTED'));

    const row = page.getByTestId(/^order-option-row-/).first();
    await expect(row.getByTestId('order-option-stock-badge')).toHaveText(/재고 차감/);
  });

  test('재고 미차감 옵션 — 구매수량 아래 "재고 미차감" 배지가 노출된다', async ({
    page,
    ordersReadToken,
  }) => {
    await authenticatePage(page, ordersReadToken);
    await page.goto(ORDER_DETAIL_URL('ORD-STOCK-NOT-DEDUCTED'));

    const row = page.getByTestId(/^order-option-row-/).first();
    await expect(row.getByTestId('order-option-stock-badge')).toHaveText(/재고 미차감/);
  });

  test('구매 적립 발행 옵션 — 적립예정 아래 "적립완료" 배지가 노출된다', async ({
    page,
    ordersReadToken,
  }) => {
    await authenticatePage(page, ordersReadToken);
    await page.goto(ORDER_DETAIL_URL('ORD-POINTS-EARNED'));

    const row = page.getByTestId(/^order-option-row-/).first();
    await expect(row.getByTestId('order-option-earn-badge')).toHaveText(/적립완료/);
  });

  test('적립 미발행(예정) 옵션 — 적립예정 아래 "적립예정" 배지가 노출된다', async ({
    page,
    ordersReadToken,
  }) => {
    await authenticatePage(page, ordersReadToken);
    await page.goto(ORDER_DETAIL_URL('ORD-POINTS-PENDING'));

    const row = page.getByTestId(/^order-option-row-/).first();
    await expect(row.getByTestId('order-option-earn-badge')).toHaveText(/적립예정/);
  });
});
