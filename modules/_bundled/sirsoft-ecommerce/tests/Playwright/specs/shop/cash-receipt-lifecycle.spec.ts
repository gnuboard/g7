/**
 * 현금영수증 전주기 E2E (#454 S3 이월 → S5).
 *
 * checkout-cash-receipt.spec.ts 가 "신청 폼이 렌더·동작하는가" 를 본다면, 이 spec 은
 * 신청 이후의 **발급 생애주기**를 본다: 입금확인 → 자동발급 → 금액변동 → 전액취소·재발급.
 *
 * 단위 테스트가 green 인 상태에서도 실환경에서만 드러난 결함이 실제로 있었다(§13-3 실측):
 *   - 취소 API 에 cancelReason 누락 → 토스가 400 거부 (Http::fake 는 본문을 검증하지 않음)
 *   - 취소 응답의 'c_' 접두 키를 저장 → 영수증이 영원히 "활성" 으로 남아 재발급 미발동
 * 두 결함 모두 브라우저 + 실 PG 경로에서만 재현된다. 그래서 이 spec 은 PG 응답을 모킹하지 않고
 * 관리자 화면의 실제 버튼을 눌러 서버가 PG 를 호출하게 한다.
 *
 * 발급 결과는 화면 문구가 아니라 서버 상태(주문 상세 API 의 cash_receipt / cash_receipts)로
 * 단언한다 — 화면 문구는 i18n 으로 바뀌지만 발급 회차·금액·상태는 도메인 사실이다.
 *
 * 전제: 관리자 환경설정에 현금영수증 발급사(cash_receipt_provider)가 설정되어 있고 그 PG 의
 * 테스트 키가 유효해야 한다. 미설정/무효면 발급 자체가 불가하므로 해당 테스트는 skip 한다
 * (실패로 위장하지 않는다 — 환경 미비와 코드 결함을 구분한다).
 *
 * @scenario cash-receipt-provider-and-refund-integrity
 * @effects amount_change_triggers_cancel_then_reissue,
 *          cancel_delegated_as_full_cancel_without_amount,
 *          order_id_suffixed_with_issue_sequence,
 *          tax_free_amount_sent_with_issue,
 *          issue_response_mapped_to_receipt_key_and_issue_number,
 *          receipt_type_converted_to_provider_specific_value
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';
import type { Page } from '@playwright/test';

const CART_LOCALE = 'ko';

const TOGGLE = '#ext_checkout_cash_receipt_toggle';
const IDENTIFIER = '#ext_checkout_cash_receipt_identifier';

type ApiResult = { status: number; body: string };

/** 앱이 쓰는 것과 동일한 Bearer 헤더로 API 를 호출한다. */
async function api(
  page: Page,
  path: string,
  init: { method?: string; body?: unknown } = {}
): Promise<ApiResult> {
  return page.evaluate(
    async ({ path, init, locale }) => {
      const res = await fetch(path, {
        method: init.method ?? 'GET',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'Accept-Language': locale,
          Authorization: `Bearer ${localStorage.getItem('auth_token')}`,
        },
        body: init.body === undefined ? undefined : JSON.stringify(init.body),
      });
      return { status: res.status, body: await res.text() };
    },
    { path, init, locale: CART_LOCALE }
  );
}

/**
 * 주문번호로 관리자용 주문 id 를 얻는다.
 *
 * 관리자 상세 라우트는 id 바인딩이고, 주문 생성 결과로 손에 쥐는 건 주문번호다.
 * 유저 주문 상세는 주문번호로 조회되며 id 를 함께 내려준다.
 */
async function resolveOrderId(page: Page, orderNumber: string): Promise<number> {
  const res = await api(page, `/api/modules/sirsoft-ecommerce/user/orders/${orderNumber}`);
  expect(res.status, `주문(${orderNumber}) 조회 실패: ${res.body}`).toBe(200);
  const id = JSON.parse(res.body)?.data?.id as number;
  expect(id, '주문 id 를 얻지 못했습니다.').toBeTruthy();
  return id;
}

