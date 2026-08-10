/**
 * 체크아웃 현금영수증 신청 폼 E2E (#454 S3 / W-1).
 *
 * 이 spec 이 검증하는 것은 "슬롯에 주입된 모듈 폼이 브라우저에서 실제로 동작하는가" 다.
 * 레이아웃 JSON 단위 테스트(cashReceiptUi.test.tsx)는 JSON 구조만 읽으므로,
 * 확장 주입 → 렌더 → 상호작용 → 상태 반영의 실경로는 브라우저에서만 확인된다.
 *
 * 실측으로만 드러난 결함이 실제로 있었다(계획서 §12-3): 무통장 결제 상태가 waiting_deposit 이
 * 아니라 ready 였고, 유닛 픽스처는 내가 넣어준 값이라 green 이었다. 그래서 본 spec 은
 * 픽스처 대신 라이브 API/DOM 만 신뢰한다.
 *
 * 시드는 도메인 seeder(playwright:seed-ecommerce, 아직 stub)를 쓰지 않는다.
 * 대신 사용자가 실제로 밟는 경로 — 인증 토큰 발급 → 카트 담기 API → 체크아웃 진입 — 을 그대로 밟는다.
 * 검증 대상(현금영수증 폼)을 우회하지 않으므로 "강제 API 로 통과 선언" 에 해당하지 않는다.
 *
 * @scenario cash-receipt-ui-and-refund-bank
 * @effects checkout_slot_renders_only_inside_dbank_block,
 *          checkout_slot_hidden_when_provider_unset,
 *          checkout_fields_mount_on_request_toggle,
 *          checkout_purpose_switch_resets_invalid_identifier,
 *          checkout_identifier_type_options_by_purpose,
 *          checkout_identifier_cleared_on_type_change,
 *          checkout_state_reset_on_payment_method_change,
 *          new_ui_uses_portable_only,
 *          new_ui_has_no_tailwind_breakpoint
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';
import type { Page } from '@playwright/test';

/**
 * 카트 담기 로케일.
 *
 * CartService::bulkAddToCart 는 저장된 다국어 JSON 이 아니라 현재 로케일로 평탄화된 맵
 * (`{"색상":"화이트"}`) 과 비교한다(getLocalizedOptionValues). 그래서 요청 로케일을 ko 로 고정한다.
 */
const CART_LOCALE = 'ko';

/** 발급수단 드롭다운의 항목 라벨 (ko 고정 — beforeEach 가 g7_locale 을 ko 로 pin 한다) */
const IDENTIFIER_LABEL = {
  phone: '휴대폰번호',
  card: '현금영수증 카드번호',
  business: '사업자등록번호',
} as const;

const SLOT = '#ext_checkout_cash_receipt';
const FIELDS = '#ext_checkout_cash_receipt_fields';
const TOGGLE = '#ext_checkout_cash_receipt_toggle';
const PURPOSE = '#ext_checkout_cash_receipt_purpose';
const ID_TYPE = '#ext_checkout_cash_receipt_identifier_type';
const IDENTIFIER = '#ext_checkout_cash_receipt_identifier';

/**
 * 카트를 비우고 상품 1건을 담은 뒤 임시주문을 생성한다.
 *
 * 체크아웃 화면은 카트에 상품이 있는 것만으로는 열리지 않는다 — 카트의 "주문하기" 가
 * POST /checkout 으로 만든 임시주문이 있어야 하고, 없으면 "주문 정보를 찾을 수 없습니다"
 * 모달이 뜨고 카트로 되돌아간다. 그래서 사용자가 밟는 두 단계를 그대로 밟는다.
 */
