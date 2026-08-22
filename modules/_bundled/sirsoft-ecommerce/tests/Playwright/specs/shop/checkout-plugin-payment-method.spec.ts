/**
 * 플러그인 결제수단(toss_* 등) 주문 생성 E2E (#454 S4).
 *
 * 이 spec 이 지키는 계약은 하나다:
 *   "플러그인이 등록한 결제수단은 화면에서 독립 항목으로 보이지만,
 *    주문 생성 시에는 코어 결제수단(core_payment_method)으로 번역되어 전송된다."
 *
 * 이 번역이 빠지면 코어 PaymentMethodEnum(card/vbank/dbank/bank/phone/point/deposit/free)이
 * 원시 id(toss_virtual_account)를 거부해 **주문 자체가 422 로 실패**한다 — 결제창에 도달조차 못 한다.
 * 실제로 그 결함이 있었고(계획서 §B-4 미이행), 유닛 테스트는 전부 green 이었다:
 * 레이아웃 JSON 테스트는 body 표현식을 직접 평가하고, 핸들러 테스트는 SDK 매핑만 본다.
 * "체크아웃 카탈로그 → computed 번역 → 서버 검증" 의 실경로는 브라우저에서만 드러난다.
 *
 * 그래서 본 spec 은 픽스처를 심지 않고 라이브 카탈로그/DOM/네트워크만 신뢰한다.
 * PG 결제창(외부 SDK)은 열지 않는다 — 검증 대상은 그 앞단(주문 생성 요청/응답)이다.
 *
 * @scenario toss_payment_methods_vbank_escrow
 * @effects checkout_body_sends_core_payment_method,
 *          non_mapped_method_falls_back_to_raw_id,
 *          order_created_with_core_payment_method,
 *          core_payment_method_preserved_through_settings_merge,
 *          escrow_products_attached_to_virtual_account_sdk_payload
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';
import type { Page } from '@playwright/test';

/**
 * CartService 는 저장된 다국어 JSON 이 아니라 현재 로케일로 평탄화된 맵과 대조하므로 ko 로 고정한다.
 */
const CART_LOCALE = 'ko';

/** 결제수단 버튼 — iteration 으로 렌더되며 method.id 로 식별된다. */
const paymentMethod = (page: Page, id: string) =>
  page.getByTestId(`checkout-payment-method-${id}`);

/**
 * 활성 결제수단 카탈로그를 읽는다.
 *
 * 이 응답의 core_payment_method 가 곧 템플릿 computed(selectedCorePaymentMethod)의 입력이다.
 * 모듈의 병합/스냅샷이 이 키를 떨구면 프론트가 번역할 근거를 잃는다 — 그 회귀를 여기서 잠근다.
 */
async function activePaymentMethods(
  page: Page
): Promise<Array<{ id: string; core_payment_method?: string }>> {
  return page.evaluate(async (locale) => {
    const token = localStorage.getItem('auth_token');
    const res = await fetch('/api/modules/sirsoft-ecommerce/settings/payment', {
      headers: {
        Accept: 'application/json',
        'Accept-Language': locale,
        Authorization: `Bearer ${token}`,
      },
    });
    const json = await res.json();
    const methods = json?.data?.order_settings?.payment_methods ?? [];
    return methods
      .filter((m: { is_active?: boolean }) => m.is_active)
      .map((m: { id: string; core_payment_method?: string }) => ({
        id: m.id,
        core_payment_method: m.core_payment_method,
      }));
  }, CART_LOCALE);
}

/**
 * 카트를 비우고 상품 1건을 담은 뒤 임시주문을 생성한다 (체크아웃은 임시주문 없이 열리지 않는다).
 *
 * 상품 id 를 상수로 박지 않고 목록 API 에서 **판매 중인 상품을 찾아 쓴다**. 시드 데이터는
 * 재설치·재시드마다 id 가 바뀌므로(실제로 product_id=1 이 사라져 spec 이 깨졌다) 라이브 카탈로그를
 * 신뢰한다. option_values 는 저장된 다국어 JSON 이 아니라 **현재 로케일로 평탄화된 맵**과 대조되므로
 * 상품 상세의 옵션 값을 ko 로 풀어서 그대로 되돌려준다.
 */