/** 주문 상세(관리자)를 읽어 현금영수증 상태를 돌려준다. */
async function readReceiptState(page: Page, orderId: number) {
  const res = await api(page, `/api/modules/sirsoft-ecommerce/admin/orders/${orderId}`);
  expect(res.status, `주문 상세 조회 실패: ${res.body}`).toBe(200);
  const data = JSON.parse(res.body)?.data ?? {};
  return {
    total: Number(data.total_amount ?? 0),
    active: data.cash_receipt ?? null,
    history: (data.cash_receipts ?? []) as Array<Record<string, unknown>>,
  };
}

/**
 * 관리자 주문 상세에서 입금확인을 처리한다 (자동발급 리스너의 발화 지점).
 *
 * 이미 주문 상세 화면에 있어야 한다.
 */
async function confirmDeposit(page: Page, amount: number): Promise<void> {
  await page.getByRole('button', { name: '입금확인' }).click();
  const dialog = page.getByRole('dialog');
  await dialog.waitFor({ state: 'visible' });
  await dialog.locator('input[type=number]').fill(String(amount));
  await dialog.locator('input[type=checkbox]').check();
  await dialog.getByRole('button', { name: '입금확인' }).click();

  // 입금확인 요청은 그 안에서 자동발급 리스너가 **동기적으로** PG 왕복을 수행한다.
  // 실측상 응답까지 30초를 넘기는 경우가 있어(주문 생성 → 발급 완료 시각차 ~40초),
  // 30초로 잡으면 "모달이 안 닫힘" 으로 위장된 타임아웃이 난다 — 서버는 정상 처리 중이다.
  await expect(dialog).toBeHidden({ timeout: 120_000 });
}

/**
 * 자진발급(미신청 주문을 국세청 지정번호로 자동발급) 설정을 읽는다.
 *
 * 이 설정이 켜져 있으면 "신청하지 않은 주문" 도 입금완료 시 자동발급된다 —
 * 사후 발급(유저가 나중에 직접 발급)의 전제인 "미발급 주문" 이 만들어지지 않는다.
 */
async function selfIssueEnabled(page: Page): Promise<boolean> {
  const res = await api(page, '/api/modules/sirsoft-ecommerce/settings/payment');
  if (res.status !== 200) return false;
  return Boolean(JSON.parse(res.body)?.data?.order_settings?.cash_receipt_self_issue);
}

/** 발급사가 설정되어 있는지 — 미설정이면 발급 경로 자체가 존재하지 않는다. */
async function cashReceiptProvider(page: Page): Promise<string | null> {
  const res = await api(page, '/api/modules/sirsoft-ecommerce/settings/payment');
  if (res.status !== 200) return null;
  const provider = JSON.parse(res.body)?.data?.order_settings?.cash_receipt_provider;
  return typeof provider === 'string' && provider !== '' ? provider : null;
}

/**
 * 무통장 + 현금영수증 신청 주문을 체크아웃 화면에서 실제로 만든다.
 *
 * 주문 생성은 검증 대상(발급 생애주기)의 전제이지 검증 대상이 아니므로 카트/임시주문은
 * 사용자가 밟는 API 경로로 만들되, 현금영수증 신청 자체는 화면 폼으로 입력한다.
 */