async function seedCheckout(page: Page): Promise<void> {
  const result = await page.evaluate(
    async ({ locale }) => {
      const token = localStorage.getItem('auth_token');
      const headers = {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'Accept-Language': locale,
        Authorization: `Bearer ${token}`,
      };

      // 담을 상품을 런타임에 찾는다. 상품 ID 를 고정하면 시드가 다른 환경에서
      // 전부 실패하고, 그 실패가 "현금영수증 폼 결함" 으로 오해된다.
      const list = await fetch(
        '/api/modules/sirsoft-ecommerce/products?per_page=30',
        { headers }
      ).then((r) => r.json());

      const candidates = (list?.data?.data ?? list?.data ?? []) as Array<{ id: number }>;
      if (candidates.length === 0) {
        return { step: 'product-list', status: 0, body: '판매 중인 상품이 없습니다.' };
      }

      await fetch('/api/modules/sirsoft-ecommerce/cart/all', { method: 'DELETE', headers });

      // 상품마다 옵션 구성이 다르므로 상세를 읽어 판매 가능한 첫 옵션을 그대로 쓴다.
      // option_values_localized 는 서버가 현재 로케일로 평탄화해 내려준 맵이며,
      // CartService::bulkAddToCart 가 비교하는 형태와 동일하다.
      let added: Response | null = null;
      let lastBody = '';
      for (const candidate of candidates) {
        const detail = await fetch(
          `/api/modules/sirsoft-ecommerce/products/${candidate.id}`,
          { headers }
        ).then((r) => r.json());

        const options = (detail?.data?.options ?? []) as Array<{
          option_values_localized?: Record<string, string>;
          stock_quantity?: number;
        }>;
        // 재고가 없는 옵션을 고르면 주문 생성이 422 로 막혀 검증 대상에 닿지도 못한다.
        const usable = options.find((o) => (o.stock_quantity ?? 0) > 0);
        if (!usable) continue;

        const res = await fetch('/api/modules/sirsoft-ecommerce/cart', {
          method: 'POST',
          headers,
          body: JSON.stringify({
            product_id: candidate.id,
            items: [
              {
                option_values: usable.option_values_localized ?? {},
                quantity: 1,
              },
            ],
          }),
        });
        if (res.ok) {
          added = res;
          break;
        }
        lastBody = await res.text();
      }

      if (!added) {
        return { step: 'cart', status: 422, body: `담을 수 있는 상품 없음: ${lastBody}` };
      }

      const cart = await fetch('/api/modules/sirsoft-ecommerce/cart', { headers }).then((r) => r.json());
      const itemIds = (cart?.data?.items ?? cart?.data ?? []).map((i: { id: number }) => i.id);
      if (itemIds.length === 0) {
        return { step: 'cart-read', status: 0, body: JSON.stringify(cart).slice(0, 300) };
      }

      // 카트의 "주문하기" 버튼과 동일한 호출 (_cart_summary.json)
      const checkout = await fetch('/api/modules/sirsoft-ecommerce/checkout', {
        method: 'POST',
        headers,
        body: JSON.stringify({ item_ids: itemIds }),
      });
      return { step: 'checkout', status: checkout.status, body: await checkout.text() };
    },
    { locale: CART_LOCALE }
  );

  expect(result.status, `${result.step} 단계 실패: ${result.body}`).toBeLessThan(300);
}

/** 결제수단 버튼 — iteration 으로 렌더되며 method.id 로 식별된다. */
const paymentMethod = (page: Page, id: string) =>
  page.getByTestId(`checkout-payment-method-${id}`);

/**
 * GDPR 쿠키 동의 배너를 닫는다.
 *
 * 신규 브라우저 컨텍스트에는 동의 기록이 없어 전체 화면 오버레이가 클릭을 가로챈다.
 * 동의 상태는 서버(/consent/cookie/status)가 SSoT 이므로 localStorage 를 조작하지 않고
 * 실제 사용자와 같이 버튼을 누른다.
 */
