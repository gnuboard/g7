/**
 * 관리자 클레임 상세 — 상품 행 송장 「송장만 저장」 흐름 (#599 상품 행 송장)
 *
 * 송장은 상품 행마다 있다. 브라우저로만 드러나는 축을 본다:
 *
 *  ① **[송장만 저장] 은 두 번 누른다.** 처음은 입력칸을 열고, 칸이 열린 뒤에는 수량 확인 모달을
 *     「송장만 저장」 모드로 연다. 모달의 수량을 줄이면 그 행이 쪼개진다는 안내가 선다(R4).
 *  ② **수량 1 만 저장하면 행이 1·나머지로 나뉘고 송장은 1 행에만 붙는다.** 상태는 그대로다(R7).
 *  ③ **배송 정보 탭은 그 운송장을 한 묶음으로 보여 준다**(입력칸 없음, R9 묶음).
 *  ④ **택배사만 고르고 운송장을 비우면 요청이 나가지 않는다**(R2 — 서버도 `claim_tracking_incomplete`).
 *  ⑤ **운송장 한 묶음의 [도착 처리] 는 그 운송장의 행만 옮긴다**(R9). 셔츠 3개 중 2개 + 바지를 운송장 A,
 *     나머지 셔츠 1개를 운송장 B 로 회수중에 두고 A 만 도착 처리하면 A 행만 회수도착이고 B 는 회수중이다.
 *     헤더는 모든 행이 도착해야 회수도착으로 간다(S2).
 *  ⑥ **재발송 운송장이 둘이면 구매자 화면이 상품마다 제 운송장을 보여 준다**(R11) — 신청서 상세의 운송장
 *     묶음과 주문 상세의 후속 주문상품 줄이 같은 운송장을 가리킨다.
 *  ⑦ **선교환은 상세 화면에서 전환해 발송 → 회수 송장(수량 1 쪼개기) → 완료까지 간다**(§3-3·§3-4·R10) —
 *     회수 송장이 없는 행은 완료 대상에 들지 않고, 쪼갠 행마다 후속 주문상품도 따로 선다.
 *  ⑧ **완료 모달의 「미회수로 완료」 는 사유가 있어야 나가고, 그 행의 재고를 되돌리지 않는다**(D3).
 *
 * 신청서는 목록 API 로 찾는다 — 테스트마다 필요한 모양(상태·진행 방식·수량·송장 유무)을 갖춘 교환·반품.
 * 없으면 그 사실을 드러내며 건너뛴다(없는 데이터를 있는 척 단언하지 않는다). ②·⑤~⑧ 은 라이브 쓰기다 —
 * 실측 전용 신청서(`seed-tracking-fixtures.php` 의 FX-A·FX-B·FX-G 형태)에서 돌린다. ⑦ 은 쇼핑몰 설정
 * `claim.advance_exchange_enabled` 가 켜져 있어야 하고(꺼져 있으면 사유를 밝히고 건너뛴다), ⑥ 은 그 주문을
 * 가진 구매자의 토큰을 환경변수 `G7_E2E_CLAIM_BUYER_TOKEN` 으로 받는다(`playwright:issue-token` 은 새 계정만
 * 만들어 기존 주문의 주인이 될 수 없다).
 *
 * 소유 시나리오: `claim-item-tracking`, `claim-advance-exchange`
 * @axes claim_type=exchange|return
 * @effects claim_item_tracking_only_opens_inputs_then_quantity_modal, claim_item_tracking_split_writes_only_the_chosen_quantity, claim_item_tracking_group_shows_on_shipping_tab, claim_item_tracking_incomplete_pair_is_blocked_before_request
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';

const API = '/api/modules/sirsoft-ecommerce/admin';
const DETAIL_URL = (claimNumber: string) => `/admin/ecommerce/claims/${encodeURIComponent(claimNumber)}`;

/** 발견한 대상 신청서와 행 */
type Target = { claimNumber: string; claimType: string; itemId: number; quantity: number; productName: string };

/**
 * 승인 상태이고 수량 2 이상·쪼갤 수 있는 행이 있는 신청서를 찾습니다.
 *
 * @param request Playwright API 요청 컨텍스트
 * @param token 인증 토큰
 * @returns 대상 (없으면 null)
 */
async function discoverSplittable(
  request: import('@playwright/test').APIRequestContext,
  token: string,
): Promise<Target | null> {
  const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' };

  for (const claimType of ['exchange', 'return']) {
    const list = await request.get(`${API}/claims?type=${claimType}&per_page=30`, { headers });
    const rows: Array<{ claim_number: string; status?: string }> = (await list.json())?.data?.data ?? [];

    for (const row of rows) {
      const detail = (await (await request.get(`${API}/claims/${encodeURIComponent(row.claim_number)}`, { headers })).json())?.data;

      if (detail?.claim?.status !== 'approved') {
        continue;
      }

      const item = (detail?.claim?.items ?? []).find(
        (i: any) => (i.quantity ?? 0) >= 2 && i.is_splittable === true && !i.collection_tracking_number,
      );

      if (item) {
        return { claimNumber: row.claim_number, claimType, itemId: item.id, quantity: item.quantity, productName: item.product_name ?? '' };
      }
    }
  }

  return null;
}

/**
 * 상세를 열고 상세 응답이 올 때까지 기다립니다.
 *
 * @param page 페이지
 * @param claimNumber 클레임 번호
 */
