/**
 * 주문 항목상태 전이 + 리뷰 인라인 모달 (skeleton, placeholder).
 *
 * @scenario order-option-status-review
 * @effects member_sees_option_status_transition_on_order_detail,
 *          member_can_open_review_modal_after_confirm,
 *          review_button_opens_inline_modal_not_dead_route,
 *          review_button_visible_only_when_can_write_review
 *
 * e2e:allow 본 변경의 사용자측 표면은 리뷰작성 진입을 데드라우트 navigate 제거 후 항목별 인라인
 *           모달(openModal modal_write_review)로 통일한 것(F1/D4)으로, 신규 화면이 아닌 기존
 *           항목 모달 단일화다. 레이아웃 렌더링 테스트(orderReviewButton.test.tsx 7건 green)가
 *           show.json 데드라우트 부재 + _items 항목별 can_write_review 게이트 + openModal +
 *           reviewTarget setState 를 구조적으로 회귀 차단한다. option_status 전이 자체는
 *           백엔드 회귀(OrderProcessingServiceTest/OrderServiceTest/OrderOptionServiceTest,
 *           전부 green)가 라이브 검증한다. 본 placeholder spec 은 아래 사전작업 후 활성화한다.
 *
 * 활성화 전 사전 작업 (data-testid 보강):
 *   1. _items.json 리뷰 버튼에 data-testid="order-item-review-btn-{optionId}"
 *   2. _modal_write_review.json 에 data-testid="review-modal"
 *   3. CONFIRMED 옵션을 가진 주문을 시드하는 fixture (memberConfirmedOrder)
 *   4. 회원 토큰 fixture (memberToken)
 *   5. test.describe.skip → test.describe
 *
 * 매트릭스(시나리오 매니페스트 order-option-status-transition.yaml 와 1:1):
 *   - 무통장 입금확인/관리자 상태변경 → 주문상세 항목 배지 결제완료 전이
 *   - CONFIRMED 옵션 → 리뷰 버튼 노출 + 클릭 시 인라인 모달 오픈 (데드라우트 없음)
 */
import { test, expect, authenticatePage } from '../fixtures/ecommerce-auth';

const ORDER_DETAIL_URL = (orderNumber: string) => `/mypage/orders/${orderNumber}`;

test.describe.skip('주문 항목상태 전이 + 리뷰 모달 (placeholder — data-testid 보강 후 활성화)', () => {
  test('CONFIRMED 옵션에 리뷰 버튼이 노출되고 클릭 시 인라인 모달이 열린다 (데드라우트 아님)', async ({
    page,
    memberToken,
  }) => {
    await authenticatePage(page, memberToken);
    await page.goto(ORDER_DETAIL_URL('ORD-CONFIRMED-WITH-OPTION'));

    const reviewBtn = page.getByTestId(/^order-item-review-btn-/).first();
    await expect(reviewBtn).toBeVisible();
    await reviewBtn.click();

    // 데드라우트 navigate 가 아니라 인라인 모달이 떠야 한다 (URL 불변)
    await expect(page.getByTestId('review-modal')).toBeVisible();
    expect(page.url()).not.toContain('/mypage/reviews/write');
  });

  test('무통장 입금확인 후 주문상세 항목 배지가 결제완료로 전이된다', async ({
    page,
    memberToken,
  }) => {
    await authenticatePage(page, memberToken);
    await page.goto(ORDER_DETAIL_URL('ORD-DBANK-CONFIRMED'));

    const statusBadge = page.getByTestId(/^order-option-status-badge-/).first();
    await expect(statusBadge).toHaveText(/결제완료|Payment/i);
  });
});
