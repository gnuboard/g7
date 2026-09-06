/**
 * 추가옵션 추가금의 표시통화 환산 — 추가금이 상품가·총액과 **같은 통화**로 표기되고
 * 합계에도 그 통화로 더해지는지 검증. 템플릿 sirsoft-basic (유저 화면).
 *
 * @scenario currency-symbol-display
 * @effects additional_option_adjustment_uses_configured_symbol,
 *          detail_total_converts_additional_option_amount,
 *          cart_line_additional_option_uses_display_currency,
 *          checkout_line_additional_option_uses_display_currency
 *
 * 배경(회귀): 추가옵션 추가금은 쇼핑몰 기본 통화 기준으로 저장되는데, 서버가 그 값을
 * 통화별로 환산해 주지 않았고 화면 합계 계산은 그 값을 KRW 로 간주했다. 기본통화가 KRW 인
 * 쇼핑몰에서만 우연히 맞았고, 기본통화 JPY + 표시통화 KRW 인 쇼핑몰에서는
 * "환산된 상품가 + 환산 안 된 추가금" 이라는 통화가 섞인 합계가 나왔다
 * (상품가 ₩114,000 + 추가금 ¥5,000 → ₩119,000 표기).
 *
 * **불변조건**: 한 화면 안에서 추가금 표기의 통화 = 그 상품가·총액 표기의 통화.
 * 어느 통화가 기본이든 성립해야 하므로 이 spec 은 통화 코드를 박지 않는다 —
 * 화면에서 읽은 총액의 통화 표식과 대조한다. 기본통화 = 표시통화인 쇼핑몰에서는
 * 대조가 자명하게 성립하므로, 결함을 실제로 잡으려면 둘이 다른 설정이 필요하다.
 * 그 상태를 이 spec 자신이 만든다 — 헤더 통화 셀렉터로 **기본통화가 아닌 통화**로
 * 전환한 뒤 검증하고, 전환할 통화가 하나도 없으면 사유와 함께 스킵한다.
 *
 * 이 spec 은 시드를 만들지 않는다 — 공개 상품 목록에서 "메인 옵션 2개 이상 + 추가금이
 * 양수인 추가옵션" 상품을 찾아 쓰고, 없으면 개별 테스트가 사유와 함께 스킵된다.
 *
 * 매트릭스:
 *   T1 상품상세 추가옵션 선택지 라벨 — 총액과 같은 통화로 표기
 *   T2 상품상세 "추가옵션 금액" — 총액과 같은 통화 (기준통화 표식이 남지 않는다)
 *   T3 장바구니 줄 — 추가옵션 라벨이 상세에서 본 표시통화와 같은 통화
 *   T4 주문서 줄 — 동일
 *   T5 통화 전환 — 표시통화를 바꾸면 추가금 표기가 함께 따라간다
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';
import type { Page } from '@playwright/test';
import {
  findAdditionalOptionProduct,
  pickOption,
  pickAllMainOptions,
  escapeRegExp,
  switchToOtherCurrency,
  type AddOptProduct,
} from '../../fixtures/shop-additional-option-lookup';

/**
 * 표기 문자열에서 **통화**를 뽑는다 (글리프가 아니다).
 *
 * KRW 는 한 화면 안에서도 두 표기가 공존한다 — 총액은 `₩142,500`, 추가금 라인은
 * `+28,500원` 이다. 앞은 다중통화 포맷터, 뒤는 레이아웃의 지역 포맷 분기가 만든다.
 * 이 spec 이 재는 불변조건은 "추가금과 총액이 **같은 통화**인가" 이므로 둘을 같은 값으로
 * 정규화한다. 글리프까지 맞추라고 요구하면 통화가 섞이지 않은 화면도 빨갛게 된다.
 *
 * @param text 화면에서 읽은 금액 표기
 * @return 통화 코드 성격의 표식 (찾지 못하면 null)
 */
function currencyMarkOf(text: string): string | null {
  if (text.includes('원') || text.includes('₩')) return 'KRW';
  const symbol = text.match(/[$¥€元]/);
  if (!symbol) return null;
  return { $: 'USD', '¥': 'JPY', '€': 'EUR', 元: 'CNY' }[symbol[0]] ?? symbol[0];
}

/**
 * 상품상세에서 메인 옵션 전부 + 추가금이 있는 추가옵션을 고른다.
 *
 * @param page Playwright 페이지
 * @param product 대상 상품
 * @return 없음
 */
async function selectOptionsWithAdditional(page: Page, product: AddOptProduct): Promise<void> {
  await pickAllMainOptions(page, product.mainValues);
  await pickOption(page, `add-option-0-${product.groupId}`, new RegExp(escapeRegExp(product.valueName)));
}

