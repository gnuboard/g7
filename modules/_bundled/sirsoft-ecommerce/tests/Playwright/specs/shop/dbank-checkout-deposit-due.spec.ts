/**
 * E2E: 무통장입금(dbank) 체크아웃 완료 — 입금기한 산정이 500 없이 끝난다
 *
 * 시나리오 매니페스트: `modules/_bundled/sirsoft-ecommerce/tests/scenarios/numeric-setting-type-safety.yaml`
 * (케이스 마킹은 각 test 의 docblock 에 있다 — 파일 헤더에 두면 축 값이 비어 매칭되지 않는다)
 *
 * 배경: 관리자 주문설정을 한 번만 저장해도 `auto_cancel_days` 가 문자열(`"5"`)로 영속됐다.
 * HTML `type="number"` 의 DOM 값은 문자열이고 Laravel `integer` 규칙은 숫자 문자열을 통과시키되
 * 캐스트하지 않기 때문이다. 그 값이 `Carbon::now()->addDays(...)` 에 닿으면
 * `rawAddUnit(int|float)` 가 `declare(strict_types=1)` 파일에서 호출되므로 TypeError 가 났고,
 * **모든 무통장입금·가상계좌 주문이 500 으로 실패**했다.
 *
 * 이 결함은 PHPUnit 이 red 를 잡을 수 있는 형태지만, 실제로 발견된 경로는 브라우저였다.
 * 상품 선택 → 체크아웃 → 결제하기까지의 흐름 전체가 이어져야 주문 생성 요청이 나가고,
 * 그 응답이 500 이면 화면은 토스트 한 줄만 띄운 채 멈춘다. 그래서 흐름 전체를 브라우저로 고정한다.
 *
 * 검증 지점 3개:
 *   ① 공개 결제설정 응답의 입금기한이 문자열이 아니다 (계층 2·3 — 요청 캐스트 + 조회 정규화)
 *   ② 회원 — 주문서 화면에서 무통장 선택 → 결제하기 → 201 + 완료 화면 입금기한 (계층 1)
 *   ③ 비회원 — 같은 브라우저 세션의 주문 생성 요청으로 201 + 입금기한
 *
 * 커버리지 경계(침묵 누락 방지): ③ 은 주문서 **화면**을 거치지 않는다. 비회원은 저장된 배송지가
 * 없어 우편번호·주소 칸을 외부 주소검색 위젯으로만 채울 수 있는데(두 칸 모두 `readOnly` — 실측),
 * 외부 위젯을 E2E 로 몰면 이 스펙이 결제 회귀가 아니라 그 위젯의 가용성을 감시하게 된다.
 * 비회원 주문서 화면의 브라우저 실측은 Chrome MCP 매트릭스 D2 로 1회 확보되어 있고,
 * 여기서는 회귀가 실제로 터지는 지점(서버의 입금기한 산정)을 같은 세션의 요청으로 고정한다.
 *
 * 부작용: ②③ 은 실제 주문을 생성한다(결제 대기 상태). 무통장입금은 외부 PG 를 거치지 않으므로
 * 샌드박스 의존이 없고, 생성된 주문은 설정된 기한이 지나면 미입금 자동취소 대상이 된다.
 *
 * 회귀 배경: 입금기한 일수 설정이 문자열로 영속되면 기한 산정이 Carbon 의 strict 타입 경계에서
 * TypeError 를 던져 해당 주문 요청이 전부 500 이 되던 결함이 있었다.
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';
import type { Page } from '@playwright/test';

const PAYMENT_SETTINGS_API = '/api/modules/sirsoft-ecommerce/settings/payment';
const ORDER_CREATE_API = /\/api\/modules\/sirsoft-ecommerce\/(user|guest)\/orders(\?|$)/;

/** 기본 입금기한 — 서버 `OrderProcessingService::AUTO_CANCEL_DAYS_DEFAULT` 와 같은 값 */
const AUTO_CANCEL_DAYS_FALLBACK = 3;