async function openDetail(page: import('@playwright/test').Page, claimNumber: string): Promise<void> {
  const waitDetail = page.waitForResponse(
    (r) => r.url().includes(`${API}/claims/${encodeURIComponent(claimNumber)}`) && r.request().method() === 'GET',
    { timeout: 30_000 },
  );
  await page.goto(DETAIL_URL(claimNumber));
  await waitDetail;
  await page.waitForSelector('[data-section-id="claim_info"]', { timeout: 30_000 });
}

/**
 * 상품 표에서 그 행의 체크박스를 고릅니다. (상품명으로 줄을 찾는다)
 *
 * @param page 페이지
 * @param productName 상품명
 */
async function checkRow(page: import('@playwright/test').Page, productName: string): Promise<void> {
  const row = page.locator('#claim_items_datagrid tbody tr', { hasText: productName }).first();
  await row.locator('input[type="checkbox"]').first().check();
}

/**
 * 모달을 찾습니다. 모달 셸은 `data-ref` 를 DOM id 로 옮기지 않는다 — `#modal_*` 는 늘 0개라
 * 「모달이 없다」 는 단언이 공허해진다. 그래서 대화상자 역할 + 모달 안에만 있는 요소로 찾는다.
 *
 * @param page 페이지
 * @param innerSelector 그 모달 안에만 있는 요소의 선택자
 * @returns 모달 locator
 */
function modalWith(page: import('@playwright/test').Page, innerSelector: string) {
  return page.getByRole('dialog').filter({ has: page.locator(innerSelector) });
}

test.describe('관리자 클레임 상세 — 상품 행 송장', () => {
  /**
   * 처음 누르면 입력칸만 열리고 요청은 나가지 않는다. 택배사만 고른 채 다시 누르면 모달도 요청도 없다.
   */
  // @scenario claim_type=exchange
  // @effects claim_item_tracking_only_opens_inputs_then_quantity_modal, claim_item_tracking_incomplete_pair_is_blocked_before_request
  test('[송장만 저장] 은 입력칸을 먼저 열고, 반쪽 송장은 요청 전에 막는다', async ({ page, request, claimsManageToken }) => {
    const target = await discoverSplittable(request, claimsManageToken);
    test.skip(target === null, '승인 상태·수량 2 이상 신청서가 없다 — seed-tracking-fixtures.php(FX-A) 선행');

    await authenticatePage(page, claimsManageToken);
    await openDetail(page, target!.claimNumber);

    const writes: string[] = [];
    page.on('request', (req) => {
      if (['PATCH', 'POST', 'PUT'].includes(req.method()) && req.url().includes('/tracking')) writes.push(`${req.method()} ${req.url()}`);
    });

    await checkRow(page, target!.productName);
    await page.locator('#ci_009_tracking_only').click();

    await expect(page.locator('#ci_006_tracking_row')).toBeVisible();
    await expect(page.locator('#ci_008_tracking_number')).toHaveAttribute('maxlength', '50');
    await expect(modalWith(page, '#ciq_005_confirm')).toHaveCount(0);

    // 택배사만 고르고 운송장은 비운다 → 모달도 요청도 없다.
    await page.locator('#ci_007_carrier button').first().click();
    await page.getByRole('listbox').getByRole('option').nth(1).click();
    await page.locator('#ci_008_tracking_number').fill('');
    await page.locator('#ci_009_tracking_only').click();

    await expect(modalWith(page, '#ciq_005_confirm')).toHaveCount(0);
    expect(writes).toEqual([]);
  });

  /**
   * 수량 1 만 저장하면 그 행이 1·나머지로 나뉘고 송장은 1 행에만 붙는다. 상태는 승인 그대로이고,
   * 배송 정보 탭에는 그 운송장 묶음이 선다.
   */
  // @scenario claim_type=exchange
  // @effects claim_item_tracking_split_writes_only_the_chosen_quantity, claim_item_tracking_group_shows_on_shipping_tab
  test('수량 1 만 [송장만 저장] 하면 행이 쪼개지고 운송장 묶음이 선다', async ({ page, request, claimsManageToken }) => {
    const target = await discoverSplittable(request, claimsManageToken);
    test.skip(target === null, '승인 상태·수량 2 이상 신청서가 없다 — seed-tracking-fixtures.php(FX-A) 선행');

    const trackingNumber = `PW${Date.now()}`.slice(0, 20);

    await authenticatePage(page, claimsManageToken);
    await openDetail(page, target!.claimNumber);

    await checkRow(page, target!.productName);
    await page.locator('#ci_009_tracking_only').click();
    await page.locator('#ci_007_carrier button').first().click();
    await page.getByRole('listbox').getByRole('option').nth(1).click();
    await page.locator('#ci_008_tracking_number').fill(trackingNumber);
    await page.locator('#ci_009_tracking_only').click();

    const modal = modalWith(page, '#ciq_005_confirm');
    await expect(modal).toBeVisible();
    await expect(page.locator('#ciq_007_tracking_only_description')).toBeVisible();

    await modal.locator('#ciq_002_rows input[type="number"]').first().fill('1');
    await expect(page.locator('#ciq_008_split_hint')).toBeVisible();

    const patch = page.waitForResponse(
      (r) => r.request().method() === 'PATCH' && r.url().includes('/options/tracking'),
      { timeout: 30_000 },
    );
    await page.locator('#ciq_005_confirm').click();
    const response = await patch;
    expect(response.status()).toBe(200);

    const body = JSON.parse(response.request().postData() ?? '{}');
    expect(body.tracking_number).toBe(trackingNumber);
    expect(body.items).toEqual([{ id: target!.itemId, quantity: 1 }]);

    // 서버 상태 — 1 행에만 송장, 나머지는 빈칸, 헤더 상태 불변.
    const headers = { Authorization: `Bearer ${claimsManageToken}`, Accept: 'application/json' };
    const detail = (await (await request.get(`${API}/claims/${encodeURIComponent(target!.claimNumber)}`, { headers })).json())?.data;
    const withTracking = (detail.claim.items as any[]).filter((i) => i.collection_tracking_number === trackingNumber);
    const original = (detail.claim.items as any[]).find((i) => i.id === target!.itemId);

    expect(detail.claim.status).toBe('approved');
    expect(withTracking.map((i) => i.quantity)).toEqual([1]);
    expect(original.quantity + withTracking[0].quantity === target!.quantity || original.id === withTracking[0].id).toBe(true);

    // 배송 정보 탭 — 입력칸 없이 그 운송장 묶음이 선다.
    const shipping = page.locator('[data-section-id="shipping_info"]');
    await expect(shipping).toContainText(trackingNumber);
    await expect(shipping.locator('input, select')).toHaveCount(0);
  });
});