async function placeDbankOrderWithCashReceipt(
  page: Page,
  identifier: string | null
): Promise<string> {
  const seeded = await page.evaluate(async ({ locale }) => {
    const headers = {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'Accept-Language': locale,
      Authorization: `Bearer ${localStorage.getItem('auth_token')}`,
    };

    const list = await fetch('/api/modules/sirsoft-ecommerce/products?per_page=30', {
      headers,
    }).then((r) => r.json());
    const candidates = (list?.data?.data ?? []) as Array<{ id: number }>;

    await fetch('/api/modules/sirsoft-ecommerce/cart/all', { method: 'DELETE', headers });

    for (const candidate of candidates) {
      const detail = await fetch(
        `/api/modules/sirsoft-ecommerce/products/${candidate.id}`,
        { headers }
      ).then((r) => r.json());
      const options = (detail?.data?.options ?? []) as Array<{
        option_values_localized?: Record<string, string>;
        stock_quantity?: number;
      }>;

      // 부분취소를 만들려면 수량 2 가 필요하다. 재고가 모자란 옵션을 고르면
      // 주문 생성이 422(재고 부족)로 실패하고, 그 실패가 "현금영수증 결함" 으로 오해된다.
      const usable = options.find((o) => (o.stock_quantity ?? 0) >= 2);
      if (!usable) continue;

      const added = await fetch('/api/modules/sirsoft-ecommerce/cart', {
        method: 'POST',
        headers,
        body: JSON.stringify({
          product_id: candidate.id,
          items: [{ option_values: usable.option_values_localized ?? {}, quantity: 2 }],
        }),
      });
      if (!added.ok) continue;

      const cart = await fetch('/api/modules/sirsoft-ecommerce/cart', { headers }).then((r) =>
        r.json()
      );
      const itemIds = (cart?.data?.items ?? []).map((i: { id: number }) => i.id);
      const checkout = await fetch('/api/modules/sirsoft-ecommerce/checkout', {
        method: 'POST',
        headers,
        body: JSON.stringify({ item_ids: itemIds }),
      });
      if (checkout.ok) return { ok: true, body: '' };
    }
    return { ok: false, body: '담을 수 있는 상품이 없습니다.' };
  }, { locale: CART_LOCALE });

  expect(seeded.ok, seeded.body).toBe(true);

  await page.goto('/shop/checkout');
  await page.waitForLoadState('domcontentloaded');

  // 무통장 결제수단 선택
  await page.getByTestId('checkout-payment-method-dbank').click();

  // 현금영수증 신청 (소득공제 + 휴대폰번호 — 기본 선택 그대로 사용).
  // identifier 가 null 이면 신청하지 않는다 — "미신청 주문" 은 사후 발급의 전제다.
  if (identifier !== null) {
    await page.locator(TOGGLE).click();
    await page.locator(IDENTIFIER).fill(identifier);
  }

  // 나머지 필수값은 앱 상태로 채운다 — 이 spec 의 검증 대상이 아닌 입력이다.
  //   - 입금할 계좌(_local.selectedDbank): 아이콘 기반 커스텀 컨트롤이라 클릭 대상이 불안정하다.
  //     관리자 환경설정에 등록된 계좌를 그대로 골라 앱 핸들러와 같은 상태를 만든다.
  //   - 주문자 연락처: 회원 프로필에 없으면 빈 값이라 서버가 422 로 거절한다.
  //   - 배송지 주소: 주소검색이 외부 스크립트(다음 우편번호) 팝업이다.
  const filled = await page.evaluate(async () => {
    const core = (
      window as unknown as {
        G7Core: {
          state: {
            getLocal(): Record<string, unknown>;
            setLocal(v: Record<string, unknown>): void;
          };
        };
      }
    ).G7Core;

    const settings = await fetch('/api/modules/sirsoft-ecommerce/settings/payment', {
      headers: {
        Accept: 'application/json',
        Authorization: `Bearer ${localStorage.getItem('auth_token')}`,
      },
    }).then((r) => r.json());

    const accounts = (settings?.data?.order_settings?.bank_accounts ?? []) as Array<
      Record<string, unknown>
    >;
    if (accounts.length === 0) return { ok: false, reason: '등록된 입금 계좌가 없습니다.' };

    const local = core.state.getLocal();
    const shipping = (local.shipping ?? {}) as Record<string, unknown>;
    const orderer = (local.orderer ?? {}) as Record<string, unknown>;

    core.state.setLocal({
      selectedDbank: accounts[0],
      orderer: { ...orderer, phone: '010-1234-5678' },
      shipping: {
        ...shipping,
        recipient_name: '테스트수령인',
        recipient_phone: '010-1234-5678',
        zipcode: '06236',
        address: '서울특별시 강남구 테헤란로 152',
        address_detail: '10층',
      },
    });
    return { ok: true, reason: '' };
  });

  expect(filled.ok, filled.reason).toBe(true);

  const submit = page.getByRole('button', { name: /결제하기/ });
  await expect(submit).toBeEnabled({ timeout: 10_000 });

  // 주문 생성 실패를 "화면이 안 넘어감" 으로 뭉뚱그리면 원인을 알 수 없다.
  // 서버 응답을 붙잡아 실패 시 본문을 그대로 드러낸다.
  const orderPost = page.waitForResponse(
    (r) => r.url().includes('/user/orders') && r.request().method() === 'POST',
    { timeout: 30_000 }
  );
  await submit.click();

  const posted = await orderPost;
  expect(
    posted.status(),
    `주문 생성 실패 (${posted.status()}): ${await posted.text()}`
  ).toBeLessThan(300);

  await page.waitForURL(/\/shop\/orders\/.+\/complete/, { timeout: 30_000 });
  const orderNumber = page.url().match(/orders\/([^/]+)\/complete/)?.[1];
  expect(orderNumber, '주문번호를 URL 에서 얻지 못했습니다.').toBeTruthy();
  return orderNumber as string;
}