/**
 * 이 스펙은 직렬로 실행한다.
 *
 * 세 테스트가 같은 재고를 소비하고 같은 체크아웃 세션(임시주문)을 만든다. 병렬로 돌리면
 * 재고/임시주문이 서로 간섭해 결함이 아닌 실패가 난다.
 */
test.describe.configure({ mode: 'serial' });

/**
 * 기본 30초로는 부족하다 — 상품 상세 → 체크아웃 → 주문 생성까지 SPA 라우팅이 세 번 일어나고
 * 각 단계가 자체 데이터소스를 기다린다. 단계별 대기(20~30초)의 합보다 크게 잡아야
 * 결함이 아닌 타임아웃으로 red 가 나지 않는다.
 */
test.setTimeout(180_000);

/**
 * 공개 결제설정에서 입금기한을 읽는다.
 *
 * 이 값이 화면 안내("입금 기한: N일 이내")와 서버 산정의 공통 입력이므로, 기대 기한을
 * 여기서 뽑아야 설정을 바꾼 환경에서도 스펙이 그대로 성립한다(값 하드코딩 금지).
 */
async function readOrderSettings(
  page: Page
): Promise<{ raw: unknown; days: number; accounts: any[] }> {
  const response = await page.request.get(PAYMENT_SETTINGS_API);
  expect(response.status()).toBe(200);

  const body = await response.json();
  const orderSettings = body?.data?.order_settings;
  // 존재를 먼저 확정한다 — 없는 객체에 대고 타입 단언을 하면 그 단언은 무의미하게 통과한다.
  expect(orderSettings, '공개 결제설정 응답에 order_settings 가 없다').toBeTruthy();

  const raw = orderSettings.auto_cancel_days;
  const days = typeof raw === 'number' && raw > 0 ? raw : AUTO_CANCEL_DAYS_FALLBACK;
  const accounts = (orderSettings.bank_accounts ?? []).filter((a: any) => a?.is_active !== false);

  return { raw, days, accounts };
}

/**
 * GDPR 쿠키 동의 배너를 닫는다.
 *
 * 배너는 화면 최상단 레이어라 열려 있으면 뒤쪽 버튼 클릭이 가로채인다. 동의 여부는 이 스펙의
 * 관심사가 아니므로, 떠 있으면 닫고 없으면 넘어간다(이미 동의된 세션에서는 렌더되지 않는다).
 */
async function dismissCookieBanner(page: Page): Promise<void> {
  const acceptAll = page.getByRole('button', { name: '모두 동의' });
  if (await acceptAll.isVisible({ timeout: 5_000 }).catch(() => false)) {
    await acceptAll.click();
    await expect(acceptAll).toBeHidden({ timeout: 10_000 });
  }
}

/**
 * 회원에게 기본 배송지가 없으면 하나 만든다.
 *
 * 주문서의 우편번호·주소 칸은 `readOnly` 이고 외부 주소검색 위젯으로만 채워진다(실측).
 * 외부 위젯을 E2E 로 몰 수는 없으므로, 저장된 배송지를 미리 두어 주문서가 그 값으로
 * 채워진 채 열리게 한다 — 실제 회원의 재구매 동선과 같은 상태다.
 */
async function ensureDefaultAddress(page: Page, token: string): Promise<any> {
  const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' };
  const endpoint = '/api/modules/sirsoft-ecommerce/user/addresses';

  const readList = async (): Promise<any[]> => {
    const response = await page.request.get(endpoint, { headers });
    if (response.status() !== 200) return [];
    const body = await response.json();
    const addresses = body?.data?.addresses?.data ?? body?.data?.addresses ?? body?.data ?? [];
    return Array.isArray(addresses) ? addresses : [];
  };

  let addresses = await readList();

  if (addresses.length === 0) {
    const created = await page.request.post(endpoint, {
      headers,
      data: {
        name: 'E2E 기본 배송지',
        recipient_name: 'E2E수령인',
        recipient_phone: '010-1234-5678',
        country_code: 'KR',
        zipcode: '06236',
        address: '서울특별시 강남구 테헤란로 1',
        address_detail: 'E2E 101호',
        is_default: true,
      },
    });
    expect(created.status(), '기본 배송지 생성이 실패했다').toBe(201);

    // 생성 응답의 봉투 형태에 기대지 않는다 — 목록을 다시 읽어 화면과 같은 표현을 쓴다.
    addresses = await readList();
  }

  expect(addresses.length, '기본 배송지를 확보하지 못했다').toBeGreaterThan(0);

  return addresses.find((a: any) => a.is_default) ?? addresses[0];
}

