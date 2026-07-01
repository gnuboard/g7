/**
 * 통화별 사용 언어(locales) 저장 — 검증 + 에러 표시 회귀.
 *
 * 배경: 시드 기본값이 사이트 지원 언어(ko/en/ja) 외 locale(zh, de/fr/es/it)을 박아두어,
 * 통화 설정 저장이 검증(in:supported_locales)에서 영구 차단되고("설정 저장에 실패했습니다"),
 * 에러가 다른 폼처럼 필드 강조 + 친화 필드명 메시지로 안 나오던 결함.
 *
 * 본 spec 은 환경설정 폼 컴포넌트에 다음 testid 보강 후 활성화한다:
 *   - data-testid="ecommerce-settings-form"
 *   - data-testid="settings-language-currency-tab"
 *   - data-testid="settings-save-button"
 *   - 통화 locales TagInput 에 data-testid="currency-locales-{code}"
 *   - 통화 locales 에러 Span 에 data-testid="currency-locales-error-{code}"
 * 그리고 test.describe.skip → test.describe 변경.
 *
 * @scenario currency_locale_save
 * @effects settings_persist
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';

test.describe.skip('통화별 사용 언어 저장', () => {
  // @scenario currency_locale_save:supported_locale
  // @effects settings_persist
  test('지원 언어로 지정 시 저장 성공', async ({ page, settingsToken }) => {
    await authenticatePage(page, settingsToken);
    await page.goto('/admin/ecommerce/settings');
    await page.getByTestId('settings-language-currency-tab').click();

    // 기본값(원=한국어, 그 외=영어) 상태에서 저장이 통과해야 함
    await page.getByTestId('settings-save-button').click();

    // 저장 실패 토스트가 뜨지 않아야 함
    await expect(page.getByText('설정 저장에 실패했습니다.')).not.toBeVisible();
    await expect(page.getByTestId('currency-locales-error-CNY')).not.toBeVisible();
  });

  // @scenario currency_locale_save:unsupported_locale
  // @effects settings_persist
  test('지원하지 않는 언어 선택 시 해당 입력칸 강조 + 친화 필드명 메시지', async ({ page, settingsToken }) => {
    await authenticatePage(page, settingsToken);
    await page.goto('/admin/ecommerce/settings');
    await page.getByTestId('settings-language-currency-tab').click();

    // (테스트 픽스처가 CNY locales 에 지원 외 값을 주입한 상태 가정) 저장 시도
    await page.getByTestId('settings-save-button').click();

    // 결함 B 회귀: 해당 입력칸 강조 + raw 경로가 아닌 친화 필드명 메시지
    const errorEl = page.getByTestId('currency-locales-error-CNY');
    await expect(errorEl).toBeVisible({ timeout: 10_000 });
    await expect(errorEl).toContainText('사용 언어');
    await expect(errorEl).not.toContainText('language_currency.currencies');
  });
});