async function seedCheckout(page: Page): Promise<void> {
  const result = await page.evaluate(async (locale) => {
    const token = localStorage.getItem('auth_token');

    // 체크아웃 조회(GET /checkout)는 Auth::id() **와 X-Cart-Key 헤더**로 임시주문을 찾는다
    // (CartKeyRequest::getCartKey() = header('X-Cart-Key')). 이 헤더가 없으면 POST 로 만든
    // 임시주문이 404 로 조회되지 않고, 화면은 "주문 정보가 없습니다" 모달을 띄운다.
    //
    // 신규 브라우저 컨텍스트에는 g7_cart_key 가 **없다**(실측: localStorage 에 auth_token/g7_locale/
    // g7_cache_version 뿐). 앱은 UI 로 카트에 담을 때 비로소 키를 만든다. API 로만 시드하는 여기서는
    // 키를 직접 만들어 localStorage 와 요청 헤더에 **같은 값**으로 심어야 화면과 서버가 같은 카트를 본다.
    let cartKey = localStorage.getItem('g7_cart_key');
    if (!cartKey) {
      cartKey = `ck_pw_${Math.random().toString(36).slice(2)}${Date.now().toString(36)}`;
      localStorage.setItem('g7_cart_key', cartKey);
    }

    const headers: Record<string, string> = {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'Accept-Language': locale,
      Authorization: `Bearer ${token}`,
      'X-Cart-Key': cartKey,
    };

    // 1) 판매 중 + 옵션 보유 상품을 찾는다.
    const list = await fetch('/api/modules/sirsoft-ecommerce/products?per_page=20', {
      headers,
    }).then((r) => r.json());
    const products = list?.data?.data ?? list?.data ?? [];

    for (const p of products) {
      const detail = await fetch(`/api/modules/sirsoft-ecommerce/products/${p.id}`, {
        headers,
      }).then((r) => r.json());
      const product = detail?.data ?? detail;

      // 옵션은 `options` 에 있다. `additional_options`(추가옵션)는 **다른 개념**이고 보통 비어 있어,
      // 그쪽을 먼저 보면 항상 빈 배열을 집어 상품을 못 찾는다(실측으로 이 함정에 빠졌다).
      const option = (product?.options ?? []).find(
        (o: { is_active?: boolean; is_sold_out?: boolean; stock_quantity?: number }) =>
          o.is_active !== false && !o.is_sold_out && (o.stock_quantity ?? 0) > 0
      );
      if (!option) continue;

      // API 가 이미 현재 로케일로 평탄화한 맵을 준다 ({ 색상: '빨강' }) — CartService 가 대조하는 형태와 같다.
      const optionValues: Record<string, string> = option.option_values_localized ?? {};
      if (Object.keys(optionValues).length === 0) continue;

      // 2) 카트를 비우고 담는다.
      await fetch('/api/modules/sirsoft-ecommerce/cart/all', { method: 'DELETE', headers });
      const added = await fetch('/api/modules/sirsoft-ecommerce/cart', {
        method: 'POST',
        headers,
        body: JSON.stringify({
          product_id: p.id,
          items: [{ option_values: optionValues, quantity: 1 }],
        }),
      });
      if (!added.ok) continue; // 품절·판매중지 등 — 다음 상품으로

      const cart = await fetch('/api/modules/sirsoft-ecommerce/cart', { headers }).then((r) =>
        r.json()
      );
      const itemIds = (cart?.data?.items ?? []).map((i: { id: number }) => i.id);
      if (itemIds.length === 0) continue;

      // 3) 카트의 "주문하기" 와 동일한 호출 — 임시주문 생성.
      const checkout = await fetch('/api/modules/sirsoft-ecommerce/checkout', {
        method: 'POST',
        headers,
        body: JSON.stringify({ item_ids: itemIds }),
      });
      return { step: 'checkout', status: checkout.status, body: await checkout.text() };
    }

    return { step: 'product-discovery', status: 0, body: '구매 가능한 옵션 보유 상품을 찾지 못했다' };
  }, CART_LOCALE);

  // status 0(상품 탐색 실패)은 `< 300` 을 **통과해버린다** — 실제로 이 함정에 빠져
  // "임시주문 생성 성공" 으로 오판한 채 한참 헤맸다. 성공 범위를 명시적으로 좁힌다.
  expect(result.status, `${result.step} 단계 실패: ${result.body}`).toBeGreaterThanOrEqual(200);
  expect(result.status, `${result.step} 단계 실패: ${result.body}`).toBeLessThan(300);

  // 임시주문이 실제로 조회되는지 확인한다 — 조회가 안 되면 체크아웃이 "주문 정보가 없습니다"
  // 모달을 띄우고, 그 오버레이가 결제수단 클릭을 가로채 90s 타임아웃으로만 드러난다(디버깅 지옥).
  // 여기서 먼저 빠르게 깨뜨린다.
  const visible = await page.evaluate(async (locale) => {
    const token = localStorage.getItem('auth_token');
    const cartKey = localStorage.getItem('g7_cart_key') ?? '';
    const res = await fetch('/api/modules/sirsoft-ecommerce/checkout?country_code=KR', {
      headers: {
        Accept: 'application/json',
        'Accept-Language': locale,
        Authorization: `Bearer ${token}`,
        'X-Cart-Key': cartKey,
      },
    });
    return res.status;
  }, CART_LOCALE);

  expect(visible, '임시주문이 생성됐는데 체크아웃 조회가 실패한다 (X-Cart-Key 확인)').toBeLessThan(300);
}