/**
 * 주문서의 저장된 배송지 칩을 눌러 주소를 채운다.
 *
 * 저장된 배송지가 있어도 주문서는 자동 적용하지 않고 선택을 기다린다 — 누르지 않으면
 * 우편번호·주소가 빈 채로 남아 '결제하기' 가 계속 비활성이다(실측). 칩 라벨은 배송지 이름이라
 * 환경마다 다르므로 API 로 받은 이름으로 지목한다.
 */
async function applySavedAddress(page: Page, address: any): Promise<void> {
  const label = String(address?.name ?? '').trim();
  expect(label, '저장된 배송지에 이름이 없다').not.toBe('');

  const chip = page.getByRole('button').filter({ hasText: label }).first();
  await expect(chip, `저장된 배송지 "${label}" 칩이 없다`).toBeVisible({ timeout: 20_000 });
  await chip.click();

  // 주소가 실제로 실렸는지 확정한다 — 비어 있으면 뒤의 '결제하기' 비활성이 회귀로 오독된다.
  await expect
    .poll(async () => (await page.locator('input[name="zipcode"]').first().inputValue()).trim(), {
      timeout: 20_000,
    })
    .not.toBe('');
}

/**
 * 판매중인 상품 하나로 '바로구매' 임시주문을 만들고 체크아웃 화면을 연다.
 *
 * 상품 상세의 옵션 선택은 이 스펙이 지키려는 대상이 아닌데도 가장 불안정하다 —
 * `Select` 는 옵션 데이터가 실릴 때까지 네이티브 `<select>`(빈 목록)로 렌더되다가
 * 커스텀 드롭다운(버튼 + 포털 리스트박스)으로 바뀌고, 레이아웃의 `name` 은 자동바인딩이
 * 소비해 DOM 에 남지 않는다(실측). 그 위에 스펙을 세우면 결제 회귀가 아니라 옵션 위젯의
 * 렌더 타이밍을 감시하게 된다.
 *
 * 그래서 **결제 직전 상태까지는 실제 API 로 만들고**, 회귀가 일어난 구간(체크아웃 화면 →
 * 결제하기 → 주문 생성)만 브라우저로 몬다. 임시주문은 화면의 '바로구매' 가 호출하는 것과
 * 같은 엔드포인트·같은 payload(`direct_items`) 다.
 */
async function createDirectCheckout(
  page: Page,
  token?: string,
  cartKey?: string
): Promise<number> {
  const headers: Record<string, string> = { Accept: 'application/json' };
  if (token) headers.Authorization = `Bearer ${token}`;
  // 비회원의 장바구니·임시주문·주문 생성은 모두 이 헤더로 묶인다. 빠지면 주문 단계에서
  // 임시주문을 찾지 못해 404 가 난다(실측).
  if (cartKey) headers['X-Cart-Key'] = cartKey;

  const listResponse = await page.request.get('/api/modules/sirsoft-ecommerce/products?per_page=1');
  expect(listResponse.status()).toBe(200);
  const listBody = await listResponse.json();
  const products = listBody?.data?.data ?? listBody?.data ?? [];
  expect(
    Array.isArray(products) && products.length > 0,
    '판매중인 상품이 없다 — 시드 확인 필요'
  ).toBe(true);

  const detailResponse = await page.request.get(
    `/api/modules/sirsoft-ecommerce/products/${products[0].product_code}`
  );
  expect(detailResponse.status()).toBe(200);
  const product = (await detailResponse.json())?.data;
  expect(product?.id, '상품 상세 응답에 id 가 없다').toBeTruthy();

  const options = product.options ?? [];
  const usableOption = options.find((o: any) => (o.stock_quantity ?? 0) > 0) ?? options[0];
  if (options.length > 0) {
    expect(usableOption, '구매 가능한 옵션이 없다 — 재고 확인 필요').toBeTruthy();
  }

  const checkoutResponse = await page.request.post('/api/modules/sirsoft-ecommerce/checkout', {
    headers,
    data: {
      direct_items: [
        {
          product_id: product.id,
          quantity: 1,
          ...(usableOption ? { product_option_id: usableOption.id } : {}),
        },
      ],
    },
  });
  expect(checkoutResponse.status(), '임시주문 생성이 실패했다').toBe(201);

  const summary = (await checkoutResponse.json())?.data?.calculation?.summary;
  const total = summary?.final_amount ?? summary?.total_amount;
  expect(typeof total, '임시주문 응답에 결제 예정 금액이 없다').toBe('number');

  return total;
}