test.describe('현금영수증 전주기 (신청 → 입금확인 → 자동발급 → 금액변동 재발급)', () => {
  // 각 테스트가 실 PG(토스) 왕복을 여러 번 수행한다. 병렬로 돌리면 동시 발급 요청이 겹쳐
  // 응답 지연이 누적되고, 그 지연이 "모달이 안 닫힘" 같은 UI 결함으로 오인된다.
  test.describe.configure({ mode: 'serial' });

  // 한 테스트가 주문생성 → 입금확인 → PG 발급 → 부분취소 → PG 취소 → PG 재발급을 모두 밟는다.
  // PG 왕복이 여러 번이라 기본 30초로는 부족하다 (초과 시 모달 대기 실패로 위장된다).
  test.setTimeout(180_000);

  test('무통장 신청 → 입금확인 → 자동발급 → 영수증 링크 노출', async ({
    page,
    orderManageToken,
  }) => {
    await authenticatePage(page, orderManageToken);
    await page.addInitScript((l) => localStorage.setItem('g7_locale', l), CART_LOCALE);
    await page.goto('/shop');
    await page.waitForLoadState('domcontentloaded');

    const provider = await cashReceiptProvider(page);
    test.skip(provider === null, '현금영수증 발급사가 설정되지 않은 환경 — 발급 경로 자체가 없음');

    const orderNumber = await placeDbankOrderWithCashReceipt(page, '01012341234');

    // 주문 조회 → 입금 전에는 발급 이력이 없어야 한다.
    const orderId = await resolveOrderId(page, orderNumber);

    const before = await readReceiptState(page, orderId);
    expect(before.active, '입금 전에는 발급된 영수증이 없어야 한다').toBeNull();

    // 관리자 화면에서 입금확인 — 자동발급 리스너가 여기서 발화한다.
    await page.goto(`/admin/ecommerce/orders/${orderId}`);
    await confirmDeposit(page, before.total);

    // 자동발급 결과를 서버 상태로 단언한다.
    await expect
      .poll(async () => (await readReceiptState(page, orderId)).active?.issue_status, {
        timeout: 30_000,
      })
      .toBe('COMPLETED');

    const after = await readReceiptState(page, orderId);
    expect(after.active?.provider).toBe(provider);
    expect(Number(after.active?.amount)).toBe(before.total);
    expect(after.active?.issue_number, '발급번호가 없다').toBeTruthy();
    expect(String(after.active?.receipt_url ?? ''), '영수증 URL 이 없다').toMatch(/^https?:\/\//);

    // 식별번호는 마스킹되어야 한다 (원문 노출 금지).
    expect(String(after.active?.identifier_masked ?? '')).not.toContain('01012341234');

    // 화면에도 링크가 노출된다.
    await page.reload();
    await expect(page.locator('a[href^="http"]', { hasText: /영수증/ }).first()).toBeVisible({
      timeout: 15_000,
    });
  });

  test('자동발급 → 부분환불 → 전액취소 후 재발급 (발급 회차 증가)', async ({
    page,
    orderManageToken,
  }) => {
    await authenticatePage(page, orderManageToken);
    await page.addInitScript((l) => localStorage.setItem('g7_locale', l), CART_LOCALE);
    await page.goto('/shop');
    await page.waitForLoadState('domcontentloaded');

    const provider = await cashReceiptProvider(page);
    test.skip(provider === null, '현금영수증 발급사가 설정되지 않은 환경');

    const orderNumber = await placeDbankOrderWithCashReceipt(page, '01055556666');
    const orderId = await resolveOrderId(page, orderNumber);

    const before = await readReceiptState(page, orderId);

    // 입금확인 → 자동발급
    await page.goto(`/admin/ecommerce/orders/${orderId}`);
    await confirmDeposit(page, before.total);

    await expect
      .poll(async () => (await readReceiptState(page, orderId)).active?.issue_status, {
        timeout: 30_000,
      })
      .toBe('COMPLETED');

    const issued = await readReceiptState(page, orderId);
    const firstReceiptId = issued.active?.id;
    const firstNumber = issued.active?.issue_number;
    expect(firstReceiptId, '최초 발급 영수증을 식별하지 못했다').toBeTruthy();

    // 부분취소 — 주문상품 1건(수량 2) 중 1 개만 취소해 주문 금액을 낮춘다.
    await page.reload();
    await page.waitForLoadState('networkidle');

    // 주문상품 행이 렌더될 때까지 기다린다. 주문 데이터는 비동기로 로드되므로
    // 바로 체크박스를 누르면 헤더(전체선택)만 있는 상태를 건드리게 된다.
    const rowCheckbox = page.locator('input[type=checkbox]').nth(1);
    await rowCheckbox.waitFor({ state: 'visible', timeout: 20_000 });
    await rowCheckbox.click();

    // 화면 문구("N개 선택됨")가 아니라 핸들러가 실제로 읽는 앱 상태를 단언한다.
    // buildOrderDetailBulkConfirmData 는 _local.selectedProducts 를 읽으며,
    // 이 값이 비어 있으면 취소할 항목이 없다고 판단해 모달을 열지 않는다.
    await expect
      .poll(
        async () =>
          page.evaluate(() => {
            const g = (
              window as unknown as {
                G7Core?: { state?: { getLocal?(): Record<string, unknown> } };
              }
            ).G7Core;
            return ((g?.state?.getLocal?.()?.selectedProducts ?? []) as unknown[]).length;
          }),
        { timeout: 10_000 }
      )
      .toBeGreaterThan(0);

    // 주문상태 Select 는 네이티브 <select> 가 아니라 커스텀 listbox 다 (Select.tsx).
    // 옵션 클릭이 합성 change 이벤트를 만들어 _local.batchOrderStatus 를 커밋한다.
    await page.getByRole('button', { name: '주문상태 변경' }).click();
    const statusListbox = page.getByRole('listbox').last();
    await statusListbox.waitFor({ state: 'visible', timeout: 10_000 });
    await statusListbox.getByRole('option', { name: '주문취소', exact: true }).click();

    await expect(statusListbox).toBeHidden({ timeout: 10_000 });

    // 핸들러(buildOrderDetailBulkConfirmData)는 _local.batchOrderStatus 를 읽는다.
    // 화면상 드롭다운 라벨이 바뀌어도 이 상태가 커밋되지 않으면 핸들러는 경고 토스트만
    // 띄우고 조용히 반환한다 — 증상은 "모달이 안 열림" 이라 원인을 오해하기 쉽다.
    await expect
      .poll(
        async () =>
          page.evaluate(() => {
            const g = (
              window as unknown as {
                G7Core?: { state?: { getLocal?(): Record<string, unknown> } };
              }
            ).G7Core;
            return (g?.state?.getLocal?.()?.batchOrderStatus ?? null) as string | null;
          }),
        { timeout: 10_000 }
      )
      .toBe('cancelled');

    const applyButton = page.getByRole('button', { name: '일괄변경' });
    await expect(applyButton).toBeEnabled({ timeout: 10_000 });
    await applyButton.click();

    const cancelModal = page.getByRole('dialog');
    await cancelModal.waitFor({ state: 'visible', timeout: 30_000 });

    // 취소 수량을 1 로 낮춰 부분취소로 만든다 (기본값은 주문 수량 전량).
    // 전량이면 발급 대상 금액이 0 이 되어 "취소 후 재발급" 이 아니라 "발급 대상 소멸" 이 된다.
    const qty = cancelModal.locator('input[type=number]').first();
    await qty.fill('1');

    await cancelModal.locator('select').first().selectOption({ label: '관리자 취소' });
    await cancelModal.getByRole('button', { name: '주문 취소' }).click();
    await expect(cancelModal).toBeHidden({ timeout: 60_000 });

    // 금액이 줄었으면 기존 영수증은 전액취소되고 새 금액으로 재발급되어야 한다 (D7).
    // receipt_key 는 API 가 노출하지 않으므로(자격증명성 식별자) 영수증 행 id 로 판별한다.
    await expect
      .poll(async () => (await readReceiptState(page, orderId)).active?.id, {
        timeout: 60_000,
      })
      .not.toBe(firstReceiptId);

    const reissued = await readReceiptState(page, orderId);

    expect(reissued.active?.issue_status).toBe('COMPLETED');
    expect(Number(reissued.active?.amount), '재발급 금액이 취소 후 주문 금액과 달라졌다').toBe(
      reissued.total
    );
    expect(Number(reissued.active?.amount)).toBeLessThan(before.total);
    expect(reissued.active?.issue_number).not.toBe(firstNumber);

    // 이력에 취소 행이 남아야 한다 — 부분환불이 영수증에 반영되지 않으면
    // 고객이 실제 결제액과 다른 금액의 영수증을 계속 보유하게 된다.
    const cancelRow = reissued.history.find((r) => r.transaction_type === 'cancel');
    expect(cancelRow, '취소 이력이 없다 — 부분취소가 영수증에 반영되지 않았다').toBeTruthy();
    expect(cancelRow?.issue_status).toBe('COMPLETED');

    // 부분취소인데 영수증을 부분취소하면 안 된다 — 취소 행 금액은 원 발급액 전액이다 (D7).
    expect(Number(cancelRow?.amount)).toBe(before.total);

    // 이력은 발급 → 취소 → 재발급 3건이어야 한다.
    expect(reissued.history.length).toBe(3);
  });

  test('입금 전에는 발급 버튼이 비활성 — 발급 시도가 불가능하다', async ({
    page,
    orderManageToken,
  }) => {
    await authenticatePage(page, orderManageToken);
    await page.addInitScript((l) => localStorage.setItem('g7_locale', l), CART_LOCALE);
    await page.goto('/shop');
    await page.waitForLoadState('domcontentloaded');

    const provider = await cashReceiptProvider(page);
    test.skip(provider === null, '현금영수증 발급사가 설정되지 않은 환경');

    const orderNumber = await placeDbankOrderWithCashReceipt(page, '01077778888');
    const orderId = await resolveOrderId(page, orderNumber);

    await page.goto(`/admin/ecommerce/orders/${orderId}`);
    await page.waitForLoadState('domcontentloaded');

    // 입금 전 상태에서는 발급 버튼이 없어야 한다 (입금확인 후에만 발급 가능).
    await expect(page.getByRole('button', { name: '현금영수증 발급' })).toHaveCount(0);

    // 서버도 같은 판정을 해야 한다 — UI 만 숨기고 API 가 열려 있으면 우회 발급이 가능하다.
    // 필드명은 FormRequest 규칙(receipt_type)과 일치해야 한다. 틀린 키를 보내면 "미입금 거부"가
    // 아니라 "필수값 누락"으로 422 가 나서, 가드가 죽어 있어도 테스트는 통과해 버린다.
    const forced = await api(
      page,
      `/api/modules/sirsoft-ecommerce/admin/orders/${orderId}/cash-receipt`,
      {
        method: 'POST',
        body: { receipt_type: 'income', identifier_type: 'phone', identifier: '01012341234' },
      }
    );
    expect(forced.status, `입금 전 발급이 서버에서 허용됐다: ${forced.body}`).toBeGreaterThanOrEqual(400);

    // 거부 사유가 "미입금"이어야 한다 — 검증 실패로 인한 422 와 구분한다.
    expect(
      JSON.parse(forced.body)?.errors?.error_code,
      `미입금 가드가 아닌 다른 사유로 거부됐다: ${forced.body}`
    ).toBe('PAYMENT_NOT_PAID');

    const state = await readReceiptState(page, orderId);
    expect(state.active, '입금 전인데 영수증이 발급되었다').toBeNull();
  });

  test('미신청 주문 → 입금완료 후 사후 발급 (유저가 직접 발급)', async ({
    page,
    orderManageToken,
  }) => {
    await authenticatePage(page, orderManageToken);
    await page.addInitScript((l) => localStorage.setItem('g7_locale', l), CART_LOCALE);
    await page.goto('/shop');
    await page.waitForLoadState('domcontentloaded');

    const provider = await cashReceiptProvider(page);
    test.skip(provider === null, '현금영수증 발급사가 설정되지 않은 환경');

    // 자진발급이 켜져 있으면 미신청 주문도 입금완료 시 국세청 지정번호로 자동발급된다.
    // 그 경우 "미발급 상태의 입금완료 주문" 자체가 만들어지지 않으므로 사후 발급을 검증할 수 없다.
    // (자진발급 자체의 동작은 아래 '자진발급' 테스트가 검증한다.)
    test.skip(
      await selfIssueEnabled(page),
      '자진발급이 켜진 환경 — 미신청 주문도 자동발급되어 사후 발급 대상이 생기지 않음'
    );

    // 신청 없이 무통장 주문 → 자진발급 꺼짐 + 미신청이므로 발급되지 않아야 한다.
    const orderNumber = await placeDbankOrderWithCashReceipt(page, null);
    const orderId = await resolveOrderId(page, orderNumber);
    const before = await readReceiptState(page, orderId);

    await page.goto(`/admin/ecommerce/orders/${orderId}`);
    await confirmDeposit(page, before.total);

    // 입금완료 후에도 영수증은 없어야 한다 — 신청하지 않았고 자진발급도 꺼져 있으므로.
    expect(
      (await readReceiptState(page, orderId)).active,
      '신청하지 않았고 자진발급도 꺼졌는데 발급되었다'
    ).toBeNull();

    // 이제 사후 발급 — 유저 경로로 발급한다 (입금완료 + 미발급이므로 허용되어야 한다).
    const issued = await api(
      page,
      `/api/modules/sirsoft-ecommerce/user/orders/${orderId}/cash-receipt`,
      {
        method: 'POST',
        body: { receipt_type: 'income', identifier_type: 'phone', identifier: '01099998888' },
      }
    );
    expect(issued.status, `사후 발급이 거부됐다: ${issued.body}`).toBe(200);

    await expect
      .poll(async () => (await readReceiptState(page, orderId)).active?.issue_status, {
        timeout: 30_000,
      })
      .toBe('COMPLETED');

    const after = await readReceiptState(page, orderId);
    expect(Number(after.active?.amount)).toBe(before.total);
    expect(after.active?.issue_number, '발급번호가 없다').toBeTruthy();
    expect(String(after.active?.identifier_masked ?? '')).not.toContain('01099998888');

    // 이미 발급된 주문의 재발급 시도는 409 여야 한다 (중복 발급 차단).
    const again = await api(
      page,
      `/api/modules/sirsoft-ecommerce/user/orders/${orderId}/cash-receipt`,
      {
        method: 'POST',
        body: { receipt_type: 'income', identifier_type: 'phone', identifier: '01099998888' },
      }
    );
    expect(again.status, `이미 발급된 주문에 중복 발급이 허용됐다: ${again.body}`).toBe(409);
    expect(JSON.parse(again.body)?.errors?.error_code).toBe('ALREADY_ISSUED');
  });

  test('자진발급 ON + 미신청 주문 → 국세청 지정번호로 소득공제 자동발급', async ({
    page,
    orderManageToken,
  }) => {
    await authenticatePage(page, orderManageToken);
    await page.addInitScript((l) => localStorage.setItem('g7_locale', l), CART_LOCALE);
    await page.goto('/shop');
    await page.waitForLoadState('domcontentloaded');

    const provider = await cashReceiptProvider(page);
    test.skip(provider === null, '현금영수증 발급사가 설정되지 않은 환경');
    test.skip(!(await selfIssueEnabled(page)), '자진발급이 꺼진 환경 — 이 경로가 존재하지 않음');

    // 신청하지 않은 주문이라도 자진발급이 켜져 있으면 입금완료 시 발급된다.
    // (현금 결제분을 미발급으로 남기지 않기 위한 제도적 처리 — 국세청 지정번호로 소득공제 발급)
    const orderNumber = await placeDbankOrderWithCashReceipt(page, null);
    const orderId = await resolveOrderId(page, orderNumber);
    const before = await readReceiptState(page, orderId);

    await page.goto(`/admin/ecommerce/orders/${orderId}`);
    await confirmDeposit(page, before.total);

    await expect
      .poll(async () => (await readReceiptState(page, orderId)).active?.issue_status, {
        timeout: 60_000,
      })
      .toBe('COMPLETED');

    const issued = await readReceiptState(page, orderId);

    // 자진발급은 제도상 소득공제용만 가능하다 (지출증빙 자진발급 불가).
    expect(issued.active?.receipt_type, '자진발급인데 소득공제가 아니다').toBe('income');
    expect(Number(issued.active?.amount)).toBe(before.total);
    expect(issued.active?.issue_number, '발급번호가 없다').toBeTruthy();

    // 국세청 지정번호(0100001234)로 발급되어야 한다 — 마스킹 뒤 4자리로 확인한다.
    expect(
      String(issued.active?.identifier_masked ?? ''),
      '자진발급 지정번호가 아닌 식별번호로 발급되었다'
    ).toContain('1234');
  });

  test('지출증빙 + 자진발급 지정번호(0100001234) 조합은 거부된다 (422)', async ({
    page,
    orderManageToken,
  }) => {
    await authenticatePage(page, orderManageToken);
    await page.addInitScript((l) => localStorage.setItem('g7_locale', l), CART_LOCALE);
    await page.goto('/shop');
    await page.waitForLoadState('domcontentloaded');

    const provider = await cashReceiptProvider(page);
    test.skip(provider === null, '현금영수증 발급사가 설정되지 않은 환경');

    // 입금확인을 하지 않는다 — 용도×식별번호 조합 검증은 FormRequest 단계라
    // 발급 가드(미입금/기발급)보다 **먼저** 걸린다. 입금까지 진행하면 자진발급 설정에 따라
    // 이미 발급된 상태가 되어 409(ALREADY_ISSUED)가 먼저 나고, 정작 검증 규칙을 못 건드린다.
    const orderNumber = await placeDbankOrderWithCashReceipt(page, null);
    const orderId = await resolveOrderId(page, orderNumber);

    const endpoint = `/api/modules/sirsoft-ecommerce/admin/orders/${orderId}/cash-receipt`;

    // 자진발급 지정번호는 소득공제 전용이다 (지출증빙 자진발급은 제도상 불가).
    const selfIssueAsExpense = await api(page, endpoint, {
      method: 'POST',
      body: { receipt_type: 'expense', identifier_type: 'phone', identifier: '0100001234' },
    });
    expect(
      selfIssueAsExpense.status,
      `지출증빙 + 자진발급번호가 허용됐다: ${selfIssueAsExpense.body}`
    ).toBe(422);

    // 소득공제 + 사업자등록번호도 거부된다 (INCOME 은 phone/card 만 허용).
    const incomeWithBusiness = await api(page, endpoint, {
      method: 'POST',
      body: { receipt_type: 'income', identifier_type: 'business', identifier: '1234567890' },
    });
    expect(
      incomeWithBusiness.status,
      `소득공제 + 사업자등록번호가 허용됐다: ${incomeWithBusiness.body}`
    ).toBe(422);

    // 반증: 허용 조합(소득공제 + 자진발급번호)은 검증을 통과해야 한다.
    // 거부가 "자진발급번호 자체 금지"로 과잉 적용되면 여기서 드러난다.
    // 미입금 주문이므로 검증 통과 후 발급 가드에 걸려 PAYMENT_NOT_PAID 가 나온다 —
    // 즉 422 라도 error_code 가 검증 오류가 아닌 발급 가드여야 한다.
    const selfIssueAsIncome = await api(page, endpoint, {
      method: 'POST',
      body: { receipt_type: 'income', identifier_type: 'phone', identifier: '0100001234' },
    });
    expect(
      JSON.parse(selfIssueAsIncome.body)?.errors?.error_code,
      `소득공제 + 자진발급번호가 검증에서 거부됐다 — 과잉 차단: ${selfIssueAsIncome.body}`
    ).toBe('PAYMENT_NOT_PAID');

    // 거부된 조합이 발급을 남기지 않았는지 확인한다 — 422 를 주고도 발급하면 최악이다.
    expect(
      (await readReceiptState(page, orderId)).active,
      '거부된 조합인데 영수증이 발급되었다'
    ).toBeNull();
  });
});
