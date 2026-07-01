/**
 * 관리자 배송정책 통화 — 상점 기본 통화 고정 / 읽기전용 표시 (skeleton, placeholder).
 *
 * @scenario mp08-shipping-policy-currency
 * @effects currency_forced_to_default_on_create,
 *          currency_forced_to_default_on_update,
 *          submitted_currency_ignored,
 *          omitted_currency_defaults_to_base,
 *          backfill_unifies_existing_rows,
 *          backfill_supports_non_krw_default,
 *          backfill_idempotent,
 *          form_shows_readonly_default_label,
 *          form_currency_input_removed
 *
* 배송정책 국가별 설정의 통화는 상품 등록과 동일하게 상점 기본 통화로 서버가 강제한다.
 *   관리자 입력값은 무시되고 폼은 통화를 읽기전용으로 표시한다. 배송비는 항상 기본 통화 정수로
 *   합산되므로 정책별 통화 분리는 합계 단위 혼합을 유발해 금지한다. 기존 데이터는 백필로 통일한다.
 *
 * e2e:allow 배송정책 통화 고정(Select → 읽기전용 라벨 표시 + 서버 강제)의 회귀를 단위/레이아웃
 *           테스트가 구조적으로 차단한다.
 *           - 서버 강제: ShippingPolicyServiceTest 가 create/update 시 USD/EUR 전송이 무시되고
 *             country_setting 통화가 기본 통화로 고정됨을 검증한다.
 *           - 백필: BackfillShippingPolicyCurrencyTest 가 기존 비-기본 통화 행 통일 + 비-KRW 기본
 *             통화 + idempotent 를 검증한다.
 *           - 폼 표시: shippingPolicyFormLayouts.test.tsx 가 통화 Select 제거(currencyOptions /
 *             updateCountryField currency_code 분기 부재) + 기본 통화 라벨 읽기전용 표시
 *             (defaultCurrencyLabel + currency_code_fixed_hint)를 회귀 차단한다.
 *           본 placeholder spec(test.describe.skip)은 data-testid 보강 후 활성화된다.
 *
 * 본 spec 은 다음 사전 작업 완료 후 활성화한다 (data-testid 보강):
 *   1. 국가 탭 통화 표시 영역에 data-testid="country-currency-display"
 *   2. 통화 안내 문구에 data-testid="country-currency-hint"
 *   3. 통화 입력 Select(제거됨 — 부재 검증용 셀렉터 유지) data-testid="country-currency-select"
 *   4. test.describe.skip → test.describe 변경
 *
 * 매트릭스(시나리오 매니페스트 mp08-shipping-policy-currency.yaml 와 1:1):
 *   - 폼 진입            : 통화 입력 Select 부재 + 기본 통화 라벨 읽기전용 표시
 *   - 안내 문구          : "통화는 상점 기본 통화로 자동 적용됩니다" 표시
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';

const NEW_POLICY_URL = '/admin/ecommerce/shipping-policies/create';

test.describe.skip('관리자 배송정책 통화 읽기전용 표시 (placeholder — data-testid 보강 후 활성화)', () => {
  test('통화 입력 Select 가 없고 기본 통화가 읽기전용으로 표시된다', async ({ page, settingsToken }) => {
    await authenticatePage(page, settingsToken);
    await page.goto(NEW_POLICY_URL);

    // 통화 입력 Select 는 제거됨 (자유 지정 금지)
    await expect(page.getByTestId('country-currency-select')).toHaveCount(0);

    // 기본 통화 라벨이 읽기전용으로 표시됨
    await expect(page.getByTestId('country-currency-display').first()).toBeVisible({ timeout: 10_000 });
  });

  test('통화 고정 안내 문구가 표시된다', async ({ page, settingsToken }) => {
    await authenticatePage(page, settingsToken);
    await page.goto(NEW_POLICY_URL);

    await expect(page.getByTestId('country-currency-hint').first()).toBeVisible({ timeout: 10_000 });
  });
});