async function dismissCookieNotice(page: Page): Promise<void> {
  const necessaryOnly = page.getByRole('button', { name: /Necessary Only|필수만/i });
  const overlay = page.locator('.fixed.inset-0');

  // 배너는 /consent/cookie/status 응답 후 비동기로 마운트되고, 클릭 직후 자기 자신을 떼어낸다.
  // 한 번만 확인하면 (a) 아직 없는 상태를 "없음" 으로 오판하거나 (b) 클릭 재시도가
  // detach 와 겹쳐 실패한다. 오버레이가 사라질 때까지 클릭을 재시도한다.
  for (let attempt = 0; attempt < 3; attempt += 1) {
    if ((await overlay.count()) === 0) return;
    if (!(await necessaryOnly.isVisible().catch(() => false))) {
      await necessaryOnly.waitFor({ state: 'visible', timeout: 5_000 }).catch(() => undefined);
    }
    await necessaryOnly.click({ timeout: 5_000 }).catch(() => undefined);
    const cleared = await expect
      .poll(() => overlay.count(), { timeout: 5_000 })
      .toBe(0)
      .then(() => true, () => false);
    if (cleared) return;
  }

  await expect(overlay, '쿠키 동의 오버레이가 닫히지 않았다').toHaveCount(0);
}

/** 체크아웃으로 이동하고 결제수단 블록이 그려질 때까지 기다린다. */
async function gotoCheckout(page: Page): Promise<void> {
  await page.goto('/shop/checkout');
  await page.waitForLoadState('domcontentloaded');
  await dismissCookieNotice(page);
  // 무통장 버튼이 뜨면 결제수단 섹션 렌더 완료
  await paymentMethod(page, 'dbank').waitFor({ timeout: 30_000 });
}

/** 무통장입금을 선택한다 — 현금영수증 슬롯은 dbank 블록 내부에만 있다. */
async function selectDbank(page: Page): Promise<void> {
  await paymentMethod(page, 'dbank').click();
  await expect(page.locator(SLOT)).toBeVisible();
}

/**
 * 발급수단 드롭다운을 열고 보이는 항목 라벨을 반환한다.
 *
 * 템플릿의 Select 는 네이티브 <select> 가 아니라 portal 로 띄우는 커스텀 드롭다운이다
 * (role=listbox / role=option, value 속성 없음). 그래서 selectOption() 이 동작하지 않고
 * 항목은 라벨 텍스트로만 식별된다.
 */
async function openIdentifierOptions(page: Page): Promise<string[]> {
  await page.locator(ID_TYPE).click();
  const listbox = page.getByRole('listbox');
  await listbox.waitFor({ state: 'visible' });
  return listbox.getByRole('option').allInnerTexts();
}

/** 발급수단 드롭다운에서 항목을 고른다. */
async function chooseIdentifierType(page: Page, key: keyof typeof IDENTIFIER_LABEL): Promise<void> {
  await page.locator(ID_TYPE).click();
  const listbox = page.getByRole('listbox');
  await listbox.waitFor({ state: 'visible' });
  await listbox.getByRole('option', { name: IDENTIFIER_LABEL[key], exact: true }).click();
  await expect(listbox).toBeHidden();
}