/**
 * GDPR 쿠키 배너를 닫는다 — 전체 화면 오버레이가 클릭을 가로챈다.
 *
 * 배너는 /consent/cookie/status 응답 후 비동기로 마운트되므로 한 번만 확인하면 "아직 없는 상태"를
 * "없음" 으로 오판한다. 동의 상태는 서버가 SSoT 라 localStorage 를 조작하지 않고 실제로 버튼을 누른다.
 *
 * ※ `.fixed.inset-0` 만으로 판정하면 안 된다 — 체크아웃 화면에는 배너가 아닌 다른 fixed 오버레이가
 *   상주해 루프가 영원히 안 끝난다(실측). 배너 자신(동의 버튼)의 존재를 기준으로 판정한다.
 */
async function dismissCookieNotice(page: Page): Promise<void> {
  const necessaryOnly = page.getByRole('button', { name: /Necessary Only|필수만/i });

  for (let attempt = 0; attempt < 3; attempt += 1) {
    if (!(await necessaryOnly.isVisible().catch(() => false))) {
      // 아직 안 떴을 수 있으니 짧게 기다려 본다. 끝내 안 뜨면 이미 동의된 상태다.
      const appeared = await necessaryOnly
        .waitFor({ state: 'visible', timeout: 3_000 })
        .then(() => true, () => false);
      if (!appeared) return;
    }

    // 일반 클릭이 다른 오버레이(주문서 없음 모달 등)에 가로막힐 수 있으므로 pointer event 를
    // 우회해 DOM 클릭을 직접 발사한다. 배너를 닫는 것 자체는 검증 대상이 아니라 전제조건이다.
    await necessaryOnly.click({ timeout: 5_000 }).catch(async () => {
      await necessaryOnly.evaluate((el) => (el as HTMLElement).click()).catch(() => undefined);
    });

    const gone = await necessaryOnly
      .waitFor({ state: 'hidden', timeout: 5_000 })
      .then(() => true, () => false);
    if (gone) return;
  }

  await expect(necessaryOnly, '쿠키 동의 배너가 닫히지 않았다').toBeHidden();
}

/**
 * 로딩 오버레이가 걷힐 때까지 기다린다.
 *
 * 체크아웃은 데이터 로딩 중 전체화면 오버레이(`div.fixed.inset-0.flex.items-center.justify-center`)를
 * 띄우고, 이것이 결제수단 버튼 클릭의 pointer event 를 가로챈다 — 버튼 자체는 이미 visible/enabled 라
 * Playwright 가 클릭을 재시도하다 타임아웃한다(실측). 버튼 가시성만으로 준비 완료를 단정하면 안 된다.
 */
async function waitForOverlayGone(page: Page): Promise<void> {
  const overlay = page.locator('div.fixed.inset-0.flex.items-center.justify-center');
  await expect
    .poll(() => overlay.count(), { timeout: 30_000 })
    .toBe(0)
    .catch(() => undefined);
}

/** 체크아웃으로 이동하고 결제수단 블록이 클릭 가능해질 때까지 기다린다. */
async function gotoCheckout(page: Page): Promise<void> {
  await page.goto('/shop/checkout');
  await page.waitForLoadState('domcontentloaded');
  await dismissCookieNotice(page);
  await paymentMethod(page, 'dbank').waitFor({ timeout: 30_000 });
  await waitForOverlayGone(page);
}

