/**
 * 관리자 마일리지 설정 — 통화 규칙 인라인 추가 확정 가드 (skeleton, placeholder).
 *
 * @scenario settings_currency_add_confirm_guard
 * @effects settings_currency_add_requires_check_confirm_before_persisting_to_rules,
 *          settings_save_blocked_with_warning_when_currency_add_row_unconfirmed
 *
 * 배경: 통화 추가는 2단계다 — ① 인라인 입력 행에 통화 입력(임시 객체 newMileageCurrency)
 *       → ② 체크(✓) 버튼으로 currency_rules 배열에 확정. 입력만 하고 체크 없이 저장하면
 *       임시 입력이 배열에 들어가지 않아 저장 시 조용히 사라지는 회귀가 있었다.
 *       저장 sequence 는 미확정 상태에서 차단 + 경고 토스트를 띄운다.
 *
 * 본 spec 은 다음 사전 작업 완료 후 활성화한다 (data-testid 보강):
 *   1. 마일리지 탭 통화 추가 버튼(add_currency_rule_button)에 data-testid="mileage-currency-add"
 *   2. 인라인 입력 행 통화코드 Input 에 data-testid="mileage-new-currency-code"
 *   3. 인라인 입력 행 확정(체크) 버튼에 data-testid="mileage-new-currency-confirm"
 *   4. 저장 버튼(save_button)에 data-testid="ecommerce-settings-save"
 *   5. 통화 규칙 테이블 행에 통화코드별 식별 가능한 마커(예: data-testid="mileage-currency-row-USD")
 *   6. test.describe.skip → test.describe 변경
 *
 * 매트릭스(시나리오 매니페스트 ecommerce-mileage.yaml settings_currency_add_confirm_guard 와 1:1):
 *   - 미확정(체크 미클릭) 저장 : 경고 토스트 + 저장 차단(성공 토스트 미노출, USD 행 미생성)
 *   - 확정(체크 클릭) 저장     : USD 행 currency_rules 에 push → 저장 성공 → 새로고침 후 USD 유지
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';

const SETTINGS_URL = '/admin/ecommerce/settings?tab=mileage';

test.describe.skip('관리자 마일리지 설정 — 통화 추가 확정 가드 (placeholder — data-testid 보강 후 활성화)', () => {
  test('미확정(체크 미클릭) 저장 — 경고 토스트 + 저장 차단', async ({ page, settingsToken }) => {
    await authenticatePage(page, settingsToken);
    await page.goto(SETTINGS_URL);

    // 통화 추가 → 인라인 입력 행 열림
    await page.getByTestId('mileage-currency-add').click();
    await page.getByTestId('mileage-new-currency-code').fill('USD');

    // 체크(확정) 버튼을 누르지 않고 바로 저장
    await page.getByTestId('ecommerce-settings-save').click();

    // 경고 토스트 노출, USD 행은 생성되지 않음
    await expect(page.getByText('추가 중인 통화를 먼저 확정')).toBeVisible({ timeout: 5_000 });
    await expect(page.getByTestId('mileage-currency-row-USD')).not.toBeVisible();
  });

  test('확정(체크 클릭) 저장 — USD 가 currency_rules 에 push 되어 저장·유지', async ({
    page,
    settingsToken,
  }) => {
    await authenticatePage(page, settingsToken);
    await page.goto(SETTINGS_URL);

    await page.getByTestId('mileage-currency-add').click();
    await page.getByTestId('mileage-new-currency-code').fill('USD');

    // 체크(확정) 버튼 클릭 → 배열에 push
    await page.getByTestId('mileage-new-currency-confirm').click();
    await expect(page.getByTestId('mileage-currency-row-USD')).toBeVisible();

    // 저장 → 성공
    await page.getByTestId('ecommerce-settings-save').click();
    await expect(page.getByText('설정이 저장되었습니다')).toBeVisible({ timeout: 10_000 });

    // 새로고침 후에도 USD 유지
    await page.reload();
    await expect(page.getByTestId('mileage-currency-row-USD')).toBeVisible({ timeout: 10_000 });
  });
});

/*
 * 마일리지 단일 차감 시점(mileage.deduction_timing) 저장 spec 은 제거됨 (MP06).
 * 차감 시점은 결제수단별(order_settings.payment_methods.*.mileage_deduction_timing)로 이전되어
 * mp06-coupon-and-mileage-timing.spec.ts 가 결제수단별 컨트롤·disabled 연동을 커버한다.
 */