test.describe('체크아웃 현금영수증 신청 폼 (무통장 슬롯 주입)', () => {
  test.beforeEach(async ({ page, noPermissionToken }) => {
    // 구매자 역할 — 관리자 권한 없이 상점 화면만 사용한다.
    await authenticatePage(page, noPermissionToken);
    // 드롭다운 항목은 라벨 텍스트로만 식별되므로 앱 로케일을 ko 로 고정한다
    // (기본 브라우저 로케일이 en 이면 라벨이 달라져 spec 이 로케일에 종속된다).
    await page.addInitScript((locale) => localStorage.setItem('g7_locale', locale), CART_LOCALE);
    await page.goto('/shop');
    await page.waitForLoadState('domcontentloaded');
    await dismissCookieNotice(page);
    await seedCheckout(page);
  });

  test('무통장 선택 시에만 현금영수증 슬롯이 렌더된다 (다른 결제수단에서는 0개)', async ({ page }) => {
    await gotoCheckout(page);

    // 무통장 → 슬롯 1개.
    // 활성 결제수단이 무통장 하나뿐이면 진입 시점에 이미 선택되어 있다(슬롯도 이미 렌더).
    // 그래서 "선택 전 0개" 를 단정하지 않는다 — 그건 결제수단 구성에 따라 달라지는 값이다.
    await selectDbank(page);
    await expect(page.locator(SLOT)).toHaveCount(1);

    // 다른 결제수단으로 전환하면 슬롯이 사라진다.
    // 활성 결제수단은 환경설정 가변이므로 dbank 아닌 활성 버튼이 있을 때만 전환을 검증한다.
    // 전수(8종) 확인은 환경설정을 바꿔가며 수행하는 매트릭스 실측의 몫이다 — 여기서 활성화를
    // 조작하면 병렬 워커가 공유하는 상점 설정을 오염시킨다.
    const others = page.locator('[data-testid^="checkout-payment-method-"]:not([data-testid$="-dbank"])');
    if ((await others.count()) === 0) {
      test.info().annotations.push({
        type: 'coverage-gap',
        description: '무통장 외 활성 결제수단이 없어 전환 후 슬롯 소멸은 미검증 (환경설정 의존)',
      });
      return;
    }

    await others.first().click();
    await expect(page.locator(SLOT)).toHaveCount(0);
  });

  test('신청 체크 전에는 입력 필드가 없고, 체크하면 마운트된다', async ({ page }) => {
    await gotoCheckout(page);
    await selectDbank(page);

    await expect(page.locator(FIELDS)).toHaveCount(0);
    await expect(page.locator('[name^="cash_receipt_"]')).toHaveCount(1); // 토글 체크박스만

    await page.locator(TOGGLE).check();

    await expect(page.locator(FIELDS)).toHaveCount(1);
    await expect(page.locator(PURPOSE)).toBeVisible();
    await expect(page.locator(ID_TYPE)).toBeVisible();
    await expect(page.locator(IDENTIFIER)).toBeVisible();

    // 해제하면 다시 사라진다
    await page.locator(TOGGLE).uncheck();
    await expect(page.locator(FIELDS)).toHaveCount(0);
  });

  test('용도에 따라 발급수단 선택지가 달라진다 — 지출증빙에만 사업자등록번호', async ({ page }) => {
    await gotoCheckout(page);
    await selectDbank(page);
    await page.locator(TOGGLE).check();

    // 기본값 = 소득공제 → 2종 (사업자등록번호 없음)
    await expect(page.locator('input[name="cash_receipt_type"][value="income"]')).toBeChecked();
    expect(await openIdentifierOptions(page)).toEqual([
      IDENTIFIER_LABEL.phone,
      IDENTIFIER_LABEL.card,
    ]);
    await page.keyboard.press('Escape');

    // 지출증빙 → 3종 (사업자등록번호 포함)
    await page.locator('input[name="cash_receipt_type"][value="expense"]').check();
    expect(await openIdentifierOptions(page)).toEqual([
      IDENTIFIER_LABEL.business,
      IDENTIFIER_LABEL.phone,
      IDENTIFIER_LABEL.card,
    ]);
  });

  test('지출증빙+사업자등록번호에서 소득공제로 전환하면 발급수단과 번호가 리셋된다', async ({ page }) => {
    await gotoCheckout(page);
    await selectDbank(page);
    await page.locator(TOGGLE).check();

    // 지출증빙 → 사업자등록번호 선택 → 번호 입력
    await page.locator('input[name="cash_receipt_type"][value="expense"]').check();
    await chooseIdentifierType(page, 'business');
    await page.locator(IDENTIFIER).fill('1234567890');
    await expect(page.locator(IDENTIFIER)).toHaveValue('1234567890');

    // 소득공제로 전환 — business 는 소득공제에 쓸 수 없으므로 phone 으로 리셋되고 번호는 비워진다
    await page.locator('input[name="cash_receipt_type"][value="income"]').check();

    await expect(page.locator(ID_TYPE)).toContainText(IDENTIFIER_LABEL.phone);
    await expect(page.locator(IDENTIFIER)).toHaveValue('');
  });

  test('결제수단을 바꾸면 현금영수증 입력이 초기화된다 (이전 수단 값 잔류 금지)', async ({ page }) => {
    // 회귀: 결제수단 버튼이 _local.paymentMethod 만 바꾸고 슬롯이 소유한 _local
    // (checkoutExtraPayload / refundBank*) 은 비우지 않아, 무통장에서 현금영수증을 입력한 뒤
    // 카드로 전환하면 그 값이 주문 생성 POST 에 그대로 실렸다.
    await gotoCheckout(page);
    await selectDbank(page);

    const others = page.locator('[data-testid^="checkout-payment-method-"]:not([data-testid$="-dbank"])');
    if ((await others.count()) === 0) {
      test.info().annotations.push({
        type: 'coverage-gap',
        description: '무통장 외 활성 결제수단이 없어 전환 시 초기화는 미검증 (환경설정 의존)',
      });
      return;
    }

    // 무통장에서 현금영수증 신청 + 번호 입력
    await page.locator(TOGGLE).check();
    await page.locator(IDENTIFIER).fill('01012345678');
    await expect(page.locator(IDENTIFIER)).toHaveValue('01012345678');

    // 다른 결제수단으로 전환 → 슬롯 언마운트
    await others.first().click();
    await expect(page.locator(SLOT)).toHaveCount(0);

    // 무통장으로 돌아오면 이전 입력이 남아 있지 않다 (신청 토글이 꺼진 초기 상태)
    await selectDbank(page);
    await expect(page.locator(SLOT)).toHaveCount(1);
    await expect(page.locator(TOGGLE)).not.toBeChecked();
    await expect(page.locator(FIELDS)).toHaveCount(0);
  });

  test('발급수단을 바꾸면 이전에 입력한 번호가 비워진다', async ({ page }) => {
    await gotoCheckout(page);
    await selectDbank(page);
    await page.locator(TOGGLE).check();

    await page.locator(IDENTIFIER).fill('01012345678');
    await expect(page.locator(IDENTIFIER)).toHaveValue('01012345678');

    await chooseIdentifierType(page, 'card');
    await expect(page.locator(IDENTIFIER)).toHaveValue('');
  });

  test('반응형은 portable 단일 오버라이드로만 분기한다 (md:/lg: 브레이크포인트 없음)', async ({ page }) => {
    await gotoCheckout(page);
    await selectDbank(page);
    await page.locator(TOGGLE).check();

    // 신설 UI 안에 Tailwind 브레이크포인트 클래스가 없어야 한다 (§6-0)
    const breakpointClasses = await page.locator(SLOT).evaluate((root) =>
      Array.from(root.querySelectorAll('*'))
        .flatMap((el) => Array.from(el.classList))
        .filter((c) => /^(sm|md|lg|xl|2xl):/.test(c))
    );
    expect(breakpointClasses).toEqual([]);

    const direction = () => page.locator(PURPOSE).evaluate((el) => getComputedStyle(el).flexDirection);
    const columns = () => page.locator('#ext_checkout_cash_receipt_identifier_row')
      .evaluate((el) => getComputedStyle(el).gridTemplateColumns.split(' ').length);

    // 뷰포트 변경 후 responsive 오버라이드가 리렌더될 때까지 재시도한다 (즉시 읽으면 이전 값).
    const expectLayout = async (width: number, dir: string, cols: number) => {
      await page.setViewportSize({ width, height: 900 });
      await expect.poll(direction, { message: `${width}px flexDirection` }).toBe(dir);
      await expect.poll(columns, { message: `${width}px grid columns` }).toBe(cols);
    };

    // 데스크톱(1091px) — 가로 배치 / 2열
    await expectLayout(1091, 'row', 2);

    // 태블릿(900px)·모바일(390px) 은 동일 구간(portable 0~1023) — 세로 배치 / 1열
    await expectLayout(900, 'column', 1);
    await expectLayout(390, 'column', 1);
  });
});