/**
 * 테스트 유저의 배송지를 미리 등록한다.
 *
 * playwright:issue-token 은 매 실행마다 **새 유저**를 만들므로(PlaywrightIssueToken:85 —
 * User::factory()->create()) 저장된 배송지가 하나도 없다. 그런데 주소 입력칸(우편번호·주소)은
 * readonly 라 주소검색 팝업으로만 채워지므로, 폼에 직접 타이핑할 수 없다 → 결제하기가 영원히 disabled.
 *
 * 그래서 배송지는 API 로 미리 심는다. 이 spec 의 검증 대상은 payment_method 번역이지 배송지 입력이
 * 아니므로, 이는 검증 대상 우회가 아니라 **그 앞의 전제조건 구성**이다.
 */
async function seedShippingAddress(page: Page): Promise<void> {
  const status = await page.evaluate(async (locale) => {
    const token = localStorage.getItem('auth_token');
    const res = await fetch('/api/modules/sirsoft-ecommerce/user/addresses', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'Accept-Language': locale,
        Authorization: `Bearer ${token}`,
      },
      body: JSON.stringify({
        name: '테스트 배송지',
        recipient_name: '홍길동',
        recipient_phone: '010-1234-5678',
        country_code: 'KR',
        zipcode: '06236',
        address: '서울특별시 강남구 테헤란로 152',
        address_detail: '강남파이낸스센터',
        is_default: true,
      }),
    });
    return res.status;
  }, CART_LOCALE);

  expect(status, '테스트 배송지 등록 실패').toBeLessThan(300);
}

/**
 * 주문 제출에 필요한 필수 입력을 채운다.
 *
 * 저장된 배송지 카드를 고르면 수령인·연락처·주소가 한 번에 채워진다(주소는 readonly 라 이 경로뿐).
 * 주문자 연락처만 비어 있으면 직접 채운다.
 */
async function fillRequiredCheckoutFields(page: Page): Promise<void> {
  const savedAddress = page.getByRole('button', { name: /테스트 배송지|홍길동/ }).first();
  await savedAddress.waitFor({ state: 'visible', timeout: 15_000 }).catch(() => undefined);
  await savedAddress.click().catch(() => undefined);

  const fill = async (name: string, value: string) => {
    const input = page.locator(`input[name="${name}"]`);
    if ((await input.count()) === 0) return;
    if ((await input.inputValue().catch(() => '')) !== '') return;
    await input.fill(value).catch(() => undefined);
  };

  await fill('orderer_phone', '010-1234-5678');
  await fill('recipient_name', '홍길동');
  await fill('recipient_phone', '010-1234-5678');
}