/** 관리자 상세 응답 `data` (필요한 칸만 느슨하게 본다) */
type Detail = any;

/**
 * 인증 헤더를 만듭니다.
 *
 * @param token 인증 토큰
 * @returns 요청 헤더
 */
function authHeaders(token: string): Record<string, string> {
  return { Authorization: `Bearer ${token}`, Accept: 'application/json' };
}

/**
 * 번호로 관리자 상세 응답을 읽습니다.
 *
 * @param request Playwright API 요청 컨텍스트
 * @param token 인증 토큰
 * @param claimNumber 클레임 번호
 * @returns 상세 응답 `data`
 */
async function readDetail(
  request: import('@playwright/test').APIRequestContext,
  token: string,
  claimNumber: string,
): Promise<Detail> {
  const response = await request.get(`${API}/claims/${encodeURIComponent(claimNumber)}`, { headers: authHeaders(token) });
  expect(response.status(), `상세 GET ${claimNumber}`).toBe(200);

  return (await response.json())?.data;
}

/**
 * 교환 신청서 가운데 조건에 맞는 첫 건을 찾습니다(최근 접수 순).
 *
 * @param request Playwright API 요청 컨텍스트
 * @param token 인증 토큰
 * @param statuses 목록 필터로 좁힐 헤더 상태
 * @param accept 상세 응답을 받아 대상이면 true
 * @returns 조건에 맞는 상세 응답 (없으면 null)
 */
async function discoverExchange(
  request: import('@playwright/test').APIRequestContext,
  token: string,
  statuses: string[],
  accept: (detail: Detail) => boolean,
): Promise<Detail | null> {
  const query = statuses.map((s) => `statuses[]=${encodeURIComponent(s)}`).join('&');
  const list = await request.get(`${API}/claims?type=exchange&per_page=30&${query}`, { headers: authHeaders(token) });
  const rows: Array<{ claim_number: string }> = (await list.json())?.data?.data ?? [];

  for (const row of rows) {
    const detail = await readDetail(request, token, row.claim_number);

    if (accept(detail)) {
      return detail;
    }
  }

  return null;
}

/** 행에 송장이 하나도 없는가 (회수·재발송 모두) */
const hasNoTracking = (item: any): boolean => !item.collection_tracking_number && !item.redelivery_tracking_number;

/**
 * 상품 단위 전이를 API 로 보냅니다 (화면 밖 선행 조건 — 화면이 쓰는 같은 엔드포인트).
 *
 * @param request Playwright API 요청 컨텍스트
 * @param token 인증 토큰
 * @param claimId 교환 신청서 ID
 * @param body 요청 본문
 * @returns 응답 JSON
 */
async function patchItems(
  request: import('@playwright/test').APIRequestContext,
  token: string,
  claimId: number,
  body: Record<string, unknown>,
): Promise<any> {
  const response = await request.patch(`${API}/exchanges/${claimId}/options/bulk-status`, { headers: authHeaders(token), data: body });
  const json = await response.json();
  expect(response.status(), `bulk-status ${JSON.stringify(body)} → ${JSON.stringify(json?.message ?? json?.errors ?? '')}`).toBe(200);

  return json;
}

/**
 * 「송장만 저장」 을 API 로 보냅니다.
 *
 * @param request Playwright API 요청 컨텍스트
 * @param token 인증 토큰
 * @param claimId 교환 신청서 ID
 * @param body 요청 본문
 * @returns 응답 JSON
 */
async function patchTracking(
  request: import('@playwright/test').APIRequestContext,
  token: string,
  claimId: number,
  body: Record<string, unknown>,
): Promise<any> {
  const response = await request.patch(`${API}/exchanges/${claimId}/options/tracking`, { headers: authHeaders(token), data: body });
  const json = await response.json();
  expect(response.status(), `options/tracking ${JSON.stringify(body)} → ${JSON.stringify(json?.message ?? json?.errors ?? '')}`).toBe(200);

  return json;
}

/**
 * 상세 응답의 첫 활성 택배사 ID 를 고릅니다.
 *
 * @param detail 상세 응답
 * @returns 택배사 ID
 */
function firstCarrierId(detail: Detail): number {
  const carrier = (detail?.carriers ?? [])[0];
  expect(carrier, '활성 택배사가 없다').toBeTruthy();

  return Number(carrier.value ?? carrier.id);
}

/**
 * 테스트마다 겹치지 않는 운송장 번호를 만듭니다 (50자 제한 안).
 *
 * @param tag 구분 접두
 * @returns 운송장 번호
 */
function waybill(tag: string): string {
  return `PW${tag}${Date.now()}${Math.floor(Math.random() * 1000)}`.slice(0, 30);
}

