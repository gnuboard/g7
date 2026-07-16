/**
 * 체크아웃 간편결제 브랜드 마크 데이터 기반 렌더 + 애플페이 iOS 게이팅.
 *
 * 3사(kginicis/nhnkcp/nicepayments) 동시 활성 체크아웃에서, 각 간편결제 버튼의 브랜드 마크
 * (색 배지 / 브랜드 SVG 로고)가 카탈로그 _cached_brand_mark 데이터로 렌더되고(injector 후처리
 * 없이), 애플페이는 iOS 기기에서만 노출되는지 검증. 템플릿 sirsoft-basic (유저 화면).
 * (skeleton, placeholder)
 *
 * @effects badge_renders_text_and_color,
 *          svg_renders_inline_logo,
 *          svg_dangerous_content_sanitized,
 *          none_form_falls_back_to_icon,
 *          applepay_shown_on_ios,
 *          applepay_hidden_on_non_ios,
 *          non_ios_methods_unaffected,
 *          injectors_removed
 *
 * e2e:allow 브랜드 마크 렌더/게이팅 — 카탈로그→레이아웃 계약을 단위/렌더/리소스 테스트로 1차 차단하고,
 *           브라우저 회귀는 본 placeholder(test.describe.skip)가 data-testid 보강 + 3-PG 동시활성 시드 후 활성화될 때 검증한다.
 *           현재 커버리지:
 *           (1) 카탈로그 보강 — modules/_bundled/sirsoft-ecommerce/tests/Unit/Services/EcommerceSettingsOrderSettingsTest.php
 *               (brand_mark→_cached_brand_mark 병합, requires_ios 전달, 마크 없는 builtin=null) green.
 *           (2) 등록 리스너 — plugins/_bundled/sirsoft-pay_{nhnkcp,nicepayments,kginicis}/tests/Unit/Listeners/RegisterEasyPayMethodsListenerTest.php
 *               (배지 9종 text/class, SVG 6종 markup, 애플페이만 requires_ios) green.
 *           (3) BrandMark 컴포넌트 — templates/_bundled/sirsoft-basic/src/components/composite/__tests__/BrandMark.test.tsx
 *               (배지/SVG 렌더, SVG script/onload/foreignObject sanitize 제거, 마크 없음→null) green.
 *           (4) 레이아웃 렌더 + iOS 필터 — templates/_bundled/sirsoft-basic/__tests__/layouts/checkout-payment-brand-mark.test.tsx
 *               (BrandMark 노드 바인딩 + Icon 폴백, iteration source 필터가 iOS↔비iOS 로 애플페이 포함/제외) green.
 *           (5) 서버/클라 iOS 게이팅 — tests/Unit/Helpers/DeviceDetectorTest.php + tests/Unit/Middleware/DetectDeviceTest.php
 *               + tests/Unit/Services/SettingsServiceAppConfigTest.php + templates/_bundled/sirsoft-basic/src/handlers/__tests__/deviceCorrection.test.ts
 *               (UA→appConfig.isIos, iPadOS 데스크탑 UA 클라 보정) green.
 *           라이브 검수는 Chrome MCP 실측(3사 브랜드 버튼 공존 + 마크 표시, iOS 에뮬레이션 애플페이 노출/숨김, 스크린샷)으로 기록 예정.
 */
// @scenario mark_form=svg, requires_ios=false, device=ios
// @scenario mark_form=svg, requires_ios=false, device=non_ios
// @scenario mark_form=none, requires_ios=false, device=non_ios
// @scenario mark_form=badge, requires_ios=false, device=non_ios
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';

test.describe.skip('체크아웃 간편결제 브랜드 마크 + 애플페이 iOS 게이팅 (skeleton)', () => {
  test('3사 간편결제 버튼이 브랜드 마크(배지/SVG)로 렌더된다', async ({ page, userToken }) => {
    await authenticatePage(page, userToken);
    await page.goto('/shop/checkout');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    // 활성화 시: 배지 형태(nhnkcp 네이버페이 'N')와 SVG 형태(kginicis 네이버페이 로고)가 함께 렌더
    await expect(page.getByTestId('payment-method-nhnkcp_naverpay').getByText('N')).toBeVisible();
    await expect(page.getByTestId('payment-method-kginicis_naverpay').locator('svg')).toBeVisible();
    // 마크 없는 무통장은 아이콘 폴백
    await expect(page.getByTestId('payment-method-dbank').locator('i')).toBeVisible();
  });

  test('비-iOS 기기에서 애플페이 버튼이 노출되지 않는다', async ({ page, userToken }) => {
    await authenticatePage(page, userToken);
    await page.goto('/shop/checkout');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    // 활성화 시: 데스크탑(비-iOS)에서는 애플페이 수단 카드가 렌더되지 않음
    await expect(page.getByTestId('payment-method-nhnkcp_applepay')).toHaveCount(0);
    // 비-애플페이 수단은 정상 노출
    await expect(page.getByTestId('payment-method-nhnkcp_naverpay')).toBeVisible();
  });

  test('iOS 기기 에뮬레이션에서 애플페이 버튼이 노출된다', async ({ browser }) => {
    // 활성화 시: iPhone UA 컨텍스트에서 애플페이 수단 카드가 렌더됨(서버 UA 게이팅)
    const context = await browser.newContext({
      userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15',
    });
    const page = await context.newPage();
    await page.goto('/shop/checkout');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    await expect(page.getByTestId('payment-method-nhnkcp_applepay')).toBeVisible();
    await context.close();
  });
});
