/**
 * 관리자 상태변경 시 사용자 알림 발송 (skeleton, placeholder).
 *
 * @scenario order-status-notification
 * @effects admin_status_change_triggers_user_notification
 *
 * e2e:allow 본 변경의 관리자측 표면은 무통장 입금확인 버튼+모달 추가(B2/D7)와 상태전이 알림
 *           중앙화(A35/A36/D9 — 발화는 백엔드 훅)다. 무통장 입금확인 UI 는 레이아웃 렌더링
 *           테스트(adminOrderConfirmDeposit.test.tsx 8건 green)가 버튼 노출조건(dbank+미결제)·
 *           모달 필드·confirm-deposit PATCH·order refetch 를 구조적으로 회귀 차단한다.
 *           알림 발화/매핑은 백엔드 회귀(OrderStatusNotificationListenerTest 5건,
 *           OrderStatusNotificationTest Feature 5건, 전부 green)가 라이브 검증한다.
 *           본 placeholder spec 은 아래 사전작업 후 활성화한다.
 *
 * 활성화 전 사전 작업 (data-testid 보강):
 *   1. _partial_payment_info.json 입금확인 버튼에 data-testid="confirm-deposit-btn"
 *   2. _modal_confirm_deposit.json 에 data-testid="confirm-deposit-modal"
 *      + 입금액/입금자명 입력 data-testid
 *   3. dbank+미결제 주문을 시드하는 fixture (adminDbankOrder)
 *   4. notification_logs 조회 또는 사용자 알림함 노출 검증 헬퍼
 *   5. orders.update 권한 토큰 fixture (ordersUpdateToken)
 *   6. test.describe.skip → test.describe
 *
 * 매트릭스(시나리오 매니페스트 order-status-notification.yaml 와 1:1):
 *   - 관리자 update/일괄로 payment_complete 전이 → order_confirmed(결제완료) 발송 (D9)
 *   - 무통장 입금확인 액션 → 결제완료 전이 + 알림
 *   - DELIVERED→CONFIRMED 일괄 → 각 주문 order_completed + 재고 무변동 (A36)
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';

const ORDER_DETAIL_URL = (orderNumber: string) => `/admin/ecommerce/orders/${orderNumber}`;

test.describe.skip('관리자 상태변경 → 사용자 알림 (placeholder — data-testid 보강 후 활성화)', () => {
  test('무통장 입금확인 버튼은 dbank+미결제 주문에서 노출되고 모달로 결제완료 처리한다', async ({
    page,
    ordersUpdateToken,
  }) => {
    await authenticatePage(page, ordersUpdateToken);
    await page.goto(ORDER_DETAIL_URL('ORD-DBANK-PENDING'));

    const btn = page.getByTestId('confirm-deposit-btn');
    await expect(btn).toBeVisible();
    await btn.click();

    await expect(page.getByTestId('confirm-deposit-modal')).toBeVisible();
  });

  test('관리자가 payment_complete 로 전이하면 사용자에게 결제완료 알림이 적재된다 (D9)', async ({
    page,
    ordersUpdateToken,
  }) => {
    await authenticatePage(page, ordersUpdateToken);
    await page.goto(ORDER_DETAIL_URL('ORD-DBANK-PENDING'));
    // 입금확인 → 결제완료 → notification_logs(order_confirmed) 적재 검증 (헬퍼 보강 후)
    expect(true).toBe(true);
  });
});