/**
 * 상품 옵션의 현재 재고를 관리자 상품 API 로 읽습니다.
 *
 * @param request Playwright API 요청 컨텍스트
 * @param token 인증 토큰
 * @param productId 상품 ID
 * @param productOptionId 상품 옵션 ID
 * @returns 재고 수량
 */
async function readStock(
  request: import('@playwright/test').APIRequestContext,
  token: string,
  productId: number,
  productOptionId: number,
): Promise<number> {
  const response = await request.get(`${API}/products/${productId}`, { headers: authHeaders(token) });
  expect(response.status(), `상품 ${productId} GET`).toBe(200);
  const options: any[] = (await response.json())?.data?.options ?? [];
  const option = options.find((o) => Number(o.id) === Number(productOptionId));
  expect(option, `상품 ${productId} 에 옵션 ${productOptionId} 이 없다`).toBeTruthy();

  return Number(option.stock_quantity);
}

/**
 * 배송 정보 탭에서 그 운송장의 묶음을 찾습니다.
 *
 * @param page 페이지
 * @param direction 회수(collection)·재발송(redelivery)
 * @param trackingNumber 운송장 번호
 * @returns 묶음 locator
 */
function waybillGroup(page: import('@playwright/test').Page, direction: 'collection' | 'redelivery', trackingNumber: string) {
  const prefix = direction === 'collection' ? 'cs_006_collection_group_' : 'cs_015_redelivery_group_';

  return page.locator(`[data-section-id="shipping_info"] [id^="${prefix}"]`, { hasText: trackingNumber });
}

