/**
 * 클레임 신청 수량 칸이 잔여 수량을 넘겨 쳐도 상한으로 되돌아오는지 검증 (#599 S2-D9).
 *
 * 결함: `updateClaimQuantityHandler` 는 state 를 잔여 수량으로 자르지만, **클램프 결과가
 *   state 에 들어 있던 값과 같으면** state 가 안 바뀌어 리액트가 입력 칸을 다시 그리지 않았다.
 *   사용자가 친 값이 칸에 그대로 남고 전송만 상한 값으로 나간다 — **화면이 말하는 수량과
 *   실제 신청 수량이 어긋난다.**
 *
 *   실측(2026-09-15 · 주문 754 · 에코백 가용 2):
 *     입력 3  → DOM 2  · state 2   (state 1→2 변화 → 리렌더 → 칸이 따라옴)
 *     입력 99 → DOM 99 · state 2   ← 결함
 *     입력 2  → DOM 2  · state 2
 *     전송 본문 {"items":[{"order_option_id":1049,"quantity":2}], …}
 *
 *   서버·DB 는 언제나 클램프 값이라 금액은 안전했다. 위험한 것은 고객이 **99개를 반품한다고
 *   믿은 채 제출**한다는 점이다.
 *
 * 왜 E2E 인가: 이 결함은 **리액트가 DOM 값을 되돌려 놓는 것**이 본질이라 jsdom 단위 테스트로는
 *   재현되지 않는다(핸들러 단위 테스트는 state 만 보므로 결함이 있어도 green 이었다).
 *   실제로 `el.value = …` 만으로 고쳤을 때 단위 테스트는 통과했으나 브라우저에서는 여전히
 *   99가 남았다 — 네이티브 setter + input 이벤트로 바꾼 뒤에야 칸이 따라왔다.
 *   그 차이는 브라우저에서만 드러난다.
 *
 * 단위/정적:
 *   - resources/js/__tests__/handlers/claimApplyHandlers.test.ts
 *     가 state 클램프와 (활성 입력이 있을 때의) 칸 동기화를 잠근다.
 *
 * 활성화 경위(#599 세션2 계획서준수검증 결함3): 이 spec 은 오래 `test.skip` 이었다. 사전
 *   작업으로 ① `data-testid` ② 시드 fixture ③ 회원 토큰 fixture 세 가지를 적었으나, 실제로
 *   필요했던 것은 ①뿐이었다 — 이 스위트는 DB 를 시드하지 않고 `mockClaimable()` 로 응답을
 *   가로채고, 회원 토큰은 `ecommerce-auth` 가 이미 제공한다. 남은 ①을 레이아웃에 넣고 활성화했다.
 *   **`skip` 인 spec 은 초록으로 세지만 아무것도 잠그지 않는다.**
 *
 * 활성화하며 드러난 것 둘 (S2-D12 · 아래 둘 다 이 spec 을 **거짓 초록**으로 만들던 것이다):
 *   1. **`fill()` 로는 이 결함이 재현되지 않는다.** Playwright 의 fill 은 리액트가 추적하는
 *      내부 값까지 맞춰 놓아 「state 무변화」 조건이 서지 않는다. 수정을 통째로 꺼도 칸이
 *      저절로 상한으로 돌아와 red 가 나지 않았다. `typeQuantity()` 가 네이티브 setter 를
 *      쓰는 이유다. 그리고 순서도 **3 → 99** 여야 한다(실측 순서). `2 → 99` 로 적으면
 *      첫 입력이 state 를 바꾸지 못해 조건이 어긋난다.
 *   2. **모듈 `npm run build` 만으로는 브라우저에 반영되지 않는다.** 화면이 받는 것은
 *      `public/build/ext/{ext.cache_version}/bundles/modules.js` 라는 **병합 번들**이고,
 *      그 버전은 확장 구성이 바뀔 때만 오른다. dist 를 다시 빌드해도 번들은 옛 코드 그대로라
 *      수정 전 코드가 계속 서빙됐다. 캐시 버전을 올리고 `ext-static:publish --force` 를
 *      해야 실제로 바뀐다 — **프론트 수정의 브라우저 재실측은 이 단계까지 해야 유효하다.**
 */
import type { Locator, Page } from '@playwright/test';
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';
import {
  MOCK_ORDER,
  mockClaimable,
  grantFunctionalConsent,
  mockOrder,
  mockPreview,
  waitForApp,
} from '../../fixtures/claim-screens';

/** 잔여 수량 — 상한. 2 여야 「상한을 한 번 찍은 뒤 더 큰 값」축이 선다(1이면 첫 입력이 곧 상한). */
const AVAILABLE = 2;

/** 신청 대상 주문옵션 */
const OPTION_ID = 610;

/**
 * 수량 칸에 값을 **사람이 친 것과 같은 경로**로 넣습니다.
 *
 * `fill()` 을 쓰면 안 된다 — Playwright 의 fill 은 리액트가 추적하는 내부 값까지 함께
 * 맞춰 놓아 **결함 조건(state 무변화)이 만들어지지 않는다.** 실제로 fill 로 적었을 때는
 * 수정을 통째로 꺼도 칸이 저절로 2로 돌아와 **red 가 나지 않았다**(#599 세션2 계획서준수검증
 * 결함3 해소 중 확인). 네이티브 setter 로 쓰고 `input` 을 흘려야 브라우저의 실제 경로가 된다.
 *
 * @param qty 수량 입력 칸 로케이터
 * @param value 넣을 값
 * @returns 완료 Promise
 */