test.describe('플러그인 결제수단 → 코어 결제수단 번역 (주문 생성)', () => {
  test.beforeEach(async ({ page, noPermissionToken }) => {
    await authenticatePage(page, noPermissionToken);
    await page.addInitScript((locale) => localStorage.setItem('g7_locale', locale), CART_LOCALE);
    await page.goto('/shop');
    await page.waitForLoadState('domcontentloaded');
    await dismissCookieNotice(page);
    await seedCheckout(page);
  });

  test('결제수단 카탈로그가 플러그인 결제수단의 코어 매핑을 함께 내려준다', async ({ page }) => {
    await gotoCheckout(page);

    const methods = await activePaymentMethods(page);
    const plugin = methods.filter((m) => m.id.includes('_') && m.core_payment_method);

    // 플러그인 결제수단이 하나도 활성화돼 있지 않으면 이 계약을 검증할 수 없다.
    // (조합은 상점 설정 가변 — 활성화 조작은 병렬 워커가 공유하는 설정을 오염시키므로 하지 않는다)
    test.skip(
      plugin.length === 0,
      '활성 플러그인 결제수단 없음 — 주문서형 결제수단을 켠 상점에서만 검증 가능'
    );

    // 코어 매핑은 반드시 코어 PaymentMethodEnum 값이어야 한다 (원시 id 를 그대로 실어보내면 서버가 거부).
    const CORE_METHODS = ['card', 'vbank', 'dbank', 'bank', 'phone', 'point', 'deposit', 'free'];
    for (const m of plugin) {
      expect(CORE_METHODS, `${m.id} 의 core_payment_method 가 코어 enum 값이 아니다`).toContain(
        m.core_payment_method
      );
    }
  });

  test('코어 매핑이 없는 결제수단(무통장)은 raw id 를 그대로 쓴다', async ({ page }) => {
    await gotoCheckout(page);

    const methods = await activePaymentMethods(page);
    const dbank = methods.find((m) => m.id === 'dbank');

    expect(dbank, '무통장입금이 활성 결제수단에 없다').toBeTruthy();
    // 코어 결제수단 자신은 번역 대상이 아니므로 키가 붙지 않는다 (붙으면 폴백 경로가 죽는다).
    expect(dbank?.core_payment_method).toBeUndefined();
  });

  test('플러그인 결제수단으로 주문하면 코어 결제수단으로 전송되어 주문이 생성된다', async ({
    page,
  }) => {
    // 상품 탐색 → 카트 → 임시주문 → 체크아웃 → 폼 입력 → 주문 생성까지 한 흐름을 다 밟는다.
    // 기본 30s 로는 부족하다(실측 ~40s).
    test.setTimeout(120_000);

    // 신규 유저라 저장된 배송지가 없다 — 주소칸은 readonly(주소검색 팝업 전용)라 미리 심어야 한다.
    await seedShippingAddress(page);

    await gotoCheckout(page);

    const methods = await activePaymentMethods(page);
    const target = methods.find((m) => m.core_payment_method);
    test.skip(
      !target,
      '활성 플러그인 결제수단 없음 — 주문서형 결제수단을 켠 상점에서만 검증 가능'
    );

    // 주문 생성 요청 body 를 가로채 payment_method 가 무엇으로 나가는지 본다.
    let sentPaymentMethod: string | undefined;
    page.on('request', (req) => {
      if (req.method() === 'POST' && req.url().includes('/user/orders')) {
        try {
          sentPaymentMethod = JSON.parse(req.postData() ?? '{}').payment_method;
        } catch {
          /* body 파싱 실패는 아래 단언에서 undefined 로 드러난다 */
        }
      }
    });

    // 쿠키 배너 / 로딩 오버레이는 비동기로 다시 뜰 수 있고, 그 전체화면 오버레이가 결제수단 버튼
    // 클릭의 pointer event 를 가로챈다(버튼은 visible/enabled 인데 클릭만 타임아웃). 클릭 직전에 정리한다.
    await dismissCookieNotice(page);
    await waitForOverlayGone(page);

    await paymentMethod(page, target!.id).click();
    await fillRequiredCheckoutFields(page);

    // 결제하기가 활성화되어야 제출할 수 있다 (필수값 미충족이면 disabled 로 남아 클릭이 타임아웃된다).
    const submit = page.getByRole('button', { name: /결제하기/ });
    await expect(submit, '필수 입력이 채워졌는데도 결제하기가 비활성이다').toBeEnabled({
      timeout: 15_000,
    });

    // 주문 생성 응답까지 기다린다 — 422 면 여기서 상태로 드러난다.
    const [response] = await Promise.all([
      page.waitForResponse(
        (r) => r.request().method() === 'POST' && r.url().includes('/user/orders'),
        { timeout: 30_000 }
      ),
      submit.click(),
    ]);

    // 핵심 계약 1 — 원시 id 가 아니라 코어 값이 나갔다.
    expect(
      sentPaymentMethod,
      `원시 id(${target!.id})가 그대로 전송되면 코어 enum 이 거부해 422 가 된다`
    ).toBe(target!.core_payment_method);

    // 핵심 계약 2 — 서버가 그 값을 받아들여 주문이 생성됐다 (422 회귀 잠금).
    expect(
      response.status(),
      `주문 생성 실패(${response.status()}): ${await response.text()}`
    ).toBeLessThan(300);
  });

  /**
   * 에스크로 가상계좌 결제 시 SDK 로 넘어가는 escrowProducts 를 **직접 캡처**한다.
   *
   * 왜 서버 응답 실측으로 충분하지 않은가: 서버(buildPgPaymentData)가 escrow_products 를
   * 정확히 조립해 응답에 실어도, 프론트 핸들러가 그것을 SDK 페이로드에 **부착하지 않으면**
   * 토스는 에스크로 필수 파라미터 누락으로 결제를 거부한다. 실제로 그 결함이 있었다 —
   * 가상계좌 분기가 attachEscrowProducts() 를 호출하지 않아 계좌이체에서만 부착됐다.
   * "주문 응답에 escrow_products 가 있다" 는 계약의 **절반**일 뿐이고, 나머지 절반은
   * SDK 경계로 무엇이 넘어가는지다. 여기서 그 경계를 실측한다.
   *
   * 상점 설정(use_escrow)은 병렬 워커가 공유하므로 건드리지 않는다. 대신 핸들러가 읽는
   * client-config 응답만 라우트 인터셉트로 갈아끼워 에스크로 켜진 상점을 재현한다 —
   * 검증 대상은 서버 설정 저장이 아니라 **핸들러의 페이로드 조립 분기**다.
   *
   * 토스 SDK 는 스텁으로 대체한다. 실제 결제창을 여는 것이 목적이 아니라,
   * requestPayment() 에 무엇이 전달되는지가 목적이다.
   */
  test('에스크로 가상계좌 결제는 SDK 페이로드에 escrowProducts 를 싣는다', async ({ page }) => {
    test.setTimeout(120_000);

    await seedShippingAddress(page);

    // 1) 토스 SDK 스텁 — requestPayment 인자를 window 에 남긴다 (실제 결제창은 열지 않는다).
    await page.addInitScript(() => {
      const w = window as unknown as Record<string, unknown>;
      const TossPayments = () => ({
        payment: () => ({
          requestPayment: async (payload: unknown) => {
            (window as unknown as Record<string, unknown>).__tossPayload = payload;
          },
        }),
      });
      (TossPayments as unknown as Record<string, unknown>).ANONYMOUS = 'ANONYMOUS';
      w.TossPayments = TossPayments;
    });

    // 2) client-config 응답에 에스크로를 켠다 (서버 설정은 그대로 둔다).
    await page.route('**/payments/client-config/tosspayments*', async (route) => {
      const res = await route.fetch();
      const json = await res.json();
      if (json?.data) json.data.use_escrow = 'on';
      await route.fulfill({ response: res, json });
    });

    await gotoCheckout(page);

    const methods = await activePaymentMethods(page);
    const vbank = methods.find((m) => m.id === 'toss_virtual_account');
    test.skip(!vbank, '토스 가상계좌가 활성이 아님 — 켠 상점에서만 검증 가능');

    await dismissCookieNotice(page);
    await waitForOverlayGone(page);

    await paymentMethod(page, 'toss_virtual_account').click();
    await fillRequiredCheckoutFields(page);

    const submit = page.getByRole('button', { name: /결제하기/ });
    await expect(submit, '필수 입력이 채워졌는데도 결제하기가 비활성이다').toBeEnabled({
      timeout: 15_000,
    });

    // 주문 생성 응답을 받아 escrow_products 가 서버에서 조립됐는지 먼저 확인한다.
    const [orderResponse] = await Promise.all([
      page.waitForResponse(
        (r) => r.request().method() === 'POST' && r.url().includes('/user/orders'),
        { timeout: 30_000 }
      ),
      submit.click(),
    ]);

    expect(
      orderResponse.status(),
      `주문 생성 실패(${orderResponse.status()}): ${await orderResponse.text()}`
    ).toBeLessThan(300);

    const orderBody = await orderResponse.json();
    const serverEscrowProducts = orderBody?.data?.pg_payment_data?.escrow_products;

    // 계약 절반 ①: 서버가 escrow_products 를 조립해 내려준다.
    expect(
      Array.isArray(serverEscrowProducts) && serverEscrowProducts.length > 0,
      '서버가 pg_payment_data.escrow_products 를 조립하지 않았다'
    ).toBe(true);

    // 3) 핸들러가 SDK 를 호출할 때까지 기다린 뒤 페이로드를 읽는다.
    await expect
      .poll(
        () => page.evaluate(() => (window as unknown as Record<string, unknown>).__tossPayload),
        { timeout: 20_000, message: '토스 SDK requestPayment 가 호출되지 않았다' }
      )
      .toBeTruthy();

    const payload = (await page.evaluate(
      () => (window as unknown as Record<string, unknown>).__tossPayload
    )) as {
      method?: string;
      virtualAccount?: { useEscrow?: boolean };
      escrowProducts?: Array<{ id: string; unitPrice: number; quantity: number }>;
    };

    expect(payload.method, '가상계좌를 골랐는데 SDK method 가 VIRTUAL_ACCOUNT 가 아니다').toBe(
      'VIRTUAL_ACCOUNT'
    );
    expect(payload.virtualAccount?.useEscrow, 'use_escrow=on 인데 useEscrow 가 true 가 아니다').toBe(
      true
    );

    // 계약 절반 ②(회귀 잠금) — SDK 페이로드에 escrowProducts 가 실제로 실렸다.
    // 이것이 빠지면 토스가 에스크로 필수 파라미터 누락으로 결제를 거부한다.
    expect(
      payload.escrowProducts,
      '가상계좌 + 에스크로인데 SDK 페이로드에 escrowProducts 가 없다 (E2 위반)'
    ).toBeTruthy();
    expect(payload.escrowProducts!.length).toBe(serverEscrowProducts.length);
    expect(payload.escrowProducts![0].unitPrice).toBe(serverEscrowProducts[0].unitPrice);
  });
});
