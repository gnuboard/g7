/**
 * 결제(PG) provider-agnostic 동적 dispatch — checkout 결제 진입 (skeleton, placeholder).
 *
 * @scenario checkout-pg-dynamic-dispatch
 * @effects checkout_dispatches_response_payment_handler,
 *          checkout_pg_branch_skipped_when_no_payment_handler,
 *          checkout_non_pg_fallback_preserved_guest_verify_chain,
 *          dynamic_handler_binding_resolved_before_routing
 *
 * e2e:allow 본 변경의 사용자측 표면은 checkout onSuccess 가 특정 PG 하드코딩 대신 응답
 *           pg_payment_handler 를 dispatch 하도록 바꾼 것이다. 실제 결제창 도달은 설정된
 *           PG 샌드박스 + 주문 시드 + PG 팝업이 필요해 Playwright 자동화로 결정적으로 몰기
 *           어렵다(외부 PG SDK·팝업 의존). 본 동적 dispatch 의 회귀는 다음 계층 테스트가
 *           구조적으로 차단한다:
 *             - 엔진(ActionDispatcher.dynamicHandler.test.ts, 8건 green): `{{...}}` 핸들러
 *               해석→라우팅, 빌트인 미진입, nested, 프리뷰 억제 정합, 미등록 graceful skip.
 *             - 템플릿(checkout-pg-dynamic-dispatch.test.tsx, 11건 green): tosspayments
 *               하드코딩 부재, 동적 핸들러 바인딩, if 가드(PG+handler→발화 / handler 부재·
 *               non-PG→미발화), non-PG fallback(비회원 verify→navigate) 보존 — ConditionEvaluator
 *               라운드트립으로 런타임 동작 검증.
 *             - 백엔드(OrderResponsePaymentHandlerTest.php, 6건 green): provider→응답
 *               pg_payment_handler 매핑, 미선언 시 키 부재.
 *           브라우저 실측은 PG 샌드박스가 구성된 환경에서 PO 가 직접 수행한다.
 *
 * 활성화 전 사전 작업:
 *   1. checkout 결제하기 버튼에 data-testid="checkout-submit-order"
 *   2. PG 샌드박스가 구성된 테스트 환경 + 결제 진입 가능한 주문 시드 fixture
 *   3. PG 팝업/리다이렉트를 가로채는 page.on('popup') 또는 PG SDK 스텁
 *   4. 회원/비회원 토큰 fixture
 *   5. test.describe.skip → test.describe
 *
 * 매트릭스(시나리오 매니페스트 checkout-pg-dynamic-dispatch.yaml 와 1:1):
 *   - PG 결제 + 응답 pg_payment_handler 선언 → 결제 진입 핸들러 dispatch (결제창 도달)
 *   - PG 결제 + pg_payment_handler 미선언 → non-PG fallback (완료 페이지 navigate)
 *   - 비회원 non-PG → verify → saveGuestOrderToken → 완료 페이지
 */
import { test, expect, issueToken, authenticatePage } from '../fixtures/auth';

const CHECKOUT_URL = '/shop/checkout';

test.describe.skip('checkout 결제 진입 provider-agnostic 동적 dispatch (placeholder — PG 샌드박스 구성 후 활성화)', () => {
  test('응답 pg_payment_handler 가 선언되면 그 결제 진입 핸들러로 dispatch 되어 결제창에 도달한다', async ({
    page,
  }) => {
    await authenticatePage(page, issueToken());
    await page.goto(CHECKOUT_URL);

    // PG 팝업을 가로채 결제 진입 핸들러가 실제로 호출됐는지 확인
    const popupPromise = page.waitForEvent('popup');
    await page.getByTestId('checkout-submit-order').click();
    const popup = await popupPromise;
    expect(popup).toBeTruthy();
    // 하드코딩 PG(tosspayments) navigate-to-complete 가 아니라 결제창이 떠야 한다
    expect(page.url()).toContain('/shop/checkout');
  });

  test('pg_payment_handler 미선언(non-PG) 시 완료 페이지로 navigate 한다 (분기 미발화)', async ({
    page,
  }) => {
    await authenticatePage(page, issueToken());
    await page.goto(CHECKOUT_URL);

    await page.getByTestId('checkout-submit-order').click();
    await expect(page).toHaveURL(/\/shop\/orders\/.+\/complete/);
  });
});