async function typeQuantity(qty: Locator, value: string): Promise<void> {
  // 핸들러는 되돌릴 칸을 `document.activeElement` 로 찾는다 — 포커스가 없으면 아무것도
  // 고치지 않는다. 사람이 칠 때는 늘 포커스가 있으므로 여기서도 먼저 포커스를 준다.
  await qty.focus();

  await qty.evaluate((el, val) => {
    const setter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')?.set;
    setter?.call(el, val);
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
  }, value);
}

/**
 * 반품 신청서를 열고 첫 상품을 골라 수량 칸을 돌려줍니다.
 *
 * @param page Playwright 페이지
 * @returns 수량 입력 칸 로케이터
 */
async function openAndSelect(page: Page): Promise<Locator> {
  await page.goto(`/mypage/orders/${encodeURIComponent(MOCK_ORDER.number)}/claims/apply/return`);
  await waitForApp(page);

  await expect(page.locator('#claim_apply_items')).toBeVisible({ timeout: 30_000 });
  await page.locator('#claim_apply_items input[type="checkbox"]').last().check();

  const qty = page.locator('[data-testid="claim-qty-0"]');
  await expect(qty).toBeEnabled({ timeout: 15_000 });

  return qty;
}

test.describe('#599 S2-D9 클레임 수량 칸 클램프', () => {
  test.beforeEach(async ({ page, memberToken }) => {
    await grantFunctionalConsent(page);
    await authenticatePage(page, memberToken);
    await mockOrder(page);
    await mockClaimable(page, [
      {
        option: { id: OPTION_ID, name: '에코백', optionName: '기본', unitPrice: 12_000 },
        availableQuantity: AVAILABLE,
        types: ['return', 'exchange'],
      },
    ]);
    await mockPreview(page, () => ({
      claim_type: 'return',
      requires_payment: false,
      shipping_fee: { fee: 0, extra_fee: 0, groups: [] },
      price_difference: 0,
      additional_product_amount: 0,
      refund_product_amount: 12_000,
      total_charge_amount: 0,
      adjustment: { refund_total: 12_000 },
      items: [{ order_option_id: OPTION_ID, price_difference: 0 }],
    }));
  });

  /**
   * @scenario quantity_input=already_at_max
   *
   * @effects claim_quantity_input_stays_clamped_when_state_is_unchanged
   */
  test('이미 상한인 상태에서 더 큰 값을 쳐도 칸이 상한으로 되돌아온다', async ({ page }) => {
    const qty = await openAndSelect(page);

    // ① 상한을 **넘는** 값을 먼저 친다. state 가 1→2 로 바뀌므로 리렌더가 칸을 2로 고쳐 준다.
    //    (실측 순서와 같다 — 주문 754 에서 3 을 친 뒤 99 를 쳤다.)
    await typeQuantity(qty, '3');
    await expect(qty, '1차 클램프부터 실패했습니다 — state 변화 경로가 깨졌습니다').toHaveValue(
      String(AVAILABLE),
      { timeout: 10_000 },
    );

    // ② 이제 state 는 이미 상한이다. 여기서 더 큰 값을 치면 클램프 결과가 state 와 같아
    //    **리렌더가 일어나지 않는다** — 핸들러가 칸을 직접 되돌리지 않으면 99가 남는다.
    await typeQuantity(qty, '99');

    await expect(
      qty,
      '수량 칸에 상한 초과 값이 남았습니다 — 화면은 99개를 반품한다고 말하는데 전송은 ' +
        `${AVAILABLE}개로 나갑니다(S2-D9). 클램프 결과가 state 와 같으면 리렌더가 일어나지 ` +
        '않으므로, 핸들러가 네이티브 setter + input 이벤트로 칸을 직접 되돌려야 합니다.',
    ).toHaveValue(String(AVAILABLE), { timeout: 10_000 });
  });

  /**
   * 상한 아래 수량은 **건드리지 않는다** — 대조군.
   *
   * 이것이 없으면 위 단언은 「칸을 늘 상한으로 덮어쓴다」는 구현으로도 통과한다.
   * 그 구현은 사용자가 일부 수량만 신청할 수 없게 만든다.
   *
   * @scenario quantity_input=below_max
   *
   * @effects claim_quantity_input_stays_clamped_when_state_is_unchanged
   */
  test('상한 아래 수량은 그대로 남는다', async ({ page }) => {
    const qty = await openAndSelect(page);

    await typeQuantity(qty, '3');
    await expect(qty).toHaveValue(String(AVAILABLE), { timeout: 10_000 });

    // 상한에서 1로 내린다 — 클램프 대상이 아니므로 그대로여야 한다.
    await typeQuantity(qty, '1');

    await expect(
      qty,
      '상한 아래 수량이 상한으로 덮어써졌습니다 — 사용자가 일부 수량만 신청할 수 없습니다.',
    ).toHaveValue('1', { timeout: 10_000 });
  });
});