/** 임시주문을 만들고 주문서 화면을 연다 (회원 전용 — 저장된 배송지를 적용한다). */
async function openCheckoutScreen(page: Page, token: string): Promise<void> {
  const savedAddress = await ensureDefaultAddress(page, token);
  await createDirectCheckout(page, token);

  await page.goto('/shop/checkout');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await dismissCookieBanner(page);

  // 주문서가 임시주문을 실제로 물고 떴는지 확정한다 — 빈 화면 위에서 입력을 채우면
  // 뒤따르는 실패가 회귀인지 진입 실패인지 구분되지 않는다.
  await expect(page.getByRole('button', { name: /결제하기/ }).first()).toBeVisible({
    timeout: 30_000,
  });

  await applySavedAddress(page, savedAddress);
}

/**
 * 값이 비어 있고 편집 가능한 입력만 채운다.
 *
 * 회원 주문서의 주문자 칸은 계정 값으로 채워진 읽기 전용이라, 비었다고 채우려 들면
 * "element is not editable" 로 타임아웃한다(실측). 편집 가능 여부를 먼저 확인한다.
 */
async function fillIfEmpty(page: Page, selector: string, value: string): Promise<void> {
  const input = page.locator(selector).first();
  if ((await input.count()) === 0) return;
  if (!(await input.isEditable().catch(() => false))) return;
  const current = await input.inputValue().catch(() => '');
  if (current.trim() !== '') return;
  await input.fill(value);
}

/** 주문자·배송지 필수 입력을 채운다. 비회원은 조회 비밀번호까지 필요하다. */
async function fillOrdererAndShipping(page: Page, isGuest: boolean): Promise<void> {
  await fillIfEmpty(page, 'input[name="orderer_name"]', 'E2E주문자');
  await fillIfEmpty(page, 'input[name="orderer_phone"]', '010-1234-5678');
  await fillIfEmpty(page, 'input[name="orderer_email"]', 'e2e-dbank@example.com');

  if (isGuest) {
    await fillIfEmpty(page, 'input[name="guest_lookup_password"]', 'e2eGuest!234');
    await fillIfEmpty(page, 'input[name="guest_lookup_password_confirmation"]', 'e2eGuest!234');
  }

  await fillIfEmpty(page, 'input[name="recipient_name"]', 'E2E수령인');
  await fillIfEmpty(page, 'input[name="recipient_phone"]', '010-1234-5678');
  await fillIfEmpty(page, 'input[name="zipcode"]', '06236');
  await fillIfEmpty(page, 'input[name="address"]', '서울특별시 강남구 테헤란로 1');
  await fillIfEmpty(page, 'input[name="address_detail"]', 'E2E 101호');
}

/**
 * 결제수단을 무통장입금으로 고르고, 입금 계좌 선택·입금자명까지 채운다.
 *
 * 계좌는 Select 가 아니라 계좌 목록 버튼이다. 어떤 계좌가 등록돼 있는지는 환경마다 다르므로
 * 공개 설정에서 받은 계좌번호로 해당 버튼을 지목한다(문구·순서 하드코딩 회피).
 */