test.describe('관리자 클레임 상세 — 운송장 단위 도착 · 구매자 운송장 · 선교환 (#599 §7-3)', () => {
  /**
   * 셔츠 3개 중 2개 + 바지를 운송장 A, 셔츠 1개를 운송장 B 로 회수중에 둔다. 배송 정보 탭에서 A 묶음만
   * [도착 처리] 하면 A 의 두 행만 회수도착(도착 시각·회수 진행 collected)이고 B 는 회수중이다. 헤더는
   * B 까지 도착한 뒤에야 회수도착이 되고 전체 회수 완료 시각도 그때 찍힌다.
   */
  // @scenario claim_type=exchange, exchange_mode=standard, direction=collection
  // @effects claim_item_tracking_waybill_deliver_moves_only_that_group, tracking_deliver_moves_every_row_of_the_waybill, tracking_quantity_splits_the_row_and_the_remainder_keeps_its_value, header_arrival_stamp_waits_for_every_living_item
  test('운송장 A 묶음만 [도착 처리] 하면 A 행만 회수도착이고 헤더는 B 도착 뒤에 옮겨 간다', async ({ page, request, claimsManageToken }) => {
    test.setTimeout(120_000);

    // 승인 · 일반 교환 · 송장 없음 · 수량 3 이상 행 + 다른 행 (FX-A 형태 — 손대지 않은 것)
    const d0 = await discoverExchange(request, claimsManageToken, ['approved'], (d) => {
      const items: any[] = d?.claim?.items ?? [];

      return d?.claim?.status === 'approved'
        && d?.claim?.is_advance_exchange !== true
        && items.length >= 2
        && items.every((i) => i.status === 'approved' && hasNoTracking(i))
        && items.some((i) => (i.quantity ?? 0) >= 3 && i.is_splittable === true);
    });
    test.skip(d0 === null, '승인·일반 교환·송장 없음·수량 3 이상 행과 다른 행을 가진 신청서가 없다 — seed-tracking-fixtures.php(FX-A 형태) 선행');

    const claimId: number = d0.claim.id;
    const claimNumber: string = d0.claim.claim_number;
    const shirt = (d0.claim.items as any[]).find((i) => (i.quantity ?? 0) >= 3 && i.is_splittable === true);
    const pants = (d0.claim.items as any[]).find((i) => i.id !== shirt.id);
    const carrierId = firstCarrierId(d0);
    const waybillA = waybill('A');
    const waybillB = waybill('B');

    // 선행 조건 (API): 셔츠 (수량-1)개 + 바지 → 운송장 A 로 회수중, 남은 셔츠 1개 → 운송장 B 로 회수중.
    await patchItems(request, claimsManageToken, claimId, {
      status: 'collecting',
      carrier_id: carrierId,
      tracking_number: waybillA,
      items: [{ id: shirt.id, quantity: shirt.quantity - 1 }, { id: pants.id }],
    });
    const d1 = await readDetail(request, claimsManageToken, claimNumber);
    const shirtRows = (d1.claim.items as any[]).filter((i) => i.order_option_id === shirt.order_option_id);
    const remainder = shirtRows.find((i) => !i.collection_tracking_number);
    expect(shirtRows.map((i) => i.quantity).sort(), '셔츠 행이 (수량-1)·1 로 쪼개진다').toEqual([1, shirt.quantity - 1].sort());
    expect(remainder?.quantity, '남은 셔츠 행은 송장 없이 1개').toBe(1);
    expect(remainder?.status, '남은 셔츠 행은 승인 그대로').toBe('approved');

    await patchItems(request, claimsManageToken, claimId, {
      status: 'collecting',
      carrier_id: carrierId,
      tracking_number: waybillB,
      items: [{ id: remainder.id }],
    });
    const d2 = await readDetail(request, claimsManageToken, claimNumber);
    expect(d2.claim.status, '모든 행이 회수중이면 헤더도 회수중').toBe('collecting');
    expect((d2.claim.tracking_groups?.collection ?? []).length, '회수 운송장 묶음 2개').toBe(2);

    // 화면: 배송 정보 탭의 A 묶음 [도착 처리].
    await authenticatePage(page, claimsManageToken);
    await openDetail(page, claimNumber);

    const groupA = waybillGroup(page, 'collection', waybillA);
    const groupB = waybillGroup(page, 'collection', waybillB);
    await expect(groupA).toHaveCount(1);
    await expect(groupB).toHaveCount(1);
    await expect(groupA).toContainText(`${shirt.product_name}`);
    await expect(groupA).toContainText(`${pants.product_name}`);
    await expect(groupB).not.toContainText(`${pants.product_name}`);

    const deliverA = page.waitForResponse(
      (r) => r.request().method() === 'POST' && r.url().includes(`/exchanges/${claimId}/tracking/deliver`),
      { timeout: 30_000 },
    );
    await groupA.getByRole('button', { name: '도착 처리' }).click();
    const responseA = await deliverA;
    expect(responseA.status()).toBe(200);
    expect(JSON.parse(responseA.request().postData() ?? '{}')).toMatchObject({
      direction: 'collection',
      carrier_id: carrierId,
      tracking_number: waybillA,
    });

    // 서버 상태 — A 행만 회수도착, B 는 회수중, 헤더는 그대로.
    const d3 = await readDetail(request, claimsManageToken, claimNumber);
    const rowsA = (d3.claim.items as any[]).filter((i) => i.collection_tracking_number === waybillA);
    const rowsB = (d3.claim.items as any[]).filter((i) => i.collection_tracking_number === waybillB);
    expect(rowsA.map((i) => i.quantity).reduce((a, b) => a + b, 0), 'A 에는 셔츠 (수량-1) + 바지').toBe(shirt.quantity - 1 + pants.quantity);
    for (const row of rowsA) {
      expect(row.status, `A 행 ${row.id} 회수도착`).toBe('collected');
      expect(row.collection_state, `A 행 ${row.id} 회수 진행`).toBe('collected');
      expect(row.collected_at_formatted, `A 행 ${row.id} 도착 시각`).toBeTruthy();
    }
    expect(rowsB).toHaveLength(1);
    expect(rowsB[0].status, 'B 행은 회수중 그대로').toBe('collecting');
    expect(rowsB[0].collected_at_formatted ?? null, 'B 행은 도착 시각 없음').toBeFalsy();
    expect(d3.claim.status, 'B 가 남아 헤더는 회수중').toBe('collecting');
    expect(d3.claim.collection_completed_at_formatted ?? null, '전체 회수 완료 시각은 아직 없다').toBeFalsy();

    // 화면 — A 묶음에 도착 표시가 서고 버튼이 잠긴다. B 묶음은 도착 표시 없이 열려 있다.
    await expect(groupA.getByText('도착', { exact: true }).first()).toBeVisible();
    await expect(groupA.getByRole('button', { name: '도착 처리' })).toBeDisabled();
    await expect(groupB.getByText('도착', { exact: true })).toHaveCount(0);
    await expect(groupB.getByRole('button', { name: '도착 처리' })).toBeEnabled();

    // B 까지 도착 처리하면 헤더가 회수도착으로 가고 전체 회수 완료 시각이 찍힌다.
    const deliverB = page.waitForResponse(
      (r) => r.request().method() === 'POST' && r.url().includes(`/exchanges/${claimId}/tracking/deliver`),
      { timeout: 30_000 },
    );
    await groupB.getByRole('button', { name: '도착 처리' }).click();
    expect((await deliverB).status()).toBe(200);

    const d4 = await readDetail(request, claimsManageToken, claimNumber);
    expect(d4.claim.status, '모든 행이 도착하면 헤더 회수도착').toBe('collected');
    expect(d4.claim.collection_completed_at_formatted, '전체 회수 완료 시각').toBeTruthy();
    await expect(page.locator('#cs_002_collection_done_badge')).toBeVisible();
  });

  /**
   * 회수도착한 교환의 한 상품을 운송장 C, 다른 상품을 운송장 D 로 발송하면 구매자 신청서 상세에 재발송 묶음이
   * 둘 서고 각 묶음에는 제 상품만 담긴다. 주문 상세의 후속 주문상품 줄도 각자 제 운송장을 보인다(R11).
   */
  // @scenario claim_type=exchange, exchange_mode=standard, direction=redelivery
  // @effects claim_item_tracking_buyer_screens_show_each_rows_waybill, successor_shipping_uses_its_rows_redelivery_tracking
  test('재발송 운송장이 둘이면 구매자 신청서·주문 상세가 상품마다 제 운송장을 보인다', async ({ page, request, claimsManageToken }) => {
    test.setTimeout(120_000);

    const buyerToken = process.env.G7_E2E_CLAIM_BUYER_TOKEN ?? '';
    test.skip(buyerToken === '', '구매자 토큰(G7_E2E_CLAIM_BUYER_TOKEN)이 없다 — 회수도착한 교환 주문의 주인 토큰을 넘겨야 구매자 화면을 잰다');

    // 구매자 자신의 신청서 가운데: 회수도착 · 상품 2행 이상 · 재발송 송장 없음
    const userApi = '/api/modules/sirsoft-ecommerce/user/claims';
    const list = await request.get(`${userApi}?type=exchange&per_page=30`, { headers: authHeaders(buyerToken) });
    expect(list.status(), '구매자 신청서 목록').toBe(200);
    const rows: Array<{ claim_number: string }> = (await list.json())?.data?.data ?? [];

    let claimNumber: string | null = null;
    for (const row of rows) {
      const d = await readDetail(request, claimsManageToken, row.claim_number);
      const items: any[] = d?.claim?.items ?? [];

      if (d?.claim?.status === 'collected' && items.length >= 2 && items.every((i) => i.status === 'collected' && !i.redelivery_tracking_number)) {
        claimNumber = row.claim_number;
        break;
      }
    }
    test.skip(claimNumber === null, '그 구매자에게 회수도착·상품 2행·재발송 송장 없는 교환이 없다 — 픽스처(상품 2종 교환을 회수도착까지) 선행');

    const d0 = await readDetail(request, claimsManageToken, claimNumber!);
    const claimId: number = d0.claim.id;
    const [first, second] = d0.claim.items as any[];
    const carrierId = firstCarrierId(d0);
    const waybillC = waybill('C');
    const waybillD = waybill('D');

    // 선행 조건 (API): 상품마다 다른 운송장으로 발송중.
    await patchItems(request, claimsManageToken, claimId, { status: 'redelivering', carrier_id: carrierId, tracking_number: waybillC, items: [{ id: first.id }] });
    await patchItems(request, claimsManageToken, claimId, { status: 'redelivering', carrier_id: carrierId, tracking_number: waybillD, items: [{ id: second.id }] });

    const d1 = await readDetail(request, claimsManageToken, claimNumber!);
    const firstRow = (d1.claim.items as any[]).find((i) => i.id === first.id);
    const secondRow = (d1.claim.items as any[]).find((i) => i.id === second.id);
    expect(firstRow.redelivery_tracking_number).toBe(waybillC);
    expect(secondRow.redelivery_tracking_number).toBe(waybillD);
    expect(firstRow.new_order_option_id, '첫 행 후속 주문상품').toBeTruthy();
    expect(secondRow.new_order_option_id, '둘째 행 후속 주문상품').toBeTruthy();
    expect(firstRow.new_order_option_id).not.toBe(secondRow.new_order_option_id);

    // 구매자 화면 ① 신청서 상세 — 재발송 묶음 둘, 각 묶음에 제 상품만.
    await authenticatePage(page, buyerToken);
    await page.route('**/api/plugins/sirsoft-gdpr/consent/cookie/status*', (route) => route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ success: true, data: { consents: { necessary: true, functional: true, analytics: false, marketing: false }, needs_renewal: false, current_policy_version: '1' } }),
    }));

    const waitClaim = page.waitForResponse(
      (r) => r.url().includes(`${userApi}/${encodeURIComponent(claimNumber!)}`) && r.request().method() === 'GET',
      { timeout: 30_000 },
    );
    await page.goto(`/mypage/claims/${encodeURIComponent(claimNumber!)}`);
    expect((await waitClaim).status()).toBe(200);

    // 배송 카드의 운송장 묶음(테두리 카드) — 운송장마다 한 장이고 그 운송장에 담긴 상품만 적는다.
    const buyerGroup = (trackingNumber: string) => page.locator('div.rounded-lg.border.p-3', { hasText: trackingNumber });
    // 신청 상품 줄 — 줄마다 그 행의 재발송 운송장을 적는다.
    const buyerItemRow = (trackingNumber: string) => page.locator('div.flex.flex-col.pb-4', { hasText: trackingNumber });

    await expect(buyerGroup(waybillC)).toHaveCount(1, { timeout: 30_000 });
    await expect(buyerGroup(waybillD)).toHaveCount(1);
    await expect(buyerGroup(waybillC)).toContainText(firstRow.product_name);
    await expect(buyerGroup(waybillC)).not.toContainText(secondRow.product_name);
    await expect(buyerGroup(waybillD)).toContainText(secondRow.product_name);
    await expect(buyerGroup(waybillD)).not.toContainText(firstRow.product_name);
    await expect(buyerItemRow(waybillC)).toHaveCount(1);
    await expect(buyerItemRow(waybillC)).toContainText(firstRow.product_name);
    await expect(buyerItemRow(waybillC)).not.toContainText(waybillD);
    await expect(buyerItemRow(waybillD)).toHaveCount(1);
    await expect(buyerItemRow(waybillD)).toContainText(secondRow.product_name);

    // 구매자 화면 ② 주문 상세 — 후속 주문상품 줄마다 제 운송장.
    const orderNumber: string = d1.claim.order_number ?? d1.order?.order_number;
    expect(orderNumber, '주문번호').toBeTruthy();
    await page.goto(`/mypage/orders/${encodeURIComponent(orderNumber)}`);

    const orderLine = (orderOptionId: number) => page.locator('div.py-4', { has: page.locator(`#order_item_check_${orderOptionId}`) });
    await expect(orderLine(firstRow.new_order_option_id)).toContainText(waybillC, { timeout: 30_000 });
    await expect(orderLine(firstRow.new_order_option_id)).not.toContainText(waybillD);
    await expect(orderLine(secondRow.new_order_option_id)).toContainText(waybillD);
    await expect(orderLine(secondRow.new_order_option_id)).not.toContainText(waybillC);
  });

  /**
   * 승인된 일반 교환(셔츠 2개)을 상세에서 [선교환으로 전환] 한다. 회수 송장 없이 발송중으로 보내면 완료할 행이
   * 없다(회수 대기). 셔츠 1개에만 회수 송장을 넣으면 행과 후속 주문상품이 1·1 로 쪼개지고 그 행만 완료 대상이
   * 된다. 남은 1개에도 회수 송장을 넣은 뒤 완료 모달로 끝낸다.
   */
  // @scenario exchange_mode=advance, stage=redelivery
  // @effects advance_exchange_switch_ship_split_and_complete_on_the_detail_screen, advance_transition_table_skips_the_collection_stages, advance_completion_needs_both_axes, advance_split_of_a_redelivered_row_splits_the_successor_option
  test('[선교환으로 전환] → 발송 → 회수 송장 수량 1 쪼개기 → 완료', async ({ page, request, claimsManageToken }) => {
    test.setTimeout(150_000);

    // 승인 · 일반 교환 · 상품 1행 · 수량 2 이상 · 송장 없음 (FX-B 형태)
    const d0 = await discoverExchange(request, claimsManageToken, ['approved'], (d) => {
      const items: any[] = d?.claim?.items ?? [];

      return d?.claim?.status === 'approved'
        && d?.claim?.is_advance_exchange !== true
        && items.length === 1
        && (items[0].quantity ?? 0) >= 2
        && items[0].status === 'approved'
        && hasNoTracking(items[0]);
    });
    test.skip(d0 === null, '승인·일반 교환·상품 1행·수량 2 이상·송장 없는 신청서가 없다 — seed-tracking-fixtures.php(FX-B 형태) 선행');
    test.skip(
      d0.exchange_mode_abilities?.can_set_advance !== true,
      `선교환으로 전환할 수 없다(${d0.exchange_mode_abilities?.blocked_reason ?? '사유 없음'}) — 쇼핑몰 설정 claim.advance_exchange_enabled 를 켜고 실행`,
    );

    const claimId: number = d0.claim.id;
    const claimNumber: string = d0.claim.claim_number;
    const item = d0.claim.items[0];
    const carrierId = firstCarrierId(d0);

    // 화면: 진행 방식 줄 [선교환으로 전환] → 확인 모달 → 전환.
    await authenticatePage(page, claimsManageToken);
    await openDetail(page, claimNumber);

    await page.locator('#cd_016_set_advance').click();
    await expect(modalWith(page, '#cem_004_confirm')).toBeVisible();
    await expect(page.locator('#cem_001_advance_message')).toBeVisible();

    const putMode = page.waitForResponse(
      (r) => r.request().method() === 'PUT' && r.url().includes(`/exchanges/${claimId}/exchange-mode`),
      { timeout: 30_000 },
    );
    await page.locator('#cem_004_confirm').click();
    const modeResponse = await putMode;
    expect(modeResponse.status()).toBe(200);
    expect(JSON.parse(modeResponse.request().postData() ?? '{}')).toEqual({ mode: 'advance' });
    await expect(page.locator('#cd_017_set_standard')).toBeVisible();

    const d1 = await readDetail(request, claimsManageToken, claimNumber);
    expect(d1.claim.exchange_mode).toBe('advance');
    expect(d1.claim.is_advance_exchange).toBe(true);

    // 선교환은 승인에서 곧장 발송중으로 간다(회수 단계 없음). 회수 송장이 없으니 완료할 행이 없다.
    await patchItems(request, claimsManageToken, claimId, {
      status: 'redelivering',
      carrier_id: carrierId,
      tracking_number: waybill('R'),
      items: [{ id: item.id }],
    });
    const d2 = await readDetail(request, claimsManageToken, claimNumber);
    expect(d2.claim.items[0].status).toBe('redelivering');
    expect(d2.claim.items[0].collection_state, '회수 대기').toBe('pending');
    expect(d2.claim.items[0].new_order_option_id, '후속 주문상품').toBeTruthy();
    expect(d2.completable_item_count, '회수 송장 없이는 완료할 행이 없다').toBe(0);

    // 한 개에만 회수 송장 → 행이 1·나머지로, 후속 주문상품도 따로 쪼개진다.
    const waybillK1 = waybill('K');
    await patchTracking(request, claimsManageToken, claimId, {
      direction: 'collection',
      carrier_id: carrierId,
      tracking_number: waybillK1,
      items: [{ id: item.id, quantity: 1 }],
    });
    const d3 = await readDetail(request, claimsManageToken, claimNumber);
    const rows3 = d3.claim.items as any[];
    const tracked = rows3.find((i) => i.collection_tracking_number === waybillK1);
    const pending = rows3.find((i) => !i.collection_tracking_number);
    expect(rows3.map((i) => i.quantity).reduce((a: number, b: number) => a + b, 0), '수량 합 보존').toBe(item.quantity);
    expect(tracked?.quantity).toBe(1);
    expect(tracked?.collection_state, '회수 송장 행은 오는 중').toBe('in_transit');
    expect(pending?.collection_state, '나머지 행은 회수 대기').toBe('pending');
    expect(tracked?.status).toBe('redelivering');
    expect(pending?.status).toBe('redelivering');
    expect(tracked?.new_order_option_id && pending?.new_order_option_id, '두 행 모두 후속 주문상품이 있다').toBeTruthy();
    expect(tracked?.new_order_option_id, '쪼갠 행은 후속 주문상품도 따로').not.toBe(pending?.new_order_option_id);
    expect(d3.completable_item_count, '회수 송장이 있는 행만 완료 대상').toBe(1);

    // 남은 행에도 회수 송장 → 둘 다 완료 대상.
    await patchTracking(request, claimsManageToken, claimId, {
      direction: 'collection',
      carrier_id: carrierId,
      tracking_number: waybill('L'),
      items: [{ id: pending.id }],
    });
    const d4 = await readDetail(request, claimsManageToken, claimNumber);
    expect(d4.completable_item_count).toBe(2);

    // 화면: [완료 처리] → 완료 모달 → 실행.
    await openDetail(page, claimNumber);
    await expect(page.locator('#cmpl_002_button')).toBeEnabled();
    const preview = page.waitForResponse((r) => r.url().includes('/claims/completion-preview'), { timeout: 30_000 });
    await page.locator('#cmpl_002_button').click();
    await expect(modalWith(page, '#cc_022_submit_button')).toBeVisible();
    expect((await preview).status()).toBe(200);
    await expect(page.locator('#cc_022_submit_button')).toBeEnabled();

    const complete = page.waitForResponse(
      (r) => r.request().method() === 'PATCH' && r.url().includes(`/exchanges/${claimId}/status`),
      { timeout: 30_000 },
    );
    await page.locator('#cc_022_submit_button').click();
    const completeResponse = await complete;
    expect(completeResponse.status(), JSON.stringify((await completeResponse.json())?.message ?? '')).toBe(200);

    const d5 = await readDetail(request, claimsManageToken, claimNumber);
    expect(d5.claim.status).toBe('completed');
    expect((d5.claim.items as any[]).every((i) => i.status === 'completed'), '두 행 모두 완료').toBe(true);
  });

  /**
   * 헌 물건이 출발하지 않은 선교환(발송중 · 회수 대기)은 완료 모달의 「미회수로 완료」 로 끝낸다. 사유를 비우면
   * 요청이 나가지 않고, 사유를 넣어 완료하면 그 행은 미회수로 남고 재고는 움직이지 않는다.
   */
  // @scenario exchange_mode=advance, stage=redelivery
  // @effects advance_unreturned_completion_from_the_completion_modal, advance_unreturned_completion_requires_a_reason_and_keeps_stock
  test('「미회수로 완료」 는 사유 없이는 나가지 않고, 완료하면 그 행은 미회수·재고 불변이다', async ({ page, request, claimsManageToken }) => {
    test.setTimeout(120_000);

    // 선교환 · 종결 전 · 미회수 완료 후보 행 · 회수 송장 있는 행 없음 (FX-G 형태)
    const d0 = await discoverExchange(request, claimsManageToken, ['redelivering', 'redelivered'], (d) => {
      const items: any[] = d?.claim?.items ?? [];

      return d?.claim?.is_advance_exchange === true
        && d?.claim?.is_final !== true
        && (d?.unreturned_eligible_count ?? 0) >= 1
        && items.every((i) => !i.collection_tracking_number && i.collection_state === 'pending');
    });
    test.skip(d0 === null, '발송중·회수 대기인 선교환이 없다 — seed-tracking-fixtures.php(FX-G 형태) 선행');

    const claimId: number = d0.claim.id;
    const claimNumber: string = d0.claim.claim_number;
    const item = (d0.claim.items as any[])[0];
    const stockBefore = await readStock(request, claimsManageToken, item.product_id, item.product_option_id);
    const reason = `고객 연락 두절 PW${Date.now()}`;

    await authenticatePage(page, claimsManageToken);
    await openDetail(page, claimNumber);

    await expect(page.locator('#cmpl_002_button')).toBeEnabled();
    await page.locator('#cmpl_002_button').click();
    const modal = modalWith(page, '#cc_022_submit_button');
    await expect(modal).toBeVisible();

    const section = page.locator('#cc_023_unreturned_section');
    await expect(section).toBeVisible({ timeout: 30_000 });
    await expect(section).toContainText('미회수로 완료');

    const statusWrites: string[] = [];
    page.on('request', (req) => {
      if (req.method() === 'PATCH' && req.url().includes(`/exchanges/${claimId}/status`)) statusWrites.push(req.url());
    });

    // 체크만 하고 사유를 비운 채 실행 → 요청이 나가지 않고 모달이 남는다.
    // 체크 상태는 전역 상태에서 다시 그려지는 제어 컴포넌트다 — `check()` 의 즉시 검증 대신 누른 뒤 그려진 결과를 기다린다.
    const unreturnedCheckbox = section.locator('input[type="checkbox"]').first();
    await unreturnedCheckbox.click();
    await expect(unreturnedCheckbox).toBeChecked();
    const reasonInput = section.locator('input[type="text"]').first();
    await expect(reasonInput).toBeVisible();
    await page.locator('#cc_022_submit_button').click();
    await expect(modal).toBeVisible();
    await expect(page.locator('#cc_022_submit_button')).toBeEnabled();
    expect(statusWrites, '사유가 비면 완료 요청이 나가지 않는다').toEqual([]);

    // 사유를 넣고 실행.
    await reasonInput.fill(reason);
    await reasonInput.press('Tab');
    const complete = page.waitForResponse(
      (r) => r.request().method() === 'PATCH' && r.url().includes(`/exchanges/${claimId}/status`),
      { timeout: 30_000 },
    );
    await page.locator('#cc_022_submit_button').click();
    const response = await complete;
    expect(response.status(), JSON.stringify((await response.json())?.message ?? '')).toBe(200);
    const body = JSON.parse(response.request().postData() ?? '{}');
    expect(body.completion?.unreturned?.[String(item.id)]).toBe(reason);

    const d1 = await readDetail(request, claimsManageToken, claimNumber);
    const row = (d1.claim.items as any[]).find((i) => i.id === item.id);
    expect(d1.claim.status).toBe('completed');
    expect(row.collection_state).toBe('unreturned');
    expect(row.is_unreturned).toBe(true);
    expect(row.unreturned_reason).toBe(reason);
    expect(row.is_stock_restored ?? false, '미회수 행은 재고를 되돌리지 않는다').toBe(false);
    expect(await readStock(request, claimsManageToken, item.product_id, item.product_option_id), '재고 불변').toBe(stockBefore);
  });
});
