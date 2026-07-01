/**
 * Smoke: 비회원 주문 조회 폼 진입 검증 (Issue #55 단계 5 그룹 C+D)
 *
 * @scenario guest-order: actor=guest, entry_point=login_screen_lookup_link
 * @effects guest_order_form_accepts_order_number_phone_password_three_fields
 *
 * 검증 범위:
 *   - /shop/guest/orders 라우트 진입 시 폼 마운트
 *   - 주문번호 / 휴대폰 / 비밀번호 3개 입력 필드 가시성
 *
 * 인증 없이 접근 가능한 공개 라우트라 토큰 fixture 불필요.
 * 흐름 전체 E2E (결제 → verify → 토큰 → 상세) 는 백엔드 fixture/시드가 필요하므로 후속 작업.
 */
import { test, expect } from '@playwright/test';

test('@smoke 비회원 주문 조회 폼 진입 시 주문번호/휴대폰/비밀번호 3개 필드가 렌더된다', async ({ page }) => {
  // 테스트 격리 — 같은 worker 의 직전 테스트가 남긴 만료 토큰/세션 잔재가
  // current_user 401 → 세션 만료 가드 리다이렉트를 유발하지 않도록 진입 전 정리.
  await page.goto('/');
  await page.evaluate(() => {
    try {
      sessionStorage.removeItem('g7_guest_order_token');
      sessionStorage.removeItem('g7_guest_order_number');
      sessionStorage.removeItem('g7_guest_order_expires_at');
      localStorage.removeItem('g7_guest_order_token');
    } catch {}
  });

  await page.goto('/shop/guest/orders');

  // URL 단언으로 라우터 매칭 완료 대기 — :96/:129 와 동일 패턴으로 통일 (input 단언 전에 SPA 라우팅 안정화)
  await expect(page).toHaveURL(/\/shop\/guest\/orders(\?|$)/, { timeout: 15_000 });

  // SPA 마운트 대기 — 첫 번째 입력 필드(주문번호) 가시화
  const orderNumberInput = page.locator('input[name="order_number"]').first();
  await expect(orderNumberInput).toBeVisible({ timeout: 15_000 });

  const phoneInput = page.locator('input[name="orderer_phone"]').first();
  await expect(phoneInput).toBeVisible();

  const passwordInput = page.locator('input[name="guest_lookup_password"]').first();
  await expect(passwordInput).toBeVisible();
});

test('@smoke 비회원 조회 폼은 인증 없이 접근 가능해야 한다 (auth_required: false 검증)', async ({ page }) => {
  await page.goto('/shop/guest/orders');

  // 로그인 페이지로 리다이렉트되지 않아야 함 — URL 유지 확인 (폼 경로 = 끝 경계로 상세 :order_number 와 구분)
  await expect(page).toHaveURL(/\/shop\/guest\/orders(\?|$)/, { timeout: 15_000 });
});

/**
 * @scenario guest-order: actor=guest, token_state=missing
 * @effects order_complete_401_404_clears_token_and_shows_lookup_link
 * @effects order_complete_blur_until_orderData_loaded_prevents_flash
 *
 * 비로그인 + 토큰 부재 사용자가 /shop/orders/:n/complete 에 직접 진입하면
 * blur 가 콘텐츠를 가린 채 백엔드가 404 를 응답하고, errorHandling.404 의 비회원 분기가
 * 토큰을 정리한 뒤 로그인 페이지로 이동한다.
 *
 * 메커니즘 변경: init_actions 가드는 _user_base.current_user 데이터소스 응답보다 먼저 실행되어
 * 회원도 비로그인으로 오인하는 타이밍 결함이 있어 제거됨. 대신 blur + errorHandling 조합으로 처리.
 */
test('@smoke 비로그인 + 토큰 부재 상태로 주문 완료 화면 진입 시 안내 페이지로 리다이렉트되어야 한다', async ({ page }) => {
  // sessionStorage/localStorage 토큰을 명시적으로 비움 (테스트 격리). 본 작업에서 sessionStorage 로 전환되었으나
  // 과거 환경에서 남아있을 수 있는 localStorage 잔재도 함께 정리.
  await page.goto('/');
  await page.evaluate(() => {
    try {
      sessionStorage.removeItem('g7_guest_order_token');
      sessionStorage.removeItem('g7_guest_order_number');
      sessionStorage.removeItem('g7_guest_order_expires_at');
      localStorage.removeItem('g7_guest_order_token');
    } catch {}
  });

  // 존재하지 않을 수도 있는 주문번호로 직접 진입 (백엔드가 404 응답 → errorHandling 분기)
  await page.goto('/shop/orders/20260526-0000000000/complete');

  // errorHandling.404 비회원 분기 발화 → 비회원 조회 폼 또는 로그인 페이지로 이동
  // (백엔드 errors.redirect_to 가 회원/비회원에 따라 결정 — 비회원이면 조회 폼, 회원이면 login)
  // 404 응답 수신 → sequence(토큰 정리 → navigate) 체인이 완료되기까지 시간이 걸리므로 timeout 여유 확보.
  await expect(page).toHaveURL(/\/(login|shop\/guest\/orders)(\?|$)/, { timeout: 30_000 });
});