async function chooseBankTransfer(page: Page, accounts: any[]): Promise<void> {
  // 결제수단 카드는 화면 아래쪽이라 쿠키 배너가 떠 있으면 클릭이 가로채인다.
  await dismissCookieBanner(page);

  const method = page.getByText('무통장입금', { exact: true }).first();
  await method.scrollIntoViewIfNeeded();
  await method.click();

  // 무통장 분기가 실제로 열렸는지 먼저 확정한다 — 안 열린 상태에서 하위 입력을 찾으면
  // "없음" 이 결함인지 아직 렌더 전인지 구분되지 않는다.
  await expect(page.getByText('아래 계좌를 선택하여 입금해주세요.')).toBeVisible({
    timeout: 20_000,
  });

  expect(accounts.length, '사용중인 입금 계좌가 없다 — 주문설정의 계좌 목록 확인 필요').toBeGreaterThan(0);

  const accountNumber = String(accounts[0].account_number ?? '').trim();
  expect(accountNumber, '계좌 응답에 계좌번호가 없다').not.toBe('');

  const accountButton = page.getByRole('button').filter({ hasText: accountNumber }).first();
  await expect(accountButton, `계좌 ${accountNumber} 버튼이 없다`).toBeVisible({ timeout: 20_000 });
  await accountButton.click();

  await fillIfEmpty(page, 'input[name="depositor_name"]', 'E2E입금자');
}

/**
 * '결제하기' 를 눌러 주문을 생성하고 응답 상태를 돌려준다.
 *
 * 회귀의 실패 형태가 **500** 이므로 상태 코드를 직접 본다. 화면 단언만 하면 500 일 때
 * "완료 페이지가 아직 안 떴다" 와 구분되지 않는다.
 */
async function placeOrder(page: Page): Promise<{ status: number; body: any }> {
  const responsePromise = page.waitForResponse(
    (response) => ORDER_CREATE_API.test(response.url()) && response.request().method() === 'POST',
    { timeout: 60_000 }
  );

  await page.getByRole('button', { name: /결제하기/ }).first().click();

  const response = await responsePromise;
  const body = await response.json().catch(() => null);

  return { status: response.status(), body };
}

/**
 * 주문 응답의 입금기한이 주문일 + 설정값인지 확인한다.
 *
 * 화면 문자열을 비교하면 표기 형식(로케일/타임존)에 묶이므로, 응답의 `ordered_at` 과
 * `deposit_due_at` 의 일수 차이로 판정한다.
 */
function expectDueDaysMatch(body: any, days: number): void {
  const order = body?.data?.order ?? body?.data ?? body;
  const orderedAt = order?.ordered_at ?? order?.created_at;
  const dueAt =
    order?.payment?.deposit_due_at ??
    order?.payment?.due_date ??
    order?.deposit_due_at ??
    order?.payment?.vbank_due_at;

  expect(orderedAt, '응답에 주문일시가 없다').toBeTruthy();
  expect(dueAt, '응답에 입금기한이 없다 — 기한 산정 자체가 누락됐다').toBeTruthy();

  const diffDays = Math.round(
    (new Date(dueAt).getTime() - new Date(orderedAt).getTime()) / 86_400_000
  );
  expect(diffDays, '입금기한이 주문일 + 설정값과 다르다').toBe(days);
}

/** 완료 화면이 열리고 입금기한 항목이 표시되는지 확인한다. */
async function expectCompletePage(page: Page): Promise<void> {
  await expect(page).toHaveURL(/\/shop\/orders\/[^/]+\/complete/, { timeout: 30_000 });
  await expect(page.getByText('입금 기한').first()).toBeVisible({ timeout: 30_000 });
}

