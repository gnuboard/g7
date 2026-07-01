/**
 * MP06 — 쿠폰 검증 표면화 + 결제수단별 마일리지 차감시점 (skeleton, placeholder).
 *
 * @scenario mp06-coupon-pre-deduction, mp06-checkout-coupon-combination, mp06-checkout-coupon-per-user-limit
 * @effects mileage_off_disables_control, mileage_timing_per_method,
 *          is_combinable_false_blocks_across_all_slots, per_user_limit_axis2_within_order_blocked,
 *          coupon_reuse_blocked_until_cancel
 *
 * 배경(MP06):
 *   - 주문설정 결제수단별 마일리지 차감시점 Select 신설. mileage.enabled=false 면 disabled.
 *   - 주문서 쿠폰 위반(min_amount/per_user_limit/not_combinable)은 validation_errors 로 소프트
 *     표면화되고 위반 쿠폰은 할인에서 제외된다. 주문 확정 시 위반이 남아있으면 422 하드 차단.
 *   - 무통장 1회 제한 쿠폰은 주문 생성 즉시 USED 차감되어 입금 전까지 재사용 불가(공개#57).
 *
 *   Select 렌더/disabled 바인딩은 레이아웃 렌더 테스트(adminEcommerceSettingsOrder.test.tsx)가,
 *   차감 시점 분기·쿠폰 검증·선차감/반환은 PHPUnit(OrderCalculation*/CouponUse*/CouponRestore*/
 *   EcommerceSettingsMileageTiming/MigrateMileageDeductionTiming)가 커버한다. 본 spec 은 브라우저에서
 *   설정 disabled 연동 + 주문서 위반 안내 노출만 왕복 검증한다.
 *
 * 활성화 전 사전 작업(data-testid 보강):
 *   1. 결제수단 카드의 마일리지 차감시점 Select 에 data-testid="payment-method-mileage-timing-{id}"
 *   2. 마일리지 사용 토글(mileage.enabled)에 data-testid="mileage-enabled-toggle"
 *   3. 주문서 쿠폰 검증 안내 영역에 data-testid="checkout-coupon-validation-error"
 *   4. test.describe.skip → test.describe 변경
 *
 * 매트릭스(시나리오 매니페스트 mp06-coupon-pre-deduction.yaml 와 1:1):
 *   - mileage.enabled OFF → 차감시점 Select disabled + 안내문구 노출
 *   - mileage.enabled ON  → Select 활성, vbank/dbank 기본 order_placed / card 기본 payment_complete
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';

const SETTINGS_URL = '/admin/ecommerce/settings?tab=order_settings';

test.describe.skip('MP06 결제수단별 마일리지 차감시점 (placeholder — data-testid 보강 후 활성화)', () => {
  test('마일리지 사용 OFF — 차감시점 Select 가 disabled 여야 한다', async ({ page, settingsToken }) => {
    await authenticatePage(page, settingsToken);
    await page.goto('/admin/ecommerce/settings?tab=mileage');

    // 마일리지 사용 OFF
    const toggle = page.getByTestId('mileage-enabled-toggle');
    if (await toggle.isChecked()) {
      await toggle.click();
    }

    await page.goto(SETTINGS_URL);

    // 무통장 결제수단의 마일리지 차감시점 Select 가 disabled
    await expect(page.getByTestId('payment-method-mileage-timing-dbank')).toBeDisabled();
  });

  test('마일리지 사용 ON — 결제수단별 차감시점 선택·저장 왕복', async ({ page, settingsToken }) => {
    await authenticatePage(page, settingsToken);
    await page.goto('/admin/ecommerce/settings?tab=mileage');

    const toggle = page.getByTestId('mileage-enabled-toggle');
    if (!(await toggle.isChecked())) {
      await toggle.click();
    }

    await page.goto(SETTINGS_URL);

    // 무통장 기본 order_placed, 카드 기본 payment_complete
    await expect(page.getByTestId('payment-method-mileage-timing-dbank')).toHaveValue('order_placed');
    await expect(page.getByTestId('payment-method-mileage-timing-card')).toHaveValue('payment_complete');
  });
});