/**
 * @scenario guest-order: actor=guest, token_state=valid_in_session_storage, entry_point=guest_order_form
 * @effects guest_order_form_clears_session_token_on_entry_for_standard_form_visibility
 *
 * /shop/guest/orders 진입 시 sessionStorage 의 유효 토큰을 init_actions(clearGuestTokenOnEntry)
 * 이 초기화하고 매번 인증 폼이 노출되어야 한다 (eBay/Best Buy/카페24/11번가 표준 패턴 정합).
 *
 * 이전 동작(redirectIfGuestTokenValid)은 유효 토큰 보유자를 본인 주문 상세로 자동 진입시켰는데,
 * 그러면 다른 주문 조회 시 막다른 길에 빠짐. 새 동작은 진입 자체가 토큰을 비워 매번 새 인증.
 */
test('@smoke 유효 sessionStorage 토큰 보유 시 /guest/orders 진입은 토큰을 초기화하고 인증 폼을 노출해야 한다', async ({ page }) => {
  const fakeOrderNumber = '20260526-AUTO-CLEAR';
  const expiresAt = new Date(Date.now() + 5 * 60 * 1000).toISOString(); // +5분 (유효)
  await page.goto('/');
  await page.evaluate(({ orderNumber, expiresAt }) => {
    try {
      sessionStorage.setItem('g7_guest_order_token', '9999999999|' + 'a'.repeat(64));
      sessionStorage.setItem('g7_guest_order_number', orderNumber);
      sessionStorage.setItem('g7_guest_order_expires_at', expiresAt);
    } catch {}
  }, { orderNumber: fakeOrderNumber, expiresAt });

  await page.goto('/shop/guest/orders');

  // URL 유지 (자동 redirect 가 더 이상 일어나지 않음)
  await expect(page).toHaveURL(/\/shop\/guest\/orders(\?|$)/, { timeout: 15_000 });

  // 인증 폼이 정상 노출
  const orderNumberInput = page.locator('input[name="order_number"]').first();
  await expect(orderNumberInput).toBeVisible({ timeout: 15_000 });

  // 토큰이 자동 초기화되어야 함
  const remainingToken = await page.evaluate(() => sessionStorage.getItem('g7_guest_order_token'));
  expect(remainingToken).toBeNull();
});

/**
 * @scenario guest-order: actor=guest, token_state=expired_in_session_storage, entry_point=guest_order_form
 * @effects expired_session_token_is_cleared_and_form_renders
 *
 * 만료된 sessionStorage 토큰을 보유한 사용자가 /guest/orders 에 진입하면
 * init_actions 가 토큰을 자동 폐기하고 인증 폼이 정상 노출되어야 한다.
 */
test('@smoke 만료된 sessionStorage 토큰 보유 시 /guest/orders 진입은 인증 폼을 그대로 노출해야 한다', async ({ page }) => {
  const fakeOrderNumber = '20260526-EXPIRED';
  const expiresAt = new Date(Date.now() - 60 * 1000).toISOString(); // -1분 (만료됨)
  await page.goto('/');
  await page.evaluate(({ orderNumber, expiresAt }) => {
    try {
      sessionStorage.setItem('g7_guest_order_token', '9999999999|' + 'b'.repeat(64));
      sessionStorage.setItem('g7_guest_order_number', orderNumber);
      sessionStorage.setItem('g7_guest_order_expires_at', expiresAt);
    } catch {}
  }, { orderNumber: fakeOrderNumber, expiresAt });

  await page.goto('/shop/guest/orders');

  // 만료 토큰은 폐기되고 인증 폼이 정상 노출
  await expect(page).toHaveURL(/\/shop\/guest\/orders(\?|$)/, { timeout: 15_000 });
  const orderNumberInput = page.locator('input[name="order_number"]').first();
  await expect(orderNumberInput).toBeVisible({ timeout: 15_000 });

  // 토큰 자동 폐기 확인
  const remainingToken = await page.evaluate(() => sessionStorage.getItem('g7_guest_order_token'));
  expect(remainingToken).toBeNull();
});

/**
 * @scenario guest-order: actor=guest, entry_point=header_lookup_link
 * @effects header_and_mobile_nav_order_lookup_routes_via_login_redirect_param
 *
 * 비로그인 상태로 헤더 '주문조회' 메뉴 클릭 시 /login?redirect=/mypage/orders 로 진입해
 * 로그인 페이지가 회원/비회원 분기점 역할을 한다. 회원은 로그인 후 redirect 파라미터로
 * 마이페이지 주문 내역 자동 이동, 비회원은 로그인 페이지의 '비회원 주문 조회' 링크로 폼 진입.
 */
test('@smoke 비로그인 상태로 헤더 \'주문조회\' 클릭 시 /login?redirect=/mypage/orders 로 진입해야 한다', async ({ page }) => {
  await page.goto('/');

  // 헤더 '주문조회' 버튼 클릭 (비로그인 분기에만 노출됨). 환경에 따라 한국어/영문 라벨 모두 매칭.
  const headerLookupButton = page.getByRole('button', { name: /주문조회|Order Lookup/ }).first();
  await expect(headerLookupButton).toBeVisible({ timeout: 15_000 });
  await headerLookupButton.click();

  // /login 페이지로 이동 + redirect 파라미터 보존
  await expect(page).toHaveURL(/\/login\?redirect=%2Fmypage%2Forders|\/login\?redirect=\/mypage\/orders/, { timeout: 15_000 });
});