test.describe('무통장입금 체크아웃 — 입금기한 산정 (숫자 설정 타입 안전성)', () => {
  /**
   * @scenario payment_method=dbank, setting_value_type=int
   *
   * @effects settings_read_returns_schema_scalar_type
   */
  test('공개 결제설정의 입금기한이 문자열로 내려오지 않는다', async ({ page }) => {
    await page.goto('/shop/products');
    const { raw } = await readOrderSettings(page);

    // 회귀 형태가 "문자열 영속" 이므로 문자열 부재를 직접 단언한다.
    expect(typeof raw, `입금기한이 문자열로 내려왔다: ${JSON.stringify(raw)}`).not.toBe('string');
    if (raw !== null && raw !== undefined) {
      expect(Number.isInteger(raw)).toBe(true);
    }
  });

  /**
   * @scenario payment_method=dbank, setting_value_type=int
   *
   * @effects order_creation_succeeds_with_string_setting,
   *          deposit_due_at_survives_string_setting
   */
  test('회원 무통장 주문이 생성되고 완료 화면에 입금기한이 표시된다', async ({ page, customerToken }) => {
    await authenticatePage(page, customerToken);

    const { days, accounts } = await readOrderSettings(page);

    await openCheckoutScreen(page, customerToken);
    await fillOrdererAndShipping(page, false);
    await chooseBankTransfer(page, accounts);

    const { status, body } = await placeOrder(page);
    expect(status, '무통장 주문 생성이 실패했다 (500 이면 기한 산정 회귀)').toBe(201);

    expectDueDaysMatch(body, days);
    await expectCompletePage(page);
  });

  /**
   * @scenario payment_method=dbank, setting_value_type=int
   *
   * @effects order_creation_succeeds_with_string_setting,
   *          deposit_due_at_survives_string_setting
   */
  test('비회원 무통장 주문이 생성되고 입금기한이 주문일 + 설정값이다', async ({ page }) => {
    // 직전 테스트의 회원 세션·비회원 토큰 잔재를 비운다 — 남아 있으면 비회원 분기가 아예 안 열린다.
    await page.goto('/');
    await page.evaluate(() => {
      try {
        localStorage.removeItem('g7_auth_token');
        sessionStorage.removeItem('g7_guest_order_token');
        sessionStorage.removeItem('g7_guest_order_number');
        sessionStorage.removeItem('g7_guest_order_expires_at');
      } catch {}
    });

    const { days, accounts } = await readOrderSettings(page);
    expect(accounts.length, '사용중인 입금 계좌가 없다').toBeGreaterThan(0);

    const keyResponse = await page.request.post('/api/modules/sirsoft-ecommerce/cart/key', {
      headers: { Accept: 'application/json' },
    });
    expect(keyResponse.status(), '비회원 장바구니 키 발급이 실패했다').toBeLessThan(300);
    const cartKey = (await keyResponse.json())?.data?.cart_key;
    expect(cartKey, '장바구니 키 응답에 cart_key 가 없다').toBeTruthy();

    const total = await createDirectCheckout(page, undefined, cartKey);

    // 비회원은 저장된 배송지가 없어 주소를 주소검색 위젯으로만 넣을 수 있다(§상단 주석).
    // 그래서 이 케이스는 주문서 화면 대신 같은 브라우저 세션의 주문 생성 요청으로 검증한다 —
    // 회귀가 터지는 지점(서버의 입금기한 산정)은 동일하다.
    const response = await page.request.post('/api/modules/sirsoft-ecommerce/user/orders', {
      headers: { Accept: 'application/json', 'X-Cart-Key': cartKey },
      data: {
        orderer: { name: 'E2E주문자', phone: '010-1234-5678', email: 'e2e-guest@example.com' },
        shipping: {
          recipient_name: 'E2E수령인',
          recipient_phone: '010-1234-5678',
          country_code: 'KR',
          zipcode: '06236',
          address: '서울특별시 강남구 테헤란로 1',
          address_detail: 'E2E 101호',
        },
        payment_method: 'dbank',
        depositor_name: 'E2E입금자',
        dbank: {
          bank_code: accounts[0].bank_code,
          account_number: accounts[0].account_number,
          account_holder: accounts[0].account_holder,
        },
        expected_total_amount: total,
        guest_lookup_password: 'e2eGuest!234',
        guest_lookup_password_confirmation: 'e2eGuest!234',
      },
    });

    const body = await response.json().catch(() => null);
    expect(
      response.status(),
      `비회원 무통장 주문 생성이 실패했다 (500 이면 기한 산정 회귀): ${JSON.stringify(body)?.slice(0, 300)}`
    ).toBe(201);

    expectDueDaysMatch(body, days);
  });
});
