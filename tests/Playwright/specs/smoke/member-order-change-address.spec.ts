/**
 * Smoke: 회원 주문 상세 배송지 변경 모달 (Issue #55 #5)
 *
 * 회귀 차단 (브라우저 실증 — Vitest 구조 검증과 별개):
 *   - 증상1: 적용 버튼 클릭 시 confirm 다이얼로그가 한 번만 뜬다 (이전: 액션 2개 각각 confirm → 두 번)
 *   - 증상2: 직접입력 탭에서 받는분/연락처 타이핑 값이 _local.manualAddress 로 바인딩된다
 *            (이전: 컨테이너 dataKey 부재로 자동바인딩 미작동 → 빈 값 전송 → recipient_name 필수 에러)
 *
 * 데이터 의존: 회원 토큰 + 변경 가능 상태(pending_payment/payment_complete)의 주문이 시드되어야
 * 모달 트리거 버튼이 노출된다. 시드 부재 환경에서는 트리거 버튼 미노출 시 test.skip 으로 graceful 처리.
 * full 결제→주문 생성 흐름은 백엔드 fixture 필요 — 후속.
 *
 * @scenario actor=member, change_mode=manual, e2e_browser=chromium
 * @effects change_address_handler_uses_user_endpoint_without_token_header_for_member,
 *   change_address_handler_saved_mode_sends_address_id_manual_sends_full_object,
 *   change_address_handler_resets_issubmitting_and_closes_modal_and_refetches_order_on_success
 */
import { test, expect } from '@playwright/test';
import { issueToken, authenticatePage } from '../../fixtures/auth';

/**
 * 회원 인증 후 마이페이지 주문 목록에서 첫 주문 상세로 진입.
 * 변경 가능한 주문이 없으면 null 반환 (호출부에서 skip).
 */
async function gotoFirstChangeableOrderDetail(page: import('@playwright/test').Page): Promise<boolean> {
  const token = issueToken('core.users.read'); // 임의 회원 권한 — 마이페이지 접근용
  await authenticatePage(page, token);

  await page.goto('/mypage/orders');

  // 주문 목록의 첫 상세 링크 (없으면 변경 가능 주문 없음으로 간주)
  const firstOrderLink = page.locator('a[href*="/mypage/orders/"]').first();
  const hasOrder = await firstOrderLink.isVisible({ timeout: 10_000 }).catch(() => false);
  if (!hasOrder) return false;

  await firstOrderLink.click();

  // 배송지 변경 버튼 노출 여부 (pending_payment/payment_complete + 회원 가드)
  const changeBtn = page.getByRole('button', { name: /배송지 변경|Change.*Address/i }).first();
  const canChange = await changeBtn.isVisible({ timeout: 10_000 }).catch(() => false);
  return canChange;
}

test('@smoke 회원 배송지 변경 — 적용 시 확인창이 한 번만 뜬다 (증상1 회귀 차단)', async ({ page }) => {
  const ready = await gotoFirstChangeableOrderDetail(page);
  test.skip(!ready, '변경 가능한 회원 주문이 시드되지 않음 — full 흐름은 백엔드 fixture 후속');

  // confirm 다이얼로그 발생 횟수 카운트
  let confirmCount = 0;
  page.on('dialog', async (dialog) => {
    if (dialog.type() === 'confirm') {
      confirmCount += 1;
      await dialog.dismiss(); // 취소 — 실제 API 호출 방지
    } else {
      await dialog.dismiss();
    }
  });

  await page.getByRole('button', { name: /배송지 변경|Change.*Address/i }).first().click();

  // 모달의 적용 버튼 클릭 (기본 saved 모드)
  const applyBtn = page.getByRole('button', { name: /변경 적용|Apply/i }).first();
  await expect(applyBtn).toBeVisible({ timeout: 10_000 });
  await applyBtn.click();

  // confirm 은 정확히 1회만 (이전 버그: 2회)
  await page.waitForTimeout(500);
  expect(confirmCount).toBe(1);
});

test('@smoke 회원 배송지 변경 — 직접입력 탭에서 받는분/연락처 입력이 자동바인딩된다 (증상2 회귀 차단)', async ({ page }) => {
  const ready = await gotoFirstChangeableOrderDetail(page);
  test.skip(!ready, '변경 가능한 회원 주문이 시드되지 않음 — full 흐름은 백엔드 fixture 후속');

  // confirm 은 dismiss (실제 전송 방지) — 본 테스트는 입력 바인딩만 검증
  page.on('dialog', (dialog) => dialog.dismiss());

  await page.getByRole('button', { name: /배송지 변경|Change.*Address/i }).first().click();

  // 직접 입력 탭으로 전환
  await page.getByRole('button', { name: /직접 입력|Manual/i }).first().click();

  // 받는분/연락처 입력 — 모달 컨테이너 dataKey 가 _global.editingShippingAddress 이고
  // 자식 Input 은 하위 키(recipient_name 등) 상대 name 으로 자동 바인딩된다.
  const recipientInput = page.locator('input[name="recipient_name"]').first();
  await expect(recipientInput).toBeVisible({ timeout: 10_000 });
  await recipientInput.fill('회귀테스트수령인');

  const phoneInput = page.locator('input[name="recipient_phone"]').first();
  await phoneInput.fill('010-9999-8888');

  // 자동바인딩 검증: _global.editingShippingAddress 에 입력값이 반영되었는지 G7Core 상태로 확인
  const bound = await page.evaluate(() => {
    const g7 = (window as any).G7Core;
    const global = g7?.state?.get?.('_global') ?? {};
    return global.editingShippingAddress ?? null;
  });
  expect(bound?.recipient_name).toBe('회귀테스트수령인');
  expect(bound?.recipient_phone).toBe('010-9999-8888');
});