test.describe('추가옵션 추가금 표시통화 환산', () => {
  test('T1/T2 상품상세: 추가옵션 라벨과 추가금 합계가 총액과 같은 통화로 표기된다', async ({ page }) => {
    await page.goto('/shop');
    const product = await findAdditionalOptionProduct(page);
    test.skip(product === null, '메인 옵션 2개 이상 + 추가옵션을 가진 공개 상품이 없어 검증할 수 없습니다');
    test.skip(
      (product?.priceAdjustment ?? 0) <= 0,
      '추가금이 양수인 추가옵션 선택지가 없어 통화 표기를 검증할 수 없습니다',
    );

    await page.goto(product!.url);
    const switched = await switchToOtherCurrency(page);
    test.skip(switched === null, '기본통화 외 표시통화가 없어 기본통화 ≠ 표시통화 상태를 만들 수 없습니다');

    await selectOptionsWithAdditional(page, product!);

    const totalMark = currencyMarkOf(await page.getByTestId('purchase-total').innerText());
    expect(totalMark, '총액에서 통화 표식을 읽을 수 있어야 대조가 성립한다').not.toBeNull();

    // T1 — 선택지 라벨의 추가금이 총액과 같은 통화
    const label = await page.getByTestId(`add-option-0-${product!.groupId}`).innerText();
    expect(currencyMarkOf(label), '추가옵션 선택지 라벨의 추가금이 총액과 다른 통화로 표기됐다').toBe(totalMark);

    // T2 — "추가옵션 금액" 합계가 총액과 같은 통화
    const amount = await page.getByTestId('additional-options-amount').innerText();
    expect(currencyMarkOf(amount), '추가옵션 금액 합계가 총액과 다른 통화로 표기됐다').toBe(totalMark);
  });

  test('T3 장바구니: 추가옵션 줄이 상세에서 본 표시통화로 표기된다', async ({ page }) => {
    await page.goto('/shop');
    const product = await findAdditionalOptionProduct(page);
    test.skip(product === null, '메인 옵션 2개 이상 + 추가옵션을 가진 공개 상품이 없어 검증할 수 없습니다');
    test.skip(
      (product?.priceAdjustment ?? 0) <= 0,
      '추가금이 양수인 추가옵션 선택지가 없어 통화 표기를 검증할 수 없습니다',
    );

    await page.goto(product!.url);
    const switched = await switchToOtherCurrency(page);
    test.skip(switched === null, '기본통화 외 표시통화가 없어 기본통화 ≠ 표시통화 상태를 만들 수 없습니다');

    await selectOptionsWithAdditional(page, product!);
    const totalMark = currencyMarkOf(await page.getByTestId('purchase-total').innerText());

    const [response] = await Promise.all([
      page.waitForResponse((r) => r.url().includes('/cart') && r.request().method() === 'POST'),
      page.getByTestId('add-to-cart').click(),
    ]);
    expect(response.status(), '담기가 성공해야 장바구니를 확인할 수 있다').toBeLessThan(300);

    await page.goto('/shop/cart');
    const line = page.getByTestId('cart-item-additional-option').first();
    await expect(line, '장바구니에 추가옵션 줄이 있어야 한다').toBeVisible();
    expect(
      currencyMarkOf(await line.innerText()),
      '장바구니 추가옵션 줄이 표시통화가 아닌 통화로 표기됐다',
    ).toBe(totalMark);
  });

  test('T4 주문서: 추가옵션 줄이 표시통화로 표기된다', async ({ page, userToken }) => {
    await authenticatePage(page, userToken);
    await page.goto('/shop');
    const product = await findAdditionalOptionProduct(page);
    test.skip(product === null, '메인 옵션 2개 이상 + 추가옵션을 가진 공개 상품이 없어 검증할 수 없습니다');
    test.skip(
      (product?.priceAdjustment ?? 0) <= 0,
      '추가금이 양수인 추가옵션 선택지가 없어 통화 표기를 검증할 수 없습니다',
    );

    await page.goto(product!.url);
    const switched = await switchToOtherCurrency(page);
    test.skip(switched === null, '기본통화 외 표시통화가 없어 기본통화 ≠ 표시통화 상태를 만들 수 없습니다');

    await selectOptionsWithAdditional(page, product!);
    const totalMark = currencyMarkOf(await page.getByTestId('purchase-total').innerText());

    // 주문서는 "바로 구매" 로 진입한다 — 장바구니를 거치지 않아 사전 적재가 필요 없다
    await Promise.all([
      page.waitForResponse((r) => r.url().includes('/checkout') && r.request().method() === 'POST'),
      page.getByTestId('buy-now').click(),
    ]);
    await page.waitForURL(/\/shop\/checkout/, { timeout: 30_000 });

    const line = page.getByTestId('checkout-item-additional-option').first();
    await expect(line, '주문서에 추가옵션 줄이 있어야 한다').toBeVisible();
    expect(
      currencyMarkOf(await line.innerText()),
      '주문서 추가옵션 줄이 표시통화가 아닌 통화로 표기됐다',
    ).toBe(totalMark);
  });

  test('T5 통화 전환: 표시통화를 바꾸면 추가금 표기가 함께 따라간다', async ({ page }) => {
    await page.goto('/shop');
    const product = await findAdditionalOptionProduct(page);
    test.skip(product === null, '메인 옵션 2개 이상 + 추가옵션을 가진 공개 상품이 없어 검증할 수 없습니다');
    test.skip(
      (product?.priceAdjustment ?? 0) <= 0,
      '추가금이 양수인 추가옵션 선택지가 없어 통화 표기를 검증할 수 없습니다',
    );

    await page.goto(product!.url);
    const first = await switchToOtherCurrency(page);
    test.skip(first === null, '기본통화 외 표시통화가 없어 전환을 검증할 수 없습니다');

    await selectOptionsWithAdditional(page, product!);
    const before = await page.getByTestId('additional-options-amount').innerText();
    const beforeMark = currencyMarkOf(before);

    const second = await switchToOtherCurrency(page);
    test.skip(second === null || second === first, '전환할 세 번째 통화가 없어 검증할 수 없습니다');

    await expect
      .poll(async () => currencyMarkOf(await page.getByTestId('additional-options-amount').innerText()))
      .not.toBe(beforeMark);
    // 전환 후에도 총액과 같은 통화여야 한다 (한쪽만 따라가면 통화가 섞인다)
    const totalMark = currencyMarkOf(await page.getByTestId('purchase-total').innerText());
    expect(
      currencyMarkOf(await page.getByTestId('additional-options-amount').innerText()),
      '통화 전환 후 추가금과 총액의 통화가 어긋났다',
    ).toBe(totalMark);
  });
});
